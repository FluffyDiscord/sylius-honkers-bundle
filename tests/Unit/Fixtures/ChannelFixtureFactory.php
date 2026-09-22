<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Locale\Model\Locale;

class ChannelFixtureFactory
{
    /**
     * @param list<string> $localeCodes
     */
    public function createChannel(string $code, array $localeCodes, bool $enabled = true): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setEnabled($enabled);

        foreach ($localeCodes as $localeCode) {
            $locale = new Locale();
            $locale->setCode($localeCode);
            $channel->addLocale($locale);
        }

        return $channel;
    }

    /**
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     *
     * @return list<Channel>
     */
    public function createChannels(array $channelDefinitions): array
    {
        $channels = [];

        foreach ($channelDefinitions as $code => $channelDefinition) {
            $enabled = $channelDefinition['enabled'] ?? true;
            $channels[] = $this->createChannel((string) $code, $channelDefinition['locales'], $enabled);
        }

        return $channels;
    }
}
