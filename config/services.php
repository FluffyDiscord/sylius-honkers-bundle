<?php

declare(strict_types=1);

use FluffyDiscord\SyliusHonkersPlugin\Contract\ChannelTaxonRootsInterface;
use FluffyDiscord\SyliusHonkersPlugin\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusHonkersPlugin\Contract\ProductViewFactoryInterface;
use FluffyDiscord\SyliusHonkersPlugin\Product\ProductIndexability;
use FluffyDiscord\SyliusHonkersPlugin\Product\ProductViewFactory;
use FluffyDiscord\SyliusHonkersPlugin\Taxon\ChannelTaxonRoots;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('FluffyDiscord\\SyliusHonkersPlugin\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/DTO',
            __DIR__ . '/../src/Enum',
            __DIR__ . '/../src/Exception',
            __DIR__ . '/../src/Tool/DTO',
            __DIR__ . '/../src/DataSource/CmsPagesDataSource.php',
            __DIR__ . '/../src/DataSource/BitBagCmsPagesDataSource.php',
            __DIR__ . '/../src/DependencyInjection',
            __DIR__ . '/../src/FluffyDiscordSyliusHonkersPlugin.php',
        ]);

    $services->alias(ChannelTaxonRootsInterface::class, ChannelTaxonRoots::class);
    $services->alias(ProductViewFactoryInterface::class, ProductViewFactory::class);
    $services->alias(ProductIndexabilityInterface::class, ProductIndexability::class);
};
