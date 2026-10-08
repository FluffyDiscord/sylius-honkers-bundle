<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures;

use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

class ChannelCredentialsProviderDouble implements ChannelCredentialsProviderInterface
{
    /**
     * @param array<string, string> $siteKeysByChannelCode
     */
    public function __construct(
        private readonly array  $siteKeysByChannelCode = [],
        private readonly string $currentSiteKey = '',
        private readonly string $ingestSecret = 'ingest-secret',
    ) {
    }

    public function isApiSecretValid(#[\SensitiveParameter] string $token): bool
    {
        return false;
    }

    public function findCurrentSite(): ?SiteCredentials
    {
        return $this->createSiteCredentials($this->currentSiteKey);
    }

    public function findForChannel(ChannelInterface $channel): ?SiteCredentials
    {
        $channelCode = (string) $channel->getCode();
        $siteKey = $this->siteKeysByChannelCode[$channelCode] ?? '';

        return $this->createSiteCredentials($siteKey);
    }

    public function getIngestSecret(): string
    {
        return $this->ingestSecret;
    }

    private function createSiteCredentials(string $siteKey): ?SiteCredentials
    {
        if ($siteKey === '') {
            return null;
        }

        return new SiteCredentials($siteKey, $this->getIngestSecret());
    }
}
