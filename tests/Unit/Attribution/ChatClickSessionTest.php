<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Attribution;

use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatClickSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ChatClickSessionTest extends TestCase
{
    private function createChatClickSession(): ChatClickSession
    {
        $request = Request::create('https://shop.example/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ChatClickSession($requestStack);
    }

    public function testTheLastClickPerProductWins(): void
    {
        $chatClickSession = $this->createChatClickSession();

        $chatClickSession->remember('CLIPPER', 'click-1');
        $chatClickSession->remember('BLADE', 'click-2');
        $chatClickSession->remember('CLIPPER', 'click-3');

        self::assertSame(['BLADE' => 'click-2', 'CLIPPER' => 'click-3'], $chatClickSession->getClickIdsByProductCode());
    }

    public function testTheOldestEntryIsDroppedOverTheCap(): void
    {
        $chatClickSession = $this->createChatClickSession();

        for ($index = 0; $index <= $chatClickSession->getMaxEntries(); ++$index) {
            $chatClickSession->remember('PRODUCT-' . $index, 'click-' . $index);
        }

        $clickIdsByProductCode = $chatClickSession->getClickIdsByProductCode();
        self::assertCount($chatClickSession->getMaxEntries(), $clickIdsByProductCode);
        self::assertArrayNotHasKey('PRODUCT-0', $clickIdsByProductCode);
        self::assertSame('click-20', $clickIdsByProductCode['PRODUCT-20']);
    }

    public function testClearForgetsEveryClick(): void
    {
        $chatClickSession = $this->createChatClickSession();
        $chatClickSession->remember('CLIPPER', 'click-1');

        $chatClickSession->clear();

        self::assertSame([], $chatClickSession->getClickIdsByProductCode());
    }

    public function testWithoutASessionNothingIsRemembered(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example/'));
        $chatClickSession = new ChatClickSession($requestStack);

        $chatClickSession->remember('CLIPPER', 'click-1');

        self::assertSame([], $chatClickSession->getClickIdsByProductCode());
    }
}
