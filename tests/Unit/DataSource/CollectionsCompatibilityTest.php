<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\DataSource;

use PHPUnit\Framework\TestCase;

class CollectionsCompatibilityTest extends TestCase
{
    /**
     * The Order enum only exists in doctrine/collections 2.1+, while Sylius 1.14
     * pins doctrine/collections to ^1.6. Referencing it fataled every data source
     * read on Sylius 1.x. Criteria::ASC is defined in both 1.8 and 2.x.
     */
    public function testNoSourceReferencesCollectionsOrderEnum(): void
    {
        $offendingFiles = $this->getFilesReferencingOrderEnum();

        self::assertSame(
            [],
            $offendingFiles,
            'Doctrine\Common\Collections\Order requires doctrine/collections ^2.1, which Sylius 1.14 cannot install.',
        );
    }

    /**
     * @return list<string>
     */
    private function getFilesReferencingOrderEnum(): array
    {
        $sourceDirectory = dirname(__DIR__, 3) . '/src';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        $offendingFiles = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $isReferencingOrderEnum = str_contains($contents, 'Doctrine\Common\Collections\Order');
            if (!$isReferencingOrderEnum) {
                continue;
            }

            $offendingFiles[] = substr($file->getPathname(), strlen($sourceDirectory) + 1);
        }

        sort($offendingFiles);

        return $offendingFiles;
    }
}
