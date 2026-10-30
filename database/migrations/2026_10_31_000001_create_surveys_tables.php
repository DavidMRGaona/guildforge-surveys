<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Anonymity lives in this schema: responses and their answers keep the day they were sent and
 * no time, participations ("already answered") keep no date at all, and no column joins a
 * response to a participation or a recipient. Their ids are random (UUID v4) for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind');
            $table->json('definition');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('surveys_surveys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('definition');
            $table->string('status')->index();
            $table->boolean('anonymous');
            $table->json('results_settings');
            $table->string('audience_type');
            $table->json('audience_roles');
            $table->boolean('public_link_enabled')->default(false);
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->uuid('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('surveys_templates')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->json('subject_snapshot')->nullable();
            $table->unsignedInteger('audience_size')->default(0);
            $table->unsignedSmallInteger('reminder_hours_before')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('surveys_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys_surveys')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->string('guest_reference')->nullable();
            $table->string('guest_name')->nullable();
            // Encrypted, and cleared when the survey closes
            $table->text('guest_email')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unique(['survey_id', 'user_id']);
            $table->unique(['survey_id', 'guest_reference']);
        });

        Schema::create('surveys_participations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys_surveys')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->unique(['survey_id', 'user_id']);
            $table->unique(['survey_id', 'token_hash']);
        });

        Schema::create('surveys_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys_surveys')->cascadeOnDelete();
            $table->date('submitted_on');
            // Only on named surveys
            $table->uuid('user_id')->nullable();
            $table->string('guest_name')->nullable();
        });

        Schema::create('surveys_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('response_id')->constrained('surveys_responses')->cascadeOnDelete();
            $table->string('question_id');
            $table->string('option_id')->nullable();
            $table->string('row_id')->nullable();
            $table->text('text_value')->nullable();
            $table->integer('number_value')->nullable();
            $table->index(['question_id', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys_answers');
        Schema::dropIfExists('surveys_responses');
        Schema::dropIfExists('surveys_participations');
        Schema::dropIfExists('surveys_recipients');
        Schema::dropIfExists('surveys_surveys');
        Schema::dropIfExists('surveys_templates');
    }
};
