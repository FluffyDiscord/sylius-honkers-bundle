<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DataSource;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\PageInterface;
use MonsieurBiz\SyliusCmsPagePlugin\Repository\PageRepositoryInterface;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelUrlGenerator;
use FluffyDiscord\Honkers\Contract\ChatbotDataSourceInterface;
use FluffyDiscord\Honkers\Cursor\CursorCodec;
use FluffyDiscord\Honkers\DTO\DocumentPage;
use FluffyDiscord\Honkers\DTO\SourceDefinition;
use FluffyDiscord\Honkers\DTO\SourceDocument;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\Honkers\Enum\DocumentKind;
use FluffyDiscord\Honkers\Text\HtmlToText;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;
use Twig\Error\Error as TwigError;

class CmsPagesDataSource implements ChatbotDataSourceInterface
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ChannelResolver         $channelResolver,
        private readonly CursorCodec             $cursorCodec,
        private readonly HtmlToText              $htmlToText,
        private readonly ChannelUrlGenerator     $channelUrlGenerator,
        private readonly RichEditorExtension     $richEditor,
        private readonly LoggerInterface         $logger,
    ) {
    }

    public function getDefinition(): SourceDefinition
    {
        return new SourceDefinition(
            'cms_pages',
            'fluffydiscord_honkers.source.cms_pages.description',
        );
    }

    public function getDocuments(SourceQuery $query): DocumentPage
    {
        $repository = $this->pageRepository;
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf(
                'The page repository must be a Doctrine EntityRepository to build the chatbot query, got "%s".',
                $repository::class,
            ));
        }

        $locale = $query->locale;
        $channel = $this->channelResolver->getChannel();
        $isIdLookup = $query->hasIds();

        $queryBuilder = $repository->createQueryBuilder('page')
            ->innerJoin('page.translations', 'pageTranslation', Join::WITH, 'pageTranslation.locale = :locale')
            ->andWhere(':channel MEMBER OF page.channels')
            ->andWhere('page.enabled = :enabled')
            ->andWhere('page.publishAt IS NULL OR page.publishAt <= :now')
            ->andWhere('page.unpublishAt IS NULL OR page.unpublishAt >= :now')
            ->setParameter('locale', $locale)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('page.id', Criteria::ASC);

        if ($isIdLookup) {
            $queryBuilder->andWhere('page.code IN (:codes)')->setParameter('codes', $query->ids);
        } else {
            $queryBuilder->setMaxResults($this->getPageSize());
            $lastId = $this->cursorCodec->decodeNumericId($query->cursor);
            if ($lastId !== null) {
                $queryBuilder->andWhere('page.id > :lastId')->setParameter('lastId', $lastId);
            }
        }

        $pages = $queryBuilder->getQuery()->getResult();

        $documents = [];
        $lastFetchedId = null;
        foreach ($pages as $page) {
            $lastFetchedId = $page->getId();
            try {
                $document = $this->buildDocument($page, $channel, $locale);
            } catch (\RuntimeException|TwigError $exception) {
                $this->logger->error('Chatbot: building a CMS page document failed, skipping it.', [
                    'page' => $page->getCode() ?? 'id:' . $page->getId(),
                    'exception' => $exception,
                ]);

                continue;
            }
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        if ($isIdLookup) {
            return new DocumentPage($documents, null);
        }

        $fetchedCount = count($pages);
        $nextCursor = null;
        if ($fetchedCount === $this->getPageSize() && $lastFetchedId !== null) {
            $nextCursor = $this->cursorCodec->encode((string) $lastFetchedId);
        }

        return new DocumentPage($documents, $nextCursor);
    }

    private function getPageSize(): int
    {
        return 200;
    }

    private function buildDocument(PageInterface $page, ChannelInterface $channel, string $locale): ?SourceDocument
    {
        $translation = $page->getTranslation($locale);
        $slug = $translation->getSlug();
        $title = $translation->getTitle();
        if ($slug === null || $slug === '' || $title === null || $title === '') {
            return null;
        }

        $url = $this->channelUrlGenerator->generate(
            $channel,
            'monsieurbiz_cms_page_show',
            ['slug' => $slug],
        );

        $content = $translation->getContent();
        $text = $title;
        if ($content !== null && $content !== '') {
            $html = $this->richEditor->renderField([], $content);
            $text = $title . "\n\n" . $this->htmlToText->convert($html);
        }

        $metadata = [
            'url' => $url,
            'title' => $title,
        ];

        $identifier = $page->getCode();
        if ($identifier === null || $identifier === '') {
            $identifier = 'id:' . $page->getId();
        }

        $updatedAt = $page->getUpdatedAt() ?? $page->getCreatedAt() ?? new \DateTimeImmutable();

        return new SourceDocument(
            $identifier,
            $url,
            $title,
            $text,
            DocumentKind::Page,
            $metadata,
            $updatedAt->format(\DateTimeInterface::ATOM),
        );
    }
}
