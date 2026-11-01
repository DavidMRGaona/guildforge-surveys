<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The survey chosen in a subject's own form for when it ends, when it is not its type's
 * template: another template, or none. A survey of its own is the subject's end survey itself
 * (subject_end_key), so it needs no row here. No foreign key to the template: a deleted one
 * falls back to the type's template when the subject ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys_subject_end_choices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('mode');
            $table->uuid('template_id')->nullable();
            $table->uuid('chosen_by')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys_subject_end_choices');
    }
};
