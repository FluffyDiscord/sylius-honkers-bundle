<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusHonkersBundle\Contract\ChannelTaxonRootsInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

class SharedTaxonRoots implements ChannelTaxonRootsInterface
{
    /**
     * @param list<TaxonInterface> $roots
     */
    public function __construct(
        private readonly array $roots,
    ) {
    }

    /**
     * @return list<TaxonInterface>
     */
    public function findIndexableRoots(ChannelInterface $channel): array
    {
        return $this->roots;
    }
}
