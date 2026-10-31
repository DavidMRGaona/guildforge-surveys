<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A subject's end survey may be prepared before the subject ends. subject_ended_at says when the
 * end arrived (null while it waits for it); send_on_end and send_delay_hours say how a prepared
 * survey goes out then. End surveys made before this change were made on the end itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->timestamp('subject_ended_at')->nullable();
            $table->boolean('send_on_end')->default(false);
            $table->unsignedInteger('send_delay_hours')->default(0);
        });

        DB::table('surveys_surveys')
            ->whereNotNull('subject_end_key')
            ->update(['subject_ended_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropColumn(['subject_ended_at', 'send_on_end', 'send_delay_hours']);
        });
    }
};
