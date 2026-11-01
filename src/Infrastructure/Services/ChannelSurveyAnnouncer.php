<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Domain\Surveys\Events\GeneralSurveyOpened;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\SurveyAnnouncerInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyKind;

/**
 * Dispatches the core's GeneralSurveyOpened event, which a channel module listens to. The link
 * is the public one when the survey has it; otherwise the page that asks to sign in.
 */
final readonly class ChannelSurveyAnnouncer implements SurveyAnnouncerInterface
{
    public function announceOpened(Survey $survey): void
    {
        $id = $survey->id()->value;
        $token = $survey->publicToken();

        $event = new GeneralSurveyOpened(
            surveyId: $id,
            title: $survey->title(),
            description: $survey->description(),
            isPoll: $survey->kind() === SurveyKind::Poll,
            closesAt: $survey->closesAt(),
            url: $token === null ? route('surveys.respond', $id) : route('surveys.public', [$id, $token]),
        );

        DB::afterCommit(static fn () => event($event));
    }
}
