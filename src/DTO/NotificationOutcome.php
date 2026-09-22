<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DTO;

class NotificationOutcome
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }

    public function isThrottled(): bool
    {
        return $this->retryAfterSeconds !== null;
    }
}
