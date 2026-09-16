<?php

declare(strict_types=1);

use FluffyDiscord\SyliusChatbotBundle\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusChatbotBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\SyliusChatbotBundle\Product\ProductIndexability;
use FluffyDiscord\SyliusChatbotBundle\Product\ProductViewFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('FluffyDiscord\\SyliusChatbotBundle\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/DTO',
            __DIR__ . '/../src/Enum',
            __DIR__ . '/../src/Exception',
            __DIR__ . '/../src/DependencyInjection',
            __DIR__ . '/../src/Tool/DTO',
            __DIR__ . '/../src/Security/ChatbotBackendUser.php',
            __DIR__ . '/../src/Validator/ToolChoice.php',
            __DIR__ . '/../src/DataSource/CmsPagesDataSource.php',
            __DIR__ . '/../src/FluffyDiscordSyliusChatbotBundle.php',
        ]);

    $services->alias(ProductViewFactoryInterface::class, ProductViewFactory::class);
    $services->alias(ProductIndexabilityInterface::class, ProductIndexability::class);
};
