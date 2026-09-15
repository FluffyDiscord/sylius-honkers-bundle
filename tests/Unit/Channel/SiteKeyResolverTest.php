<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Channel;

use FluffyDiscord\SyliusChatbotBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

class SiteKeyResolverTest extends TestCase
{
    /**
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     * @param array<string, string>                                        $channelSiteKeys
     * @param list<string>                                                 $locales
     * @param array<string, list<string>>                                  $expectedSiteKeysByLocale
     * @param list<string>                                                 $expectedSkippedChannelCodes
     */
    #[DataProvider('provideLocaleRouting')]
    public function testEveryLocaleIsRoutedToTheSitesOfTheChannelsServingIt(
        array $channelDefinitions,
        string $defaultSiteKey,
        array $channelSiteKeys,
        array $locales,
        array $expectedSiteKeysByLocale,
        array $expectedSkippedChannelCodes,
    ): void {
        $logger = new RecordingLogger();
        $resolver = new SiteKeyResolver(
            $this->createChannelRepository($channelDefinitions),
            $logger,
            $defaultSiteKey,
            $channelSiteKeys,
        );

        $siteKeysByLocale = $resolver->getSiteKeysByLocale($locales);

        self::assertSame($expectedSiteKeysByLocale, $siteKeysByLocale);
        self::assertSame($expectedSkippedChannelCodes, $logger->getContextValues('channelCode'));
    }

    /**
     * @return iterable<string, array{array<string, array{locales: list<string>, enabled?: bool}>, string, array<string, string>, list<string>, array<string, list<string>>, list<string>}>
     */
    public static function provideLocaleRouting(): iterable
    {
        $czechAndSlovakChannels = [
            'CZ' => ['locales' => ['cs_CZ']],
            'SK' => ['locales' => ['sk_SK']],
        ];

        yield 'no channel keys sends every locale to the default site' => [
            $czechAndSlovakChannels,
            'default-key',
            [],
            ['cs_CZ', 'en_US'],
            ['cs_CZ' => ['default-key'], 'en_US' => ['default-key']],
            [],
        ];

        yield 'no channel keys and no default key reaches no site' => [
            $czechAndSlovakChannels,
            '',
            [],
            ['cs_CZ'],
            ['cs_CZ' => []],
            [],
        ];

        yield 'each channel locale goes to its own site' => [
            $czechAndSlovakChannels,
            '',
            ['CZ' => 'cz-key', 'SK' => 'sk-key'],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => ['sk-key']],
            [],
        ];

        yield 'channels sharing a locale reach each of their sites' => [
            [
                'DE' => ['locales' => ['de_DE']],
                'AT' => ['locales' => ['de_DE', 'de_AT']],
            ],
            '',
            ['DE' => 'de-key', 'AT' => 'at-key'],
            ['de_DE', 'de_AT'],
            ['de_DE' => ['de-key', 'at-key'], 'de_AT' => ['at-key']],
            [],
        ];

        yield 'channels sharing a site key reach it once' => [
            [
                'CZ' => ['locales' => ['cs_CZ']],
                'CZ_B2B' => ['locales' => ['cs_CZ']],
            ],
            '',
            ['CZ' => 'cz-key', 'CZ_B2B' => 'cz-key'],
            ['cs_CZ'],
            ['cs_CZ' => ['cz-key']],
            [],
        ];

        yield 'an unmapped channel falls back to the default site' => [
            $czechAndSlovakChannels,
            'default-key',
            ['CZ' => 'cz-key'],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => ['default-key']],
            [],
        ];

        yield 'an unmapped channel without a default key is skipped' => [
            $czechAndSlovakChannels,
            '',
            ['CZ' => 'cz-key'],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
            ['SK'],
        ];

        yield 'a channel mapped to an empty key is skipped instead of falling back' => [
            $czechAndSlovakChannels,
            'default-key',
            ['CZ' => 'cz-key', 'SK' => ''],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
            ['SK'],
        ];

        yield 'a disabled channel is not notified' => [
            [
                'CZ' => ['locales' => ['cs_CZ']],
                'SK' => ['locales' => ['sk_SK'], 'enabled' => false],
            ],
            '',
            ['CZ' => 'cz-key', 'SK' => 'sk-key'],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
            [],
        ];

        yield 'a locale no channel serves reaches no site' => [
            $czechAndSlovakChannels,
            'default-key',
            ['CZ' => 'cz-key'],
            ['ru_RU'],
            ['ru_RU' => []],
            [],
        ];

        yield 'numeric channel codes are matched' => [
            ['123' => ['locales' => ['cs_CZ']]],
            '',
            ['123' => 'numeric-key'],
            ['cs_CZ'],
            ['cs_CZ' => ['numeric-key']],
            [],
        ];
    }

    public function testWithoutChannelKeysTheChannelsAreNeverLoaded(): void
    {
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findAll');
        $resolver = new SiteKeyResolver($channelRepository, new NullLogger(), 'default-key', []);

        $resolver->getSiteKeysByLocale(['cs_CZ']);
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    #[DataProvider('provideChannelSiteKeys')]
    public function testTheChannelSiteKeyFallsBackToTheDefault(
        string $defaultSiteKey,
        array $channelSiteKeys,
        string $channelCode,
        string $expectedSiteKey,
    ): void {
        $resolver = new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), new NullLogger(), $defaultSiteKey, $channelSiteKeys);

        self::assertSame($expectedSiteKey, $resolver->getSiteKey($channelCode));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, string}>
     */
    public static function provideChannelSiteKeys(): iterable
    {
        yield 'mapped channel' => ['default-key', ['CZ' => 'cz-key'], 'CZ', 'cz-key'];
        yield 'unmapped channel' => ['default-key', ['CZ' => 'cz-key'], 'SK', 'default-key'];
        yield 'no channel keys' => ['default-key', [], 'CZ', 'default-key'];
        yield 'channel mapped to an empty key' => ['default-key', ['CZ' => ''], 'CZ', ''];
        yield 'unmapped channel without default' => ['', ['CZ' => 'cz-key'], 'SK', ''];
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    #[DataProvider('provideConfiguredSiteKeys')]
    public function testASiteKeyIsConfiguredByTheDefaultOrAnyChannel(
        string $defaultSiteKey,
        array $channelSiteKeys,
        bool $expectedHasAnySiteKey,
    ): void {
        $resolver = new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), new NullLogger(), $defaultSiteKey, $channelSiteKeys);

        self::assertSame($expectedHasAnySiteKey, $resolver->hasAnySiteKey());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function provideConfiguredSiteKeys(): iterable
    {
        yield 'nothing' => ['', [], false];
        yield 'only empty channel keys' => ['', ['CZ' => ''], false];
        yield 'default only' => ['default-key', [], true];
        yield 'channel keys only' => ['', ['CZ' => 'cz-key'], true];
    }

    /**
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     */
    private function createChannelRepository(array $channelDefinitions): ChannelRepositoryInterface
    {
        $channels = (new ChannelFixtureFactory())->createChannels($channelDefinitions);

        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepository->method('findAll')->willReturn($channels);

        return $channelRepository;
    }
}
