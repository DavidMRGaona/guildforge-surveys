<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyOpeningTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = $this->app->make(SurveyLifecycleService::class);
        ExampleThingSource::$audience = null;
    }

    public function test_all_users_means_every_active_account_at_opening(): void
    {
        $active = UserModel::factory()->count(2)->create();
        UserModel::factory()->create(['anonymized_at' => now()]);
        UserModel::factory()->create()->delete();
        $survey = $this->draft(AudienceType::AllUsers);

        $this->lifecycle->open($survey->id());
        UserModel::factory()->create();

        $this->assertEqualsCanonicalizing($active->pluck('id')->all(), $this->recipientUserIds($survey));
        $this->assertSame(2, SurveyModel::query()->findOrFail($survey->id()->value)->audience_size);
    }

    public function test_roles_means_their_active_holders(): void
    {
        $role = RoleModel::query()->create(['name' => 'junta-de-ejemplo', 'display_name' => 'Junta']);
        $holder = UserModel::factory()->create();
        $holder->roles()->attach($role->id);
        UserModel::factory()->create();

        $this->lifecycle->open(($survey = $this->draft(AudienceType::Roles, ['junta-de-ejemplo']))->id());

        $this->assertSame([$holder->id], $this->recipientUserIds($survey));
    }

    public function test_association_members_follow_the_configured_role(): void
    {
        $member = UserModel::factory()->create();
        $member->roles()->attach(RoleModel::query()->where('name', 'socio')->value('id'));
        UserModel::factory()->create();

        $this->lifecycle->open(($survey = $this->draft(AudienceType::AssociationMembers))->id());

        $this->assertSame([$member->id], $this->recipientUserIds($survey));
    }

    public function test_a_subject_survey_takes_its_audience_and_tokens_for_guests(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $player = UserModel::factory()->create();
        ExampleThingSource::$audience = new SurveyAudienceDTO([$player->id], [new SurveyGuestDTO('guest-1', 'Invitada de ejemplo', 'invitada@example.test')]);

        $id = $this->lifecycle->createForSubject(ExampleThingSource::TYPE, 'thing-1', 'Valora la partida', $this->definition(), anonymous: true, closesAt: null);
        $opened = $this->lifecycle->open($id);

        $survey = $this->app->make(SurveyRepositoryInterface::class)->findOrFail($id);
        $this->assertSame('Partida de ejemplo', $survey->subject()?->title);
        $this->assertSame([$player->id], $this->recipientUserIds($survey));
        $guest = SurveyRecipientModel::query()->where('guest_reference', 'guest-1')->sole();
        $this->assertSame('invitada@example.test', $guest->guest_email);
        $this->assertSame(hash('sha256', $opened->guestTokens['guest-1']), $guest->token_hash);
        $this->assertSame(2, SurveyModel::query()->findOrFail($id->value)->audience_size);
    }

    public function test_an_unknown_subject_cannot_get_a_survey(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->expectException(SubjectNotFoundException::class);

        $this->lifecycle->createForSubject(ExampleThingSource::TYPE, 'missing', 'Valora la partida', $this->definition(), anonymous: true, closesAt: null);
    }

    public function test_the_public_link_token_is_kept_encrypted_and_hashed(): void
    {
        $survey = $this->draft(AudienceType::AllUsers, publicLink: true);

        $opened = $this->lifecycle->open($survey->id());

        $this->assertIsString($opened->publicToken);
        $model = SurveyModel::query()->findOrFail($survey->id()->value);
        $this->assertSame(hash('sha256', $opened->publicToken), $model->public_token_hash);
        $this->assertSame($opened->publicToken, $model->public_token);
        $this->assertStringNotContainsString($opened->publicToken, (string) DB::table('surveys_surveys')->where('id', $survey->id()->value)->value('public_token'));
    }

    /**
     * @param  list<string>  $roles
     */
    private function draft(AudienceType $audience, array $roles = [], bool $publicLink = false): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: $this->definition(),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: $audience,
            audienceRoles: $roles,
            publicLinkEnabled: $publicLink,
            closesAt: new DateTimeImmutable('+7 days'),
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey;
    }

    /**
     * @return list<string>
     */
    private function recipientUserIds(Survey $survey): array
    {
        return array_values(SurveyRecipientModel::query()->where('survey_id', $survey->id()->value)->whereNotNull('user_id')->pluck('user_id')->all());
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
