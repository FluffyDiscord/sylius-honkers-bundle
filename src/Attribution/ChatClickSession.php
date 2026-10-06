<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Attribution;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ChatClickSession
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getSessionKey(): string
    {
        return 'fluffydiscord_honkers.chat_clicks';
    }

    public function getMaxEntries(): int
    {
        return 20;
    }

    public function remember(string $productCode, string $clickId): void
    {
        $session = $this->findSession();
        if ($session === null) {
            return;
        }

        $clickIds = $this->readClickIds($session);
        unset($clickIds[$productCode]);
        $clickIds[$productCode] = $clickId;

        $overflowCount = count($clickIds) - $this->getMaxEntries();
        if ($overflowCount > 0) {
            $clickIds = array_slice($clickIds, $overflowCount, preserve_keys: true);
        }

        $session->set($this->getSessionKey(), $clickIds);
    }

    /**
     * @return array<string, string>
     */
    public function getClickIdsByProductCode(): array
    {
        $session = $this->findSession();
        if ($session === null) {
            return [];
        }

        return $this->readClickIds($session);
    }

    public function clear(): void
    {
        $session = $this->findSession();
        if ($session === null) {
            return;
        }

        $session->remove($this->getSessionKey());
    }

    private function findSession(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }

        $hasSession = $request->hasSession();
        if (!$hasSession) {
            return null;
        }

        return $request->getSession();
    }

    /**
     * @return array<string, string>
     */
    private function readClickIds(SessionInterface $session): array
    {
        $storedClickIds = $session->get($this->getSessionKey());
        if (!is_array($storedClickIds)) {
            return [];
        }

        $clickIds = [];
        foreach ($storedClickIds as $productCode => $clickId) {
            if (!is_string($clickId)) {
                continue;
            }

            $clickIds[(string) $productCode] = $clickId;
        }

        return $clickIds;
    }
}
