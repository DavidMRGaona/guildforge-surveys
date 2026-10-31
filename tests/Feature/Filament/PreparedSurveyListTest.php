<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The panel tells a survey prepared for its subject's end from an ordinary draft.
 */
final class PreparedSurveyListTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_a_prepared_survey_says_how_it_goes_out_on_the_end(): void
    {
        $this->save($this->endSurvey('example-thing:thing-1', sendOnEnd: true, delay: 3));
        $this->save($this->endSurvey('example-thing:thing-2', sendOnEnd: false, delay: 0));

        Livewire::test(ListSurveys::class)
            ->assertSee(__('surveys::filament.survey.awaiting_end_auto', ['hours' => 3]))
            ->assertSee(__('surveys::filament.survey.awaiting_end_one_click'));
    }

    public function test_an_end_survey_made_on_the_end_is_an_ordinary_draft(): void
    {
        $this->save($this->endSurvey('example-thing:thing-1', sendOnEnd: true, delay: 3, endedAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00')));

        Livewire::test(ListSurveys::class)
            ->assertDontSee(__('surveys::filament.survey.awaiting_end_auto', ['hours' => 3]));
    }

    private function endSurvey(string $endKey, bool $sendOnEnd, int $delay, ?DateTimeImmutable $endedAt = null): Survey
    {
        [$type, $id] = explode(':', $endKey);

        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: new SubjectSnapshot($type, $id, 'Partida de ejemplo', null, null, [], [], null),
            subjectEndKey: $endKey,
            subjectEndedAt: $endedAt,
            sendOnEnd: $sendOnEnd,
            sendDelayHours: $delay,
        );
    }

    private function save(Survey $survey): void
    {
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);
    }
}
