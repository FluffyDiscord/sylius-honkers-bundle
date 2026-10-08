<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\EventListener;

use FluffyDiscord\Honkers\Telemetry\ClickId;
use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatClickSession;
use FluffyDiscord\SyliusHonkersPlugin\EventListener\ChatClickLandingListener;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ChatClickLandingListenerTest extends TestCase
{
    private ChatClickSession $chatClickSession;

    private function createListener(string $url): ChatClickLandingListener
    {
        $request = Request::create($url);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->chatClickSession = new ChatClickSession($requestStack);

        return new ChatClickLandingListener($this->chatClickSession, new ClickId(), $requestStack);
    }

    private function createProductShowEvent(string $productCode): ResourceControllerEvent
    {
        $product = new Product();
        $product->setCode($productCode);

        return new ResourceControllerEvent($product);
    }

    public function testALandingWithAClickIdRemembersTheProduct(): void
    {
        $listener = $this->createListener('https://shop.example/products/clipper?gooseclid=click-1');

        $listener->rememberClick($this->createProductShowEvent('CLIPPER'));

        self::assertSame(['CLIPPER' => 'click-1'], $this->chatClickSession->getClickIdsByProductCode());
    }

    public function testALandingWithoutAClickIdRemembersNothing(): void
    {
        $listener = $this->createListener('https://shop.example/products/clipper?utm_source=chat');

        $listener->rememberClick($this->createProductShowEvent('CLIPPER'));

        self::assertSame([], $this->chatClickSession->getClickIdsByProductCode());
    }
}
