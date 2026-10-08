<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusHonkersPlugin\Credentials\HonkersChannelInterface;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\HonkersChannelTrait;
use Sylius\Component\Core\Model\Channel;

class HonkersChannel extends Channel implements HonkersChannelInterface
{
    use HonkersChannelTrait;
}
