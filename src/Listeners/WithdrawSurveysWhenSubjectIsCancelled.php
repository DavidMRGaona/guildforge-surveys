<?php

declare(strict_types=1);

namespace Modules\Surveys\Listeners;

use App\Domain\Surveys\Events\SurveySubjectCancelled;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Surveys\Application\Services\SubjectCancelledService;

/**
 * A subject offered by another module will never end: drop its surveys that never opened.
 */
final readonly class WithdrawSurveysWhenSubjectIsCancelled implements ShouldQueue
{
    public function __construct(private SubjectCancelledService $service) {}

    public function handle(SurveySubjectCancelled $event): void
    {
        $this->service->handle($event->sourceType, $event->subjectId, DateTimeImmutable::createFromInterface($event->cancelledAt));
    }
}
