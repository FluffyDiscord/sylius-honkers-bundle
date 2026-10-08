<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Credentials;

use Doctrine\ORM\EntityManagerInterface;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Exception\PairingException;
use FluffyDiscord\Honkers\Pairing\HostMatcher;
use FluffyDiscord\HonkersBundle\Contract\CredentialsWriterInterface;
use FluffyDiscord\HonkersBundle\Credentials\EnvCredentialsProvider;
use FluffyDiscord\HonkersBundle\Pairing\PairedCredentials;
use FluffyDiscord\HonkersBundle\Pairing\PairingErrorCode;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

class SyliusCredentialsProvider implements ChannelCredentialsProviderInterface, CredentialsWriterInterface
{
    /**
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     * @param array<array-key, string>                     $channelSiteKeys
     */
    public function __construct(
        private readonly EnvCredentialsProvider     $envCredentialsProvider,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly ChannelContextInterface    $channelContext,
        private readonly EntityManagerInterface     $entityManager,
        private readonly HostMatcher                $hostMatcher,

        #[Autowire(param: 'fluffydiscord_sylius_honkers.channel_site_keys')]
        private readonly array $channelSiteKeys,
    ) {
    }

    public function isApiSecretValid(#[\SensitiveParameter] string $token): bool
    {
        $isEnvApiSecretValid = $this->envCredentialsProvider->isApiSecretValid($token);
        if ($isEnvApiSecretValid) {
            return true;
        }

        $canStoreCredentials = $this->canStoreCredentials();
        if (!$canStoreCredentials) {
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $pairedChannels = $this->channelRepository->findBy(['honkersApiSecretHash' => $tokenHash], null, 1);

        return $pairedChannels !== [];
    }

    public function findCurrentSite(): ?SiteCredentials
    {
        $channel = $this->findRequestChannel();
        if ($channel === null) {
            return $this->envCredentialsProvider->findCurrentSite();
        }

        return $this->findChannelCredentials($channel);
    }

    public function findForChannel(ChannelInterface $channel): ?SiteCredentials
    {
        return $this->findChannelCredentials($channel);
    }

    public function findTrustedHost(Request $request): ?string
    {
        $channel = $this->findRequestChannel();
        if ($channel === null) {
            return null;
        }

        $hostname = $channel->getHostname();
        if ($hostname === '') {
            return null;
        }

        return $hostname;
    }

    public function save(PairedCredentials $credentials, Request $request): void
    {
        $channels = $this->getChannelsToPair($credentials->channelCodes);

        foreach ($channels as $channel) {
            $this->assertHostnameIsVerified($channel, $credentials->verifiedDomains);
        }

        foreach ($channels as $channel) {
            $channel->setHonkersSiteKey($credentials->siteKey);
            $channel->setHonkersApiSecret($credentials->apiSecret);
            $channel->setHonkersIngestSecret($credentials->ingestSecret);
        }

        $this->entityManager->flush();
    }

    private function canStoreCredentials(): bool
    {
        $channelClass = $this->channelRepository->getClassName();

        return is_a($channelClass, HonkersChannelInterface::class, true);
    }

    private function findRequestChannel(): ?ChannelInterface
    {
        try {
            return $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }
    }

    private function findChannelCredentials(ChannelInterface $channel): ?SiteCredentials
    {
        $pairedCredentials = $this->findPairedCredentials($channel);
        if ($pairedCredentials !== null) {
            return $pairedCredentials;
        }

        $channelCode = (string) $channel->getCode();

        return $this->findConfiguredCredentials($channelCode);
    }

    private function findPairedCredentials(ChannelInterface $channel): ?SiteCredentials
    {
        if (!$channel instanceof HonkersChannelInterface) {
            return null;
        }

        $siteKey = $channel->getHonkersSiteKey();
        if ($siteKey === null) {
            return null;
        }

        $ingestSecret = $channel->getHonkersIngestSecret() ?? '';

        return new SiteCredentials($siteKey, $ingestSecret);
    }

    private function findConfiguredCredentials(string $channelCode): ?SiteCredentials
    {
        $hasChannelSiteKey = array_key_exists($channelCode, $this->channelSiteKeys);
        if (!$hasChannelSiteKey) {
            return $this->envCredentialsProvider->findCurrentSite();
        }

        $channelSiteKey = $this->channelSiteKeys[$channelCode];
        if ($channelSiteKey === '') {
            return null;
        }

        return $this->envCredentialsProvider->findSite($channelSiteKey);
    }

    /**
     * @param list<string> $channelCodes
     *
     * @return list<HonkersChannelInterface>
     *
     * @throws PairingException
     */
    private function getChannelsToPair(array $channelCodes): array
    {
        if ($channelCodes === []) {
            $requestChannel = $this->findRequestChannel();

            return [$this->getPairableChannel($requestChannel)];
        }

        $channels = [];

        foreach ($channelCodes as $channelCode) {
            $channel = $this->channelRepository->findOneByCode($channelCode);
            $channels[] = $this->getPairableChannel($channel);
        }

        return $channels;
    }

    /**
     * @throws PairingException
     */
    private function getPairableChannel(?ChannelInterface $channel): HonkersChannelInterface
    {
        if (!$channel instanceof HonkersChannelInterface) {
            throw new PairingException('This channel cannot store chatbot credentials.', PairingErrorCode::Unsupported->value);
        }

        return $channel;
    }

    /**
     * @param list<string> $verifiedDomains
     *
     * @throws PairingException
     */
    private function assertHostnameIsVerified(HonkersChannelInterface $channel, array $verifiedDomains): void
    {
        $hostname = (string) $channel->getHostname();
        $normalizedHostname = $this->hostMatcher->normalize($hostname);
        if ($normalizedHostname === null) {
            throw new PairingException('A paired channel has no hostname.', PairingErrorCode::HostnameMissing->value);
        }

        foreach ($verifiedDomains as $verifiedDomain) {
            $isCovered = $this->hostMatcher->isCoveredByDomain($hostname, $verifiedDomain);
            if ($isCovered) {
                return;
            }
        }

        throw new PairingException('A paired channel is not on a verified domain.', PairingErrorCode::WrongShop->value);
    }
}
