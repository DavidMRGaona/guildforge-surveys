<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

use DateTimeImmutable;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * What the public site's editor sends for a subject's survey. The duration only counts before
 * the survey first opens; after that, the closing date does.
 */
final readonly class OrganizerSurveyInput
{
    public function __construct(
        public string $title,
        public ?string $description,
        public SurveyDefinition $definition,
        public bool $anonymous,
        public ?int $durationDays,
        public ?DateTimeImmutable $closesAt,
        public ?int $reminderHoursBefore,
        public bool $liveResults,
        public bool $visibleToRespondents,
        /** How a survey prepared for its subject's end goes out; null leaves it as it is */
        public ?bool $sendOnEnd = null,
        public ?int $sendDelayHours = null,
    ) {}
}
