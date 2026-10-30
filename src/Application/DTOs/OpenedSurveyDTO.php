<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * The secrets a survey gets when it opens. They are stored only as hashes (or encrypted, the
 * public token), so they are handed back once, to send the guests' links.
 */
final readonly class OpenedSurveyDTO
{
    /**
     * @param  array<string, string>  $guestTokens  guest reference => link token
     */
    public function __construct(
        public SurveyId $surveyId,
        public array $guestTokens,
        public ?string $publicToken,
    ) {}
}
