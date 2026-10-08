<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Contract;

use FluffyDiscord\SyliusHonkersPlugin\DTO\ProductItem;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductViewFactoryInterface
{
    public function createItem(ProductVariantInterface $variant, ChannelInterface $channel, string $locale): ?ProductItem;
}
