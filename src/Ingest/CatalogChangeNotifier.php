<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Ingest;

use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\DTO\NotificationOutcome;
use FluffyDiscord\SyliusHonkersBundle\DTO\SiteKeyRouting;
use FluffyDiscord\SyliusHonkersBundle\Enum\CatalogSourceName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

class CatalogChangeNotifier implements ResetInterface
{
    /** @var array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> */
    private array $pendingChanges = [];

    private bool $hasLoggedOverflow = false;

    public function __construct(
        private readonly HttpClientInterface $backendClient,
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
        return 500;
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
            $missingKeys[] = 'widget.site_key or widget.channel_site_keys';
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

        $responses = $this->issueRequests($pendingChanges, $siteKeyRouting);
        $this->drainWithinBudget($responses);
    }

    /**
     * @param list<string> $externalIds
     */
    public function notify(
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        ?string $channelCode = null,
    ): NotificationOutcome {
        if ($externalIds === []) {
            return new NotificationOutcome(true);
        }

        $canNotify = $this->canNotify();
        if (!$canNotify) {
            return new NotificationOutcome(false);
        }

        $siteKey = $this->resolveSiteKey($channelCode);
        if ($siteKey === '') {
            $this->logMissingSiteKey($channelCode);

            return new NotificationOutcome(false);
        }

        try {
            $response = $this->requestChanges($source, $locale, $externalIds, $siteKey);

            return $this->readOutcome($response);
        } catch (\Throwable $exception) {
            $this->logFailure($source, $locale, $siteKey, $exception);

            return new NotificationOutcome(false);
        }
    }

    public function reset(): void
    {
        $this->pendingChanges = [];
        $this->hasLoggedOverflow = false;
    }

    private function getTimeoutSeconds(): float
    {
        return 2.0;
    }

    private function getMaxDurationSeconds(): float
    {
        return 5.0;
    }

    private function getFlushBudgetSeconds(): float
    {
        return 5.0;
    }

    private function getDefaultRetryAfterSeconds(): int
    {
        return 60;
    }

    private function getChangesPath(): string
    {
        return '/api/v1/catalog/changes';
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

    /**
     * @param array<string, array{source: CatalogSourceName, locale: string, externalIds: array<string, true>}> $pendingChanges
     *
     * @return list<ResponseInterface>
     */
    private function issueRequests(array $pendingChanges, SiteKeyRouting $siteKeyRouting): array
    {
        $responses = [];
        foreach ($pendingChanges as $change) {
            $externalIds = array_keys($change['externalIds']);
            $batches = array_chunk($externalIds, $this->getMaxExternalIdsPerRequest());
            foreach ($siteKeyRouting->getSiteKeys($change['locale']) as $siteKey) {
                foreach ($batches as $batch) {
                    $response = $this->issueRequest($change['source'], $change['locale'], $batch, $siteKey);
                    if ($response === null) {
                        continue;
                    }

                    $responses[] = $response;
                }
            }
        }

        return $responses;
    }

    /**
     * @param list<string> $externalIds
     */
    private function issueRequest(CatalogSourceName $source, string $locale, array $externalIds, string $siteKey): ?ResponseInterface
    {
        try {
            return $this->requestChanges($source, $locale, $externalIds, $siteKey);
        } catch (\Throwable $exception) {
            $this->logFailure($source, $locale, $siteKey, $exception);

            return null;
        }
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function drainWithinBudget(array $responses): void
    {
        if ($responses === []) {
            return;
        }

        $deadline = microtime(true) + $this->getFlushBudgetSeconds();

        $unfinishedResponses = [];
        foreach ($responses as $response) {
            $unfinishedResponses[spl_object_id($response)] = $response;
        }

        try {
            foreach ($this->backendClient->stream($responses, $this->getTimeoutSeconds()) as $response => $chunk) {
                $this->consumeChunk($response, $chunk, $unfinishedResponses);

                $isBudgetSpent = microtime(true) >= $deadline;
                if ($isBudgetSpent) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Chatbot: draining catalog notifications failed.', ['exception' => $exception]);
        }

        $this->abandonRemaining($unfinishedResponses);
    }

    /**
     * @param array<int, ResponseInterface> $unfinishedResponses
     */
    private function consumeChunk(ResponseInterface $response, ChunkInterface $chunk, array &$unfinishedResponses): void
    {
        try {
            $isTimeout = $chunk->isTimeout();
            if ($isTimeout) {
                return;
            }

            $isLast = $chunk->isLast();
            if (!$isLast) {
                return;
            }

            unset($unfinishedResponses[spl_object_id($response)]);
            $this->readOutcome($response);
        } catch (\Throwable $exception) {
            unset($unfinishedResponses[spl_object_id($response)]);
            $this->logger->warning('Chatbot: notifying the backend about catalog changes failed.', [
                'target' => $this->readRequestTarget($response),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @param array<int, ResponseInterface> $unfinishedResponses
     */
    private function abandonRemaining(array $unfinishedResponses): void
    {
        foreach ($unfinishedResponses as $response) {
            $target = $this->readRequestTarget($response);
            $response->cancel();

            $this->logger->warning('Chatbot: the catalog notification budget was spent, abandoning a request.', [
                'target' => $target,
                'flushBudgetSeconds' => $this->getFlushBudgetSeconds(),
            ]);
        }
    }

    private function readRequestTarget(ResponseInterface $response): string
    {
        $userData = $response->getInfo('user_data');
        if (is_string($userData)) {
            return $userData;
        }

        return 'unknown';
    }

    /**
     * @param list<string> $externalIds
     */
    private function requestChanges(CatalogSourceName $source, string $locale, array $externalIds, string $siteKey): ResponseInterface
    {
        return $this->backendClient->request(Request::METHOD_POST, $this->buildChangesUrl(), [
            'timeout' => $this->getTimeoutSeconds(),
            'max_duration' => $this->getMaxDurationSeconds(),
            'user_data' => $this->buildRequestTarget($source, $locale, $siteKey),
            'headers' => ['Authorization' => 'Bearer ' . $siteKey . '.' . $this->ingestSecret],
            'json' => [
                'source' => $source->value,
                'locale' => $locale,
                'externalIds' => array_values($externalIds),
            ],
        ]);
    }

    private function readOutcome(ResponseInterface $response): NotificationOutcome
    {
        $target = $this->readRequestTarget($response);
        $statusCode = $response->getStatusCode();

        $isAccepted = $statusCode >= 200 && $statusCode < 300;
        if ($isAccepted) {
            return new NotificationOutcome(true);
        }

        if ($statusCode === Response::HTTP_TOO_MANY_REQUESTS) {
            $retryAfterSeconds = $this->readRetryAfterSeconds($response->getHeaders(false));
            $this->logger->warning('Chatbot: the backend is throttling catalog notifications.', [
                'target' => $target,
                'retryAfterSeconds' => $retryAfterSeconds,
            ]);

            return new NotificationOutcome(false, $retryAfterSeconds);
        }

        $this->logger->warning('Chatbot: the backend rejected a catalog notification.', [
            'target' => $target,
            'statusCode' => $statusCode,
        ]);

        return new NotificationOutcome(false);
    }

    private function logFailure(CatalogSourceName $source, string $locale, string $siteKey, \Throwable $exception): void
    {
        $this->logger->warning('Chatbot: notifying the backend about catalog changes failed.', [
            'target' => $this->buildRequestTarget($source, $locale, $siteKey),
            'exception' => $exception,
        ]);
    }

    private function buildRequestTarget(CatalogSourceName $source, string $locale, string $siteKey): string
    {
        return $source->value . ' / ' . $locale . ' / ' . $siteKey;
    }

    private function buildChangesUrl(): string
    {
        return rtrim($this->backendUrl, '/') . $this->getChangesPath();
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

    /**
     * @param array<string, list<string>> $headers
     */
    private function readRetryAfterSeconds(array $headers): int
    {
        $retryAfter = $headers['retry-after'][0] ?? '';
        $isSeconds = ctype_digit($retryAfter);
        if (!$isSeconds) {
            return $this->getDefaultRetryAfterSeconds();
        }

        return (int) $retryAfter;
    }
}
