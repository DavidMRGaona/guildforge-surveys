<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A template with keyed questions of every kind trends know, and surveys made from it that
 * already opened, with their answers stored as the public link would.
 *
 * @phpstan-require-extends ModuleTestCase
 */
trait BuildsTrendSurveys
{
    private SurveyTemplateId $template;

    /**
     * Keyed questions of every kind trends know, a paragraph and a date with keys, and nothing else.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return ['sections' => [['id' => 's-1', 'title' => null, 'description' => null, 'questions' => [
            ['id' => 'q-sat', 'key' => 'satisfaction', 'type' => 'linear_scale', 'title' => '¿Te gustó?', 'help' => null, 'required' => false, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => null, 'max_label' => null]],
            ['id' => 'q-stars', 'key' => 'stars', 'type' => 'star_rating', 'title' => 'Estrellas', 'help' => null, 'required' => false, 'stars' => 5],
            ['id' => 'q-best', 'key' => 'best_part', 'type' => 'single_choice', 'title' => 'Lo mejor', 'help' => null, 'required' => false, 'allow_other' => true, 'options' => [['id' => 'o-1', 'label' => 'Historia'], ['id' => 'o-2', 'label' => 'Combate']]],
            ['id' => 'q-liked', 'key' => 'liked', 'type' => 'multiple_choice', 'title' => 'Qué te gustó', 'help' => null, 'required' => false, 'options' => [['id' => 'o-1', 'label' => 'Mapa'], ['id' => 'o-2', 'label' => 'Fichas']]],
            ['id' => 'q-grid', 'key' => 'aspects', 'type' => 'grid', 'title' => '¿Qué tal fue?', 'help' => null, 'required' => false, 'grid' => [
                'rows' => [['id' => 'r-1', 'label' => 'Ambiente'], ['id' => 'r-2', 'label' => 'Ritmo']],
                'columns' => [['id' => 'c-1', 'label' => 'Mal'], ['id' => 'c-2', 'label' => 'Bien']],
            ]],
            ['id' => 'q-comment', 'key' => 'comment', 'type' => 'paragraph', 'title' => 'Comentarios', 'help' => null, 'required' => false],
            ['id' => 'q-day', 'key' => 'day', 'type' => 'date', 'title' => 'Día', 'help' => null, 'required' => false],
        ]]]];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function saveTemplate(array $definition, string $name = 'Valoración de la partida'): SurveyTemplateId
    {
        $template = new SurveyTemplate(SurveyTemplateId::generate(), $name, null, SurveyKind::Form, SurveyDefinition::fromArray($definition));
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template->id();
    }

    /**
     * A survey made from the template that already opened (and by default closed), with an
     * extra question without key. Options: starts, opens, organizers, system, type, subject,
     * anonymous, status, live, hidden, definition, template.
     *
     * @param  array<string, mixed>  $options
     */
    private function survey(array $options = []): SurveyId
    {
        /** @var array<string, mixed> $definition */
        $definition = $options['definition'] ?? $this->definition();
        $definition['sections'][0]['questions'][] = ['id' => 'q-extra', 'key' => null, 'type' => 'linear_scale', 'title' => 'Extra', 'help' => null, 'required' => false, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => null, 'max_label' => null]];

        $starts = new DateTimeImmutable(is_string($options['starts'] ?? null) ? $options['starts'] : '2027-01-10T18:00:00+00:00');
        $opens = isset($options['opens']) && is_string($options['opens']) ? new DateTimeImmutable($options['opens']) : $starts->modify('+1 day');
        $status = $options['status'] ?? SurveyStatus::Closed;
        $subject = ($options['subject'] ?? true) === false ? null : new SubjectSnapshot(
            sourceType: is_string($options['type'] ?? null) ? $options['type'] : ExampleThingSource::TYPE,
            subjectId: (string) Str::uuid(),
            title: 'Partida de ejemplo',
            startsAt: $starts,
            endsAt: $starts->modify('+4 hours'),
            organizerIds: is_array($options['organizers'] ?? null) ? array_values($options['organizers']) : [],
            attributes: array_key_exists('system', $options) && $options['system'] === null ? [] : [['key' => 'game_system', 'label' => 'Sistema de juego', 'value' => is_string($options['system'] ?? null) ? $options['system'] : 'Rolemaster']],
            url: null,
        );

        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración de la partida',
            description: null,
            definition: SurveyDefinition::fromArray($definition),
            anonymous: ($options['anonymous'] ?? true) === true,
            results: new ResultsSettings(liveResults: ($options['live'] ?? false) === true, hiddenQuestionIds: is_array($options['hidden'] ?? null) ? array_values($options['hidden']) : []),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : AudienceType::AllUsers,
            templateId: $options['template'] ?? $this->template,
            subject: $subject,
            status: $status,
            opensAt: $status === SurveyStatus::Draft ? null : $opens,
            closesAt: $status === SurveyStatus::Open ? now()->addDays(7)->toDateTimeImmutable() : $opens->modify('+7 days'),
            closedAt: $status === SurveyStatus::Closed ? $opens->modify('+7 days') : null,
            audienceSize: 10,
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey->id();
    }

    /**
     * Stores responses as the public link would: no person on either side.
     * Keys: sat, stars (numbers), best (option id or ['other' => text]), liked (option ids), grid (row => column).
     *
     * @param  list<array<string, mixed>>  $responses
     */
    private function answerMany(SurveyId $id, array $responses): void
    {
        $store = $this->app->make(ResponseStoreInterface::class);

        foreach ($responses as $answers) {
            $values = [];
            foreach ($answers as $key => $answer) {
                match ($key) {
                    'sat' => $values[] = new AnswerValue('q-sat', number: is_int($answer) ? $answer : null),
                    'stars' => $values[] = new AnswerValue('q-stars', number: is_int($answer) ? $answer : null),
                    'best' => $values[] = is_array($answer)
                        ? new AnswerValue('q-best', text: is_string($answer['other'] ?? null) ? $answer['other'] : null)
                        : new AnswerValue('q-best', optionId: is_string($answer) ? $answer : null),
                    'liked' => array_push($values, ...array_map(static fn (mixed $option): AnswerValue => new AnswerValue('q-liked', optionId: is_string($option) ? $option : null), is_array($answer) ? $answer : [])),
                    'grid' => array_push($values, ...array_map(static fn (mixed $column, mixed $row): AnswerValue => new AnswerValue('q-grid', optionId: is_string($column) ? $column : null, rowId: (string) $row), is_array($answer) ? array_values($answer) : [], is_array($answer) ? array_keys($answer) : [])),
                    default => null,
                };
            }

            $store->record($id, null, null, new DateTimeImmutable('2027-06-01'), null, null, $values);
        }
    }

    /**
     * @return list<string> ids of people named «Persona 1», «Persona 2»…
     */
    private function people(int $count): array
    {
        return array_map(static fn (int $n): string => (string) UserModel::factory()->create(['name' => "Persona {$n}"])->id, range(1, $count));
    }
}
