<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatAttributedOrderInterface;
use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatAttributedOrderTrait;
use Sylius\Component\Core\Model\Order;

class ChatAttributedOrder extends Order implements ChatAttributedOrderInterface
{
    use ChatAttributedOrderTrait;
}
