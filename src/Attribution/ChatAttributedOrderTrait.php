<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Attribution;

use Doctrine\ORM\Mapping\Column;

trait ChatAttributedOrderTrait
{
    /**
     * @var list<string>|null
     *
     * @Column(name="chat_click_ids", type="json", nullable=true)
     */
    #[Column(name: 'chat_click_ids', type: 'json', nullable: true)]
    private ?array $chatClickIds = null;

    /**
     * @return list<string>
     */
    public function getChatClickIds(): array
    {
        return $this->chatClickIds ?? [];
    }

    /**
     * @param list<string> $chatClickIds
     */
    public function setChatClickIds(array $chatClickIds): void
    {
        $this->chatClickIds = $chatClickIds;
    }
}
