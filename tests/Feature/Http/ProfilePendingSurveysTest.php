<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\SurveysServiceProvider;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class ProfilePendingSurveysTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->player = UserModel::factory()->create();
    }

    public function test_the_profile_lists_only_the_surveys_the_person_still_has_to_answer(): void
    {
        $pending = $this->openSurvey('Pendiente', [$this->player->id]);
        $answered = $this->openSurvey('Respondida', [$this->player->id]);
        $this->app->make(SubmissionService::class)->submit($answered, Respondent::account($this->player->id), ['q-1' => 'o-1']);
        $closed = $this->openSurvey('Cerrada', [$this->player->id]);
        $this->app->make(SurveyLifecycleService::class)->close($closed);
        $this->openSurvey('Ajena', [UserModel::factory()->create()->id]);

        $this->actingAs($this->player)->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('profilePendingSurveys', 1)
                ->where('profilePendingSurveys.0.id', $pending->value)
                ->where('profilePendingSurveys.0.title', 'Pendiente')
                ->where('profilePendingSurveys.0.url', route('surveys.respond', $pending->value))
                ->where('profilePendingSurveysTotal', 1));
    }

    public function test_other_pages_do_not_load_it(): void
    {
        $this->openSurvey('Pendiente', [$this->player->id]);

        $this->actingAs($this->player)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('profilePendingSurveys', null));
    }

    public function test_it_is_a_profile_tab(): void
    {
        $slots = array_filter(
            (new SurveysServiceProvider($this->app))->registerSlots(),
            static fn (SlotRegistrationDTO $slot): bool => $slot->slot === 'profile-sections',
        );

        $this->assertCount(1, $slots);
        $slot = array_first($slots);
        $this->assertSame(['profilePendingSurveys'], $slot->dataKeys);
        $this->assertSame('profilePendingSurveysTotal', $slot->profileTab['badgeKey'] ?? null);
    }

    /**
     * @param  list<string>  $userIds
     */
    private function openSurvey(string $title, array $userIds): SurveyId
    {
        ExampleThingSource::$audience = new SurveyAudienceDTO($userIds);
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            $title,
            SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
                'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué tal?', 'required' => true,
                'options' => [['id' => 'o-1', 'label' => 'Bien'], ['id' => 'o-2', 'label' => 'Muy bien']],
            ]]]]]),
            anonymous: true,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );
        $lifecycle->open($id, notify: false);

        return $id;
    }
}
