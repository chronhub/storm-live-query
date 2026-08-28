<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use JsonException;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Newline-delimited JSON: one event object per line. Streams straight from the store's
 * `Generator`, so it scales to large dumps and pipes cleanly into `jq -c`, `grep`, or `wc -l`.
 *
 * Warning: the command materializes the `--limit`-bounded page before rendering in two cases, to
 * re-sort it under `--order wallclock` and to feed every target from one read under `--out` or
 * `--export`; the streaming promise holds for an `asc`/`desc` run to stdout alone.
 */
final readonly class NdjsonRenderer implements EventRenderer
{
    public function __construct(
        private EventTypeMapper $types,
        private PersonalDataVeil $veil,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws JsonException on a JSON encoding failure
     */
    public function render(iterable $records, OutputInterface $output): int
    {
        $count = 0;

        foreach ($records as $record) {
            $output->writeln(json_encode(EventView::toArray($record, $this->types, $this->veil), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $count++;
        }

        return $count;
    }
}
