<?php

declare(strict_types=1);

namespace Modules\Surveys\Database\Seeders;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Authorization\Services\MemberRoleServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\SurveysServiceProvider;
use Ramsey\Uuid\Uuid;

/**
 * A complete local environment for the module: the recommended templates and one of its own,
 * surveys and polls in every state, subject surveys of every kind of subject (as snapshots only,
 * no other module needed), responses of every kind of question and a year of closed surveys
 * whose values move over time, so trends have something to show.
 *
 * Surveys get ids derived from fixed names: running it again replaces them (their responses go
 * with them) instead of adding more. Responses keep random ids and only their day, as real ones.
 * Nothing is sent: everything is written through the repositories, not the services that notify.
 *
 * @phpstan-type Answers array<string, int|string|array<int|string, string>>
 */
final class DevelopmentSeeder extends Seeder
{
    /**
     * Enabling the module in a test runs every seeder, so the demo data would land in every module
     * test. Only the tests of this seeder turn it on.
     */
    public static bool $seedInTests = false;

    private const string ID_NAMESPACE = '6b1f2c3e-0d7a-4b58-9e61-2f4c8a9d3b17';

    private const string OWN_TEMPLATE = 'own.every_type';

    /**
     * Organizers' usual quality, so comparing them in trends shows a difference.
     *
     * @var array<string, float>
     */
    private const array ORGANIZER_BIAS = ['admin' => 0.05, 'john' => 0.1, 'jane' => 0.0, 'richard' => -0.1, 'mary' => -0.05];

    private const array COMMENTS = [
        'Muy buen ambiente en la mesa.',
        'Me gustaría repetir con el mismo grupo.',
        'Faltó algo de tiempo al final.',
        'Todo muy bien preparado.',
        'Mejor empezar un poco antes.',
        'El local se queda pequeño cuando hay muchas mesas.',
        'Echo en falta más partidas para gente nueva.',
    ];

    private DateTimeImmutable $now;

    /**
     * @var array<string, UserModel>
     */
    private array $people = [];

    /**
     * @var array<string, SurveyTemplate> catalog key (or OWN_TEMPLATE) => template
     */
    private array $templates = [];

    private SurveyRepositoryInterface $surveys;

    private SurveyTemplateRepositoryInterface $templateRepository;

    private ResponseStoreInterface $responses;

    /**
     * Only the core's services here: on install the seeders are built before the module's
     * provider binds its own, which run() resolves once it knows there is something to do.
     */
    public function __construct(
        private readonly TimezoneServiceInterface $timezone,
        private readonly MemberRoleServiceInterface $memberRole,
        private readonly AssociationMembershipServiceInterface $membership,
        private readonly AuthorizationServiceInterface $authorization,
    ) {}

    public function run(): void
    {
        // Demo data: the core runs every module seeder on install and update, production included
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (app()->runningUnitTests() && ! self::$seedInTests) {
            return;
        }

        if (! app()->bound(SurveyRepositoryInterface::class)) {
            app()->register(SurveysServiceProvider::class);
        }
        $this->surveys = app(SurveyRepositoryInterface::class);
        $this->templateRepository = app(SurveyTemplateRepositoryInterface::class);
        $this->responses = app(ResponseStoreInterface::class);

        // Through the container: the core runs seeders without one, so call() would build it bare
        app(RecommendedTemplatesSeeder::class)->run();

        // The same values on every run
        mt_srand(20261101);
        $this->now = now()->toDateTimeImmutable();

        $this->seedPeople();
        $this->loadTemplates();
        $this->seedGeneralSurveys();
        $this->seedSubjectSurveys();
        $this->seedHistory();

        mt_srand();
    }

    /**
     * The accounts every module's demo data shares, sixteen more people to answer, and the
     * core's admin when it exists (it organizes and has surveys to answer).
     */
    private function seedPeople(): void
    {
        $accounts = [
            'john' => ['John Doe', 'john.doe@example.local'],
            'jane' => ['Jane Doe', 'jane.doe@example.local'],
            'richard' => ['Richard Roe', 'richard.roe@example.local'],
            'mary' => ['Mary Major', 'mary.major@example.local'],
        ];
        foreach (range(1, 16) as $n) {
            $accounts[sprintf('p%02d', $n)] = [sprintf('Participante %02d', $n), sprintf('participante%02d@example.local', $n)];
        }

        foreach ($accounts as $key => [$name, $email]) {
            $user = UserModel::query()->where('email', $email)->first();
            if (! $user instanceof UserModel) {
                $user = new UserModel;
                $user->forceFill([
                    'name' => $name,
                    'display_name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ])->save();
            }

            // Idempotent: also covers an account another module's seeder created
            $this->memberRole->assignTo($user);
            $this->people[$key] = $user;
        }

        $admin = UserModel::query()->where('email', 'admin@example.local')->first();
        $this->people['admin'] = $admin instanceof UserModel ? $admin : $this->people['john'];

        // Half of them are members of the association, for the surveys sent to members
        $memberRoleName = $this->membership->roleName();
        if ($memberRoleName !== null && RoleModel::query()->where('name', $memberRoleName)->exists()) {
            foreach (['jane', 'mary', 'p01', 'p02', 'p03', 'p04', 'p05', 'p06', 'p07', 'p08'] as $key) {
                if (! $this->people[$key]->roles()->where('name', $memberRoleName)->exists()) {
                    $this->authorization->assignRole($this->people[$key], $memberRoleName);
                }
            }
        }
    }

    private function loadTemplates(): void
    {
        foreach (SurveyTemplateModel::query()->whereNotNull('catalog_key')->get() as $model) {
            $this->templates[(string) $model->catalog_key] = $this->templateRepository->findOrFail(SurveyTemplateId::fromString($model->id));
        }

        $own = new SurveyTemplate(
            SurveyTemplateId::fromString($this->uuid(self::OWN_TEMPLATE)),
            'Plantilla de ejemplo con todos los tipos',
            'Una pregunta de cada tipo, para probar el editor, los resultados y la exportación.',
            SurveyKind::Form,
            SurveyDefinition::fromArray($this->everyTypeDefinition()),
        );
        $this->templateRepository->save($own);
        $this->templates[self::OWN_TEMPLATE] = $own;
    }

    /**
     * @return array<string, mixed>
     */
    private function everyTypeDefinition(): array
    {
        $options = static fn (array $labels): array => array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], array_keys($labels), array_values($labels));
        $question = static fn (string $key, string $type, string $title, array $extra = []): array => [
            'id' => $key, 'key' => $key, 'type' => $type, 'title' => $title, 'help' => null, 'required' => false, ...$extra,
        ];

        return ['sections' => [
            ['id' => 'texts', 'title' => 'Textos y opciones', 'description' => null, 'questions' => [
                $question('nickname', 'short_text', '¿Cómo quieres que te llamemos?', ['required' => true]),
                $question('story', 'paragraph', 'Cuéntanos tu mejor partida'),
                $question('genre', 'single_choice', 'Género favorito', ['allow_other' => true, 'options' => $options(['fantasy' => 'Fantasía', 'horror' => 'Terror', 'science_fiction' => 'Ciencia ficción'])]),
                $question('formats', 'multiple_choice', 'Formatos que te gustan', ['allow_other' => true, 'options' => $options(['one_shot' => 'Partida suelta', 'campaign' => 'Campaña', 'tournament' => 'Torneo'])]),
                $question('day', 'dropdown', 'Día preferido', ['options' => $options(['friday' => 'Viernes', 'saturday' => 'Sábado', 'sunday' => 'Domingo'])]),
            ]],
            ['id' => 'ratings', 'title' => 'Valoraciones y fechas', 'description' => null, 'questions' => [
                $question('venue', 'linear_scale', 'El local', ['scale' => ['min' => 1, 'max' => 5, 'min_label' => 'Mejorable', 'max_label' => 'Excelente']]),
                $question('overall', 'star_rating', 'Valoración general', ['stars' => 5]),
                $question('next_meeting', 'date', '¿Qué día propones para la próxima quedada?'),
                $question('start_time', 'time', '¿A qué hora empezamos?'),
                $question('aspects', 'grid', 'Valora estos aspectos', ['grid' => [
                    'rows' => $options(['games' => 'Partidas', 'people' => 'Gente', 'venue' => 'Local']),
                    'columns' => $options(['improvable' => 'Mejorable', 'good' => 'Bien', 'very_good' => 'Muy bien']),
                ]]),
            ]],
        ]];
    }

    /**
     * Writes a survey with the id of its name, replacing the one an earlier run left.
     */
    private function store(Survey $survey): Survey
    {
        SurveyModel::query()->whereKey($survey->id()->value)->delete();
        $this->surveys->save($survey);

        return $survey;
    }

    private function surveyId(string $name): SurveyId
    {
        return SurveyId::fromString($this->uuid("survey.{$name}"));
    }

    private function uuid(string $name): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, $name)->toString();
    }

    /**
     * @param  list<string>  $organizers  keys of $people
     */
    private function snapshot(string $name, string $type, string $title, DateTimeImmutable $startsAt, int $hours, array $organizers, ?string $gameSystem): SubjectSnapshot
    {
        return new SubjectSnapshot(
            sourceType: $type,
            subjectId: $this->uuid("subject.{$name}"),
            title: $title,
            startsAt: $startsAt,
            endsAt: $startsAt->modify("+{$hours} hours"),
            organizerIds: array_map(fn (string $key): string => (string) $this->people[$key]->id, $organizers),
            attributes: $gameSystem === null ? [] : [['key' => 'game_system', 'label' => 'Sistema de juego', 'value' => $gameSystem]],
            url: null,
        );
    }

    /**
     * The instant of a wall time in the display zone, some days from now (negative: ago).
     */
    private function at(int $days, string $time = '18:00'): DateTimeImmutable
    {
        $day = $this->timezone->toDisplay($this->now)->modify("{$days} days")->format('Y-m-d');

        return $this->timezone->fromDisplayWallTime("{$day} {$time}");
    }

    /**
     * The instant of a wall time in the display zone, on a day of a month some months ago.
     */
    private function monthsAgo(int $months, int $day, string $time = '18:00'): DateTimeImmutable
    {
        $month = $this->timezone->toDisplay($this->now)->modify('first day of this month')->modify("-{$months} months");

        return $this->timezone->fromDisplayWallTime(sprintf('%s-%02d %s', $month->format('Y-m'), $day, $time));
    }

    /**
     * @param  list<string>  $keys  keys of $people
     */
    private function addRecipients(Survey $survey, array $keys): void
    {
        foreach ($keys as $key) {
            SurveyRecipientModel::query()->create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey->id()->value,
                'user_id' => $this->people[$key]->id,
                'source' => 'audience',
                'notified_at' => $survey->opensAt(),
            ]);
        }
    }

    /**
     * One response, as an account would send it (and, on named surveys, under its name).
     * With no person it is what the open public link stores: no participation, not verified.
     *
     * @param  Answers  $answers  question key => value (see toValues())
     */
    private function respond(Survey $survey, ?string $person, array $answers, DateTimeImmutable $on): void
    {
        $userId = $person === null ? null : (string) $this->people[$person]->id;

        $this->responses->record(
            $survey->id(),
            $userId,
            null,
            $on,
            $survey->isAnonymous() ? null : $userId,
            null,
            $this->toValues($survey->definition(), $answers),
        );
    }

    /**
     * Values by question key (or id, for questions without one): a number for scales and stars; an option id, or ['other' => text],
     * for single choice and dropdown; option ids (and 'other' => text) for checkboxes; row id =>
     * column id for grids; text for free text, dates (Y-m-d) and times (H:i).
     *
     * @param  Answers  $answers
     * @return list<AnswerValue>
     */
    private function toValues(SurveyDefinition $definition, array $answers): array
    {
        $values = [];
        foreach ($definition->questions() as $question) {
            // Questions added by hand have no key: they are answered by id
            $answer = $answers[$question->key ?? $question->id] ?? null;
            if ($answer === null) {
                continue;
            }

            $id = $question->id;
            if (is_int($answer)) {
                $values[] = new AnswerValue($id, number: $answer);

                continue;
            }

            if (is_string($answer)) {
                $values[] = $question->type->hasOptions() ? new AnswerValue($id, optionId: $answer) : new AnswerValue($id, text: $answer);

                continue;
            }

            foreach ($answer as $index => $item) {
                $values[] = match (true) {
                    $question->type === QuestionType::Grid => new AnswerValue($id, optionId: $item, rowId: (string) $index),
                    $index === 'other' => new AnswerValue($id, text: $item),
                    default => new AnswerValue($id, optionId: $item),
                };
            }
        }

        return $values;
    }

    /**
     * Plausible answers to every keyed question of a definition. $quality (0 to 1) pulls ratings
     * and ordered choices up or down; the rest varies at random, the same on every run.
     *
     * @return Answers
     */
    private function answersFor(SurveyDefinition $definition, float $quality, DateTimeImmutable $answeredOn): array
    {
        $answers = [];
        foreach ($definition->questions() as $question) {
            if ($question->key === null || (! $question->required && mt_rand(1, 100) > 85)) {
                continue;
            }

            $answer = $this->answerFor($question, $quality, $answeredOn);
            if ($answer !== null) {
                $answers[$question->key] = $answer;
            }
        }

        return $answers;
    }

    /**
     * @return int|string|array<int|string, string>|null
     */
    private function answerFor(Question $question, float $quality, DateTimeImmutable $answeredOn): int|string|array|null
    {
        $options = array_map(static fn ($option): string => $option->id, $question->options);

        return match ($question->type) {
            QuestionType::StarRating => $this->rating(1, $question->stars ?? 5, $quality),
            QuestionType::LinearScale => $this->rating($question->scale->min ?? 1, $question->scale->max ?? 5, $quality),
            QuestionType::SingleChoice, QuestionType::Dropdown => $this->choose($question, $options, $quality),
            QuestionType::MultipleChoice => $this->chooseSeveral($question, $options),
            QuestionType::Grid => $this->gridAnswer($question, $quality),
            QuestionType::ShortText, QuestionType::Paragraph => mt_rand(1, 100) <= 45 ? self::COMMENTS[mt_rand(0, count(self::COMMENTS) - 1)] : null,
            QuestionType::Date => $this->timezone->toDisplay($answeredOn)->modify('+'.mt_rand(7, 40).' days')->format('Y-m-d'),
            QuestionType::Time => ['17:00', '17:30', '18:00', '19:00'][mt_rand(0, 3)],
        };
    }

    private function rating(int $min, int $max, float $quality): int
    {
        $value = $min + ($max - $min) * $this->clamp($quality + (mt_rand(-20, 20) / 100));

        return (int) round($value);
    }

    /**
     * Options ordered from worst to best, for the questions where that order exists.
     *
     * @var array<string, list<string>>
     */
    private const array ORDERED = [
        'pacing' => ['too_slow', 'bit_slow', 'bit_fast', 'right'],
        'would_repeat' => ['no', 'maybe', 'yes'],
        'would_return' => ['no', 'maybe', 'yes'],
        'sequel' => ['no', 'maybe', 'yes'],
        'price' => ['expensive', 'fair', 'cheap'],
        'schedule' => ['very_tight', 'too_much_waiting', 'bit_tight', 'right'],
        'safety_tools' => ['missed', 'unknown', 'improvable', 'not_needed', 'worked'],
        'prior_info' => ['scarce', 'enough', 'complete'],
        'round_time' => ['short', 'plenty', 'right'],
        'terrain_density' => ['sparse', 'excessive', 'right'],
        'inducements' => ['too_generous', 'scarce', 'balanced'],
        'match_length' => ['long', 'short', 'right'],
    ];

    /**
     * @param  list<string>  $options
     * @return string|array<string, string>
     */
    private function choose(Question $question, array $options, float $quality): string|array
    {
        $ordered = self::ORDERED[(string) $question->key] ?? null;
        if ($ordered !== null) {
            $index = (int) round(($this->clamp($quality + (mt_rand(-25, 25) / 100))) * (count($ordered) - 1));

            return $ordered[$index];
        }

        if ($question->allowOther && mt_rand(1, 100) <= 10) {
            return ['other' => 'Otra cosa'];
        }

        return $options[mt_rand(0, count($options) - 1)];
    }

    /**
     * @param  list<string>  $options
     * @return array<int|string, string>
     */
    private function chooseSeveral(Question $question, array $options): array
    {
        shuffle($options);
        $chosen = array_slice($options, 0, mt_rand(1, min(3, count($options))));
        if ($question->allowOther && mt_rand(1, 100) <= 15) {
            $chosen['other'] = 'Partidas por foro';
        }

        return $chosen;
    }

    /**
     * @return array<string, string> row id => column id, better columns with more quality
     */
    private function gridAnswer(Question $question, float $quality): array
    {
        $columns = array_map(static fn ($column): string => $column->id, $question->grid->columns ?? []);
        $answer = [];
        foreach ($question->grid->rows ?? [] as $row) {
            $answer[$row->id] = $columns[(int) round($this->clamp($quality + (mt_rand(-30, 30) / 100)) * (count($columns) - 1))];
        }

        return $answer;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * How good a subject was some months ago: things get a bit better every month.
     */
    private function quality(int $monthsAgo, string $organizer): float
    {
        return 0.45 + 0.03 * (12 - $monthsAgo) + (self::ORGANIZER_BIAS[$organizer] ?? 0.0);
    }

    /**
     * Every account once (without the core's admin, the admin is John).
     *
     * @return list<string>
     */
    private function everyone(): array
    {
        $keys = array_keys($this->people);

        return $this->people['admin']->is($this->people['john']) ? array_values(array_diff($keys, ['admin'])) : $keys;
    }

    /**
     * People who answer, never the organizers, picked the same way on every run.
     *
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function respondents(int $count, array $exclude = []): array
    {
        $pool = array_values(array_diff(array_keys($this->people), ['admin', ...$exclude]));
        shuffle($pool);

        return array_slice($pool, 0, $count);
    }

    /**
     * A survey made from a template (or from its own content), with what most of them share.
     *
     * @param  list<string>  $roles
     */
    private function survey(
        string $name,
        string $title,
        SurveyStatus $status,
        ?string $template = null,
        ?SurveyDefinition $definition = null,
        SurveyKind $kind = SurveyKind::Form,
        bool $anonymous = true,
        ?SubjectSnapshot $subject = null,
        AudienceType $audience = AudienceType::AllUsers,
        array $roles = [],
        ?PublicLinkMode $publicLink = null,
        ?DateTimeImmutable $opensAt = null,
        int $openDays = 7,
        int $audienceSize = 0,
        bool $liveResults = false,
        ?string $createdBy = null,
        ?string $rejectionReason = null,
        ?string $submittedBy = null,
        ?string $reviewedBy = null,
        ?DateTimeImmutable $scheduledSendAt = null,
        ?DateTimeImmutable $dismissedAt = null,
        bool $endKey = false,
        ?DateTimeImmutable $subjectEndedAt = null,
        bool $sendOnEnd = false,
    ): Survey {
        $source = $template === null ? null : $this->templates[$template];
        $closesAt = $opensAt?->modify("+{$openDays} days");

        return new Survey(
            id: $this->surveyId($name),
            kind: $kind,
            title: $title,
            description: $source?->description(),
            definition: $definition ?? $source?->definition() ?? throw new \LogicException("Survey {$name} has no content"),
            anonymous: $anonymous,
            results: new ResultsSettings(liveResults: $liveResults),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : $audience,
            audienceRoles: $roles,
            publicLinkEnabled: $publicLink instanceof PublicLinkMode,
            templateId: $source?->id(),
            subject: $subject,
            status: $status,
            opensAt: $status === SurveyStatus::Draft || $status === SurveyStatus::PendingReview ? null : $opensAt,
            closesAt: $status === SurveyStatus::Draft || $status === SurveyStatus::PendingReview ? null : $closesAt,
            closedAt: $status === SurveyStatus::Closed ? $closesAt : null,
            createdBy: $createdBy,
            audienceSize: $audienceSize,
            publicToken: $publicLink instanceof PublicLinkMode && $status !== SurveyStatus::Draft ? hash('sha256', "public.{$name}") : null,
            durationDays: $openDays,
            unreviewedChanges: $status === SurveyStatus::PendingReview,
            lastChangedBy: $submittedBy,
            rejectionReason: $rejectionReason,
            submittedBy: $submittedBy,
            submittedForReviewAt: $submittedBy === null ? null : $this->at(-1, '10:00'),
            reviewedBy: $reviewedBy,
            reviewedAt: $reviewedBy === null ? null : $this->at(0, '09:00'),
            scheduledSendAt: $scheduledSendAt,
            dismissedAt: $dismissedAt,
            subjectEndKey: $endKey && $subject instanceof SubjectSnapshot ? "{$subject->sourceType}:{$subject->subjectId}" : null,
            subjectEndedAt: $subjectEndedAt,
            sendOnEnd: $sendOnEnd,
            sendDelayHours: $sendOnEnd ? 12 : 0,
            publicLinkMode: $publicLink ?? PublicLinkMode::VerifiedEmail,
        );
    }

    /**
     * Answers $people (and, with $guests, that many through the open public link), on the days after
     * the survey opened.
     *
     * @param  list<string>  $people
     */
    private function answerAll(Survey $survey, array $people, float $quality, int $guests = 0): void
    {
        $opened = $survey->opensAt() ?? $this->now;
        $last = $survey->closedAt() ?? $this->now;

        foreach ([...$people, ...array_fill(0, $guests, null)] as $person) {
            $on = $opened->modify('+'.mt_rand(0, 4).' days');
            $this->respond($survey, $person, $this->answersFor($survey->definition(), $quality, $on), $on > $last ? $last : $on);
        }
    }

    /**
     * General surveys and polls of the board, one in each state.
     */
    private function seedGeneralSurveys(): void
    {
        $everyone = $this->everyone();
        $admin = (string) $this->people['admin']->id;

        $this->store($this->survey('general.interest.draft', 'Interés por actividades y sistemas (próxima temporada)', SurveyStatus::Draft, 'activity_interest', createdBy: $admin));

        $satisfaction = $this->store($this->survey('general.satisfaction.open', 'Satisfacción de los socios: este trimestre', SurveyStatus::Open, 'member_satisfaction', audience: AudienceType::AssociationMembers, publicLink: PublicLinkMode::VerifiedEmail, opensAt: $this->at(-3, '10:00'), openDays: 14, audienceSize: 10, createdBy: $admin));
        $this->addRecipients($satisfaction, ['jane', 'mary', 'p01', 'p02', 'p03', 'p04', 'p05', 'p06', 'p07', 'p08']);
        $this->answerAll($satisfaction, ['jane', 'p01', 'p02', 'p03', 'p05'], 0.8);

        $editors = UserModel::query()->whereHas('roles', static fn ($query) => $query->where('name', 'editor'))->pluck('email')->all();
        $availability = $this->store($this->survey('general.availability.open', 'Disponibilidad para las jornadas de primavera', SurveyStatus::Open, 'availability', anonymous: false, audience: AudienceType::Roles, roles: ['editor'], opensAt: $this->at(-1, '12:00'), audienceSize: count($editors), createdBy: $admin));
        $editorKeys = array_values(array_filter(array_keys($this->people), fn (string $key): bool => $key !== 'admin' && in_array($this->people[$key]->email, $editors, true)));
        $this->addRecipients($availability, $editorKeys);

        $everyType = $this->store($this->survey('general.every_type.open', 'Encuesta de ejemplo con todos los tipos', SurveyStatus::Open, self::OWN_TEMPLATE, publicLink: PublicLinkMode::Open, opensAt: $this->at(-5, '10:00'), openDays: 10, audienceSize: count($everyone), createdBy: $admin));
        $this->addRecipients($everyType, $everyone);
        foreach (['john', 'jane', 'richard', 'p01', 'p02', 'p03', 'p04', 'p05', 'p06'] as $i => $person) {
            $this->respond($everyType, $person, $this->everyTypeAnswers($i), $this->at(-4 + ($i % 4), '12:00'));
        }
        $this->respond($everyType, null, $this->everyTypeAnswers(9), $this->at(-1, '12:00'));
        $this->respond($everyType, null, $this->everyTypeAnswers(10), $this->at(0, '09:00'));

        $interest = $this->store($this->survey('general.interest.closed', 'Interés por actividades y sistemas', SurveyStatus::Closed, 'activity_interest', opensAt: $this->monthsAgo(1, 3, '10:00'), openDays: 14, audienceSize: 20, createdBy: $admin));
        $this->answerAll($interest, $this->respondents(14), 0.6);

        // Under the minimum of responses: its results stay hidden behind a notice
        $venue = $this->store($this->survey('general.venue.below_minimum', 'Valoración del local nuevo', SurveyStatus::Closed, definition: SurveyDefinition::fromArray(['sections' => [['id' => 'main', 'title' => null, 'description' => null, 'questions' => [
            ['id' => 'venue', 'key' => null, 'type' => 'linear_scale', 'title' => '¿Qué te parece el local nuevo?', 'help' => null, 'required' => true, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => 'Mal', 'max_label' => 'Muy bien']],
            ['id' => 'why', 'key' => null, 'type' => 'paragraph', 'title' => '¿Por qué?', 'help' => null, 'required' => false],
        ]]]]), opensAt: $this->monthsAgo(2, 10, '10:00'), audienceSize: 20, createdBy: $admin));
        $this->respond($venue, 'p09', ['venue' => 4], $this->monthsAgo(2, 11));
        $this->respond($venue, 'p10', ['venue' => 5], $this->monthsAgo(2, 12));

        $dates = array_map(fn (int $days): string => $this->timezone->format($this->at($days), 'd/m'), [12, 13, 19]);
        $poll = $this->store($this->survey('general.poll.open', '¿Qué fecha os viene mejor para las jornadas?', SurveyStatus::Open, definition: $this->pollDefinition('date', '¿Qué fecha os viene mejor para las jornadas?', ['first' => "Sábado {$dates[0]}", 'second' => "Domingo {$dates[1]}", 'third' => "Sábado {$dates[2]}"], multiple: true), kind: SurveyKind::Poll, opensAt: $this->at(-2, '20:00'), openDays: 5, audienceSize: count($everyone), liveResults: true, createdBy: $admin));
        $this->addRecipients($poll, $everyone);
        foreach (['john', 'jane', 'p01', 'p02', 'p03', 'p07'] as $i => $person) {
            $this->respond($poll, $person, ['date' => [['first', 'second'], ['first'], ['third'], ['first', 'third'], ['second'], ['first']][$i]], $this->at(-1));
        }

        $closedPoll = $this->store($this->survey('general.poll.closed', '¿Pasamos la tarde de juego al jueves?', SurveyStatus::Closed, definition: $this->pollDefinition('thursday', '¿Pasamos la tarde de juego al jueves?', ['yes' => 'Sí', 'no' => 'No', 'any' => 'Me da igual']), kind: SurveyKind::Poll, opensAt: $this->monthsAgo(1, 20, '20:00'), openDays: 3, audienceSize: 20, liveResults: true, createdBy: $admin));
        foreach ($this->respondents(14) as $i => $person) {
            $this->respond($closedPoll, $person, ['thursday' => ['yes', 'yes', 'no', 'any', 'yes'][$i % 5]], $this->monthsAgo(1, 21));
        }
    }

    /**
     * Surveys of each kind of subject in the states an organizer meets: answering, prepared ahead,
     * offered with a click, dismissed, scheduled, waiting for review and rejected. The admin
     * organizes most of them, so the organizer pages show something to whoever signs in as admin.
     */
    private function seedSubjectSurveys(): void
    {
        $table = $this->snapshot('table.open', 'game-table', 'La cripta olvidada', $this->at(-2), 4, ['admin'], 'Dungeons & Dragons 5th Edition');
        $open = $this->store($this->survey('table.open', 'La cripta olvidada', SurveyStatus::Open, 'game_table', subject: $table, opensAt: $this->at(-1, '10:00'), audienceSize: 6));
        $this->addRecipients($open, ['john', 'jane', 'p01', 'p02', 'p03', 'p04']);
        $this->answerAll($open, ['john', 'p01', 'p02', 'p03'], 0.8);

        $prepared = $this->snapshot('table.prepared', 'game-table', 'El faro de la costa', $this->at(3), 4, ['admin'], 'La llamada de Cthulhu');
        $this->store($this->survey('table.prepared', 'El faro de la costa', SurveyStatus::Draft, 'game_table', subject: $prepared, endKey: true, sendOnEnd: true));

        $offered = $this->snapshot('table.offered', 'game-table', 'Ladrones en la ciudad', $this->at(-1, '17:00'), 4, ['admin'], 'Pathfinder 2nd Edition');
        $this->store($this->survey('table.offered', 'Ladrones en la ciudad', SurveyStatus::Draft, 'game_table', subject: $offered, endKey: true, subjectEndedAt: $this->at(-1, '21:00')));

        $dismissed = $this->snapshot('table.dismissed', 'game-table', 'Partida de iniciación', $this->at(-6), 3, ['john'], 'Dungeons & Dragons 5th Edition');
        $this->store($this->survey('table.dismissed', 'Partida de iniciación', SurveyStatus::Draft, 'game_table', subject: $dismissed, dismissedAt: $this->at(-5, '10:00'), endKey: true, subjectEndedAt: $this->at(-6, '21:00')));

        // Made by its organizer on the site, waiting for a moderator
        $review = $this->snapshot('table.review', 'game-table', 'Noche de terror', $this->at(-3), 4, ['john'], 'La llamada de Cthulhu');
        $this->store($this->survey('table.review', 'Lo que más miedo dio', SurveyStatus::PendingReview, definition: $this->ownDefinition('¿Qué escena te dio más miedo?'), subject: $review, createdBy: (string) $this->people['john']->id, submittedBy: (string) $this->people['john']->id));

        $session = $this->snapshot('session.open', 'campaign-session', 'La corona rota: sesión 13', $this->at(-2, '17:00'), 4, ['admin'], 'Pathfinder 2nd Edition');
        $sessionSurvey = $this->store($this->survey('session.open', 'La corona rota: sesión 13', SurveyStatus::Open, 'campaign_session', subject: $session, opensAt: $this->at(-1, '10:00'), audienceSize: 5));
        $this->addRecipients($sessionSurvey, ['jane', 'richard', 'mary', 'p05', 'p06']);
        $this->answerAll($sessionSurvey, ['jane', 'richard', 'p05'], 0.75);

        $rejected = $this->snapshot('campaign.rejected', 'campaign', 'Ecos del abismo', $this->monthsAgo(4, 2), 4, ['richard'], 'Vampiro: la mascarada');
        $this->store($this->survey('campaign.rejected', 'Cierre de Ecos del abismo', SurveyStatus::Draft, definition: $this->ownDefinition('¿Con qué otra persona de la mesa no repetirías?'), subject: $rejected, createdBy: (string) $this->people['richard']->id, rejectionReason: 'Quita la pregunta sobre otras personas de la mesa: puede señalar a alguien. Pregunta por la campaña.', submittedBy: (string) $this->people['richard']->id, reviewedBy: (string) $this->people['admin']->id));

        $campaign = $this->snapshot('campaign.closed', 'campaign', 'Sombras sobre el puerto', $this->monthsAgo(6, 1), 4, ['admin'], 'Vampiro: la mascarada');
        $campaignSurvey = $this->store($this->survey('campaign.closed', 'Sombras sobre el puerto', SurveyStatus::Closed, 'campaign', subject: $campaign, opensAt: $this->monthsAgo(1, 8, '10:00'), audienceSize: 6));
        $this->answerAll($campaignSurvey, ['jane', 'mary', 'p05', 'p06', 'p07', 'p08'], 0.7);

        // Sent by itself twelve hours after the tournament ended
        $tournament = $this->snapshot('tournament.scheduled', 'tournament', 'Torneo de otoño de Warhammer 40.000', $this->at(0, '09:00'), 9, ['admin'], 'Warhammer 40.000');
        $this->store($this->survey('tournament.scheduled', 'Torneo de otoño de Warhammer 40.000', SurveyStatus::Draft, 'tournament_40k', subject: $tournament, scheduledSendAt: $this->at(1, '06:00'), endKey: true, subjectEndedAt: $this->at(0, '18:00'), sendOnEnd: true));

        $event = $this->snapshot('event.open', 'event', 'Jornadas de puertas abiertas', $this->at(-3, '10:00'), 9, ['admin'], null);
        $eventSurvey = $this->store($this->survey('event.open', 'Jornadas de puertas abiertas', SurveyStatus::Open, 'association_event', subject: $event, opensAt: $this->at(-2, '10:00'), audienceSize: 10));
        $this->addRecipients($eventSurvey, ['john', 'jane', 'richard', 'mary', 'p09', 'p10', 'p11', 'p12', 'p13', 'p14']);
        $this->answerAll($eventSurvey, ['jane', 'p09', 'p10', 'p11', 'p12'], 0.8);
    }

    /**
     * A year of closed surveys from the recommended templates: game tables of several organizers
     * and systems, campaign sessions, tournaments of each game and the members' satisfaction.
     * One organizer's tables are named surveys with two answers: shown by themselves, they fall
     * under the minimum in any cut of their own.
     */
    private function seedHistory(): void
    {
        $tableOrganizers = ['john', 'jane', 'richard'];
        $systems = ['Dungeons & Dragons 5th Edition', 'Pathfinder 2nd Edition', 'La llamada de Cthulhu', 'Vampiro: la mascarada'];
        $tableTitles = ['La torre del mago', 'El bosque de los susurros', 'Asalto al tren', 'La mansión Corbitt', 'Mercaderes de Sal', 'La última cosecha'];

        foreach (range(12, 1) as $monthsAgo) {
            foreach ([0, 1] as $slot) {
                $n = (12 - $monthsAgo) * 2 + $slot;
                $organizer = $tableOrganizers[$n % 3];
                $title = $tableTitles[$n % count($tableTitles)];
                $this->closedSubjectSurvey("history.table.{$monthsAgo}.{$slot}", 'game-table', 'game_table', $title, $monthsAgo, 6 + 12 * $slot, $organizer, $systems[$n % 4], mt_rand(4, 7));
            }

            if ($monthsAgo % 3 === 0) {
                $this->closedSubjectSurvey("history.table.{$monthsAgo}.mary", 'game-table', 'game_table', 'Exploradores de la marca', $monthsAgo, 15, 'mary', 'Forbidden Lands', 2, anonymous: false);
            }

            $this->closedSubjectSurvey("history.session.{$monthsAgo}", 'campaign-session', 'campaign_session', sprintf('La corona rota: sesión %d', 13 - $monthsAgo), $monthsAgo, 10, 'jane', 'Pathfinder 2nd Edition', mt_rand(3, 5));

            $games = ['Warhammer 40.000', 'Warhammer Age of Sigmar', 'Kill Team', 'Blood Bowl'];
            $game = $games[$monthsAgo % 4];
            $this->closedSubjectSurvey("history.tournament.{$monthsAgo}", 'tournament', 'tournament', "Torneo mensual de {$game}", $monthsAgo, 20, $monthsAgo % 2 === 0 ? 'richard' : 'admin', $game, mt_rand(6, 10));
        }

        $variants = [
            'tournament_40k' => ['Warhammer 40.000', 'Liga de Warhammer 40.000', [11, 8, 5, 2]],
            'tournament_aos' => ['Warhammer Age of Sigmar', 'Torneo de Age of Sigmar', [10, 4]],
            'tournament_kill_team' => ['Kill Team', 'Torneo de Kill Team', [9, 3]],
            'tournament_blood_bowl' => ['Blood Bowl', 'Copa de Blood Bowl', [7, 1]],
        ];
        foreach ($variants as $template => [$game, $title, $months]) {
            foreach ($months as $monthsAgo) {
                $this->closedSubjectSurvey("history.{$template}.{$monthsAgo}", 'tournament', $template, $title, $monthsAgo, 25, 'richard', $game, mt_rand(6, 9));
            }
        }

        foreach ([12, 9, 6, 3] as $monthsAgo) {
            $survey = $this->store($this->survey("history.satisfaction.{$monthsAgo}", 'Satisfacción de los socios', SurveyStatus::Closed, 'member_satisfaction', audience: AudienceType::AssociationMembers, opensAt: $this->monthsAgo($monthsAgo, 1, '10:00'), openDays: 14, audienceSize: 16, createdBy: (string) $this->people['admin']->id));
            $this->answerAll($survey, $this->respondents(mt_rand(10, 14)), $this->quality($monthsAgo, 'admin'));
        }
    }

    private function closedSubjectSurvey(string $name, string $type, string $template, string $title, int $monthsAgo, int $day, string $organizer, ?string $gameSystem, int $responses, bool $anonymous = true): void
    {
        $startsAt = $this->monthsAgo($monthsAgo, $day);
        $subject = $this->snapshot($name, $type, $title, $startsAt, 4, [$organizer], $gameSystem);
        $survey = $this->store($this->survey($name, $title, SurveyStatus::Closed, $template, anonymous: $anonymous, subject: $subject, opensAt: $startsAt->modify('+1 day'), audienceSize: $responses + mt_rand(0, 3)));

        $quality = $this->quality($monthsAgo, $organizer);
        // The tournament fee went up half a year ago
        if ($type === 'tournament' && $monthsAgo <= 6) {
            $quality -= 0.1;
        }

        $this->answerAll($survey, $this->respondents($responses, [$organizer]), $quality);
    }

    /**
     * Content an organizer wrote on the site, without template or keys.
     */
    private function ownDefinition(string $question): SurveyDefinition
    {
        return SurveyDefinition::fromArray(['sections' => [['id' => 'main', 'title' => null, 'description' => null, 'questions' => [
            ['id' => 'rating', 'key' => null, 'type' => 'star_rating', 'title' => '¿Qué tal fue?', 'help' => null, 'required' => true, 'stars' => 5],
            ['id' => 'question', 'key' => null, 'type' => 'paragraph', 'title' => $question, 'help' => null, 'required' => false],
        ]]]]);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function pollDefinition(string $key, string $title, array $options, bool $multiple = false): SurveyDefinition
    {
        return SurveyDefinition::fromArray(['sections' => [['id' => 'main', 'title' => null, 'description' => null, 'questions' => [[
            'id' => $key,
            'key' => $key,
            'type' => $multiple ? 'multiple_choice' : 'single_choice',
            'title' => $title,
            'help' => null,
            'required' => true,
            'options' => array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], array_keys($options), array_values($options)),
        ]]]]]);
    }

    /**
     * Answers to the template with every type, varied by $i, with «Other» and free text.
     *
     * @return Answers
     */
    private function everyTypeAnswers(int $i): array
    {
        $nicknames = ['Johnny', 'Jane', 'Rich', 'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Invitado', 'Invitada'];

        return [
            'nickname' => $nicknames[$i],
            'story' => self::COMMENTS[$i % count(self::COMMENTS)],
            'genre' => $i % 4 === 3 ? ['other' => 'Western'] : ['fantasy', 'horror', 'science_fiction'][$i % 3],
            'formats' => $i % 5 === 4 ? ['campaign', 'other' => 'Partidas por foro'] : (['one_shot', 'campaign', 'tournament'][$i % 3] === 'tournament' ? ['tournament'] : ['one_shot', 'campaign']),
            'day' => ['friday', 'saturday', 'sunday'][$i % 3],
            'venue' => 2 + ($i % 4),
            'overall' => 3 + ($i % 3),
            'next_meeting' => $this->timezone->toDisplay($this->at(10 + $i))->format('Y-m-d'),
            'start_time' => ['17:00', '17:30', '18:00'][$i % 3],
            'aspects' => ['games' => ['good', 'very_good'][$i % 2], 'people' => 'very_good', 'venue' => ['improvable', 'good', 'very_good'][$i % 3]],
        ];
    }
}
