<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Tests\Support\BuildsTrendSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * An organizer's own trends on the site: the surveys whose snapshot names them, nothing else.
 */
final class OrganizerTrendsTest extends ModuleTestCase
{
    use BuildsTrendSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private string $ana;

    private string $bea;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        config(['modules.settings.surveys.min_responses' => 3]);
        $this->template = $this->saveTemplate($this->definition());
        [$this->ana, $this->bea] = $this->people(2);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('surveys.manage.trends'))->assertRedirect(route('login'));
    }

    public function test_without_template_surveys_the_page_is_empty(): void
    {
        $this->actingAs($this->user($this->ana))->get(route('surveys.manage.trends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Trends', false)
                ->where('templates', [])
                ->where('templateId', null)
                ->where('trends', null));
    }

    public function test_only_templates_of_their_surveys(): void
    {
        $other = $this->saveTemplate($this->definition(), 'Valoración del torneo');
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana]]), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);
        $this->answerMany($this->survey(['starts' => '2027-01-11', 'organizers' => [$this->bea]]), [['sat' => 5], ['sat' => 5], ['sat' => 5]]);
        $this->survey(['starts' => '2027-01-12', 'organizers' => [$this->bea], 'template' => $other]);

        $this->actingAs($this->user($this->ana))->get(route('surveys.manage.trends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Trends', false)
                ->where('templates', [['id' => $this->template->value, 'name' => 'Valoración de la partida']])
                ->where('templateId', $this->template->value)
                ->where('trends.visible', true)
                ->where('trends.surveys', 1)
                ->where('trends.responses', 3)
                ->where('trends.questions.0.key', 'satisfaction')
                ->where('trends.questions.0.series.0.points.0.mean', 2.0)
                ->where('options.gameSystems', ['Rolemaster'])
                ->where('minimum', 3));
    }

    public function test_a_foreign_template_falls_back_to_their_own(): void
    {
        $other = $this->saveTemplate($this->definition(), 'Valoración del torneo');
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana]]);
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$this->bea], 'template' => $other]);

        $this->actingAs($this->user($this->ana))->get(route('surveys.manage.trends', ['template' => $other->value]))
            ->assertInertia(fn (Assert $page) => $page->where('templateId', $this->template->value));
    }

    public function test_filters_come_from_the_query_string_without_organizers(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana]]), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);

        $this->actingAs($this->user($this->ana))
            ->get(route('surveys.manage.trends', ['organizer' => $this->bea, 'compare' => 'organizer', 'period' => 'month', 'system' => 'Rolemaster']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['type' => null, 'system' => 'Rolemaster', 'from' => null, 'to' => null, 'period' => 'month', 'compare' => 'none'])
                ->where('trends.surveys', 1)
                ->where('trends.questions.0.periods', [['key' => '2027-01', 'label' => 'ene 2027']]));
    }

    public function test_payload_keeps_the_minimum_per_cut(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana], 'anonymous' => false]), [['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-04-10', 'organizers' => [$this->ana], 'anonymous' => false]), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $this->actingAs($this->user($this->ana))->get(route('surveys.manage.trends'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trends.questions.0.series.0.points.0', ['period' => '2027-Q1', 'answered' => 1, 'belowMinimum' => true, 'mean' => null, 'shares' => []])
                ->where('trends.questions.0.series.0.points.1.mean', 4.0));
    }

    public function test_payload_has_no_survey_or_subject_details(): void
    {
        $survey = $this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana, $this->bea]]);
        $this->answerMany($survey, [['sat' => 2], ['sat' => 2], ['sat' => 2]]);

        $response = $this->actingAs($this->user($this->ana))->get(route('surveys.manage.trends', ['compare' => 'subject_type']));
        $props = json_encode($response->viewData('page')['props']['trends'] ?? null, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($survey->value, $props);
        $this->assertStringNotContainsString('Partida de ejemplo', $props);
        $this->assertStringNotContainsString($this->bea, $props);
        $this->assertStringNotContainsString('Persona 2', $props);
        $this->assertStringContainsString('Cosa de ejemplo', $props);
    }

    public function test_the_profile_links_to_trends_only_with_template_surveys(): void
    {
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$this->ana]]);

        $this->actingAs($this->user($this->ana))->get(route('profile.show'))
            ->assertInertia(fn (Assert $page) => $page->where('profileSurveyTrendsUrl', route('surveys.manage.trends')));

        $this->actingAs($this->user($this->bea))->get(route('profile.show'))
            ->assertInertia(fn (Assert $page) => $page->where('profileSurveyTrendsUrl', null));
    }

    private function user(string $id): UserModel
    {
        return UserModel::query()->findOrFail($id);
    }
}
