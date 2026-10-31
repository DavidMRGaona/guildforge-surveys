<?php

declare(strict_types=1);

namespace Modules\Surveys\Listeners;

use App\Domain\Surveys\Events\SurveySubjectEnded;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Surveys\Application\Services\SubjectEndedService;

/**
 * Queued: the module that ended the subject should not wait while its audience is resolved and mailed.
 */
final readonly class OfferSurveyWhenSubjectEnds implements ShouldQueue
{
    public function __construct(private SubjectEndedService $service) {}

    public function handle(SurveySubjectEnded $event): void
    {
        $this->service->handle($event->sourceType, $event->subjectId, DateTimeImmutable::createFromInterface($event->endedAt));
    }
}
