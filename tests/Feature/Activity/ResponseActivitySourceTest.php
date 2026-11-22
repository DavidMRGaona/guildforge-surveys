<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\Contracts\OffersActivityAttributes;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\Enums\ActivityReadOutcome;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Activity\CountedResponses;
use Modules\Surveys\Infrastructure\Activity\ResponseActivitySource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\BuildsActivityResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Activity\ActivitySourceTestCase;

/**
 * «Encuestas respondidas» as an activity source (core#774): each response of an account to a named
 * survey that every account was asked, once it is open or closed. Whatever a member could not know
 * exists, or would learn something restricted from, never counts: anonymous surveys, surveys sent
 * to association members, to some roles or to the people of a subject, drafts and surveys waiting
 * for review, guests.
 */
final class ResponseActivitySourceTest extends ActivitySourceTestCase
{
    use BuildsActivityResponses;

    protected ?string $moduleName = 'surveys';

    protected function activitySource(): ActivitySourceInterface
    {
        return $this->app->make(ResponseActivitySource::class);
    }

    protected function createCountedActivitySubject(): string
    {
        return $this->activityResponse($this->activitySurvey(), UserModel::factory()->create())->id;
    }

    /**
     * @return list<string>
     */
    private function historyIds(?ActivitySourceInterface $source = null): array
    {
        $ids = [];
        $cursor = null;

        do {
            $page = ($source ?? $this->activitySource())->history($cursor, 50);
            foreach ($page->subjects as $subject) {
                $ids[] = $subject->subjectId;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $ids;
    }

    public function test_the_source_is_about_survey_responses_and_offers_no_attributes(): void
    {
        $source = $this->activitySource();

        $this->assertSame('surveys.response', $source->type());
        $this->assertSame(ActivityKind::SurveyResponse, $source->kind());
        $this->assertSame('Encuestas respondidas', $source->label());
        $this->assertNotInstanceOf(OffersActivityAttributes::class, $source);
    }

    public function test_a_response_to_a_counted_survey_is_one_respondent_fact_at_the_start_of_its_day(): void
    {
        $user = UserModel::factory()->create();
        $response = $this->activityResponse($this->activitySurvey(), $user, '2030-03-04');

        $facts = $this->activitySource()->facts($response->id);

        $this->assertCount(1, $facts);
        $this->assertSame($user->id, $facts[0]->userId);
        $this->assertSame(ActivityRole::Respondent, $facts[0]->role);
        $this->assertSame([], $facts[0]->attributes);
        $this->assertNull($facts[0]->placement);
        // The response keeps only the display day it was sent: the fact is that day's first instant
        $this->assertEquals(
            $this->app->make(TimezoneServiceInterface::class)->fromDisplayWallTime('2030-03-04 00:00'),
            $facts[0]->occurredAt,
        );
    }

    public function test_the_date_is_the_response_day_never_the_time_it_was_saved(): void
    {
        $response = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create(), '2029-12-31');

        $this->travelTo('2031-07-08 15:30:00');
        $fact = $this->activitySource()->facts($response->id)[0];

        $timezone = $this->app->make(TimezoneServiceInterface::class);
        $this->assertSame('2029-12-31', $timezone->toDisplay($fact->occurredAt)->format('Y-m-d'));
        $this->assertSame('00:00', $timezone->toDisplay($fact->occurredAt)->format('H:i'));
    }

    public function test_a_closed_survey_keeps_counting(): void
    {
        $response = $this->activityResponse($this->activitySurvey(['status' => 'closed']), UserModel::factory()->create());

        $this->assertCount(1, $this->activitySource()->facts($response->id));
        $this->assertSame([$response->id], $this->historyIds());
    }

    /**
     * Each case changes one thing from the survey that counts, so it cannot pass by being wrong
     * about something else: the control response is in the same table and must always be there.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function surveysThatNeverCount(): array
    {
        return [
            'draft' => [['status' => 'draft']],
            'waiting for review' => [['status' => 'pending_review']],
            'anonymous' => [['anonymous' => true]],
            'sent to association members' => [['audience_type' => 'association_members']],
            'sent to some roles' => [['audience_type' => 'roles', 'audience_roles' => ['moderacion']]],
            'sent to the people of a subject' => [['audience_type' => 'subject']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('surveysThatNeverCount')]
    public function test_a_survey_that_members_could_not_know_exists_or_that_says_who_never_counts(array $overrides): void
    {
        $control = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());
        $hidden = $this->activityResponse($this->activitySurvey($overrides), UserModel::factory()->create());

        $this->assertSame([], $this->activitySource()->facts($hidden->id));
        $this->assertCount(1, $this->activitySource()->facts($control->id));
        $this->assertSame([$control->id], $this->historyIds());
    }

    public function test_a_guest_response_never_counts(): void
    {
        $control = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());
        $guest = $this->activityResponse($this->activitySurvey(), null, guestName: 'Invitada de ejemplo');

        $this->assertSame([], $this->activitySource()->facts($guest->id));
        $this->assertSame([$control->id], $this->historyIds());
    }

    public function test_an_account_that_no_longer_exists_has_no_fact(): void
    {
        $control = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());
        $gone = UserModel::factory()->create();
        $survey = $this->activitySurvey();
        $response = $this->activityResponse($survey, $gone);
        $gone->forceDelete();

        $this->assertSame([], $this->activitySource()->facts($response->id));
        $this->assertSame([$control->id], $this->historyIds());
    }

    public function test_a_deactivated_account_keeps_its_fact(): void
    {
        $user = UserModel::factory()->create();
        $response = $this->activityResponse($this->activitySurvey(), $user);
        $user->delete();

        $this->assertCount(1, $this->activitySource()->facts($response->id));
    }

    public function test_an_anonymized_account_is_dropped_by_the_core_reader(): void
    {
        $user = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $survey = $this->activitySurvey();
        $mine = $this->activityResponse($survey, $user);
        $theirs = $this->activityResponse($survey, $other);
        $user->forceFill(['anonymized_at' => now()])->save();

        $reader = $this->app->make(ActivityReaderInterface::class);

        $erased = $reader->facts('surveys.response', $mine->id);
        $this->assertSame(ActivityReadOutcome::Read, $erased->outcome);
        $this->assertSame([], $erased->facts);
        $this->assertCount(1, $reader->facts('surveys.response', $theirs->id)->facts);
    }

    public function test_an_id_that_is_not_a_uuid_has_no_facts(): void
    {
        $this->assertSame([], $this->activitySource()->facts('not-an-id'));
        $this->assertSame([], $this->activitySource()->facts(''));
    }

    public function test_the_id_is_read_in_lower_case(): void
    {
        $response = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());

        $this->assertCount(1, $this->activitySource()->facts(strtoupper($response->id)));
    }

    public function test_an_anonymous_survey_reports_nothing_even_if_a_named_row_were_forced_into_it(): void
    {
        $user = UserModel::factory()->create();
        $survey = $this->activitySurvey(['anonymous' => true]);
        // The domain never stores a user on an anonymous response; the source does not rely on it
        $forced = $this->activityResponse($survey, $user);
        $this->participation($survey, $user);

        $this->assertSame([], $this->activitySource()->facts($forced->id));
        $this->assertSame([], $this->historyIds());
    }

    public function test_an_anonymous_survey_that_stops_being_anonymous_still_reports_nothing(): void
    {
        $user = UserModel::factory()->create();
        $survey = $this->activitySurvey(['anonymous' => true]);
        // What an anonymous survey keeps: the day, no person, and who already answered apart
        $anonymous = $this->activityResponse($survey);
        $this->participation($survey, $user);

        // The domain refuses the change...
        try {
            $this->app->make(SurveyRepositoryInterface::class)
                ->findOrFail(SurveyId::fromString($survey->id))
                ->changeAnonymity(false, hasResponses: false);
            $this->fail('An anonymous survey can never become named.');
        } catch (InvalidSurveyStateException) {
            $this->addToAssertionCount(1);
        }

        // ...and even when the column is flipped by hand, nothing leaks: the responses it holds
        // never had a person, and the participations are never read
        SurveyModel::query()->whereKey($survey->id)->update(['anonymous' => false]);

        $this->assertSame([], $this->activitySource()->facts($anonymous->id));
        $this->assertSame([], $this->historyIds());
    }

    public function test_a_named_survey_turned_anonymous_stops_counting_what_it_held(): void
    {
        $response = $this->activityResponse($survey = $this->activitySurvey(), UserModel::factory()->create());
        $this->assertCount(1, $this->activitySource()->facts($response->id));

        $survey->update(['anonymous' => true]);

        $this->assertSame([], $this->activitySource()->facts($response->id));
        $this->assertSame([], $this->historyIds());
    }

    public function test_facts_and_history_are_the_same_whoever_is_signed_in(): void
    {
        $author = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $response = $this->activityResponse($this->activitySurvey(), $author);

        $asGuest = [$this->activitySource()->facts($response->id), $this->historyIds()];
        $this->actingAs($author);
        $asAuthor = [$this->activitySource()->facts($response->id), $this->historyIds()];
        $this->actingAs($other);
        $asOther = [$this->activitySource()->facts($response->id), $this->historyIds()];

        $this->assertEquals($asGuest, $asAuthor);
        $this->assertEquals($asGuest, $asOther);
        $this->assertSame([$response->id], $asGuest[1]);
    }

    public function test_the_php_rule_and_the_query_agree_on_every_combination(): void
    {
        $counts = $this->app->make(CountedResponses::class);

        foreach (['draft', 'pending_review', 'open', 'closed'] as $status) {
            foreach (['all_users', 'association_members', 'roles', 'subject'] as $audience) {
                foreach ([false, true] as $anonymous) {
                    $survey = $this->activitySurvey(['status' => $status, 'audience_type' => $audience, 'anonymous' => $anonymous]);

                    $this->assertSame(
                        $counts->surveyCounts($status, $audience, $anonymous),
                        $counts->surveys()->whereKey($survey->id)->exists(),
                        "{$status} / {$audience} / ".($anonymous ? 'anonymous' : 'named'),
                    );
                }
            }
        }

        $this->assertTrue($counts->surveyCounts('open', 'all_users', false));
        $this->assertTrue($counts->surveyCounts('closed', 'all_users', false));
    }

    public function test_history_lists_each_counted_response_once_in_a_stable_order_with_an_opaque_cursor(): void
    {
        $ids = [];
        foreach (range(1, 5) as $_) {
            $ids[] = $this->createCountedActivitySubject();
        }
        sort($ids);
        $source = $this->activitySource();

        $first = $source->history(null, 2);
        $second = $source->history($first->nextCursor, 2);
        $third = $source->history($second->nextCursor, 2);

        $this->assertSame(array_slice($ids, 0, 2), array_map(static fn ($subject): string => $subject->subjectId, $first->subjects));
        $this->assertSame(array_slice($ids, 2, 2), array_map(static fn ($subject): string => $subject->subjectId, $second->subjects));
        $this->assertSame(array_slice($ids, 4), array_map(static fn ($subject): string => $subject->subjectId, $third->subjects));
        $this->assertNotNull($first->nextCursor);
        $this->assertNotNull($second->nextCursor);
        $this->assertNull($third->nextCursor);
        $this->assertStringNotContainsString($ids[1], $first->nextCursor);
    }

    public function test_a_page_that_ends_exactly_on_the_last_response_has_no_next_cursor(): void
    {
        $this->createCountedActivitySubject();
        $this->createCountedActivitySubject();

        $page = $this->activitySource()->history(null, 2);

        $this->assertCount(2, $page->subjects);
        $this->assertNull($page->nextCursor);
    }

    public function test_a_cursor_that_cannot_be_read_throws_instead_of_starting_again(): void
    {
        $this->createCountedActivitySubject();
        $source = $this->activitySource();
        $encode = static fn (string $payload): string => rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        foreach (['', 'not a cursor!', $encode('other|'.Str::uuid()), $encode('sv1|not-a-uuid'), $encode('sv1|')] as $cursor) {
            try {
                $source->history($cursor, 10);
                $this->fail("The cursor '{$cursor}' must not be read.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_limit_below_one_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->activitySource()->history(null, 0);
    }

    public function test_a_page_never_reads_more_than_the_most_the_core_asks_for(): void
    {
        $this->createCountedActivitySubject();

        DB::enableQueryLog();
        $page = $this->activitySource()->history(null, 100_000);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $page->subjects);
        // Laravel writes a limit into the SQL text, not into its bindings
        $sql = implode("\n", array_map(static fn (array $query): string => (string) $query['query'], array_filter($log, static fn (array $query): bool => str_contains((string) $query['query'], 'surveys_responses'))));
        $this->assertStringContainsString('limit '.(ActivityHistoryPageDTO::MAX_LIMIT + 1), $sql, 'One more than the page, to know whether there is a next one.');
        $this->assertStringNotContainsString('limit 100001', $sql);
    }

    public function test_a_page_costs_the_same_number_of_queries_however_many_responses_it_holds(): void
    {
        $count = function (): int {
            DB::enableQueryLog();
            $this->activitySource()->history(null, 50);
            $queries = count(DB::getQueryLog());
            DB::flushQueryLog();
            DB::disableQueryLog();

            return $queries;
        };

        $this->createCountedActivitySubject();
        $count(); // warms what is read once per process (settings, zone)
        $few = $count();
        foreach (range(1, 8) as $_) {
            $this->createCountedActivitySubject();
        }

        $this->assertSame($few, $count());
    }

    public function test_describe_gives_the_person_who_answered_the_title_and_the_link_to_the_survey(): void
    {
        $respondent = UserModel::factory()->create();
        $survey = $this->activitySurvey(['title' => 'Jornadas de otoño']);
        $response = $this->activityResponse($survey, $respondent);

        $described = $this->activitySource()->describe([$response->id], $respondent);

        $this->assertSame([$response->id], array_keys($described));
        $this->assertSame('Jornadas de otoño', $described[$response->id]->title);
        $this->assertSame('/encuestas/'.$survey->id, $described[$response->id]->url);
    }

    public function test_describe_never_tells_somebody_else_who_answered_even_when_they_were_asked_to_answer_too(): void
    {
        $survey = $this->activitySurvey();
        $response = $this->activityResponse($survey, UserModel::factory()->create());
        $recipient = UserModel::factory()->create();
        $this->invite($survey, $recipient);
        $manager = $this->userWith(['surveys:surveys.manage', 'surveys:surveys.moderate']);

        $this->assertSame([], $this->activitySource()->describe([$response->id], null));
        $this->assertSame([], $this->activitySource()->describe([$response->id], $recipient));
        $this->assertSame([], $this->activitySource()->describe([$response->id], UserModel::factory()->create()));
        // Managing surveys is not reading their results
        $this->assertSame([], $this->activitySource()->describe([$response->id], $manager));
    }

    public function test_describe_gives_the_title_without_a_link_to_whoever_may_read_the_results(): void
    {
        $reader = $this->userWith(['surveys:surveys.view_results']);
        $survey = $this->activitySurvey(['title' => 'Jornadas de otoño']);
        $response = $this->activityResponse($survey, UserModel::factory()->create());

        $described = $this->activitySource()->describe([$response->id], $reader);

        $this->assertSame('Jornadas de otoño', $described[$response->id]->title);
        $this->assertNull($described[$response->id]->url);
    }

    public function test_describe_asks_whether_the_results_may_be_read_once_per_survey(): void
    {
        $reader = $this->userWith(['surveys:surveys.view_results']);
        $survey = $this->activitySurvey();
        $ids = [];
        foreach (range(1, 4) as $_) {
            $ids[] = $this->activityResponse($survey, UserModel::factory()->create())->id;
        }
        $asked = 0;
        Gate::after(function () use (&$asked): void {
            $asked++;
        });

        $described = $this->activitySource()->describe($ids, $reader);

        $this->assertCount(4, $described);
        $this->assertSame(1, $asked);
    }

    public function test_describe_never_answers_for_a_response_that_does_not_count_not_even_to_its_own_person(): void
    {
        $person = UserModel::factory()->create();
        $reader = $this->userWith(['surveys:surveys.view_results']);
        $anonymous = $this->activityResponse($this->activitySurvey(['anonymous' => true]));
        $forced = $this->activityResponse($this->activitySurvey(['anonymous' => true]), $person);
        $restricted = $this->activityResponse($this->activitySurvey(['audience_type' => 'roles']), $person);

        $this->assertSame([], $this->activitySource()->describe([$anonymous->id, $forced->id, $restricted->id], $person));
        $this->assertSame([], $this->activitySource()->describe([$anonymous->id, $forced->id, $restricted->id], $reader));
    }

    public function test_describe_reads_ids_in_lower_case_and_ignores_the_ones_that_are_not_uuids(): void
    {
        $respondent = UserModel::factory()->create();
        $response = $this->activityResponse($this->activitySurvey(), $respondent);

        $described = $this->activitySource()->describe([strtoupper($response->id), 'nope'], $respondent);

        $this->assertSame([$response->id], array_keys($described));
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'surveys', 'action' => Str::afterLast($key, '.'), 'module' => 'surveys'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
