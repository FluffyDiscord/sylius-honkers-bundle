<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Ingest;

use FluffyDiscord\Honkers\DTO\CatalogChange;
use FluffyDiscord\Honkers\DTO\CatalogChangeResult;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Exception\CatalogIngestException;
use FluffyDiscord\Honkers\Ingest\CatalogIngestClient;
use FluffyDiscord\HonkersBundle\Reporting\BackendReportGuard;
use FluffyDiscord\SyliusHonkersPlugin\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use FluffyDiscord\SyliusHonkersPlugin\DTO\SiteKeyRouting;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

class CatalogChangeNotifier implements ResetInterface
{
    /** @var array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> */
    private array $pendingChanges = [];

    private bool $hasLoggedOverflow = false;

    public function __construct(
        private readonly CatalogIngestClient                 $catalogIngestClient,
        private readonly LoggerInterface                     $logger,
        private readonly SiteKeyResolver                     $siteKeyResolver,
        private readonly ChannelCredentialsProviderInterface $credentialsProvider,
        private readonly BackendReportGuard                  $backendReportGuard,
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

    public function getMaxFlushRetryWaitSeconds(): int
    {
        return 2;
    }

    /**
     * @return list<string>
     */
    public function getMissingConfigurationKeys(): array
    {
        return $this->backendReportGuard->getMissingBackendConfigurationKeys();
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

        $canReport = $this->canReport();
        if (!$canReport) {
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
        ?ChannelInterface $channel = null,
    ): CatalogChangeResult {
        if ($externalIds === []) {
            return new CatalogChangeResult(true);
        }

        $canReport = $this->canReport();
        if (!$canReport) {
            return new CatalogChangeResult(false);
        }

        $siteCredentials = $this->findSiteCredentials($channel);
        if ($siteCredentials === null) {
            $this->logMissingSiteKey($channel);

            return new CatalogChangeResult(false);
        }

        $batches = array_chunk($externalIds, $this->catalogIngestClient->getMaxExternalIdsPerRequest());
        $accepted = true;
        $retryAfterSeconds = null;

        foreach ($batches as $batch) {
            $result = $this->sendToSite($source, $locale, $batch, $siteCredentials);
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
            foreach ($siteKeyRouting->getSiteCredentials($change['locale']) as $siteCredentials) {
                foreach ($batches as $batch) {
                    $this->flushBatch($change['source'], $change['locale'], $batch, $siteCredentials);
                }
            }
        }
    }

    /**
     * @param list<string> $externalIds
     */
    private function flushBatch(
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        SiteCredentials $siteCredentials,
    ): void {
        $result = $this->sendToSite($source, $locale, $externalIds, $siteCredentials);
        $retryAfterSeconds = $result->retryAfterSeconds;
        if ($retryAfterSeconds === null) {
            return;
        }

        $isShortWait = $retryAfterSeconds <= $this->getMaxFlushRetryWaitSeconds();
        if ($isShortWait) {
            sleep($retryAfterSeconds);
            $result = $this->sendToSite($source, $locale, $externalIds, $siteCredentials);
        }

        $isStillThrottled = $result->isThrottled();
        if ($isStillThrottled) {
            $this->logThrottled($source, $locale, $siteCredentials->siteKey, $result->retryAfterSeconds);
        }
    }

    /**
     * @param list<string> $externalIds
     */
    private function sendToSite(
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        SiteCredentials $siteCredentials,
    ): CatalogChangeResult {
        try {
            return $this->catalogIngestClient->send($siteCredentials, new CatalogChange($source, $locale, $externalIds));
        } catch (CatalogIngestException $exception) {
            $this->logFailure($source, $locale, $siteCredentials->siteKey, $exception);

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

    private function findSiteCredentials(?ChannelInterface $channel): ?SiteCredentials
    {
        $siteCredentials = $this->findChannelOrCurrentSiteCredentials($channel);
        if ($siteCredentials === null) {
            return null;
        }

        $hasIngestSecret = $siteCredentials->hasIngestSecret();
        if (!$hasIngestSecret) {
            return null;
        }

        return $siteCredentials;
    }

    private function findChannelOrCurrentSiteCredentials(?ChannelInterface $channel): ?SiteCredentials
    {
        if ($channel === null) {
            return $this->credentialsProvider->findCurrentSite();
        }

        return $this->credentialsProvider->findForChannel($channel);
    }

    private function logMissingSiteKey(?ChannelInterface $channel): void
    {
        if ($channel === null) {
            $this->logger->warning('Chatbot: the current channel has no site key or ingest secret, skipping the catalog notification.');

            return;
        }

        $this->logger->warning('Chatbot: the channel has no site key or ingest secret, skipping the catalog notification.', [
            'channelCode' => $channel->getCode(),
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
            $this->logger->warning('Chatbot: the channel has no site key or ingest secret, skipping its catalog notifications.', [
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

    private function logThrottled(CatalogSourceName $source, string $locale, string $siteKey, ?int $retryAfterSeconds): void
    {
        $this->logger->warning('Chatbot: the backend could not queue the catalog changes now; the nightly sync picks them up.', [
            'target' => $source->value . ' / ' . $locale . ' / ' . $siteKey,
            'retryAfterSeconds' => $retryAfterSeconds,
        ]);
    }

    private function canReport(): bool
    {
        return $this->backendReportGuard->canReport('catalog change');
    }
}
