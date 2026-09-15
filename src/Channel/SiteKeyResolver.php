<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Channel;

use Psr\Log\LoggerInterface;
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
        private LoggerInterface            $logger,

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
     *
     * @return array<string, list<string>>
     */
    public function getSiteKeysByLocale(array $locales): array
    {
        if ($this->channelSiteKeys === []) {
            return $this->getDefaultSiteKeyForEveryLocale($locales);
        }

        $siteKeysByLocale = array_fill_keys($locales, []);

        foreach ($this->getEnabledChannels() as $channel) {
            $channelCode = (string) $channel->getCode();
            $siteKey = $this->getSiteKey($channelCode);
            if ($siteKey === '') {
                $this->logger->warning('Chatbot: the channel has no site key, skipping its catalog notifications.', [
                    'channelCode' => $channelCode,
                ]);

                continue;
            }

            foreach ($this->getChannelLocaleCodes($channel) as $localeCode) {
                $isRequestedLocale = array_key_exists($localeCode, $siteKeysByLocale);
                if (!$isRequestedLocale) {
                    continue;
                }

                $siteKeysByLocale[$localeCode][$siteKey] = $siteKey;
            }
        }

        return array_map(array_values(...), $siteKeysByLocale);
    }

    /**
     * @param list<string> $locales
     *
     * @return array<string, list<string>>
     */
    private function getDefaultSiteKeyForEveryLocale(array $locales): array
    {
        $siteKeys = [];
        if ($this->defaultSiteKey !== '') {
            $siteKeys[] = $this->defaultSiteKey;
        }

        return array_fill_keys($locales, $siteKeys);
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
            $localeCode = $locale->getCode();

            if ($localeCode !== null && $localeCode !== '') {
                $localeCodes[] = $localeCode;
            }
        }

        return $localeCodes;
    }
}
