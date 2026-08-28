<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use Storm\Clock\PointInTime;

/**
 * The provenance half of the self-describing JSON document `{meta, results}`: what produced the
 * page, when, and under which bounds. The replayable source is the recipe plus vars, the typed
 * selectors, or a derived stream. A file produced by redirecting stdout still says what it is and
 * whether it is partial.
 *
 * The count half, `count` and `truncated`, only exists after iteration, so `toArray()` takes the
 * count and derives `truncated` as `count == limit`, the same signal the CLI stderr warning uses.
 * It may false-positive on a result that sits exactly at the bound; "truncated" means "the page
 * filled the limit, more rows may exist".
 *
 * The order is present for the typed selectors only: a derived stream is ordered by its
 * `target_position` and a recipe owns its whole query, so naming an order there would lie. The
 * `safe_head`, when `--safe-head` is set, is the applied upper bound the read ran under, the min of
 * `--to` and the store watermark.
 *
 * @see JsonRenderer
 */
final readonly class DocumentMeta
{
    /**
     * @param  array<string, mixed>  $source  the replayable origin, keyed by mode:
     *                                        ['mode' => 'recipe'|'selectors'|'derived_stream', ...]
     */
    public function __construct(
        public array $source,
        public ?int $limit,
        public PointInTime $generatedAt,
        public ?string $order = null,
        public ?int $safeHead = null,
    ) {}

    /**
     * @return array<string, mixed> the complete meta; count handed in, truncated derived
     */
    public function toArray(int $count): array
    {
        $meta = [
            'generated_at' => $this->generatedAt->toString(),
            'source' => $this->source,
        ];

        if ($this->order !== null) {
            $meta['order'] = $this->order;
        }

        $meta['limit'] = $this->limit;
        $meta['count'] = $count;
        $meta['truncated'] = $this->limit !== null && $count === $this->limit;

        if ($this->safeHead !== null) {
            $meta['safe_head'] = $this->safeHead;
        }

        return $meta;
    }
}
