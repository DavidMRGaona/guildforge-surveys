<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The activity source (core#774) reads the responses of one account, and removing or anonymizing
 * an account asks for them: without an index each of those would read the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_responses', function (Blueprint $table): void {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('surveys_responses', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });
    }
};
