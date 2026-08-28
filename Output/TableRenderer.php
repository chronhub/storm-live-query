<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use JsonException;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A human-readable table, one row per event; content is shown as compact JSON. Buffers the result.
 * For big dumps or piping, use `ndjson` or `json` instead.
 */
final readonly class TableRenderer implements EventRenderer
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
        $table = new Table($output);
        $table->setStyle('symfony-style-guide');
        $table->setHeaders(['position', 'type', 'aggregate_id', 'recorded_at', 'content']);

        $count = 0;

        foreach ($records as $record) {
            $view = EventView::toArray($record, $this->types, $this->veil);

            $table->addRow([
                $view['position'],
                $view['type'],
                $view['aggregate_id'] ?? '',
                $view['recorded_at'],
                json_encode($view['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $count++;
        }

        $table->render();

        return $count;
    }
}
