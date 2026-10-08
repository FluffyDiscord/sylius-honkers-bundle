<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Credentials;

use Doctrine\ORM\EntityManagerInterface;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Exception\PairingException;
use FluffyDiscord\Honkers\Pairing\HostMatcher;
use FluffyDiscord\HonkersBundle\Credentials\EnvCredentialsProvider;
use FluffyDiscord\HonkersBundle\Pairing\PairedCredentials;
use FluffyDiscord\HonkersBundle\Pairing\PairingErrorCode;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\SyliusCredentialsProvider;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\HonkersChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\HttpFoundation\Request;

class SyliusCredentialsProviderTest extends TestCase
{
    private const ENV_API_SECRET = 'env-api-secret';

    private const ENV_INGEST_SECRET = 'env-ingest-secret';

    private const ENV_SITE_KEY = 'env-site-key';

    public function testAnUnpairedChannelUsesTheEnvironmentCredentials(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $provider = $this->createProvider($this->createNonQueryingRepository());

        $credentials = $provider->findForChannel($channel);

        $this->assertCredentials(self::ENV_SITE_KEY, self::ENV_INGEST_SECRET, $credentials);
    }

    public function testAnUnpairedChannelUsesItsConfiguredSiteKeyWithTheEnvironmentIngestSecret(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $repository = $this->createNonQueryingRepository();
        $provider = $this->createProvider($repository, channelSiteKeys: ['CZ_WEB' => 'cz-key']);

        $credentials = $provider->findForChannel($channel);

        $this->assertCredentials('cz-key', self::ENV_INGEST_SECRET, $credentials);
    }

    public function testAConfiguredSiteKeyWithoutAnIngestSecretStillServesTheWidget(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $repository = $this->createNonQueryingRepository();
        $provider = $this->createProvider($repository, channelSiteKeys: ['CZ_WEB' => 'cz-key'], envIngestSecret: '');

        $credentials = $provider->findForChannel($channel);

        $this->assertCredentials('cz-key', '', $credentials);
        self::assertFalse($credentials?->hasIngestSecret());
    }

    public function testAPairedSiteKeyWithoutAnIngestSecretStillServesTheWidget(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $channel->setHonkersSiteKey('paired-key');
        $provider = $this->createProvider($this->createRepository([$channel]), $channel);

        $credentials = $provider->findCurrentSite();

        $this->assertCredentials('paired-key', '', $credentials);
    }

    public function testAnEmptyConfiguredSiteKeyMeansNoCredentials(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $repository = $this->createNonQueryingRepository();
        $provider = $this->createProvider($repository, channelSiteKeys: ['CZ_WEB' => '']);

        self::assertNull($provider->findForChannel($channel));
    }

    public function testAPairedChannelUsesItsStoredCredentials(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $channel->setHonkersSiteKey('paired-key');
        $channel->setHonkersIngestSecret('paired-ingest-secret');
        $repository = $this->createNonQueryingRepository();
        $provider = $this->createProvider($repository, channelSiteKeys: ['CZ_WEB' => 'cz-key']);

        $credentials = $provider->findForChannel($channel);

        $this->assertCredentials('paired-key', 'paired-ingest-secret', $credentials);
    }

    public function testAChannelModelWithoutTheTraitReadsOnlyTheConfiguration(): void
    {
        $channel = new Channel();
        $channel->setCode('CZ_WEB');
        $provider = $this->createProvider($this->createNonQueryingRepository(), channelSiteKeys: ['CZ_WEB' => 'cz-key']);

        $credentials = $provider->findForChannel($channel);

        $this->assertCredentials('cz-key', self::ENV_INGEST_SECRET, $credentials);
    }

    public function testTheCurrentSiteIsTheRequestChannelsCredentials(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $channel->setHonkersSiteKey('paired-key');
        $channel->setHonkersIngestSecret('paired-ingest-secret');
        $provider = $this->createProvider($this->createRepository([$channel]), $channel);

        $credentials = $provider->findCurrentSite();

        $this->assertCredentials('paired-key', 'paired-ingest-secret', $credentials);
    }

    public function testTheCurrentSiteWithoutARequestChannelUsesTheEnvironmentCredentials(): void
    {
        $provider = $this->createProvider($this->createRepository([]));

        $credentials = $provider->findCurrentSite();

        $this->assertCredentials(self::ENV_SITE_KEY, self::ENV_INGEST_SECRET, $credentials);
    }

    public function testTheEnvironmentApiSecretIsValidWithoutAQuery(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('findBy');
        $provider = $this->createProvider($repository);

        self::assertTrue($provider->isApiSecretValid(self::ENV_API_SECRET));
    }

    public function testAPairedApiSecretIsLookedUpByItsHash(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(HonkersChannel::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['honkersApiSecretHash' => hash('sha256', 'paired-api-secret')], null, 1)
            ->willReturn([$channel]);
        $provider = $this->createProvider($repository);

        self::assertTrue($provider->isApiSecretValid('paired-api-secret'));
    }

    public function testAnUnknownApiSecretIsInvalid(): void
    {
        $repository = $this->createStub(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(HonkersChannel::class);
        $repository->method('findBy')->willReturn([]);
        $provider = $this->createProvider($repository);

        self::assertFalse($provider->isApiSecretValid('unknown-api-secret'));
    }

    public function testAChannelModelWithoutTheTraitNeverQueriesForAnApiSecret(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(Channel::class);
        $repository->expects(self::never())->method('findBy');
        $provider = $this->createProvider($repository);

        self::assertFalse($provider->isApiSecretValid('unknown-api-secret'));
    }

    public function testSaveStoresTheCredentialsOnEveryListedChannel(): void
    {
        $czechChannel = $this->createHonkersChannel('CZ_WEB', 'shop.example.com');
        $slovakChannel = $this->createHonkersChannel('SK_WEB', 'shop.example.com');
        $otherChannel = $this->createHonkersChannel('DE_WEB', 'shop.example.com');
        $repository = $this->createRepository([$czechChannel, $slovakChannel, $otherChannel]);
        $entityManager = $this->createFlushingEntityManager(1);
        $provider = $this->createProvider($repository, $otherChannel, entityManager: $entityManager);

        $provider->save($this->createPairedCredentials(['CZ_WEB', 'SK_WEB']), new Request());

        $this->assertPaired($czechChannel);
        $this->assertPaired($slovakChannel);
        self::assertNull($otherChannel->getHonkersSiteKey());
    }

    public function testSaveStoresTheCredentialsOnChannelsOfEveryVerifiedDomain(): void
    {
        $czechChannel = $this->createHonkersChannel('CZ_WEB', 'www.Shop.CZ');
        $slovakChannel = $this->createHonkersChannel('SK_WEB', 'eshop.shop.sk');
        $repository = $this->createRepository([$czechChannel, $slovakChannel]);
        $entityManager = $this->createFlushingEntityManager(1);
        $provider = $this->createProvider($repository, entityManager: $entityManager);
        $credentials = $this->createPairedCredentials(['CZ_WEB', 'SK_WEB'], ['shop.cz', 'shop.sk']);

        $provider->save($credentials, new Request());

        $this->assertPaired($czechChannel);
        $this->assertPaired($slovakChannel);
    }

    /**
     * @param list<string> $verifiedDomains
     */
    #[DataProvider('provideUnverifiedHostnames')]
    public function testSaveRefusesWhenAnyChannelIsOffTheVerifiedDomains(string $slovakHostname, array $verifiedDomains): void
    {
        $czechChannel = $this->createHonkersChannel('CZ_WEB', 'shop.cz');
        $slovakChannel = $this->createHonkersChannel('SK_WEB', $slovakHostname);
        $repository = $this->createRepository([$czechChannel, $slovakChannel]);
        $entityManager = $this->createFlushingEntityManager(0);
        $provider = $this->createProvider($repository, entityManager: $entityManager);
        $credentials = $this->createPairedCredentials(['CZ_WEB', 'SK_WEB'], $verifiedDomains);

        $errorCode = $this->getSaveErrorCode($provider, $credentials);

        self::assertSame(PairingErrorCode::WrongShop->value, $errorCode);
        $this->assertNotPaired($czechChannel);
        $this->assertNotPaired($slovakChannel);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideUnverifiedHostnames(): iterable
    {
        yield 'other shop' => ['attacker.example.net', ['shop.cz']];
        yield 'same suffix, other domain' => ['evilshop.cz', ['shop.cz']];
        yield 'parent of a verified subdomain' => ['shop.cz', ['eshop.shop.cz']];
        yield 'no verified domains' => ['shop.cz', []];
    }

    public function testSaveRefusesAChannelWithoutHostnameAndWritesNothing(): void
    {
        $czechChannel = $this->createHonkersChannel('CZ_WEB', 'shop.cz');
        $slovakChannel = $this->createHonkersChannel('SK_WEB');
        $repository = $this->createRepository([$czechChannel, $slovakChannel]);
        $entityManager = $this->createFlushingEntityManager(0);
        $provider = $this->createProvider($repository, entityManager: $entityManager);
        $credentials = $this->createPairedCredentials(['CZ_WEB', 'SK_WEB'], ['shop.cz']);

        $errorCode = $this->getSaveErrorCode($provider, $credentials);

        self::assertSame(PairingErrorCode::HostnameMissing->value, $errorCode);
        $this->assertNotPaired($czechChannel);
    }

    public function testSaveWithoutChannelCodesStoresTheCredentialsOnTheRequestChannel(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB', 'shop.example.com');
        $entityManager = $this->createFlushingEntityManager(1);
        $provider = $this->createProvider($this->createRepository([$channel]), $channel, entityManager: $entityManager);

        $provider->save($this->createPairedCredentials([]), new Request());

        $this->assertPaired($channel);
    }

    public function testSaveRefusesAChannelWithoutTheTrait(): void
    {
        $pairableChannel = $this->createHonkersChannel('CZ_WEB', 'shop.example.com');
        $plainChannel = new Channel();
        $plainChannel->setCode('SK_WEB');
        $plainChannel->setHostname('shop.example.com');
        $repository = $this->createRepository([$pairableChannel, $plainChannel]);
        $entityManager = $this->createFlushingEntityManager(0);
        $provider = $this->createProvider($repository, entityManager: $entityManager);

        $errorCode = $this->getSaveErrorCode($provider, $this->createPairedCredentials(['CZ_WEB', 'SK_WEB']));

        self::assertSame(PairingErrorCode::Unsupported->value, $errorCode);
        $this->assertNotPaired($pairableChannel);
    }

    public function testSaveRefusesAnUnknownChannelCode(): void
    {
        $entityManager = $this->createFlushingEntityManager(0);
        $provider = $this->createProvider($this->createRepository([]), entityManager: $entityManager);

        $this->expectException(PairingException::class);

        $provider->save($this->createPairedCredentials(['XX_WEB']), new Request());
    }

    public function testTheTrustedHostIsTheRequestChannelsHostname(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB', 'shop.example.com');
        $provider = $this->createProvider($this->createRepository([$channel]), $channel);

        self::assertSame('shop.example.com', $provider->findTrustedHost(new Request()));
    }

    public function testAChannelWithoutHostnameHasNoTrustedHost(): void
    {
        $channel = $this->createHonkersChannel('CZ_WEB');
        $provider = $this->createProvider($this->createRepository([$channel]), $channel);

        self::assertNull($provider->findTrustedHost(new Request()));
    }

    public function testNoRequestChannelMeansNoTrustedHost(): void
    {
        $provider = $this->createProvider($this->createRepository([]));

        self::assertNull($provider->findTrustedHost(new Request()));
    }

    /**
     * @param ChannelRepositoryInterface<ChannelInterface> $repository
     * @param array<array-key, string>                     $channelSiteKeys
     */
    private function createProvider(
        ChannelRepositoryInterface $repository,
        ?ChannelInterface          $requestChannel = null,
        array                      $channelSiteKeys = [],
        ?EntityManagerInterface    $entityManager = null,
        string                     $envIngestSecret = self::ENV_INGEST_SECRET,
    ): SyliusCredentialsProvider {
        $envCredentialsProvider = new EnvCredentialsProvider(self::ENV_API_SECRET, $envIngestSecret, self::ENV_SITE_KEY);

        return new SyliusCredentialsProvider(
            $envCredentialsProvider,
            $repository,
            $this->createChannelContext($requestChannel),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new HostMatcher(),
            $channelSiteKeys,
        );
    }

    private function getSaveErrorCode(SyliusCredentialsProvider $provider, PairedCredentials $credentials): string
    {
        try {
            $provider->save($credentials, new Request());
        } catch (PairingException $exception) {
            return $exception->getErrorCode();
        }

        self::fail('The pairing must be refused.');
    }

    /**
     * @param list<ChannelInterface> $channels
     *
     * @return ChannelRepositoryInterface<ChannelInterface>
     */
    private function createRepository(array $channels): ChannelRepositoryInterface
    {
        $channelsByCode = [];
        foreach ($channels as $channel) {
            $channelsByCode[(string) $channel->getCode()] = $channel;
        }

        $repository = $this->createStub(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(HonkersChannel::class);
        $repository->method('findOneByCode')->willReturnCallback(
            fn (string $code): ?ChannelInterface => $channelsByCode[$code] ?? null,
        );

        return $repository;
    }

    /**
     * @return ChannelRepositoryInterface<ChannelInterface>
     */
    private function createNonQueryingRepository(): ChannelRepositoryInterface
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(HonkersChannel::class);
        $repository->expects(self::never())->method('findOneByCode');
        $repository->expects(self::never())->method('findOneBy');
        $repository->expects(self::never())->method('findBy');
        $repository->expects(self::never())->method('findAll');

        return $repository;
    }

    private function createChannelContext(?ChannelInterface $requestChannel): ChannelContextInterface
    {
        $channelContext = $this->createStub(ChannelContextInterface::class);

        if ($requestChannel === null) {
            $channelContext->method('getChannel')->willThrowException(new ChannelNotFoundException());
        } else {
            $channelContext->method('getChannel')->willReturn($requestChannel);
        }

        return $channelContext;
    }

    private function createFlushingEntityManager(int $expectedFlushCount): EntityManagerInterface&MockObject
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($expectedFlushCount))->method('flush');

        return $entityManager;
    }

    private function createHonkersChannel(string $code, ?string $hostname = null): HonkersChannel
    {
        $channel = new HonkersChannel();
        $channel->setCode($code);
        $channel->setHostname($hostname);

        return $channel;
    }

    /**
     * @param list<string> $channelCodes
     * @param list<string> $verifiedDomains
     */
    private function createPairedCredentials(array $channelCodes, array $verifiedDomains = ['shop.example.com']): PairedCredentials
    {
        return new PairedCredentials('paired-key', 'paired-api-secret', 'paired-ingest-secret', $channelCodes, $verifiedDomains);
    }

    private function assertPaired(HonkersChannel $channel): void
    {
        self::assertSame('paired-key', $channel->getHonkersSiteKey());
        self::assertSame('paired-ingest-secret', $channel->getHonkersIngestSecret());
        self::assertSame(hash('sha256', 'paired-api-secret'), $this->getApiSecretHash($channel));
    }

    private function assertNotPaired(HonkersChannel $channel): void
    {
        self::assertNull($channel->getHonkersSiteKey());
        self::assertNull($channel->getHonkersIngestSecret());
        self::assertNull($this->getApiSecretHash($channel));
    }

    private function getApiSecretHash(HonkersChannel $channel): ?string
    {
        $property = new \ReflectionProperty(HonkersChannel::class, 'honkersApiSecretHash');

        return $property->getValue($channel);
    }

    private function assertCredentials(string $expectedSiteKey, string $expectedIngestSecret, ?SiteCredentials $credentials): void
    {
        self::assertNotNull($credentials);
        self::assertSame($expectedSiteKey, $credentials->siteKey);
        self::assertSame($expectedIngestSecret, $credentials->ingestSecret);
    }
}
