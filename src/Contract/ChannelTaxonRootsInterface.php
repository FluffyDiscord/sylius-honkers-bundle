<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Contract;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

interface ChannelTaxonRootsInterface
{
    /**
     * Answers which taxon trees a channel indexes when it names no menu taxon.
     *
     * The shipped implementation refuses to guess between several trees, because on a shop that
     * gives each channel its own tree the wrong guess publishes another channel's categories.
     * A shop whose channels deliberately share one taxonomy replaces this service and returns
     * every root instead.
     *
     * @return list<TaxonInterface> the roots whose taxons the channel indexes; empty indexes none
     */
    public function findIndexableRoots(ChannelInterface $channel): array;
}
