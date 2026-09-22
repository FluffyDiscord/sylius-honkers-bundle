<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\DTO\NotificationOutcome;
use FluffyDiscord\SyliusHonkersBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusHonkersBundle\Ingest\CatalogChangeNotifier;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class RecordingCatalogChangeNotifier extends CatalogChangeNotifier
{
    /** @var list<array{0: CatalogSourceName, 1: string, 2: string}> */
    public array $collectedChanges = [];

    /** @var list<array{0: CatalogSourceName, 1: string, 2: list<string>, 3: ?string}> */
    public array $notifications = [];

    public function __construct(
        SiteKeyResolver        $siteKeyResolver,
        private readonly bool $acceptsNotifications = true,
    ) {
        parent::__construct(new MockHttpClient(), new NullLogger(), $siteKeyResolver, 'https://backend.test', 'secret', 'test');
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
    ): NotificationOutcome {
        $this->notifications[] = [$source, $locale, $externalIds, $channelCode];

        return new NotificationOutcome($this->acceptsNotifications);
    }
}
