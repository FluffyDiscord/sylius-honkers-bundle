<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Schema;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;
use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use FluffyDiscord\SyliusChatbotBundle\Schema\ArgumentsSchemaGenerator;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\MistypedRegionArguments;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\NullableArguments;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionArguments;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionChoiceLoader;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionListArguments;
use FluffyDiscord\SyliusChatbotBundle\Tool\DTO\OrderStatusArguments;
use FluffyDiscord\SyliusChatbotBundle\Tool\DTO\ProductAvailabilityArguments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ArgumentsSchemaGeneratorTest extends TestCase
{
    private ArgumentsSchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ArgumentsSchemaGenerator(new ToolChoiceLoaderRegistry(new ServiceLocator([
            RegionChoiceLoader::class => static fn (): RegionChoiceLoader => new RegionChoiceLoader(),
        ])));
    }

    public function testToolChoicePropertyListsTheLoadedChoicesAsItsEnum(): void
    {
        $schema = $this->generator->generate(RegionArguments::class);

        self::assertSame(
            ['type' => 'string', 'enum' => ['Praha', 'Moravskoslezský kraj']],
            $schema['properties']['region'],
        );
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testMultipleToolChoicePropertyListsTheLoadedChoicesAsItsItemEnum(): void
    {
        $schema = $this->generator->generate(RegionListArguments::class);

        self::assertSame(
            [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['Praha', 'Moravskoslezský kraj']],
                'minItems' => 1,
                'maxItems' => 2,
            ],
            $schema['properties']['regions'],
        );
    }

    public function testToolChoiceOnAPropertyOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(MistypedRegionArguments::class . '::$region must be typed string');

        $this->generator->generate(MistypedRegionArguments::class);
    }

    public function testToolChoicePropertyIsLeftOutWhenTheLoaderOffersNothing(): void
    {
        $emptyLoader = $this->createStub(ToolChoiceLoaderInterface::class);
        $emptyLoader->method('loadChoices')->willReturn([]);
        $generator = new ArgumentsSchemaGenerator(new ToolChoiceLoaderRegistry(new ServiceLocator([
            RegionChoiceLoader::class => static fn (): ToolChoiceLoaderInterface => $emptyLoader,
        ])));

        $schema = $generator->generate(RegionArguments::class);

        self::assertSame(['type' => 'object', 'additionalProperties' => false], $schema);
    }

    public function testOrderStatusArgumentsSchema(): void
    {
        $schema = $this->generator->generate(OrderStatusArguments::class);

        self::assertSame('object', $schema['type']);
        self::assertSame(['orderNumber', 'email'], $schema['required']);
        self::assertSame('string', $schema['properties']['orderNumber']['type']);
        self::assertSame(32, $schema['properties']['orderNumber']['maxLength']);
        self::assertSame('email', $schema['properties']['email']['format']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testNullablePropertyIsNotRequired(): void
    {
        $schema = $this->generator->generate(NullableArguments::class);

        self::assertSame(['query'], $schema['required']);
        self::assertArrayHasKey('note', $schema['properties']);
        self::assertSame(['relevance', 'price'], $schema['properties']['sort']['enum']);
    }

    public function testArrayPropertySchema(): void
    {
        $schema = $this->generator->generate(ProductAvailabilityArguments::class);

        self::assertSame('array', $schema['properties']['codes']['type']);
        self::assertSame('string', $schema['properties']['codes']['items']['type']);
        self::assertSame(['codes'], $schema['required']);
    }
}
