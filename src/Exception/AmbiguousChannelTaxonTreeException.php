<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Exception;

use FluffyDiscord\Honkers\Enum\ApiErrorCode;
use FluffyDiscord\Honkers\Exception\ChatbotApiException;
use Symfony\Component\HttpFoundation\Response;

class AmbiguousChannelTaxonTreeException extends ChatbotApiException
{
    public function __construct(?string $channelCode = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Channel "%s" has no menu taxon and the shop has more than one taxon tree,'
                . ' so the categories of this channel cannot be told apart from another channel\'s.'
                . ' Set the channel\'s menu taxon in the Sylius admin.',
                $channelCode ?? '',
            ),
            0,
            $previous,
        );
    }

    public function getErrorCode(): ApiErrorCode
    {
        return ApiErrorCode::AmbiguousTaxonTree;
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
