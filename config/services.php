<?php

declare(strict_types=1);

use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ChannelTaxonRootsInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\SyliusHonkersBundle\Locale\SyliusLocaleContext;
use FluffyDiscord\SyliusHonkersBundle\Product\ProductIndexability;
use FluffyDiscord\SyliusHonkersBundle\Product\ProductViewFactory;
use FluffyDiscord\SyliusHonkersBundle\Taxon\ChannelTaxonRoots;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('FluffyDiscord\\SyliusHonkersBundle\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/DTO',
            __DIR__ . '/../src/Enum',
            __DIR__ . '/../src/Exception',
            __DIR__ . '/../src/Tool/DTO',
            __DIR__ . '/../src/DataSource/CmsPagesDataSource.php',
            __DIR__ . '/../src/FluffyDiscordSyliusHonkersBundle.php',
        ]);

    $services->alias(ChannelTaxonRootsInterface::class, ChannelTaxonRoots::class);
    $services->alias(ProductViewFactoryInterface::class, ProductViewFactory::class);
    $services->alias(ProductIndexabilityInterface::class, ProductIndexability::class);
    $services->alias(ChatbotLocaleContextInterface::class, SyliusLocaleContext::class);
};
