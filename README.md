# Storm LiveQuery

**Ad-hoc, read-only inspection of the event store from the CLI** — no projection to define, no read
model to maintain. You ask; Storm streams the matching stored events and renders them, **deserialized**
(its value over raw `psql`: `content` is the domain event's payload, not a jsonb blob).

It is a thin skin over the Chronicler's `StreamReader::retrieveByFilter(QueryFilter)` — the same read
primitive the projector uses. LiveQuery maps CLI options to a typed, **parameter-bound** filter and
renders the result. Typed selectors and recipes run under plain autocommit (they only build a bound
WHERE — structurally read-only); a raw `--sql` fragment additionally runs inside a **read-only
transaction**, on the assumption that the reader and the guarding connection are the same one (the
shipped wiring — a custom `StreamReader` adapter on another connection would escape it).

## Why it exists

Diagnostics, audit, debugging: *"show me this stream's history", "what happened in this category between
these positions", "trace this correlation id", "find events where `content.amount > 1000`"* — without
writing and running a projection for a one-off question.

## The boundary — it renders, it does not compute

LiveQuery **fetches and renders**; it never reduces events into a value. To compute a result (a balance,
a count-by-type, a running total) use a **`QueryProjection`**, folded one-shot by a dev-written command on
`QueryProjectionRunner` (there is no generic compute command — the dev builds the query filter). One
compute engine, exposed there — LiveQuery stays a viewer. The reads compose along
three axes: *filter* (shared) → *+ reducer* = QueryProjection → *+ checkpoint/persistence* = a persistent
projection (ReadModel/Link).

## Commands

```bash
# one stream's history, as a table
bin/console storm:events:inspect --stream=order-7f3a…

# a category window by position, newest first, piped through jq
bin/console storm:events:inspect --category=order --after=10000 --order=desc --output=ndjson | jq -c .

# alias-aware type + time window
bin/console storm:events:inspect --type=order.placed --since=2026-05-01 --until=2026-06-01

# json predicates on the jsonb columns (repeatable, AND); equality + comparators
bin/console storm:events:inspect --where header.__correlation_id=<id>
bin/console storm:events:inspect --where content.amount>1000 --category=order

# a named recipe (see below)
bin/console storm:events:inspect --recipe=correlation_trace --var id=<id>

# raw read-only WHERE fragment — your form, our binding (values via --var)
bin/console storm:events:inspect --sql="e.content ->> 'status' = :s" --var s=shipped

# list recipes
bin/console storm:events:recipes
```

### Options

| Option | Effect |
|--------|--------|
| `--stream` | exact stream name (`order-7f3a…`) |
| `--category` | stream category (`order`) |
| `--type` | event type(s), CSV — **alias or FQCN**, expanded to all stored types (matches old aliases / bare FQCN) |
| `--after` / `--to` | sequence-number window (`> after`, `<= to`) |
| `--since` / `--until` | `recorded_at` window — STRICT shapes (`2026-05-01`, `… 10:30[:00[.u]]`, `…T10:30:00[+02:00]`), UTC; no relative words, no rolled-over dates. A date-only `--until` means END of that day |
| `--where col.path<op>value` | json predicate on `header`/`content` — `op ∈ = != > >= < <=`; a numeric RHS compares numerically with a GUARDED cast (a non-numeric row is a non-match, never an error); `!=` compares with `IS DISTINCT FROM`, so an event missing the path IS "not equal"; repeatable, AND. Path + value are **bound** |
| `--sql "<frag>"` + `--var k=v` | raw read-only WHERE fragment, AND-ed in; `:k` placeholders bound from `--var` (a key colliding with an internal selector binding is refused). Your risk on the *form*, framework binds the *values* |
| `--recipe <name>` + `--var k=v` | run a named recipe (owns the whole query; mutually exclusive with the selectors above; unknown vars are refused) |
| `--limit` (def. 100, max 50 000) / `--order` | cap / `asc`\|`desc` by `sequence_no`, or `wallclock` (re-sorts the page by `recorded_at` — buffers it) |
| `--output table\|json\|ndjson` / `--pretty` | renderers (ndjson streams, one event/line; `--pretty` indents json; table and json buffer everything) |
| `--safe-head` | bound at `safeHeadPosition()` (the projector consistency watermark — see the projector's lens). Default = all committed (snapshot) |
| `--derived-stream <name>` | read a derived stream (a `LinkProjection` target) by link order — mutually exclusive with the selectors |
| `--out <file>` / `--export` | also write the self-describing JSON document (`{meta, results}`) to a file / emit it through the app's `LiveQueryExport` port |
| `--timeout` (def. 30 s, 0 disables) | `statement_timeout` for the read — an ad-hoc scan must not compete with the write path unbounded |
| `--dump-sql` / `--explain` | print the generated SQL + bindings / the `EXPLAIN` plan, and run nothing |

There is no arbitrary `--sql` *SELECT* (that bypasses deserialization → use `psql`); OR-groups, casts, and
distinct stay in `psql` or a recipe.

## Recipes

A **recipe is a named, parameterised fetch preset** — a plain DI-discovered PHP class, no config DSL, no
scaffolder. It declares its `--var` params and builds a `QueryFilter`. It shapes a *fetch* only (compute →
QueryProjection). Apps add their own by implementing `LiveQueryRecipe`; the framework ships
**`correlation_trace`** (every event sharing a `__correlation_id`, in order — the full causal trace of one
operation).

```php
final class OpenOrdersRecipe implements LiveQueryRecipe
{
    public function name(): string { return 'open_orders'; }
    public function description(): string { return 'Order events for one customer.'; }
    public function params(): array { return [new RecipeParam('customerId', required: true)]; }
    public function filter(array $vars): QueryFilter
    {
        return new InspectFilter(
            category: 'order',
            conditions: [new JsonCondition('content', ['customerId'], '=', $vars['customerId'])],
        );
    }
}
```

Discovery is automatic: any class implementing `LiveQueryRecipe` is tagged `storm.live_query_recipe` and
collected by `RecipeRegistry`. List them with `storm:events:recipes`; run with
`--recipe=<name> --var <param>=<value>` (required params are validated before the recipe runs).

## What's in the module

One sub-namespace per concern — browsing a directory is the fine-grained map:

- `Console/` — `storm:events:inspect` and `storm:events:recipes`;
- `Filter/` — the bound `QueryFilter` built from the CLI selectors, `--where` predicates included
  (column + operator allow-lists, bound path and value);
- `Output/` — the render strategies (table, json, ndjson) over one EventRecord → array normalizer,
  plus the self-describing export document;
- `Export/` — the `LiveQueryExport` port an app implements to receive `--export` documents;
- `Recipe/` — the recipe interface, its declared params, the registry, and the shipped
  `correlation_trace`.

Depends on the Storm read stack — **Chronicler** (the read primitive + `EventTypeMapper`), Contracts,
Clock, Message and Stream — plus DBAL, Console and DI (the manifest is the authority). Read-only by
construction.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
