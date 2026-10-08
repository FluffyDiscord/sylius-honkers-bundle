<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Template;

use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChatAttributedOrder;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\HookableMetadataDouble;
use FluffyDiscord\SyliusHonkersPlugin\Twig\ChatClickExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

class FromChatTemplateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTemplates(): iterable
    {
        yield 'template block' => ['admin/order/from_chat.html.twig'];
        yield 'twig hook' => ['admin/order/show/from_chat.html.twig'];
    }

    #[DataProvider('provideTemplates')]
    public function testEveryClickLinksToItsConversation(string $template): void
    {
        $order = new ChatAttributedOrder();
        $order->setChatClickIds(['click-1', 'click-2']);

        $rendered = $this->render($template, $order);

        $firstLinkPosition = strpos($rendered, 'href="https://backend.test/clicks/click-1"');
        $secondLinkPosition = strpos($rendered, 'href="https://backend.test/clicks/click-2"');

        self::assertIsInt($firstLinkPosition);
        self::assertIsInt($secondLinkPosition);
        self::assertLessThan($secondLinkPosition, $firstLinkPosition);
        self::assertStringContainsString('fluffydiscord_honkers.admin.order.open_conversation', $rendered);
        self::assertStringNotContainsString('fluffydiscord_honkers.attribute_value.no', $rendered);
    }

    #[DataProvider('provideTemplates')]
    public function testAnOrderWithoutClicksSaysNo(string $template): void
    {
        $rendered = $this->render($template, new ChatAttributedOrder());

        self::assertStringContainsString('fluffydiscord_honkers.admin.order.from_chat', $rendered);
        self::assertStringContainsString('fluffydiscord_honkers.attribute_value.no', $rendered);
        self::assertStringNotContainsString('href=', $rendered);
    }

    #[DataProvider('provideTemplates')]
    public function testAnOrderWithoutTheFieldRendersNothing(string $template): void
    {
        $rendered = $this->render($template, new Order());

        self::assertSame('', $rendered);
    }

    private function render(string $template, Order $order): string
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', fn (string $key): string => $key));
        $twig->addExtension(new ChatClickExtension('https://backend.test/'));

        $context = [
            'order' => $order,
            'hookable_metadata' => new HookableMetadataDouble(['resource' => $order]),
        ];

        return trim($twig->render($template, $context));
    }
}
