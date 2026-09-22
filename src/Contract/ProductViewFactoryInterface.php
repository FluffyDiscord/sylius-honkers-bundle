<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Contract;

use FluffyDiscord\SyliusHonkersBundle\DTO\ProductItem;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductViewFactoryInterface
{
    public function createItem(ProductVariantInterface $variant, ChannelInterface $channel, string $locale): ?ProductItem;
}
