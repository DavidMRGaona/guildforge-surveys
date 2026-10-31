<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One response per address on the public link. A survey's link may ask for an email (surveys
 * that already had a link keep the open one). An address that asks for a link becomes a
 * recipient of its own source, kept out of the audience. A response says only whether it came
 * with a participation: the open link's do not count towards the minimum of an anonymous survey.
 *
 * Earlier responses are verified only on surveys that never had a public link, where every
 * response came with a participation. On the others an open link response cannot be told
 * apart, so none counts: their results wait for verified responses rather than show too early.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->string('public_link_mode')->default('open');
        });

        Schema::table('surveys_recipients', function (Blueprint $table): void {
            $table->string('source')->default('audience');
        });

        Schema::table('surveys_responses', function (Blueprint $table): void {
            $table->boolean('verified')->default(false);
        });

        DB::table('surveys_responses')
            ->whereIn('survey_id', DB::table('surveys_surveys')->where('public_link_enabled', false)->select('id'))
            ->update(['verified' => true]);
    }

    public function down(): void
    {
        Schema::table('surveys_responses', function (Blueprint $table): void {
            $table->dropColumn('verified');
        });

        Schema::table('surveys_recipients', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropColumn('public_link_mode');
        });
    }
};
