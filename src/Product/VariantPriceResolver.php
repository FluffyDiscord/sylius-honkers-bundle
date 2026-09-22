<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Product;

use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VariantPriceResolver
{
    public function __construct(
        #[Autowire(service: 'sylius.calculator.product_variant_price')]
        private readonly ProductVariantPricesCalculatorInterface $pricesCalculator,
    ) {
    }

    public function findPriceMinor(ProductVariantInterface $variant, ChannelInterface $channel): ?int
    {
        try {
            return $this->pricesCalculator->calculate($variant, ['channel' => $channel]);
        } catch (MissingChannelConfigurationException) {
            return null;
        }
    }
}
