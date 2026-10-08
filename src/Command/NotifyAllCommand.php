<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Command;

use FluffyDiscord\SyliusHonkersPlugin\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use FluffyDiscord\Honkers\Contract\ChatbotDataSourceInterface;
use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Exception\ChatbotApiException;
use FluffyDiscord\SyliusHonkersPlugin\Exception\InvalidChannelException;
use FluffyDiscord\Honkers\Exception\InvalidLocaleException;
use FluffyDiscord\SyliusHonkersPlugin\Ingest\CatalogChangeNotifier;
use FluffyDiscord\Honkers\Locale\LocaleMatcher;
use FluffyDiscord\Honkers\Registry\DataSourceRegistry;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'fluffydiscord:chatbot:notify-all',
    description: 'Re-notifies the chatbot backend about every catalog document of every served locale.',
)]
class NotifyAllCommand extends Command
{
    public function __construct(
        private readonly DataSourceRegistry                  $dataSourceRegistry,
        private readonly CatalogChangeNotifier               $catalogChangeNotifier,
        private readonly ChannelResolver                     $channelResolver,
        private readonly ChatbotLocaleContextInterface       $localeContext,
        private readonly LocaleMatcher                       $localeMatcher,
        private readonly ChannelCredentialsProviderInterface $credentialsProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this catalog source (products, categories, cms_pages).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Only this locale code.')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel code to read the catalog for; defaults to the context channel.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->__invoke(
            new SymfonyStyle($input, $output),
            $input->getOption('source'),
            $input->getOption('locale'),
            $input->getOption('channel'),
        );
    }

    public function __invoke(
        SymfonyStyle $io,
        ?string $source = null,
        ?string $locale = null,
        ?string $channel = null,
    ): int {
        $sources = $this->resolveSources($source);
        if ($sources === []) {
            $io->error(sprintf('Unknown catalog source "%s".', (string) $source));

            return Command::INVALID;
        }

        $missingConfigurationKeys = $this->catalogChangeNotifier->getMissingConfigurationKeys();
        if ($missingConfigurationKeys !== []) {
            $io->error(sprintf(
                'The chatbot backend is not configured, nothing can be announced. Set: %s.',
                implode(', ', $missingConfigurationKeys),
            ));

            return Command::FAILURE;
        }

        $this->channelResolver->setOverrideCode($channel);

        try {
            $notifiedChannel = $this->channelResolver->getChannel();
            $channelRefusal = $this->findChannelRefusal($notifiedChannel);
            if ($channelRefusal !== null) {
                $io->error($channelRefusal);

                return Command::FAILURE;
            }

            $requestedLocale = $this->resolveRequestedLocale($locale);
            $failedBatchCount = $this->notifySources($io, $sources, $requestedLocale, $notifiedChannel);
        } catch (InvalidChannelException $exception) {
            $io->error(sprintf(
                'No channel could be resolved (%s). Pass --channel=<code> when running outside a web request.',
                $exception->getMessage(),
            ));

            return Command::FAILURE;
        } catch (InvalidLocaleException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($failedBatchCount > 0) {
            $io->warning(sprintf('%d batches were not accepted by the backend.', $failedBatchCount));

            return Command::FAILURE;
        }

        $io->success('The whole catalog was announced to the chatbot backend.');

        return Command::SUCCESS;
    }

    private function getBatchPauseSeconds(): int
    {
        return 2;
    }

    private function findChannelRefusal(ChannelInterface $channel): ?string
    {
        $channelCode = (string) $channel->getCode();

        $isEnabled = $channel->isEnabled();
        if (!$isEnabled) {
            return sprintf('The channel "%s" is disabled, its catalog is not announced.', $channelCode);
        }

        $siteCredentials = $this->credentialsProvider->findForChannel($channel);
        if ($siteCredentials === null) {
            return sprintf(
                'The channel "%s" has no site key, nothing can be announced. Pair it from the chatbot dashboard or set: channel_site_keys.%s or CHATBOT_SITE_KEY.',
                $channelCode,
                $channelCode,
            );
        }

        $hasIngestSecret = $siteCredentials->hasIngestSecret();
        if (!$hasIngestSecret) {
            return sprintf(
                'The channel "%s" has no ingest secret, nothing can be announced. Pair it from the chatbot dashboard or set: CHATBOT_INGEST_SECRET.',
                $channelCode,
            );
        }

        return null;
    }

    private function getMaxThrottleRetries(): int
    {
        return 5;
    }

    /**
     * @param list<CatalogSourceName> $sources
     */
    private function notifySources(SymfonyStyle $io, array $sources, ?string $locale, ChannelInterface $channel): int
    {
        $failedBatchCount = 0;
        foreach ($sources as $source) {
            $dataSource = $this->dataSourceRegistry->get($source->value);
            if ($dataSource === null) {
                $io->warning(sprintf('The "%s" data source is not registered, skipping it.', $source->value));

                continue;
            }

            foreach ($this->resolveLocales($dataSource, $locale) as $localeCode) {
                try {
                    $failedBatchCount += $this->notifyLocale($io, $source, $dataSource, $localeCode, $channel);
                } catch (ChatbotApiException $exception) {
                    $io->error(sprintf('%s: %s', $source->value, $exception->getMessage()));
                    ++$failedBatchCount;

                    break;
                }
            }
        }

        return $failedBatchCount;
    }

    private function notifyLocale(
        SymfonyStyle $io,
        CatalogSourceName $source,
        ChatbotDataSourceInterface $dataSource,
        string $locale,
        ChannelInterface $channel,
    ): int {
        $batchSize = $this->catalogChangeNotifier->getMaxExternalIdsPerRequest();
        $externalIds = [];
        $failedBatchCount = 0;
        $notifiedCount = 0;
        $cursor = null;

        do {
            $page = $dataSource->getDocuments(new SourceQuery(locale: $locale, cursor: $cursor));
            foreach ($page->documents as $document) {
                $externalIds[] = $document->id;
                $isBatchFull = count($externalIds) === $batchSize;
                if (!$isBatchFull) {
                    continue;
                }

                $failedBatchCount += $this->sendBatch($io, $source, $locale, $externalIds, $channel);
                $notifiedCount += count($externalIds);
                $externalIds = [];
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        if ($externalIds !== []) {
            $failedBatchCount += $this->sendBatch($io, $source, $locale, $externalIds, $channel);
            $notifiedCount += count($externalIds);
        }

        $io->text(sprintf('%s / %s: %d ids announced.', $source->value, $locale, $notifiedCount));

        return $failedBatchCount;
    }

    /**
     * @param list<string> $externalIds
     */
    private function sendBatch(
        SymfonyStyle $io,
        CatalogSourceName $source,
        string $locale,
        array $externalIds,
        ChannelInterface $channel,
    ): int {
        $remainingRetries = $this->getMaxThrottleRetries();

        while (true) {
            $outcome = $this->catalogChangeNotifier->notify($source, $locale, $externalIds, $channel);
            if ($outcome->accepted) {
                sleep($this->getBatchPauseSeconds());

                return 0;
            }

            $isThrottled = $outcome->isThrottled();
            if (!$isThrottled || $remainingRetries === 0) {
                $io->warning(sprintf('%s / %s: a batch of %d ids was not accepted.', $source->value, $locale, count($externalIds)));

                return 1;
            }

            $io->text(sprintf('%s / %s: throttled, waiting %d s.', $source->value, $locale, $outcome->retryAfterSeconds));
            sleep($outcome->retryAfterSeconds);
            --$remainingRetries;
        }
    }

    /**
     * @return list<CatalogSourceName>
     */
    private function resolveSources(?string $source): array
    {
        if ($source === null || $source === '') {
            return CatalogSourceName::cases();
        }

        $requestedSource = CatalogSourceName::tryFrom($source);
        if ($requestedSource === null) {
            return [];
        }

        return [$requestedSource];
    }

    /**
     * @return list<string>
     */
    private function resolveLocales(ChatbotDataSourceInterface $dataSource, ?string $locale): array
    {
        if ($locale !== null) {
            return [$locale];
        }

        $definitionLocales = $dataSource->getDefinition()->locales;

        return array_values($definitionLocales ?? $this->localeContext->getChannelLocales());
    }

    /**
     * @throws InvalidLocaleException
     */
    private function resolveRequestedLocale(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }

        $channelLocales = $this->localeContext->getChannelLocales();

        return $this->localeMatcher->resolveServedLocaleOrFail($locale, $channelLocales);
    }
}
