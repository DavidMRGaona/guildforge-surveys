<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The public link's token, encrypted so the panel can show the link again; lookups use
 * public_token_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->text('public_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropColumn('public_token');
        });
    }
};
