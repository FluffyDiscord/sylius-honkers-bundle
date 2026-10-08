<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Credentials;

use Sylius\Component\Core\Model\ChannelInterface;

interface HonkersChannelInterface extends ChannelInterface
{
    public function getHonkersSiteKey(): ?string;

    public function setHonkersSiteKey(?string $honkersSiteKey): void;

    public function setHonkersApiSecret(#[\SensitiveParameter] string $plainApiSecret): void;

    public function getHonkersIngestSecret(): ?string;

    public function setHonkersIngestSecret(#[\SensitiveParameter] ?string $honkersIngestSecret): void;
}
