<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether closing a survey mails a summary of its results. Existing surveys mail none: new
 * ones take the setting's default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->boolean('closing_summary')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropColumn('closing_summary');
        });
    }
};
