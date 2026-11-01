<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Database;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPeriod;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Database\Seeders\DevelopmentSeeder;
use Modules\Surveys\Database\Seeders\RecommendedTemplatesSeeder;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Tests\Support\Modules\ModuleTestCase;

final class DevelopmentSeederTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    /**
     * @var list<string>
     */
    private const array TABLES = [
        'users',
        'surveys_templates',
        'surveys_surveys',
        'surveys_recipients',
        'surveys_participations',
        'surveys_responses',
        'surveys_answers',
        'surveys_organizers',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // database/ is outside the module's autoloaded src/
        foreach (glob(dirname(__DIR__, 3).'/database/seeders/*Seeder.php') ?: [] as $seeder) {
            require_once $seeder;
        }

        $this->useUtcStorage();
        Mail::fake();
        Notification::fake();

        // Enabling the module above ran both without data; these tests ask for it
        DevelopmentSeeder::$seedInTests = true;
        RecommendedTemplatesSeeder::$seedInTests = true;
    }

    protected function tearDown(): void
    {
        DevelopmentSeeder::$seedInTests = false;
        RecommendedTemplatesSeeder::$seedInTests = false;

        parent::tearDown();
    }

    public function test_it_writes_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->counts());
    }

    public function test_module_tests_get_no_demo_data_unless_they_ask(): void
    {
        DevelopmentSeeder::$seedInTests = false;

        $this->runSeeder();

        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->counts());
    }

    public function test_it_sends_nothing(): void
    {
        $this->runSeeder();

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_it_brings_the_recommended_templates_and_one_of_its_own(): void
    {
        $this->runSeeder();

        $this->assertSame(12, SurveyTemplateModel::query()->whereNotNull('catalog_key')->count());
        $this->assertSame(1, SurveyTemplateModel::query()->whereNull('catalog_key')->count());
    }

    public function test_it_covers_every_status(): void
    {
        $this->runSeeder();

        $surveys = SurveyModel::query()->get();
        foreach (SurveyStatus::cases() as $status) {
            $this->assertTrue($surveys->contains('status', $status->value), $status->value);
        }
        $this->assertTrue($surveys->contains(static fn (SurveyModel $survey): bool => $survey->rejection_reason !== null), 'rejected');
        $this->assertTrue($surveys->contains(static fn (SurveyModel $survey): bool => $survey->scheduled_send_at !== null && $survey->status === SurveyStatus::Draft->value), 'scheduled');
        $this->assertTrue($surveys->contains(static fn (SurveyModel $survey): bool => $survey->dismissed_at !== null), 'dismissed');
        $this->assertTrue($surveys->contains(static fn (SurveyModel $survey): bool => $survey->subject_end_key !== null && $survey->subject_ended_at === null), 'prepared ahead');
        $this->assertTrue($surveys->contains('kind', SurveyKind::Poll->value), 'poll');
    }

    public function test_it_covers_every_kind_of_subject_audience_and_public_link_mode(): void
    {
        $this->runSeeder();

        $surveys = SurveyModel::query()->get();
        foreach (['game-table', 'campaign-session', 'campaign', 'tournament', 'event'] as $type) {
            $this->assertTrue($surveys->contains('subject_type', $type), $type);
        }
        foreach (AudienceType::cases() as $audience) {
            $this->assertTrue($surveys->contains('audience_type', $audience->value), $audience->value);
        }
        foreach (PublicLinkMode::cases() as $mode) {
            $this->assertTrue($surveys->contains(static fn (SurveyModel $survey): bool => $survey->public_link_enabled && $survey->public_link_mode === $mode->value), $mode->value);
        }
        $this->assertTrue($surveys->contains('anonymous', false));
        $this->assertTrue($surveys->contains('anonymous', true));
    }

    public function test_results_show_above_the_minimum_and_one_anonymous_survey_stays_below(): void
    {
        $this->runSeeder();

        $closedAnonymous = SurveyModel::query()->where('status', SurveyStatus::Closed->value)->where('anonymous', true)->withCount('responses')->get();
        $this->assertTrue($closedAnonymous->contains(static fn (SurveyModel $survey): bool => $survey->responses_count === 2));
        $this->assertGreaterThan(30, $closedAnonymous->filter(static fn (SurveyModel $survey): bool => $survey->responses_count >= 3)->count());
    }

    public function test_answers_reach_every_kind_of_question(): void
    {
        $this->runSeeder();

        $own = SurveyTemplateModel::query()->whereNull('catalog_key')->firstOrFail();
        $survey = SurveyModel::query()->where('template_id', $own->id)->where('status', SurveyStatus::Open->value)->firstOrFail();
        $answers = DB::table('surveys_answers')->whereIn('response_id', SurveyResponseModel::query()->where('survey_id', $survey->id)->select('id'))->get();

        $this->assertTrue($answers->contains(static fn (object $answer): bool => $answer->row_id !== null), 'grid');
        $this->assertTrue($answers->contains(static fn (object $answer): bool => $answer->option_id === null && $answer->number_value === null && $answer->text_value !== null), 'text or other');
        $this->assertTrue($answers->contains(static fn (object $answer): bool => is_string($answer->text_value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer->text_value) === 1), 'date');
        $this->assertTrue($answers->contains(static fn (object $answer): bool => is_string($answer->text_value) && preg_match('/^\d{2}:\d{2}$/', $answer->text_value) === 1), 'time');
    }

    public function test_trends_have_a_year_of_history_with_a_cut_hidden_by_the_minimum(): void
    {
        $this->runSeeder();

        $template = SurveyTemplateModel::query()->where('catalog_key', 'game_table')->firstOrFail();
        $trends = $this->app->make(TrendsService::class)->forPanel(
            SurveyTemplateId::fromString($template->id),
            TrendFilters::fromInput(['period' => TrendPeriod::Month->value, 'compare' => 'organizer']),
        );

        $this->assertNotSame([], $trends->questions);
        $this->assertGreaterThanOrEqual(12, count($trends->questions[0]->periods));
        $this->assertTrue($this->hasHiddenPoint($trends->questions), 'a cut below the minimum');
    }

    public function test_anonymous_responses_keep_only_the_day_and_random_ids(): void
    {
        $this->runSeeder();

        $responses = SurveyResponseModel::query()->whereIn('survey_id', SurveyModel::query()->where('anonymous', true)->select('id'))->get();

        $this->assertNotEmpty($responses);
        foreach ($responses as $response) {
            $this->assertNull($response->user_id);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $response->id);
        }
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->runSeeder();
        $first = $this->counts();

        $this->runSeeder();

        $this->assertSame($first, $this->counts());
    }

    public function test_the_admin_has_pending_surveys_and_organizes_some(): void
    {
        $admin = UserModel::factory()->create(['email' => 'admin@example.local']);

        $this->runSeeder();

        $this->assertGreaterThan(0, SurveyOrganizerModel::query()->where('user_id', $admin->id)->count());
        $pending = SurveyModel::query()
            ->where('status', SurveyStatus::Open->value)
            ->whereIn('id', SurveyRecipientModel::query()->where('user_id', $admin->id)->select('survey_id'))
            ->whereNotIn('id', SurveyParticipationModel::query()->where('user_id', $admin->id)->select('survey_id'))
            ->count();
        $this->assertGreaterThan(0, $pending);
    }

    private function runSeeder(): void
    {
        $this->app->make(DevelopmentSeeder::class)->run();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<mixed>  $questions
     */
    private function hasHiddenPoint(array $questions): bool
    {
        foreach ($questions as $question) {
            foreach ($question->series as $series) {
                foreach ($series->points as $point) {
                    if ($point->belowMinimum) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
