<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Credentials;

use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\HonkersBundle\Contract\CredentialsProviderInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

interface ChannelCredentialsProviderInterface extends CredentialsProviderInterface
{
    public function findForChannel(ChannelInterface $channel): ?SiteCredentials;
}
