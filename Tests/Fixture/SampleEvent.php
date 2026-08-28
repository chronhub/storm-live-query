<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;

final class SampleEvent implements DomainEvent
{
    public function __construct(
        public int $amount = 100,
    ) {}

    public function aggregateId(): string
    {
        return 'account-7';
    }

    public function toPayload(): array
    {
        return ['amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['amount']);
    }
}
