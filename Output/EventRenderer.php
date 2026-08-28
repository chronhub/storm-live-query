<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Record\EventRecord;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a stream of `EventRecord` values to a chosen format. Implementations consume the
 * iterable once, since it may be a lazy `Generator` from the store, so a streaming format can
 * write as it reads.
 *
 * @see \Storm\Chronicler\Record\EventRecord
 */
interface EventRenderer
{
    /**
     * Render the records to the output, returning the number written.
     *
     * @param  iterable<EventRecord>  $records
     * @return int number of events rendered, the command's truncation signal; a count equal to the
     *             limit means the page may be capped, so an implementation must count what it writes
     *
     * @throws NotADomainEvent when a record's envelope does not wrap a domain event
     */
    public function render(iterable $records, OutputInterface $output): int;
}
