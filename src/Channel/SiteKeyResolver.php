<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Channel;

use FluffyDiscord\SyliusChatbotBundle\DTO\SiteKeyRouting;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class SiteKeyResolver
{
    /**
     * @param array<array-key, string> $channelSiteKeys
     */
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,

        #[Autowire(param: 'fluffydiscord_sylius_chatbot.widget.site_key')]
        private string $defaultSiteKey,

        #[Autowire(param: 'fluffydiscord_sylius_chatbot.widget.channel_site_keys')]
        private array $channelSiteKeys,
    ) {
    }

    public function getDefaultSiteKey(): string
    {
        return $this->defaultSiteKey;
    }

    public function hasChannelSiteKeys(): bool
    {
        return $this->channelSiteKeys !== [];
    }

    public function hasAnySiteKey(): bool
    {
        if ($this->defaultSiteKey !== '') {
            return true;
        }

        $configuredChannelSiteKeys = array_filter($this->channelSiteKeys, fn (string $siteKey): bool => $siteKey !== '');

        return $configuredChannelSiteKeys !== [];
    }

    public function getSiteKey(string $channelCode): string
    {
        return $this->findChannelSiteKey($channelCode) ?? $this->defaultSiteKey;
    }

    public function findChannelSiteKey(string $channelCode): ?string
    {
        return $this->channelSiteKeys[$channelCode] ?? null;
    }

    /**
     * @param list<string> $locales
     */
    public function getSiteKeyRouting(array $locales): SiteKeyRouting
    {
        $hasChannelSiteKeys = $this->hasChannelSiteKeys();
        if (!$hasChannelSiteKeys) {
            return $this->getDefaultSiteKeyRouting($locales);
        }

        $siteKeysByLocale = array_fill_keys($locales, []);
        $channelCodesWithoutSiteKey = [];
        $channelCodesWithEmptySiteKey = [];

        foreach ($this->getEnabledChannels() as $channel) {
            $channelLocaleCodes = $this->getChannelLocaleCodes($channel);
            $servedLocales = array_values(array_intersect($channelLocaleCodes, $locales));
            if ($servedLocales === []) {
                continue;
            }

            $channelCode = (string) $channel->getCode();
            $channelSiteKey = $this->findChannelSiteKey($channelCode);
            if ($channelSiteKey === '') {
                $channelCodesWithEmptySiteKey[] = $channelCode;

                continue;
            }

            $siteKey = $channelSiteKey ?? $this->defaultSiteKey;
            if ($siteKey === '') {
                $channelCodesWithoutSiteKey[] = $channelCode;

                continue;
            }

            foreach ($servedLocales as $servedLocale) {
                $siteKeysByLocale[$servedLocale][$siteKey] = $siteKey;
            }
        }

        return new SiteKeyRouting(
            array_map(array_values(...), $siteKeysByLocale),
            $channelCodesWithoutSiteKey,
            $channelCodesWithEmptySiteKey,
        );
    }

    /**
     * @param list<string> $locales
     */
    private function getDefaultSiteKeyRouting(array $locales): SiteKeyRouting
    {
        $siteKeys = [];
        if ($this->defaultSiteKey !== '') {
            $siteKeys[] = $this->defaultSiteKey;
        }

        return new SiteKeyRouting(array_fill_keys($locales, $siteKeys));
    }

    /**
     * @return list<ChannelInterface>
     */
    private function getEnabledChannels(): array
    {
        $enabledChannels = [];

        foreach ($this->channelRepository->findAll() as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }

            $isEnabled = $channel->isEnabled();
            if ($isEnabled) {
                $enabledChannels[] = $channel;
            }
        }

        return $enabledChannels;
    }

    /**
     * @return list<string>
     */
    private function getChannelLocaleCodes(ChannelInterface $channel): array
    {
        $localeCodes = [];

        foreach ($channel->getLocales() as $locale) {
            $localeCode = (string) $locale->getCode();
            if ($localeCode === '') {
                continue;
            }

            $localeCodes[] = $localeCode;
        }

        return $localeCodes;
    }
}
