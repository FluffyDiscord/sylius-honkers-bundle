<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Ingest;

use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Ingest\CatalogIngestClient;
use FluffyDiscord\HonkersBundle\Reporting\BackendReportGuard;
use FluffyDiscord\SyliusHonkersPlugin\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersPlugin\Ingest\CatalogChangeNotifier;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelCredentialsProviderDouble;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\RecordingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
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
     * @param array<string, string>                                        $providedSiteKeys
     * @param array<string, string>                                        $channelSiteKeys
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     */
    private function createNotifier(
        array $responses,
        string $backendUrl = 'https://backend.example',
        string $environment = 'prod',
        array $providedSiteKeys = ['WEB' => 'site-key'],
        string $currentSiteKey = 'site-key',
        array $channelSiteKeys = [],
        array $channelDefinitions = ['WEB' => ['locales' => ['cs_CZ', 'en_US']]],
        ?ChannelRepositoryInterface $channelRepository = null,
        string $ingestSecret = 'ingest-secret',
    ): CatalogChangeNotifier {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->capturedRequests[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 202]);
        });

        $factory = new Psr17Factory();
        $ingestClient = new CatalogIngestClient(new Psr18Client($client), $factory, $factory, $backendUrl);
        $credentialsProvider = new ChannelCredentialsProviderDouble($providedSiteKeys, $currentSiteKey, $ingestSecret);
        $channelRepository ??= $this->createChannelRepository($channelDefinitions);

        return new CatalogChangeNotifier(
            $ingestClient,
            $this->logger,
            new SiteKeyResolver($channelRepository, $credentialsProvider, $channelSiteKeys),
            $credentialsProvider,
            new BackendReportGuard($this->logger, $backendUrl, $environment),
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

    private function createNotifiedChannel(?string $channelCode): ?ChannelInterface
    {
        if ($channelCode === null) {
            return null;
        }

        return (new ChannelFixtureFactory())->createChannel($channelCode, ['cs_CZ']);
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
        $notifier = $this->createNotifier([], channelRepository: $channelRepository);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();

        self::assertSame([], $this->capturedRequests);
        self::assertSame(
            ['Chatbot: resolving the sites to notify about catalog changes failed.'],
            array_column($this->logger->records, 'message'),
        );
    }

    /**
     * @param array<string, string> $providedSiteKeys
     * @param array<string, string> $channelSiteKeys
     * @param list<string>          $expectedWarnedChannels
     * @param list<string>          $expectedDebuggedChannels
     */
    #[DataProvider('provideSkippedChannelLogs')]
    public function testOnlyKeylessChannelsServingAChangedLocaleAreLogged(
        array $providedSiteKeys,
        array $channelSiteKeys,
        string $changedLocale,
        array $expectedWarnedChannels,
        array $expectedDebuggedChannels,
    ): void {
        $notifier = $this->createNotifier(
            [],
            providedSiteKeys: $providedSiteKeys,
            channelSiteKeys: $channelSiteKeys,
            channelDefinitions: ['CZ' => ['locales' => ['cs_CZ']], 'SK' => ['locales' => ['sk_SK']]],
        );

        $notifier->collect(CatalogSourceName::Products, $changedLocale, 'T-SHIRT-01');
        $notifier->flush();

        self::assertSame($expectedWarnedChannels, $this->getLoggedChannels(LogLevel::WARNING));
        self::assertSame($expectedDebuggedChannels, $this->getLoggedChannels(LogLevel::DEBUG));
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, string, list<string>, list<string>}>
     */
    public static function provideSkippedChannelLogs(): iterable
    {
        yield 'keyless channel serving the locale warns' => [['CZ' => 'cz-key'], [], 'sk_SK', ['SK'], []];
        yield 'keyless channel not serving the locale is silent' => [['CZ' => 'cz-key'], [], 'cs_CZ', [], []];
        yield 'channel with a site key is silent' => [['CZ' => 'cz-key', 'SK' => 'site-key'], [], 'sk_SK', [], []];
        yield 'channel mapped to an empty key logs at debug' => [['CZ' => 'cz-key'], ['CZ' => 'cz-key', 'SK' => ''], 'sk_SK', [], ['SK']];
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
     * @param array<string, string>                                        $providedSiteKeys
     * @param array<string, array{locales: list<string>, enabled?: bool}> $channelDefinitions
     * @param list<array{string, string}>                                  $collectedChanges
     * @param list<string>                                                 $expectedSiteRequests
     */
    #[DataProvider('provideSiteRouting')]
    public function testFlushSendsEveryChangeToEverySiteServingItsLocale(
        array $providedSiteKeys,
        array $channelDefinitions,
        array $collectedChanges,
        array $expectedSiteRequests,
    ): void {
        $notifier = $this->createNotifier([], providedSiteKeys: $providedSiteKeys, channelDefinitions: $channelDefinitions);

        foreach ($collectedChanges as [$locale, $externalId]) {
            $notifier->collect(CatalogSourceName::Products, $locale, $externalId);
        }
        $notifier->flush();

        self::assertSame($expectedSiteRequests, $this->getCapturedSiteRequests());
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{locales: list<string>, enabled?: bool}>, list<array{string, string}>, list<string>}>
     */
    public static function provideSiteRouting(): iterable
    {
        $shopChannels = [
            'CZ' => ['locales' => ['cs_CZ']],
            'SK' => ['locales' => ['sk_SK']],
            'DE' => ['locales' => ['de_DE']],
            'AT' => ['locales' => ['de_DE']],
        ];

        yield 'channels on one site get one request per locale they serve' => [
            ['CZ' => 'site-key', 'SK' => 'site-key', 'DE' => 'site-key', 'AT' => 'site-key'],
            $shopChannels,
            [['cs_CZ', 'A'], ['sk_SK', 'A'], ['ru_RU', 'A']],
            [
                'products cs_CZ A -> site-key.ingest-secret',
                'products sk_SK A -> site-key.ingest-secret',
            ],
        ];

        yield 'every channel locale reaches its own site only' => [
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'de-key', 'AT' => 'at-key'],
            $shopChannels,
            [['cs_CZ', 'A'], ['cs_CZ', 'B'], ['sk_SK', 'A']],
            [
                'products cs_CZ A,B -> cz-key.ingest-secret',
                'products sk_SK A -> sk-key.ingest-secret',
            ],
        ];

        yield 'a locale served by two sites reaches both' => [
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'de-key', 'AT' => 'at-key'],
            $shopChannels,
            [['de_DE', 'A']],
            [
                'products de_DE A -> de-key.ingest-secret',
                'products de_DE A -> at-key.ingest-secret',
            ],
        ];

        yield 'two channels on one site key share one request' => [
            ['CZ' => 'cz-key', 'SK' => 'sk-key', 'DE' => 'dach-key', 'AT' => 'dach-key'],
            $shopChannels,
            [['de_DE', 'A']],
            ['products de_DE A -> dach-key.ingest-secret'],
        ];

        yield 'a channel without a resolvable key is skipped' => [
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
            providedSiteKeys: ['DE' => 'de-key', 'AT' => 'at-key'],
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
     * @param list<string> $expectedLogMessages
     */
    #[DataProvider('provideChannelNotifications')]
    public function testNotifyUsesTheSiteKeyOfTheGivenChannel(
        string $currentSiteKey,
        ?string $channelCode,
        ?string $expectedAuthorization,
        array $expectedLogMessages,
    ): void {
        $notifier = $this->createNotifier([], providedSiteKeys: ['CZ' => 'cz-key'], currentSiteKey: $currentSiteKey);
        $channel = $this->createNotifiedChannel($channelCode);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01'], $channel);

        $expectedRequestCount = $expectedAuthorization === null ? 0 : 1;
        self::assertSame($expectedAuthorization !== null, $outcome->accepted);
        self::assertCount($expectedRequestCount, $this->capturedRequests);
        foreach ($this->capturedRequests as $request) {
            self::assertSame($expectedAuthorization, $this->readAuthorization($request['options']['headers']));
        }
        self::assertSame($expectedLogMessages, array_column($this->logger->records, 'message'));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, list<string>}>
     */
    public static function provideChannelNotifications(): iterable
    {
        yield 'channel with a site' => ['site-key', 'CZ', 'cz-key.ingest-secret', []];
        yield 'no channel uses the current site' => ['site-key', null, 'site-key.ingest-secret', []];
        yield 'channel without a site is refused' => [
            'site-key',
            'SK',
            null,
            ['Chatbot: the channel has no site key or ingest secret, skipping the catalog notification.'],
        ];
        yield 'no channel without a current site is refused' => [
            '',
            null,
            null,
            ['Chatbot: the current channel has no site key or ingest secret, skipping the catalog notification.'],
        ];
    }

    #[DataProvider('provideNotifiedChannelCodes')]
    public function testNotifySkipsASiteWithoutAnIngestSecret(?string $channelCode): void
    {
        $notifier = $this->createNotifier([], providedSiteKeys: ['CZ' => 'cz-key'], ingestSecret: '');
        $channel = $this->createNotifiedChannel($channelCode);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01'], $channel);

        self::assertFalse($outcome->accepted);
        self::assertSame([], $this->capturedRequests);
        self::assertCount(1, $this->logger->records);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideNotifiedChannelCodes(): iterable
    {
        yield 'given channel' => ['CZ'];
        yield 'current site' => [null];
    }

    public function testFlushSkipsAChannelWithoutAnIngestSecretAndLogsIt(): void
    {
        $notifier = $this->createNotifier([], ingestSecret: '');

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();

        self::assertSame([], $this->capturedRequests);
        self::assertSame(['WEB'], $this->getLoggedChannels('warning'));
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

    public function testAnUnavailableBackendIsReportedAsThrottledSoNotifyAllWaits(): void
    {
        $notifier = $this->createNotifier([
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After' => '60']]),
        ]);

        $outcome = $notifier->notify(CatalogSourceName::Products, 'cs_CZ', ['T-SHIRT-01']);

        self::assertFalse($outcome->accepted);
        self::assertSame(60, $outcome->retryAfterSeconds);
    }

    public function testFlushSendsAShortThrottledBatchAgain(): void
    {
        $notifier = $this->createNotifier([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            new MockResponse('', ['http_code' => 202]),
        ]);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();

        self::assertCount(2, $this->capturedRequests);
        self::assertSame($this->capturedRequests[0]['options']['body'], $this->capturedRequests[1]['options']['body']);
        self::assertSame([], $this->logger->records);
    }

    public function testFlushLogsABatchTheBackendCannotQueueForLong(): void
    {
        $notifier = $this->createNotifier([
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After' => '60']]),
        ]);

        $notifier->collect(CatalogSourceName::Products, 'cs_CZ', 'T-SHIRT-01');
        $notifier->flush();

        self::assertCount(1, $this->capturedRequests);
        self::assertSame(
            ['Chatbot: the backend could not queue the catalog changes now; the nightly sync picks them up.'],
            array_column($this->logger->records, 'message'),
        );
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
