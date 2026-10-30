<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A guest's link token, encrypted, so the reminder can send the same link again. It is cleared
 * with the guest's email when the survey closes; "already answered" keeps using token_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_recipients', function (Blueprint $table): void {
            $table->text('token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_recipients', function (Blueprint $table): void {
            $table->dropColumn('token');
        });
    }
};
