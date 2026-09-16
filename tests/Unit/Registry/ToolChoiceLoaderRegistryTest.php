<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Registry;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;
use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionChoiceLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ToolChoiceLoaderRegistryTest extends TestCase
{
    public function testReturnsTheChoicesOfTheRegisteredLoader(): void
    {
        $registry = new ToolChoiceLoaderRegistry(new ServiceLocator([
            RegionChoiceLoader::class => static fn (): RegionChoiceLoader => new RegionChoiceLoader(),
        ]));

        self::assertSame(['Praha', 'Moravskoslezský kraj'], $registry->getChoices(RegionChoiceLoader::class));
    }

    public function testReturnsEachChoiceOnceAsAList(): void
    {
        $loader = $this->createStub(ToolChoiceLoaderInterface::class);
        $loader->method('loadChoices')->willReturn([3 => 'Praha', 5 => 'Praha', 7 => 'Vysočina']);
        $registry = new ToolChoiceLoaderRegistry(new ServiceLocator([
            'keyed' => static fn (): ToolChoiceLoaderInterface => $loader,
        ]));

        self::assertSame(['Praha', 'Vysočina'], $registry->getChoices('keyed'));
    }

    public function testRefusesALoaderThatIsNotRegistered(): void
    {
        $registry = new ToolChoiceLoaderRegistry(new ServiceLocator([]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RegionChoiceLoader::class);

        $registry->getChoices(RegionChoiceLoader::class);
    }
}
