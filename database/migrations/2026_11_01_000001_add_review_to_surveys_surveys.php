<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the organizers' web needs: review of content changed outside the panel, a duration counted
 * from opening, scheduled automatic sending and dismissed offers. subject_end_key ("<type>:<id>")
 * is only set on the survey made when a subject ends, so a repeated event never makes another;
 * nulls never collide in a unique index, in PostgreSQL or SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->boolean('unreviewed_changes')->default(false);
            $table->uuid('last_changed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_for_review_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('scheduled_send_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('subject_end_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropUnique(['subject_end_key']);
            $table->dropIndex(['scheduled_send_at']);
            $table->dropColumn([
                'duration_days',
                'unreviewed_changes',
                'last_changed_by',
                'rejection_reason',
                'submitted_by',
                'submitted_for_review_at',
                'reviewed_by',
                'reviewed_at',
                'scheduled_send_at',
                'dismissed_at',
                'subject_end_key',
            ]);
        });
    }
};
