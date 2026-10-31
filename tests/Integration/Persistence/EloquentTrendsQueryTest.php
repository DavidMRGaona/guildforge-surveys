<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentTrendsQuery;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Per-survey aggregates: the trends service sums them by period and series itself.
 */
final class EloquentTrendsQueryTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_counts_are_grouped_by_survey_and_question(): void
    {
        $first = $this->survey();
        $second = $this->survey();
        $this->respond($first, [new AnswerValue('q-scale', number: 4), new AnswerValue('q-single', optionId: 'o-1'), new AnswerValue('q-multiple', optionId: 'o-1'), new AnswerValue('q-multiple', optionId: 'o-2')]);
        $this->respond($first, [new AnswerValue('q-scale', number: 4), new AnswerValue('q-single', text: 'Otra cosa')]);
        $this->respond($second, [new AnswerValue('q-scale', number: 2)]);

        $query = new EloquentTrendsQuery;
        $ids = [$first->value, $second->value];
        $questions = ['q-scale', 'q-single', 'q-multiple'];

        $this->assertSame($this->sorted([$first->value => 2, $second->value => 1]), $this->sorted($query->responseCounts($ids)));
        $this->assertSame(
            $this->sorted([$first->value => ['q-multiple' => ['all' => 1, 'verified' => 1], 'q-scale' => ['all' => 2, 'verified' => 2], 'q-single' => ['all' => 2, 'verified' => 2]], $second->value => ['q-scale' => ['all' => 1, 'verified' => 1]]]),
            $this->sorted($query->answeredCounts($ids, $questions)),
        );
        $this->assertSame($this->sorted([$first->value => ['q-scale' => [4 => 2]], $second->value => ['q-scale' => [2 => 1]]]), $this->sorted($query->numberCounts($ids, $questions)));
        $this->assertSame(
            [$first->value => ['q-multiple' => ['o-1' => 1, 'o-2' => 1], 'q-single' => [Submission::OTHER => 1, 'o-1' => 1]]],
            $this->sorted($query->optionCounts($ids, $questions)),
        );
    }

    public function test_grid_cells_by_survey(): void
    {
        $survey = $this->survey();
        $this->respond($survey, [new AnswerValue('q-grid', optionId: 'c-1', rowId: 'r-1'), new AnswerValue('q-grid', optionId: 'c-2', rowId: 'r-2')]);
        $this->respond($survey, [new AnswerValue('q-grid', optionId: 'c-1', rowId: 'r-1')]);

        $query = new EloquentTrendsQuery;

        $this->assertSame([$survey->value => ['q-grid' => ['r-1' => ['c-1' => 2], 'r-2' => ['c-2' => 1]]]], $this->sorted($query->cellCounts([$survey->value], ['q-grid'])));
        // Grid cells are not options of the question
        $this->assertSame([], $query->optionCounts([$survey->value], ['q-grid']));
    }

    public function test_more_than_one_batch_of_surveys(): void
    {
        $surveys = [$this->survey(), $this->survey(), $this->survey()];
        foreach ($surveys as $survey) {
            $this->respond($survey, [new AnswerValue('q-scale', number: 3)]);
        }

        $query = new EloquentTrendsQuery(batchSize: 2);
        $ids = array_map(static fn (SurveyId $id): string => $id->value, $surveys);

        $this->assertCount(3, $query->responseCounts($ids));
        $this->assertCount(3, $query->numberCounts($ids, ['q-scale']));
        $this->assertSame([], $query->responseCounts([]));
    }

    public function test_user_names_skip_unknown_accounts(): void
    {
        $ana = UserModel::factory()->create(['name' => 'Persona 1']);
        $query = new EloquentTrendsQuery;

        $this->assertSame([(string) $ana->id => 'Persona 1'], $query->userNames([(string) $ana->id, '00000000-0000-4000-8000-000000000000']));
    }

    private function survey(): SurveyId
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: new ResultsSettings,
            audienceType: AudienceType::Subject,
            subject: new SubjectSnapshot('example-thing', 'thing-1', 'Partida de ejemplo', null, null, [], [], null),
            status: SurveyStatus::Closed,
            opensAt: new DateTimeImmutable('2027-01-10'),
            closedAt: new DateTimeImmutable('2027-01-17'),
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey->id();
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    private function respond(SurveyId $id, array $values): void
    {
        $this->app->make(ResponseStoreInterface::class)->record($id, null, null, new DateTimeImmutable('2027-01-12'), null, null, $values);
    }

    /**
     * Keys sorted at every level, so the assertions do not depend on row order.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as &$inner) {
            if (is_array($inner)) {
                $inner = $this->sorted($inner);
            }
        }

        return $value;
    }
}
