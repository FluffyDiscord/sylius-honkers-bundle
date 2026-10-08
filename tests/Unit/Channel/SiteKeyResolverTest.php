<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Channel;

use Doctrine\ORM\EntityManagerInterface;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Pairing\HostMatcher;
use FluffyDiscord\HonkersBundle\Credentials\EnvCredentialsProvider;
use FluffyDiscord\SyliusHonkersPlugin\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\SyliusCredentialsProvider;
use FluffyDiscord\SyliusHonkersPlugin\DTO\SiteKeyRouting;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelCredentialsProviderDouble;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\HonkersChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Locale\Model\Locale;

class SiteKeyResolverTest extends TestCase
{
    /**
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     * @param array<string, string>                                        $providedSiteKeys
     * @param array<string, string>                                        $configuredChannelSiteKeys
     * @param list<string>                                                 $locales
     * @param array<string, list<string>>                                  $expectedSiteKeysByLocale
     * @param list<string>                                                 $expectedChannelsWithoutSiteKey
     * @param list<string>                                                 $expectedChannelsWithEmptySiteKey
     */
    #[DataProvider('provideLocaleRouting')]
    public function testEveryLocaleIsRoutedToTheSitesOfTheChannelsServingIt(
        array $channelDefinitions,
        array $providedSiteKeys,
        array $configuredChannelSiteKeys,
        array $locales,
        array $expectedSiteKeysByLocale,
        array $expectedChannelsWithoutSiteKey = [],
        array $expectedChannelsWithEmptySiteKey = [],
    ): void {
        $resolver = new SiteKeyResolver(
            $this->createChannelRepository($channelDefinitions),
            new ChannelCredentialsProviderDouble($providedSiteKeys),
            $configuredChannelSiteKeys,
        );

        $routing = $resolver->getSiteKeyRouting($locales);

        self::assertSame($expectedSiteKeysByLocale, $this->getSiteKeysByLocale($routing));
        self::assertSame($expectedChannelsWithoutSiteKey, $routing->channelCodesWithoutSiteKey);
        self::assertSame($expectedChannelsWithEmptySiteKey, $routing->channelCodesWithEmptySiteKey);
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function provideLocaleRouting(): iterable
    {
        $czechAndSlovakChannels = [
            'CZ' => ['locales' => ['cs_CZ']],
            'SK' => ['locales' => ['sk_SK']],
        ];

        yield 'each channel locale goes to its own site' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key', 'SK' => 'sk-key'],
            [],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => ['sk-key']],
        ];

        yield 'channels sharing a locale reach each of their sites' => [
            [
                'DE' => ['locales' => ['de_DE']],
                'AT' => ['locales' => ['de_DE', 'de_AT']],
            ],
            ['DE' => 'de-key', 'AT' => 'at-key'],
            [],
            ['de_DE', 'de_AT'],
            ['de_DE' => ['de-key', 'at-key'], 'de_AT' => ['at-key']],
        ];

        yield 'channels sharing a site key reach it once' => [
            [
                'CZ' => ['locales' => ['cs_CZ']],
                'CZ_B2B' => ['locales' => ['cs_CZ']],
            ],
            ['CZ' => 'cz-key', 'CZ_B2B' => 'cz-key'],
            [],
            ['cs_CZ'],
            ['cs_CZ' => ['cz-key']],
        ];

        yield 'a channel without credentials is reported without a site key' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key'],
            [],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
            ['SK'],
        ];

        yield 'no credentials at all reaches no site' => [
            $czechAndSlovakChannels,
            [],
            [],
            ['cs_CZ'],
            ['cs_CZ' => []],
            ['CZ'],
        ];

        yield 'a keyless channel not serving the changed locales is not reported' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key'],
            [],
            ['cs_CZ'],
            ['cs_CZ' => ['cz-key']],
        ];

        yield 'a channel mapped to an empty key is reported as empty' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key'],
            ['CZ' => 'cz-key', 'SK' => ''],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
            [],
            ['SK'],
        ];

        yield 'a paired channel mapped to an empty key still reaches its site' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key', 'SK' => 'paired-key'],
            ['SK' => ''],
            ['sk_SK'],
            ['sk_SK' => ['paired-key']],
        ];

        yield 'a disabled channel is neither notified nor reported' => [
            [
                'CZ' => ['locales' => ['cs_CZ']],
                'SK' => ['locales' => ['sk_SK'], 'enabled' => false],
            ],
            ['CZ' => 'cz-key'],
            [],
            ['cs_CZ', 'sk_SK'],
            ['cs_CZ' => ['cz-key'], 'sk_SK' => []],
        ];

        yield 'a locale no channel serves reaches no site' => [
            $czechAndSlovakChannels,
            ['CZ' => 'cz-key', 'SK' => 'sk-key'],
            [],
            ['ru_RU'],
            ['ru_RU' => []],
        ];

        yield 'numeric channel codes are matched' => [
            ['123' => ['locales' => ['cs_CZ']]],
            ['123' => 'numeric-key'],
            [],
            ['cs_CZ'],
            ['cs_CZ' => ['numeric-key']],
        ];
    }

    public function testTheRoutedSitesCarryTheProvidedIngestSecret(): void
    {
        $provider = new ChannelCredentialsProviderDouble(['CZ' => 'cz-key']);
        $resolver = new SiteKeyResolver(
            $this->createChannelRepository(['CZ' => ['locales' => ['cs_CZ']]]),
            $provider,
            [],
        );

        $routing = $resolver->getSiteKeyRouting(['cs_CZ']);

        self::assertEquals([new SiteCredentials('cz-key', $provider->getIngestSecret())], $routing->getSiteCredentials('cs_CZ'));
    }

    public function testAChannelWithoutAnIngestSecretIsReportedWithoutASiteKey(): void
    {
        $resolver = new SiteKeyResolver(
            $this->createChannelRepository(['CZ' => ['locales' => ['cs_CZ']]]),
            new ChannelCredentialsProviderDouble(['CZ' => 'cz-key'], ingestSecret: ''),
            [],
        );

        $routing = $resolver->getSiteKeyRouting(['cs_CZ']);

        self::assertSame([], $routing->getSiteCredentials('cs_CZ'));
        self::assertSame(['CZ'], $routing->channelCodesWithoutSiteKey);
    }

    public function testThePairedCredentialsAreReadFromTheLoadedChannelsWithoutAnotherQuery(): void
    {
        $czechChannel = $this->createPairedChannel('CZ', 'cs_CZ', 'cz-key');
        $slovakChannel = $this->createPairedChannel('SK', 'sk_SK', 'sk-key');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('getClassName')->willReturn(HonkersChannel::class);
        $channelRepository->expects(self::once())->method('findAll')->willReturn([$czechChannel, $slovakChannel]);
        $channelRepository->expects(self::never())->method('findOneByCode');
        $channelRepository->expects(self::never())->method('findBy');
        $channelRepository->expects(self::never())->method('findOneBy');
        $credentialsProvider = new SyliusCredentialsProvider(
            new EnvCredentialsProvider('', '', ''),
            $channelRepository,
            $this->createStub(ChannelContextInterface::class),
            $this->createStub(EntityManagerInterface::class),
            new HostMatcher(),
            [],
        );
        $resolver = new SiteKeyResolver($channelRepository, $credentialsProvider, []);

        $routing = $resolver->getSiteKeyRouting(['cs_CZ', 'sk_SK']);

        self::assertSame(['cs_CZ' => ['cz-key'], 'sk_SK' => ['sk-key']], $this->getSiteKeysByLocale($routing));
    }

    private function createPairedChannel(string $code, string $localeCode, string $siteKey): HonkersChannel
    {
        $locale = new Locale();
        $locale->setCode($localeCode);

        $channel = new HonkersChannel();
        $channel->setCode($code);
        $channel->addLocale($locale);
        $channel->setHonkersSiteKey($siteKey);
        $channel->setHonkersIngestSecret('paired-ingest-secret');

        return $channel;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getSiteKeysByLocale(SiteKeyRouting $routing): array
    {
        $siteKeysByLocale = [];

        foreach ($routing->siteCredentialsByLocale as $locale => $siteCredentialsList) {
            $siteKeysByLocale[$locale] = array_map(
                fn (SiteCredentials $siteCredentials): string => $siteCredentials->siteKey,
                $siteCredentialsList,
            );
        }

        return $siteKeysByLocale;
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
