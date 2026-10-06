<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\EventListener;

use FluffyDiscord\Honkers\Telemetry\ClickId;
use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatClickSession;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

class ChatClickLandingListener
{
    public function __construct(
        private readonly ChatClickSession $chatClickSession,
        private readonly ClickId          $clickId,
        private readonly RequestStack     $requestStack,
    ) {
    }

    #[AsEventListener(event: 'sylius.product.show')]
    public function rememberClick(ResourceControllerEvent $event): void
    {
        $product = $event->getSubject();
        if (!$product instanceof ProductInterface) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $query = $request->query->all();
        $foundClickId = $this->clickId->find($query);
        if ($foundClickId === null) {
            return;
        }

        $productCode = (string) $product->getCode();
        if ($productCode === '') {
            return;
        }

        $this->chatClickSession->remember($productCode, $foundClickId);
    }
}
