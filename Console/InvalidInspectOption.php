<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use InvalidArgumentException;

/**
 * An option of `storm:events:inspect` the run refuses before the events are read, its message written
 * for the operator: a malformed or out-of-range value, two options that exclude each other, or a
 * selector the store cannot resolve.
 *
 * The command reports it once and exits `INVALID`.
 */
final class InvalidInspectOption extends InvalidArgumentException {}
