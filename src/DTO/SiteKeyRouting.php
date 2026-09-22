<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DTO;

class SiteKeyRouting
{
    /**
     * @param array<string, list<string>> $siteKeysByLocale
     * @param list<string>                $channelCodesWithoutSiteKey
     * @param list<string>                $channelCodesWithEmptySiteKey
     */
    public function __construct(
        public readonly array $siteKeysByLocale,
        public readonly array $channelCodesWithoutSiteKey = [],
        public readonly array $channelCodesWithEmptySiteKey = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function getSiteKeys(string $locale): array
    {
        return $this->siteKeysByLocale[$locale] ?? [];
    }
}
