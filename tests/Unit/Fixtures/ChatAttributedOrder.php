<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderInterface;
use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderTrait;
use Sylius\Component\Core\Model\Order;

class ChatAttributedOrder extends Order implements ChatAttributedOrderInterface
{
    use ChatAttributedOrderTrait;
}
