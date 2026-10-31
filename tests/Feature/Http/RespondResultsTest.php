<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Tests\Support\Modules\ModuleTestCase;

final class RespondResultsTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createExamplePeople();
    }

    public function test_a_named_poll_shows_its_results_and_voters_once_voted(): void
    {
        [$id] = $this->openPoll(anonymous: false);

        $this->actingAs($this->people[0])->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'open')->missing('results'));

        $this->actingAs($this->people[0])->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-1']]);

        $this->actingAs($this->people[0])->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'answered')
                ->where('results.responses', 1)
                ->where('results.questions.0.id', 'q-1')
                ->where('results.questions.0.buckets.0.label', 'Viernes')
                ->where('results.questions.0.buckets.0.count', 1)
                ->where('results.questions.0.buckets.0.voters', ['Persona A'])
                ->where('results.questions.0.buckets.1.count', 0));
    }

    public function test_an_anonymous_poll_keeps_its_results_until_the_minimum(): void
    {
        [$id] = $this->openPoll(anonymous: true);
        $this->actingAs($this->people[0])->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-1']]);

        $this->actingAs($this->people[0])->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'answered')->missing('results'));

        foreach ([1, 2] as $person) {
            $this->actingAs($this->people[$person])->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-2']]);
        }

        $this->actingAs($this->people[0])->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.questions.0.buckets.1.count', 2)
                ->where('results.questions.0.buckets.1.voters', []));
    }

    public function test_a_form_that_keeps_its_results_shows_none(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0);

        $this->actingAs($this->people[0])->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'answered')->missing('results'));
    }

    public function test_the_public_link_shows_the_results_right_after_voting(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: false, publicLink: true);
        $this->assertIsString($opened->publicToken);
        $page = route('surveys.public', [$id->value, $opened->publicToken]);
        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->people[0]->id), ['q-1' => 'o-1']);

        $this->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('state', 'open')->missing('results'));

        $this->post(route('surveys.public.store', [$id->value, $opened->publicToken]), ['answers' => ['q-1' => 'o-2']])->assertRedirect($page);

        // Visitors without an account never get the names of those who voted
        $this->get($page)->assertInertia(fn (Assert $inertia) => $inertia
            ->where('results.questions.0.buckets.1.count', 1)
            ->where('results.questions.0.buckets.0.voters', []));
        $this->get($page)->assertInertia(fn (Assert $inertia) => $inertia->missing('results'));
    }

    public function test_voting_on_one_survey_does_not_open_the_results_of_another(): void
    {
        [$voted, $first] = $this->openPoll(anonymous: false, publicLink: true);
        [$other, $second] = $this->openPoll(anonymous: false, publicLink: true);
        $this->assertIsString($first->publicToken);
        $this->assertIsString($second->publicToken);

        $this->post(route('surveys.public.store', [$voted->value, $first->publicToken]), ['answers' => ['q-1' => 'o-1']]);

        $this->get(route('surveys.public', [$other->value, $second->publicToken]))
            ->assertInertia(fn (Assert $inertia) => $inertia->where('state', 'open')->missing('results'));
    }
}
