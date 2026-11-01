<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What each template is meant for: "general" and kinds of subject, null for anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_templates', function (Blueprint $table): void {
            $table->json('uses')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_templates', function (Blueprint $table): void {
            $table->dropColumn('uses');
        });
    }
};
