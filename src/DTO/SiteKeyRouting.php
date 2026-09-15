<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\DTO;

readonly class SiteKeyRouting
{
    /**
     * @param array<string, list<string>> $siteKeysByLocale
     * @param list<string>                $channelCodesWithoutSiteKey
     * @param list<string>                $channelCodesWithEmptySiteKey
     */
    public function __construct(
        public array $siteKeysByLocale,
        public array $channelCodesWithoutSiteKey = [],
        public array $channelCodesWithEmptySiteKey = [],
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
