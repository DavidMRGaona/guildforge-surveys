<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use Illuminate\Database\Migrations\Migration;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Tests\Support\Modules\ModuleTestCase;

final class PublicLinkVerificationMigrationTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_earlier_responses_count_only_where_there_never_was_a_public_link(): void
    {
        $this->createExamplePeople();
        [$withoutLink] = $this->openForm(anonymous: true);
        $this->answer($withoutLink, 0);
        [$withLink] = $this->openForm(anonymous: true, publicLink: true);
        $this->answer($withLink, 0);

        /** @var Migration $migration */
        $migration = require __DIR__.'/../../../database/migrations/2026_11_03_000001_add_public_link_verification.php';
        $migration->down();
        $migration->up();

        $this->assertTrue(SurveyResponseModel::query()->where('survey_id', $withoutLink->value)->sole()->verified);
        $this->assertFalse(SurveyResponseModel::query()->where('survey_id', $withLink->value)->sole()->verified, 'An open link response cannot be told apart: it must not count.');
    }
}
