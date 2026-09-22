<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use FluffyDiscord\Honkers\DTO\CatalogChangeResult;
use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Ingest\CatalogIngestClient;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\Ingest\CatalogChangeNotifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

class RecordingCatalogChangeNotifier extends CatalogChangeNotifier
{
    /** @var list<array{0: CatalogSourceName, 1: string, 2: string}> */
    public array $collectedChanges = [];

    /** @var list<array{0: CatalogSourceName, 1: string, 2: list<string>, 3: ?string}> */
    public array $notifications = [];

    public function __construct(
        SiteKeyResolver       $siteKeyResolver,
        private readonly bool $acceptsNotifications = true,
    ) {
        $psr18Client = new Psr18Client(new MockHttpClient());
        $factory = new Psr17Factory();
        $ingestClient = new CatalogIngestClient($psr18Client, $factory, $factory, 'https://backend.test', 'secret');

        parent::__construct($ingestClient, new NullLogger(), $siteKeyResolver, 'https://backend.test', 'secret', 'test');
    }

    public function collect(CatalogSourceName $source, string $locale, string $externalId): void
    {
        $this->collectedChanges[] = [$source, $locale, $externalId];
    }

    public function notify(
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        ?string $channelCode = null,
    ): CatalogChangeResult {
        $this->notifications[] = [$source, $locale, $externalIds, $channelCode];

        return new CatalogChangeResult($this->acceptsNotifications);
    }
}
