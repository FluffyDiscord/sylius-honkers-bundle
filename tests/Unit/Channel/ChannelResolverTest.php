<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Channel;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

class ChannelResolverTest extends TestCase
{
    public function testEnabledChannelsLeaveOutADisabledChannel(): void
    {
        $resolver = $this->createResolver([
            'CZ_WEB' => ['locales' => ['cs_CZ']],
            'RETIRED' => ['locales' => ['ru_RU'], 'enabled' => false],
            'AT_WEB' => ['locales' => ['de_AT']],
        ]);

        $codes = array_map(
            static fn(object $channel): ?string => $channel->getCode(),
            $resolver->getEnabledChannels(),
        );

        self::assertSame(['CZ_WEB', 'AT_WEB'], $codes);
    }

    public function testEnabledChannelsAreEmptyWhenTheShopHasNone(): void
    {
        $resolver = $this->createResolver([]);

        self::assertSame([], $resolver->getEnabledChannels());
    }

    /**
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     */
    private function createResolver(array $channelDefinitions): ChannelResolver
    {
        $channels = (new ChannelFixtureFactory())->createChannels($channelDefinitions);

        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepository->method('findAll')->willReturn($channels);

        $channelContext = $this->createStub(ChannelContextInterface::class);

        return new ChannelResolver($channelContext, $channelRepository);
    }
}
