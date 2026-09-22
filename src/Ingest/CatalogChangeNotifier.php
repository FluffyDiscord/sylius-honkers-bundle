<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Ingest;

use FluffyDiscord\Honkers\DTO\CatalogChange;
use FluffyDiscord\Honkers\DTO\CatalogChangeResult;
use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Exception\CatalogIngestException;
use FluffyDiscord\Honkers\Ingest\CatalogIngestClient;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\DTO\SiteKeyRouting;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

class CatalogChangeNotifier implements ResetInterface
{
    /** @var array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> */
    private array $pendingChanges = [];

    private bool $hasLoggedOverflow = false;

    public function __construct(
        private readonly CatalogIngestClient $catalogIngestClient,
        private readonly LoggerInterface     $logger,
        private readonly SiteKeyResolver     $siteKeyResolver,

        #[Autowire(param: 'fluffydiscord_honkers.backend_url')]
        private readonly string $backendUrl,

        #[Autowire(param: 'fluffydiscord_honkers.ingest_secret')]
        private readonly string $ingestSecret,

        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public function getMaxExternalIdsPerRequest(): int
    {
        return $this->catalogIngestClient->getMaxExternalIdsPerRequest();
    }

    public function getMaxPendingExternalIds(): int
    {
        return 20000;
    }

    /**
     * @return list<string>
     */
    public function getMissingConfigurationKeys(): array
    {
        $missingKeys = [];
        if ($this->backendUrl === '') {
            $missingKeys[] = 'backend_url';
        }
        if ($this->ingestSecret === '') {
            $missingKeys[] = 'ingest_secret';
        }
        $hasAnySiteKey = $this->siteKeyResolver->hasAnySiteKey();
        if (!$hasAnySiteKey) {
            $missingKeys[] = 'widget.site_key or channel_site_keys';
        }

        return $missingKeys;
    }

    public function collect(CatalogSourceName $source, string $locale, string $externalId): void
    {
        if ($locale === '' || $externalId === '') {
            return;
        }

        $pendingCount = $this->countPendingExternalIds();
        $isAtCapacity = $pendingCount >= $this->getMaxPendingExternalIds();
        if ($isAtCapacity) {
            $this->logOverflowOnce();

            return;
        }

        $key = $source->value . '|' . $locale;
        if (!isset($this->pendingChanges[$key])) {
            $this->pendingChanges[$key] = ['source' => $source, 'locale' => $locale, 'externalIds' => []];
        }

        $this->pendingChanges[$key]['externalIds'][$externalId] = true;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function flush(): void
    {
        $pendingChanges = $this->pendingChanges;
        $this->pendingChanges = [];
        $this->hasLoggedOverflow = false;

        if ($pendingChanges === []) {
            return;
        }

        $canNotify = $this->canNotify();
        if (!$canNotify) {
            return;
        }

        try {
            $siteKeyRouting = $this->getSiteKeyRouting($pendingChanges);
        } catch (\Throwable $exception) {
            $this->logger->warning('Chatbot: resolving the sites to notify about catalog changes failed.', ['exception' => $exception]);

            return;
        }

        $this->logSkippedChannels($siteKeyRouting);
        $this->dispatchChanges($pendingChanges, $siteKeyRouting);
    }

    /**
     * @param list<string> $externalIds
     */
    public function notify(
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        ?string $channelCode = null,
    ): CatalogChangeResult {
        if ($externalIds === []) {
            return new CatalogChangeResult(true);
        }

        $canNotify = $this->canNotify();
        if (!$canNotify) {
            return new CatalogChangeResult(false);
        }

        $siteKey = $this->resolveSiteKey($channelCode);
        if ($siteKey === '') {
            $this->logMissingSiteKey($channelCode);

            return new CatalogChangeResult(false);
        }

        $batches = array_chunk($externalIds, $this->catalogIngestClient->getMaxExternalIdsPerRequest());
        $accepted = true;
        $retryAfterSeconds = null;

        foreach ($batches as $batch) {
            $result = $this->sendToSite($source, $locale, $batch, $siteKey);
            if (!$result->accepted) {
                $accepted = false;
            }
            if ($result->retryAfterSeconds !== null) {
                $retryAfterSeconds = $result->retryAfterSeconds;
            }
        }

        return new CatalogChangeResult($accepted, [], $retryAfterSeconds);
    }

    public function reset(): void
    {
        $this->pendingChanges = [];
        $this->hasLoggedOverflow = false;
    }

    /**
     * @param array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> $pendingChanges
     */
    private function dispatchChanges(array $pendingChanges, SiteKeyRouting $siteKeyRouting): void
    {
        $maxExternalIds = $this->catalogIngestClient->getMaxExternalIdsPerRequest();

        foreach ($pendingChanges as $change) {
            $externalIds = array_keys($change['externalIds']);
            $batches = array_chunk($externalIds, $maxExternalIds);
            foreach ($siteKeyRouting->getSiteKeys($change['locale']) as $siteKey) {
                foreach ($batches as $batch) {
                    $this->sendToSite($change['source'], $change['locale'], $batch, $siteKey);
                }
            }
        }
    }

    /**
     * @param list<string> $externalIds
     */
    private function sendToSite(CatalogSourceName $source, string $locale, array $externalIds, string $siteKey): CatalogChangeResult
    {
        try {
            return $this->catalogIngestClient->send($siteKey, new CatalogChange($source, $locale, $externalIds));
        } catch (CatalogIngestException $exception) {
            $this->logFailure($source, $locale, $siteKey, $exception);

            return new CatalogChangeResult(false);
        }
    }

    private function countPendingExternalIds(): int
    {
        $pendingCount = 0;
        foreach ($this->pendingChanges as $change) {
            $pendingCount += count($change['externalIds']);
        }

        return $pendingCount;
    }

    private function logOverflowOnce(): void
    {
        if ($this->hasLoggedOverflow) {
            return;
        }

        $this->hasLoggedOverflow = true;
        $this->logger->warning('Chatbot: too many pending catalog changes, dropping the rest until the next flush.', [
            'maxPendingExternalIds' => $this->getMaxPendingExternalIds(),
        ]);
    }

    private function resolveSiteKey(?string $channelCode): string
    {
        if ($channelCode === null) {
            return $this->siteKeyResolver->getDefaultSiteKey();
        }

        return $this->siteKeyResolver->getSiteKey($channelCode);
    }

    private function logMissingSiteKey(?string $channelCode): void
    {
        if ($channelCode === null) {
            $this->logger->warning('Chatbot: no default site key is configured, skipping the catalog notification.');

            return;
        }

        $this->logger->warning('Chatbot: the channel has no site key, skipping the catalog notification.', [
            'channelCode' => $channelCode,
        ]);
    }

    /**
     * @param array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> $pendingChanges
     */
    private function getSiteKeyRouting(array $pendingChanges): SiteKeyRouting
    {
        $locales = [];
        foreach ($pendingChanges as $change) {
            $locales[$change['locale']] = $change['locale'];
        }

        return $this->siteKeyResolver->getSiteKeyRouting(array_values($locales));
    }

    private function logSkippedChannels(SiteKeyRouting $siteKeyRouting): void
    {
        foreach ($siteKeyRouting->channelCodesWithoutSiteKey as $channelCode) {
            $this->logger->warning('Chatbot: the channel has no site key, skipping its catalog notifications.', [
                'channelCode' => $channelCode,
            ]);
        }

        foreach ($siteKeyRouting->channelCodesWithEmptySiteKey as $channelCode) {
            $this->logger->debug('Chatbot: the channel site key is configured empty, skipping its catalog notifications.', [
                'channelCode' => $channelCode,
            ]);
        }
    }

    private function logFailure(CatalogSourceName $source, string $locale, string $siteKey, \Throwable $exception): void
    {
        $this->logger->warning('Chatbot: notifying the backend about catalog changes failed.', [
            'target' => $source->value . ' / ' . $locale . ' / ' . $siteKey,
            'exception' => $exception,
        ]);
    }

    private function canNotify(): bool
    {
        $missingKeys = $this->getMissingConfigurationKeys();
        if ($missingKeys !== []) {
            $this->logger->warning('Chatbot: the catalog change notifier is not configured, skipping the notification.', [
                'missingConfigurationKeys' => $missingKeys,
            ]);

            return false;
        }

        $isAllowedBackendUrl = $this->isAllowedBackendUrl();
        if (!$isAllowedBackendUrl) {
            $this->logger->warning('Chatbot: refusing to notify a non-https backend URL.', [
                'backendUrl' => $this->backendUrl,
                'environment' => $this->environment,
            ]);

            return false;
        }

        return true;
    }

    private function isAllowedBackendUrl(): bool
    {
        $scheme = parse_url($this->backendUrl, PHP_URL_SCHEME);
        $isSecure = $scheme === 'https';
        if ($isSecure) {
            return true;
        }

        return $this->environment === 'dev';
    }
}
