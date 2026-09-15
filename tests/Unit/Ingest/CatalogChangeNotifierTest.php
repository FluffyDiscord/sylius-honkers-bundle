<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Ingest;

use FluffyDiscord\SyliusChatbotBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusChatbotBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusChatbotBundle\Ingest\CatalogChangeNotifier;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CatalogChangeNotifierTest extends TestCase
{
    /** @var list<array{url: string, options: array}> */
    private array $capturedRequests = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    /**
     * @param array<string, string>                                        $channelSiteKeys
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     */
    private function createNotifier(
        array $responses,
        string $backendUrl = 'https://backend.example',
        string $environment = 'prod',
        string $defaultSiteKey = 'site-key',
        array $channelSiteKeys = [],
        array $channelDefinitions = [],
        ?ChannelRepositoryInterface $channelRepository = null,
    ): CatalogChangeNotifier {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->capturedRequests[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 202]);
        });

        return new CatalogChangeNotifier(
            $client,
            $this->logger,
            new SiteKeyResolver($channelRepository ?? $this->createChannelRepository($channelDefinitions), $defaultSiteKey, $channelSiteKeys),
            $backendUrl,
            'ingest-secret',
            $environment,
        );
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

    /**
     * @return list<string>
     */
    private function getLoggedChannels(string $level): array
    {
        $loggedChannels = [];

        foreach ($this->logger->records as $record) {
            $isLevel = $record['level'] === $level;
            $hasChannelCode = array_key_exists('channelCode', $record['context']);
            if (!$isLevel || !$hasChannelCode) {
                continue;
            }

            $loggedChannels[] = $record['context']['channelCode'];
        }

        return $loggedChannels;
    }

    public function testASiteResolutionFailureIsLoggedAndSendsNothing(): void
    {
        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepository->method('findAll')->willThrowException(new \RuntimeException('database gone'));
        $notifier = $this->createNotifier([], channelSiteKeys: ['CZ' => 'cz-key'], channelRepository: $channelRepository);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();

        self::assertSame([], $this->capturedRequests);
        self::assertSame(
            ['Chatbot: resolving the sites to notify about catalog changes failed.'],
            array_column($this->logger->records, 'message'),
        );
    }

    /**
     * @param array<string, string> $channelSiteKeys
     * @param list<string>          $expectedWarnedChannels
     * @param list<string>          $expectedDebuggedChannels
     */
    #[DataProvider('provideSkippedChannelLogs')]
    public function testOnlyKeylessChannelsServingAChangedLocaleAreLogged(
        string $defaultSiteKey,
        array $channelSiteKeys,
        string $changedLocale,
        array $expectedWarnedChannels,
        array $expectedDebuggedChannels,
    ): void {
        $notifier = $this->createNotifier(
            [],
            defaultSiteKey: $defaultSiteKey,
            channelSiteKeys: $channelSiteKeys,
            channelDefinitions: ['CZ' => ['locales' => ['cs_CZ']], 'SK' => ['locales' => ['sk_SK']]],
        );

        $notifier->collect(CatalogSourceName::Products, $changedLocale, 'T-SHIRT-01');
        $notifier->flush();

        self::assertSame($expectedWarnedChannels, $this->getLoggedChannels(LogLevel::WARNING));
        self::assertSame($expectedDebuggedChannels, $this->getLoggedChannels(LogLevel::DEBUG));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, list<string>, list<string>}>
     */
    public static function provideSkippedChannelLogs(): iterable
    {
        yield 'unmapped channel without default serving the locale warns' => ['', ['CZ' => 'cz-key'], 'sk_SK', ['SK'], []];
        yield 'unmapped channel without default not serving the locale is silent' => ['', ['CZ' => 'cz-key'], 'cs_CZ', [], []];
        yield 'unmapped channel with default is silent' => ['site-key', ['CZ' => 'cz-key'], 'sk_SK', [], []];
        yield 'channel mapped to an empty key logs at debug' => ['site-key', ['CZ' => 'cz-key', 'SK' => ''], 'sk_SK', [], ['SK']];
    }

    /**
     * @return list<string>
     */
    private function getCapturedSiteRequests(): array
    {
        $siteRequests = [];

        foreach ($this->capturedRequests as $request) {
            $body = json_decode($request['options']['body'], true);
            $authorization = $this->readAuthorization($request['options']['headers']);
            $siteRequests[] = sprintf('%s %s %s -> %s', $body['source'], $body['locale'], implode(',', $body['externalIds']), $authorization);
        }

        return $siteRequests;
    }

    /**
     * @param list<string> $headers
     */
    private function readAuthorization(array $headers): string
    {
        foreach ($headers as $header) {
            $isAuthorization = str_starts_with($header, 'Authorization: Bearer ');
            if ($isAuthorization) {
                return substr($header, strlen('Authorization: Bearer '));
            }
        }

        return '';
    }

    /**
     * @param array<string, string>                                        $channelSiteKeys
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     * @param list<array{string, string}>                                  $collectedChanges
     * @param list<string>                                                 $expectedSiteRequests
     */
    #[DataProvider('provideSiteRouting')]
    public function testFlushSendsEveryChangeToEverySiteServingItsLocale(
        string $defaultSiteKey,
        array $channelSiteKeys,
        array $channelDefinitions,
        array $collectedChanges,
        array $expectedSiteRequests,
    ): void {
        $notifier = $this->createNotifier([], defaultSiteKey: $defaultSiteKey, channelSiteKeys: $channelSiteKeys, channelDefinitions: $channelDefinitions);

        foreach ($collectedChanges as [$locale, $externalId]) {
            $notifier->collect(CatalogSourceName::Products, $locale, $externalId);
        }
        $notifier->flush();

        self::assertSame($expectedSiteRequests, $this->getCapturedSiteRequests());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, array<string, array{locales: list<string>, enabled?: bool}>, list<array{string, string}>, list<string>}>
     */
    public static function provideSiteRouting(): iterable
    {
        $shopChannels = [
            'CZ' => ['locales' => ['cs_CZ']],
            'SK' => ['locales' => ['sk_SK']],
            'DE' => ['locales' => ['de_DE']],
            'AT' => ['locales' => ['de_DE']],
        ];

        yield 'without channel keys one request per locale reaches the default site' => [
            'site-key',
            [],
            $shopChannels,
            [['cs_CZ', 'A'], ['sk_SK', 'A'], ['ru_RU', 'A']],
            [
                'products cs_CZ A -> site-key.ingest-secret',
                'products sk_SK A -> site-key.ingest-secret',
                'products ru_RU A -> site-key.ingest-secret',
            ],
        ];

        yield 'every channel locale reaches its own site only' => [
            '',
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'de-key', 'AT' => 'at-key'],
            $shopChannels,
            [['cs_CZ', 'A'], ['cs_CZ', 'B'], ['sk_SK', 'A']],
            [
                'products cs_CZ A,B -> cz-key.ingest-secret',
                'products sk_SK A -> sk-key.ingest-secret',
            ],
        ];

        yield 'a locale served by two sites reaches both' => [
            '',
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'de-key', 'AT' => 'at-key'],
            $shopChannels,
            [['de_DE', 'A']],
            [
                'products de_DE A -> de-key.ingest-secret',
                'products de_DE A -> at-key.ingest-secret',
            ],
        ];

        yield 'two channels on one site key share one request' => [
            '',
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'dach-key', 'AT' => 'dach-key'],
            $shopChannels,
            [['de_DE', 'A']],
            ['products de_DE A -> dach-key.ingest-secret'],
        ];

        yield 'an unmapped channel falls back to the default site' => [
            'site-key',
            ['CZ' => 'cz-key'],
            $shopChannels,
            [['cs_CZ', 'A'], ['sk_SK', 'A'], ['de_DE', 'A']],
            [
                'products cs_CZ A -> cz-key.ingest-secret',
                'products sk_SK A -> site-key.ingest-secret',
                'products de_DE A -> site-key.ingest-secret',
            ],
        ];

        yield 'a channel without a resolvable key is skipped' => [
            '',
            ['CZ' => 'cz-key'],
            $shopChannels,
            [['cs_CZ', 'A'], ['sk_SK', 'A']],
            ['products cs_CZ A -> cz-key.ingest-secret'],
        ];
    }

    public function testEverySiteGetsItsOwnFiveHundredIdBatches(): void
    {
        $notifier = $this->createNotifier(
            [],
            defaultSiteKey: '',
            channelSiteKeys: ['DE' => 'de-key', 'AT' => 'at-key'],
            channelDefinitions: ['DE' => ['locales' => ['de_DE']], 'AT' => ['locales' => ['de_DE']]],
        );

        for ($index = 0; $index < 501; ++$index) {
            $notifier->collect(CatalogSourceName::Products, 'de_DE', 'CODE-' . $index);
        }
        $notifier->flush();

        $batchSizesBySite = [];
        foreach ($this->capturedRequests as $request) {
            $body = json_decode($request['options']['body'], true);
            $batchSizesBySite[$this->readAuthorization($request['options']['headers'])][] = count($body['externalIds']);
        }

        self::assertSame(['de-key.ingest-secret' => [500, 1], 'at-key.ingest-secret' => [500, 1]], $batchSizesBySite);
    }

    /**
     * @param array<string, string> $channelSiteKeys
     * @param list<string>          $expectedLogMessages
     */
    #[DataProvider('provideChannelNotifications')]
    public function testNotifyUsesTheSiteKeyOfTheGivenChannel(
        string $defaultSiteKey,
        array $channelSiteKeys,
        ?string $channelCode,
        ?string $expectedAuthorization,
        array $expectedLogMessages,
    ): void {
        $notifier = $this->createNotifier([], defaultSiteKey: $defaultSiteKey, channelSiteKeys: $channelSiteKeys);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01'], $channelCode);

        $expectedRequestCount = $expectedAuthorization === null ? 0 : 1;
        self::assertSame($expectedAuthorization !== null, $outcome->accepted);
        self::assertCount($expectedRequestCount, $this->capturedRequests);
        foreach ($this->capturedRequests as $request) {
            self::assertSame($expectedAuthorization, $this->readAuthorization($request['options']['headers']));
        }
        self::assertSame($expectedLogMessages, array_column($this->logger->records, 'message'));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, ?string, ?string, list<string>}>
     */
    public static function provideChannelNotifications(): iterable
    {
        yield 'mapped channel' => ['site-key', ['CZ' => 'cz-key'], 'CZ', 'cz-key.ingest-secret', []];
        yield 'unmapped channel falls back' => ['site-key', ['CZ' => 'cz-key'], 'SK', 'site-key.ingest-secret', []];
        yield 'no channel uses the default' => ['site-key', ['CZ' => 'cz-key'], null, 'site-key.ingest-secret', []];
        yield 'unmapped channel without default is refused' => [
            '',
            ['CZ' => 'cz-key'],
            'SK',
            null,
            ['Chatbot: the channel has no site key, skipping the catalog notification.'],
        ];
        yield 'no channel without default is refused' => [
            '',
            ['CZ' => 'cz-key'],
            null,
            null,
            ['Chatbot: no default site key is configured, skipping the catalog notification.'],
        ];
    }

    /**
     * @param array<string, string> $channelSiteKeys
     * @param list<string>          $expectedMissingKeys
     */
    #[DataProvider('provideSiteKeyConfigurations')]
    public function testTheSiteKeyIsMissingOnlyWithoutDefaultAndChannelKeys(
        string $defaultSiteKey,
        array $channelSiteKeys,
        array $expectedMissingKeys,
    ): void {
        $notifier = $this->createNotifier([], defaultSiteKey: $defaultSiteKey, channelSiteKeys: $channelSiteKeys);

        self::assertSame($expectedMissingKeys, $notifier->getMissingConfigurationKeys());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, list<string>}>
     */
    public static function provideSiteKeyConfigurations(): iterable
    {
        yield 'default key' => ['site-key', [], []];
        yield 'channel keys only' => ['', ['CZ' => 'cz-key'], []];
        yield 'neither' => ['', [], ['widget.site_key or widget.channel_site_keys']];
        yield 'only empty channel keys' => ['', ['CZ' => ''], ['widget.site_key or widget.channel_site_keys']];
    }

    public function testFlushPostsOnePayloadPerSourceAndLocale(): void
    {
        $notifier = $this->createNotifier([]);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->collect(CatalogSourceName::Products, 'en_US', 'T-SHIRT-01');
        $notifier->collect(CatalogSourceName::Categories, 'cs_CZ', 'T_SHIRTS');
        $notifier->flush();

        self::assertCount(3, $this->capturedRequests);
        $firstRequest = $this->capturedRequests[0];
        self::assertSame('https://backend.example/api/v1/catalog/changes', $firstRequest['url']);
        self::assertSame(
            ['source' => 'products', 'locale' => 'cs_CZ', 'externalIds' => ['T-SHIRT-01']],
            json_decode($firstRequest['options']['body'], true),
        );
        self::assertContains('Authorization: Bearer site-key.ingest-secret', $firstRequest['options']['headers']);
    }

    public function testEveryRequestCarriesTheBoundedTimeouts(): void
    {
        $notifier = $this->createNotifier([]);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->collect(CatalogSourceName::Categories, 'en_US', 'T_SHIRTS');
        $notifier->flush();
        $notifier->notify(CatalogSourceName::Products, 'sk_SK', ['T-SHIRT-01']);

        self::assertCount(3, $this->capturedRequests);
        foreach ($this->capturedRequests as $request) {
            self::assertSame(2.0, $request['options']['timeout']);
            self::assertSame(5.0, $request['options']['max_duration']);
        }
    }

    public function testAnUnconfiguredNotifierNeverBuildsARequest(): void
    {
        $notifier = $this->createNotifier([], '');

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();
        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertFalse($outcome->accepted);
        self::assertSame([], $this->capturedRequests);
    }

    public function testMissingConfigurationKeysAreNamed(): void
    {
        $notifier = $this->createNotifier([], '');

        self::assertSame(['backend_url'], $notifier->getMissingConfigurationKeys());
    }

    public function testCollectedIdsAreDroppedPastTheCeiling(): void
    {
        $notifier = $this->createNotifier([]);

        $ceiling = $notifier->getMaxPendingExternalIds();
        for ($index = 0; $index < $ceiling + 10; ++$index) {
            $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'CODE-' . $index);
        }
        $notifier->flush();

        $announcedIdCount = 0;
        foreach ($this->capturedRequests as $request) {
            $body = json_decode($request['options']['body'], true);
            $announcedIdCount += count($body['externalIds']);
        }

        self::assertSame($ceiling, $announcedIdCount);
    }

    public function testFlushChunksIdsAtFiveHundredPerRequest(): void
    {
        $notifier = $this->createNotifier([]);

        for ($index = 0; $index < 501; ++$index) {
            $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'CODE-' . $index);
        }
        $notifier->flush();

        self::assertCount(2, $this->capturedRequests);
        $firstBody = json_decode($this->capturedRequests[0]['options']['body'], true);
        $secondBody = json_decode($this->capturedRequests[1]['options']['body'], true);
        self::assertCount(500, $firstBody['externalIds']);
        self::assertCount(1, $secondBody['externalIds']);
    }

    public function testFlushClearsCollectedChanges(): void
    {
        $notifier = $this->createNotifier([]);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();
        $notifier->flush();

        self::assertCount(1, $this->capturedRequests);
    }

    public function testThrottlingIsReportedWithRetryAfter(): void
    {
        $notifier = $this->createNotifier([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '300']]),
        ]);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertFalse($outcome->accepted);
        self::assertTrue($outcome->isThrottled());
        self::assertSame(300, $outcome->retryAfterSeconds);
    }

    public function testTransportFailureIsSwallowed(): void
    {
        $notifier = $this->createNotifier([new MockResponse('', ['error' => 'connection refused'])]);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertFalse($outcome->accepted);
        self::assertFalse($outcome->isThrottled());
    }

    public function testNonHttpsBackendIsRefusedOutsideDev(): void
    {
        $notifier = $this->createNotifier([], 'http://backend.example');

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertFalse($outcome->accepted);
        self::assertSame([], $this->capturedRequests);
    }

    public function testNonHttpsBackendIsAllowedInDev(): void
    {
        $notifier = $this->createNotifier([], 'http://backend.example', 'dev');

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertTrue($outcome->accepted);
        self::assertCount(1, $this->capturedRequests);
    }
}
