<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

use Modules\Surveys\Domain\Enums\PublicLinkMode;

/**
 * A survey's public link while it takes responses, for whoever may see the survey in the panel.
 */
final readonly class PublicLinkDTO
{
    public function __construct(
        public string $token,
        public PublicLinkMode $mode,
    ) {}
}
