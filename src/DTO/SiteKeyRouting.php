<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\DTO;

use FluffyDiscord\Honkers\DTO\SiteCredentials;

class SiteKeyRouting
{
    /**
     * @param array<string, list<SiteCredentials>> $siteCredentialsByLocale
     * @param list<string>                         $channelCodesWithoutSiteKey
     * @param list<string>                         $channelCodesWithEmptySiteKey
     */
    public function __construct(
        public readonly array $siteCredentialsByLocale,
        public readonly array $channelCodesWithoutSiteKey = [],
        public readonly array $channelCodesWithEmptySiteKey = [],
    ) {
    }

    /**
     * @return list<SiteCredentials>
     */
    public function getSiteCredentials(string $locale): array
    {
        return $this->siteCredentialsByLocale[$locale] ?? [];
    }
}
