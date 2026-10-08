<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Channel;

use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use FluffyDiscord\SyliusHonkersPlugin\DTO\SiteKeyRouting;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SiteKeyResolver
{
    /**
     * @param array<array-key, string> $channelSiteKeys
     */
    public function __construct(
        private readonly ChannelRepositoryInterface          $channelRepository,
        private readonly ChannelCredentialsProviderInterface $credentialsProvider,

        #[Autowire(param: 'fluffydiscord_sylius_honkers.channel_site_keys')]
        private readonly array $channelSiteKeys,
    ) {
    }

    /**
     * @param list<string> $locales
     */
    public function getSiteKeyRouting(array $locales): SiteKeyRouting
    {
        $siteCredentialsByLocale = array_fill_keys($locales, []);
        $channelCodesWithoutSiteKey = [];
        $channelCodesWithEmptySiteKey = [];

        foreach ($this->getEnabledChannels() as $channel) {
            $channelLocaleCodes = $this->getChannelLocaleCodes($channel);
            $servedLocales = array_values(array_intersect($channelLocaleCodes, $locales));
            if ($servedLocales === []) {
                continue;
            }

            $siteCredentials = $this->findNotifiableCredentials($channel);
            if ($siteCredentials === null) {
                $channelCode = (string) $channel->getCode();
                $isOptedOut = $this->isChannelSiteKeyConfiguredEmpty($channelCode);
                if ($isOptedOut) {
                    $channelCodesWithEmptySiteKey[] = $channelCode;
                } else {
                    $channelCodesWithoutSiteKey[] = $channelCode;
                }

                continue;
            }

            foreach ($servedLocales as $servedLocale) {
                $siteCredentialsByLocale[$servedLocale][$siteCredentials->siteKey] = $siteCredentials;
            }
        }

        return new SiteKeyRouting(
            array_map(array_values(...), $siteCredentialsByLocale),
            $channelCodesWithoutSiteKey,
            $channelCodesWithEmptySiteKey,
        );
    }

    private function findNotifiableCredentials(ChannelInterface $channel): ?SiteCredentials
    {
        $siteCredentials = $this->credentialsProvider->findForChannel($channel);
        if ($siteCredentials === null) {
            return null;
        }

        $hasIngestSecret = $siteCredentials->hasIngestSecret();
        if (!$hasIngestSecret) {
            return null;
        }

        return $siteCredentials;
    }

    private function isChannelSiteKeyConfiguredEmpty(string $channelCode): bool
    {
        $configuredSiteKey = $this->channelSiteKeys[$channelCode] ?? null;

        return $configuredSiteKey === '';
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
