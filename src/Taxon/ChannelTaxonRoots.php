<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Taxon;

use Doctrine\ORM\EntityRepository;
use FluffyDiscord\SyliusHonkersBundle\Contract\ChannelTaxonRootsInterface;
use FluffyDiscord\SyliusHonkersBundle\Exception\AmbiguousChannelTaxonTreeException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

class ChannelTaxonRoots implements ChannelTaxonRootsInterface
{
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
    ) {
    }

    /**
     * @return list<TaxonInterface>
     */
    public function findIndexableRoots(ChannelInterface $channel): array
    {
        $roots = $this->findTreeRoots();
        $treeCount = count($roots);

        if ($treeCount >= $this->getAmbiguityProbeSize()) {
            throw new AmbiguousChannelTaxonTreeException($channel->getCode());
        }

        return $roots;
    }

    public function getAmbiguityProbeSize(): int
    {
        return 2;
    }

    /**
     * @return list<TaxonInterface>
     */
    private function findTreeRoots(): array
    {
        $repository = $this->taxonRepository;

        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf(
                'The taxon repository must be a Doctrine EntityRepository to find the tree roots, got "%s".',
                $repository::class,
            ));
        }

        /** @var list<TaxonInterface> $roots */
        $roots = $repository->createQueryBuilder('taxon')
            ->andWhere('taxon.parent IS NULL')
            ->setMaxResults($this->getAmbiguityProbeSize())
            ->getQuery()
            ->getResult();

        return $roots;
    }
}
