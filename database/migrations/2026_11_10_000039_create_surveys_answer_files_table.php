<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys_answer_files', function (Blueprint $table): void {
            // The answer's value: random, so it orders the files without saying who sent which
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys_surveys')->cascadeOnDelete();
            $table->foreignUuid('response_id')->constrained('surveys_responses')->cascadeOnDelete();
            $table->string('question_id');
            $table->string('path');
            $table->string('content_type');
            $table->unsignedInteger('size');
            // Only on named surveys
            $table->string('original_name')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['survey_id', 'question_id']);
        });
    }

    /**
     * Without the table nothing points at the files any more: they go with it.
     */
    public function down(): void
    {
        $disk = config('modules.settings.surveys.files_disk', 'local');
        Storage::disk(is_string($disk) ? $disk : 'local')->deleteDirectory('surveys');

        Schema::dropIfExists('surveys_answer_files');
    }
};
