<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who organized each subject survey, copied from its snapshot, so a profile can list them with
 * a plain indexed query. Access is still decided live by the subject's source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys_organizers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys_surveys')->cascadeOnDelete();
            $table->uuid('user_id')->index();
            $table->unique(['survey_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys_organizers');
    }
};
