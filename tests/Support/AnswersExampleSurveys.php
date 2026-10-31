<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use LogicException;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Opens example surveys for three example people (and one guest on polls) and answers them.
 *
 * @phpstan-require-extends ModuleTestCase
 */
trait AnswersExampleSurveys
{
    /** @var list<UserModel> */
    private array $people = [];

    private function createExamplePeople(): void
    {
        ExampleThingSource::$audience = null;
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->people = [
            UserModel::factory()->create(['name' => 'Persona A']),
            UserModel::factory()->create(['name' => 'Persona B']),
            UserModel::factory()->create(['name' => 'Persona C']),
        ];
    }

    /**
     * Answers every question of the every-type form; overrides replace single answers.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function answer(SurveyId $id, int $person, array $overrides = []): void
    {
        $answers = array_merge([
            'q-short' => 'Algo', 'q-paragraph' => 'Más', 'q-single' => 'o-2', 'q-multiple' => ['o-1'], 'q-dropdown' => 'o-1',
            'q-scale' => 3, 'q-stars' => 3, 'q-date' => '2030-01-01', 'q-time' => '10:00', 'q-grid' => ['r-1' => 'c-1', 'r-2' => 'c-1'],
        ], $overrides);

        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->people[$person]->id), $answers);
    }

    /**
     * The every-type form (SurveyDefinitionTest::everyTypeDefinition()) sent to the three people.
     *
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openForm(bool $anonymous, ?ResultsSettings $results = null): array
    {
        ExampleThingSource::$audience = null;

        return $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()), $anonymous, $results ?? ResultsSettings::defaultsFor(SurveyKind::Form));
    }

    /**
     * A poll with question q-1 (o-1 «Viernes», o-2 «Sábado») for the three people and guest-1.
     *
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openPoll(bool $anonymous, bool $audience = true, bool $publicLink = false): array
    {
        ExampleThingSource::$audience = $audience
            ? new SurveyAudienceDTO(array_map(static fn (UserModel $user): string => $user->id, $this->people), [new SurveyGuestDTO('guest-1', 'Invitada de ejemplo', 'invitada@example.test')])
            : new SurveyAudienceDTO([], []);
        $definition = SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
            'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué día?', 'required' => true,
            'options' => [['id' => 'o-1', 'label' => 'Viernes'], ['id' => 'o-2', 'label' => 'Sábado']],
        ]]]]]);

        return $this->openExample(SurveyKind::Poll, $definition, $anonymous, ResultsSettings::defaultsFor(SurveyKind::Poll), $publicLink);
    }

    /**
     * Opens any definition for the three people (polls through openPoll() also reach the guest).
     *
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openExample(SurveyKind $kind, SurveyDefinition $definition, bool $anonymous, ResultsSettings $results, bool $publicLink = false): array
    {
        ExampleThingSource::$audience ??= new SurveyAudienceDTO(array_map(static fn (UserModel $user): string => $user->id, $this->people), []);
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: $kind,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: $definition,
            anonymous: $anonymous,
            results: $results,
            audienceType: AudienceType::Subject,
            publicLinkEnabled: $publicLink,
            subject: SubjectSnapshotFactory::fromDto((new ExampleThingSource)->find('thing-1') ?? throw new LogicException('The example thing exists.')),
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return [$survey->id(), $this->app->make(SurveyLifecycleService::class)->open($survey->id(), notify: false)];
    }
}
