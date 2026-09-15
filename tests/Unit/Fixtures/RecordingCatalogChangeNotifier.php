<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\DTO\NotificationOutcome;
use FluffyDiscord\SyliusChatbotBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusChatbotBundle\Ingest\CatalogChangeNotifier;

class RecordingCatalogChangeNotifier extends CatalogChangeNotifier
{
    /** @var list<array{0: CatalogSourceName, 1: string, 2: string}> */
    public array $collectedChanges = [];

    /** @var list<array{0: CatalogSourceName, 1: string, 2: list<string>, 3: ?string}> */
    public array $notifications = [];

    public function __construct(
        private readonly bool $acceptsNotifications = true,
    ) {
    }

    public function getMissingConfigurationKeys(): array
    {
        return [];
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
