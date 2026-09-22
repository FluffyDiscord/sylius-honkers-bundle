<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Contract;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductIndexabilityInterface
{
    public function isIndexable(ProductVariantInterface $variant, ChannelInterface $channel): bool;
}
