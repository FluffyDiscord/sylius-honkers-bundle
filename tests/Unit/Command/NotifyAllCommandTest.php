<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Command;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\Command\NotifyAllCommand;
use FluffyDiscord\Honkers\DTO\SourceDocument;
use FluffyDiscord\Honkers\Enum\DocumentKind;
use FluffyDiscord\Honkers\Locale\LocaleMatcher;
use FluffyDiscord\SyliusHonkersBundle\Locale\SyliusLocaleContext;
use FluffyDiscord\Honkers\Registry\DataSourceRegistry;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\RecordingCatalogChangeNotifier;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\RecordingDataSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

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
     * @param list<?string>         $expectedNotifiedChannelCodes
     * @param list<string>          $expectedQueriedLocales
     */
    #[DataProvider('provideChannelSiteKeyOutcomes')]
    public function testTheChannelSiteKeyDecidesWhatIsAnnounced(
        string $defaultSiteKey,
        array $channelSiteKeys,
        bool $isChannelEnabled,
        bool $hasDocuments,
        int $expectedExitCode,
        string $expectedOutput,
        array $expectedNotifiedChannelCodes,
        array $expectedQueriedLocales,
    ): void {
        $documents = $hasDocuments ? [$this->createDocument('T-SHIRT-01')] : [];
        $source = new RecordingDataSource('products', null, $documents);
        $siteKeyResolver = $this->createSiteKeyResolver($defaultSiteKey, $channelSiteKeys);
        $notifier = new RecordingCatalogChangeNotifier($siteKeyResolver, acceptsNotifications: false);
        $channelResolver = $this->createChannelResolver('SK', ['sk_SK'], $isChannelEnabled);
        $command = new NotifyAllCommand(
            $this->createRegistry($source),
            $notifier,
            $channelResolver,
            $this->createLocaleContext($channelResolver),
            new LocaleMatcher(),
            $siteKeyResolver,
        );
        $output = new BufferedOutput();

        $exitCode = $command->__invoke($this->createStyle($output), 'products', null, 'SK');

        self::assertSame($expectedExitCode, $exitCode);
        self::assertStringContainsString($expectedOutput, $output->fetch());
        self::assertSame($expectedNotifiedChannelCodes, array_column($notifier->notifications, 3));
        self::assertSame($expectedQueriedLocales, $source->queriedLocales);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, bool, bool, int, string, list<?string>, list<string>}>
     */
    public static function provideChannelSiteKeyOutcomes(): iterable
    {
        yield 'no channel keys announce with the default key' => [
            'site-key', [], true, true,
            Command::FAILURE, 'a batch of 1 ids was not accepted', [null], ['sk_SK'],
        ];
        yield 'no channel keys ignore whether the channel is enabled' => [
            'site-key', [], false, false,
            Command::SUCCESS, 'The whole catalog was announced', [], ['sk_SK'],
        ];
        yield 'mapped channel announces with its own code' => [
            '', ['SK' => 'sk-key'], true, true,
            Command::FAILURE, 'a batch of 1 ids was not accepted', ['SK'], ['sk_SK'],
        ];
        yield 'mapped channel with an empty catalog succeeds' => [
            '', ['SK' => 'sk-key'], true, false,
            Command::SUCCESS, 'The whole catalog was announced', [], ['sk_SK'],
        ];
        yield 'unmapped channel with a default key announces with its own code' => [
            'site-key', ['CZ' => 'cz-key'], true, true,
            Command::FAILURE, 'a batch of 1 ids was not accepted', ['SK'], ['sk_SK'],
        ];
        yield 'unmapped channel without a default key is refused' => [
            '', ['CZ' => 'cz-key'], true, true,
            Command::FAILURE, 'The channel "SK" has no site key', [], [],
        ];
        yield 'channel mapped to an empty key is refused' => [
            'site-key', ['SK' => ''], true, true,
            Command::FAILURE, 'The channel "SK" has no site key', [], [],
        ];
        yield 'disabled channel is refused' => [
            '', ['SK' => 'sk-key'], false, true,
            Command::FAILURE, 'The channel "SK" is disabled', [], [],
        ];
    }

    /**
     * @param list<string> $channelLocales
     */
    private function createCommand(RecordingDataSource $source, array $channelLocales): NotifyAllCommand
    {
        $siteKeyResolver = $this->createSiteKeyResolver('site-key', []);
        $channelResolver = $this->createChannelResolver('CZ', $channelLocales);

        return new NotifyAllCommand(
            $this->createRegistry($source),
            new RecordingCatalogChangeNotifier($siteKeyResolver),
            $channelResolver,
            $this->createLocaleContext($channelResolver),
            new LocaleMatcher(),
            $siteKeyResolver,
        );
    }

    private function createLocaleContext(ChannelResolver $channelResolver): SyliusLocaleContext
    {
        return new SyliusLocaleContext(
            $channelResolver,
            $this->createStub(LocaleContextInterface::class),
            new LocaleMatcher(),
        );
    }

    private function createRegistry(RecordingDataSource $source): DataSourceRegistry
    {
        return new DataSourceRegistry([$source]);
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    private function createSiteKeyResolver(string $defaultSiteKey, array $channelSiteKeys): SiteKeyResolver
    {
        return new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), $defaultSiteKey, $channelSiteKeys);
    }

    /**
     * @param list<string> $channelLocales
     */
    private function createChannelResolver(string $channelCode, array $channelLocales, bool $isChannelEnabled = true): ChannelResolver
    {
        $channel = (new ChannelFixtureFactory())->createChannel($channelCode, $channelLocales, $isChannelEnabled);

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
