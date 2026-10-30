<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * An anonymous response must not be linkable to who sent it: no time anywhere, no ordered
 * ids, and nothing joining a response to a participation or a person.
 */
final class AnonymitySchemaTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_responses_and_answers_keep_no_time(): void
    {
        foreach (['surveys_responses', 'surveys_answers'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/_at$/', $column, "{$table}.{$column} would keep the time of an answer.");
            }
        }
        $this->assertContains('submitted_on', Schema::getColumnListing('surveys_responses'));
    }

    public function test_participations_keep_no_date_and_nothing_pointing_at_a_response(): void
    {
        $columns = Schema::getColumnListing('surveys_participations');

        $this->assertEqualsCanonicalizing(['id', 'survey_id', 'user_id', 'token_hash'], $columns);
    }

    public function test_response_answer_and_participation_ids_are_random_uuids(): void
    {
        $survey = $this->survey();
        $response = SurveyResponseModel::query()->create(['survey_id' => $survey->id, 'submitted_on' => '2026-11-10']);
        $answer = SurveyAnswerModel::query()->create(['response_id' => $response->id, 'question_id' => 'q-1', 'text_value' => 'Hola']);
        $participation = SurveyParticipationModel::query()->create(['survey_id' => $survey->id, 'token_hash' => hash('sha256', 'token')]);

        foreach ([$response->id, $answer->id, $participation->id] as $id) {
            $this->assertTrue(Str::isUuid($id));
            $this->assertSame('4', $id[14], "{$id} is not a version 4 UUID: its first bits would carry the creation time.");
        }
    }

    public function test_a_guest_email_is_encrypted_at_rest(): void
    {
        $recipient = SurveyRecipientModel::query()->create([
            'survey_id' => $this->survey()->id,
            'guest_reference' => 'guest-1',
            'guest_name' => 'Invitada de ejemplo',
            'guest_email' => 'invitada@example.test',
            'token_hash' => hash('sha256', 'token'),
        ]);

        $raw = DB::table('surveys_recipients')->where('id', $recipient->id)->value('guest_email');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('invitada', $raw);
        $this->assertSame('invitada@example.test', $recipient->fresh()?->guest_email);
    }

    private function survey(): SurveyModel
    {
        return SurveyModel::query()->create([
            'kind' => 'form',
            'title' => 'Encuesta de ejemplo',
            'definition' => ['sections' => []],
            'status' => 'open',
            'anonymous' => true,
            'results_settings' => [],
            'audience_type' => 'all_users',
            'audience_roles' => [],
        ]);
    }
}
