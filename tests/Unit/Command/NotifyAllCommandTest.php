<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Command;

use FluffyDiscord\SyliusChatbotBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusChatbotBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusChatbotBundle\Command\NotifyAllCommand;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceDocument;
use FluffyDiscord\SyliusChatbotBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusChatbotBundle\Enum\DocumentKind;
use FluffyDiscord\SyliusChatbotBundle\Locale\ShopLocaleResolver;
use FluffyDiscord\SyliusChatbotBundle\Registry\DataSourceRegistry;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RecordingCatalogChangeNotifier;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RecordingDataSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

class NotifyAllCommandTest extends TestCase
{
    #[DataProvider('provideRequestedLocales')]
    public function testTheRequestedLocaleIsAnnouncedInTheShopSpelling(?string $requested, array $expected): void
    {
        $source = new RecordingDataSource('products');
        $command = $this->createCommand($source, ['cs_CZ', 'en_US']);

        $exitCode = $command->__invoke($this->createStyle(), 'products', $requested);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($expected, $source->queriedLocales);
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function provideRequestedLocales(): iterable
    {
        yield 'every channel locale' => [null, ['cs_CZ', 'en_US']];
        yield 'empty option' => ['', ['cs_CZ', 'en_US']];
        yield 'language only' => ['cs', ['cs_CZ']];
        yield 'hyphen separated' => ['cs-CZ', ['cs_CZ']];
        yield 'lower cased region' => ['cs_cz', ['cs_CZ']];
    }

    public function testAnUnservedLocaleAnnouncesNothing(): void
    {
        $source = new RecordingDataSource('products');
        $command = $this->createCommand($source, ['cs_CZ']);
        $output = new BufferedOutput();

        $exitCode = $command->__invoke($this->createStyle($output), 'products', 'de_AT');

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $source->queriedLocales);
        self::assertStringContainsString('Locale "de_AT" is not served.', $output->fetch());
    }

    public function testASourceWithoutOwnLocalesFallsBackToTheChannel(): void
    {
        $source = new RecordingDataSource('products', []);
        $command = $this->createCommand($source, ['cs_CZ', 'en_US']);

        $exitCode = $command->__invoke($this->createStyle(), 'products');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['cs_CZ', 'en_US'], $source->queriedLocales);
    }

    public function testTheSourceLocalesWinOverTheChannelOnes(): void
    {
        $source = new RecordingDataSource('products', ['en_US']);
        $command = $this->createCommand($source, ['cs_CZ', 'en_US']);

        $exitCode = $command->__invoke($this->createStyle(), 'products');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['en_US'], $source->queriedLocales);
    }

    public function testTheConsoleOptionsReachTheAnnouncing(): void
    {
        $source = new RecordingDataSource('products');
        $command = $this->createCommand($source, ['cs_CZ', 'en_US']);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--source' => 'products', '--locale' => 'cs']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['cs_CZ'], $source->queriedLocales);
    }

    public function testTheDocumentedOptionsAreDeclared(): void
    {
        $command = $this->createCommand(new RecordingDataSource('products'), ['cs_CZ']);

        $options = $command->getDefinition()->getOptions();

        self::assertSame(['source', 'locale', 'channel'], array_keys($options));
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    #[DataProvider('provideAnnouncedChannelSiteKeys')]
    public function testTheResolvedChannelIsAnnouncedWithItsOwnCode(string $defaultSiteKey, array $channelSiteKeys): void
    {
        $source = new RecordingDataSource('products', null, [$this->createDocument('T-SHIRT-01')]);
        $notifier = new RecordingCatalogChangeNotifier(acceptsNotifications: false);
        $command = $this->createCommand($source, ['sk_SK'], 'SK', $notifier, $defaultSiteKey, $channelSiteKeys);

        $command->__invoke($this->createStyle(), 'products', null, 'SK');

        self::assertSame(
            [[CatalogSourceName::Products, 'sk_SK', ['T-SHIRT-01'], 'SK']],
            $notifier->notifications,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideAnnouncedChannelSiteKeys(): iterable
    {
        yield 'mapped channel' => ['', ['SK' => 'sk-key']];
        yield 'unmapped channel with a default key' => ['site-key', ['CZ' => 'cz-key']];
        yield 'no channel keys' => ['site-key', []];
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    #[DataProvider('provideRefusedChannelSiteKeys')]
    public function testAChannelWithoutASiteKeyAnnouncesNothing(string $defaultSiteKey, array $channelSiteKeys): void
    {
        $source = new RecordingDataSource('products', null, [$this->createDocument('T-SHIRT-01')]);
        $notifier = new RecordingCatalogChangeNotifier();
        $command = $this->createCommand($source, ['sk_SK'], 'SK', $notifier, $defaultSiteKey, $channelSiteKeys);
        $output = new BufferedOutput();

        $exitCode = $command->__invoke($this->createStyle($output), 'products', null, 'SK');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $source->queriedLocales);
        self::assertSame([], $notifier->notifications);
        self::assertStringContainsString('The channel "SK" has no site key', $output->fetch());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideRefusedChannelSiteKeys(): iterable
    {
        yield 'unmapped channel without a default key' => ['', ['CZ' => 'cz-key']];
        yield 'channel mapped to an empty key' => ['site-key', ['SK' => '']];
    }

    /**
     * @param list<string>          $channelLocales
     * @param array<string, string> $channelSiteKeys
     */
    private function createCommand(
        RecordingDataSource $source,
        array $channelLocales,
        string $channelCode = 'CZ',
        ?RecordingCatalogChangeNotifier $notifier = null,
        string $defaultSiteKey = 'site-key',
        array $channelSiteKeys = [],
    ): NotifyAllCommand {
        $registry = new DataSourceRegistry(new ServiceLocator([
            'products' => fn (): RecordingDataSource => $source,
        ]));
        $channelResolver = $this->createChannelResolver($channelCode, $channelLocales);
        $siteKeyResolver = new SiteKeyResolver(
            $this->createStub(ChannelRepositoryInterface::class),
            new NullLogger(),
            $defaultSiteKey,
            $channelSiteKeys,
        );

        return new NotifyAllCommand(
            $registry,
            $notifier ?? new RecordingCatalogChangeNotifier(),
            $channelResolver,
            new ShopLocaleResolver($channelResolver),
            $siteKeyResolver,
        );
    }

    /**
     * @param list<string> $channelLocales
     */
    private function createChannelResolver(string $channelCode, array $channelLocales): ChannelResolver
    {
        $channel = (new ChannelFixtureFactory())->createChannel($channelCode, $channelLocales);

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getChannel')->willReturn($channel);

        return $channelResolver;
    }

    private function createDocument(string $id): SourceDocument
    {
        return new SourceDocument($id, 'https://shop.test/' . $id, $id, $id, DocumentKind::Product, [], '2026-09-15T00:00:00+00:00');
    }

    private function createStyle(?BufferedOutput $output = null): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output ?? new BufferedOutput());
    }
}
