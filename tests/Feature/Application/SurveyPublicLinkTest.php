<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyPublicLinkTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createExamplePeople();
        $this->lifecycle = $this->app->make(SurveyLifecycleService::class);
    }

    public function test_an_open_survey_hands_back_its_link_and_mode(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::Open);

        $link = $this->lifecycle->publicLink($id);

        $this->assertNotNull($link);
        $this->assertSame($this->publicToken($opened), $link->token);
        $this->assertSame(PublicLinkMode::Open, $link->mode);
        $this->assertSame(PublicLinkMode::VerifiedEmail, $this->lifecycle->publicLink($this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail)[0])?->mode);
    }

    public function test_there_is_no_link_without_one_or_once_responses_stop(): void
    {
        [$withoutLink] = $this->openPoll(anonymous: true);
        $this->assertNull($this->lifecycle->publicLink($withoutLink));

        [$closed] = $this->openPoll(anonymous: true, publicLink: true);
        $this->lifecycle->close($closed);
        $this->assertNull($this->lifecycle->publicLink($closed));

        [$pastItsDate] = $this->openPoll(anonymous: true, publicLink: true);
        $this->assertNull($this->lifecycle->publicLink($pastItsDate, now()->addDays(8)->toDateTimeImmutable()), 'Past its closing date, even before the scheduled close runs.');

        [$pending] = $this->openPoll(anonymous: true, publicLink: true);
        $surveys = $this->app->make(SurveyRepositoryInterface::class);
        $survey = $surveys->findOrFail($pending);
        $survey->markUnreviewedChanges($this->people[0]->id);
        $survey->submitForReview($this->people[0]->id, now()->toDateTimeImmutable());
        $surveys->save($survey);
        $this->assertNull($this->lifecycle->publicLink($pending), 'A survey waiting for review takes no responses.');
    }

    public function test_renewing_the_link_retires_the_old_one(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true);
        $old = $this->publicToken($opened);

        $renewed = $this->lifecycle->renewPublicLink($id);

        $this->assertNotSame($old, $renewed->token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $renewed->token);
        $this->assertSame($renewed->token, $this->lifecycle->publicLink($id)?->token);
        $this->assertSame(hash('sha256', $renewed->token), SurveyModel::query()->findOrFail($id->value)->public_token_hash);

        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::publicLink($renewed->token), ['q-1' => 'o-1']);

        $this->expectException(NotInvitedException::class);

        $submissions->submit($id, Respondent::publicLink($old), ['q-1' => 'o-1']);
    }

    public function test_a_survey_that_takes_no_responses_keeps_its_link(): void
    {
        [$id] = $this->openPoll(anonymous: true, publicLink: true);
        $this->lifecycle->close($id);

        $this->expectException(InvalidSurveyStateException::class);

        $this->lifecycle->renewPublicLink($id);
    }

    private function publicToken(OpenedSurveyDTO $opened): string
    {
        $this->assertIsString($opened->publicToken);

        return $opened->publicToken;
    }
}
