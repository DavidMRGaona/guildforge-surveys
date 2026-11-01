<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The recommended templates the module ships are found by this key, so seeding them again never
 * duplicates one, whatever the association renamed it to. Templates made in the panel have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_templates', function (Blueprint $table): void {
            $table->string('catalog_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_templates', function (Blueprint $table): void {
            $table->dropUnique(['catalog_key']);
            $table->dropColumn('catalog_key');
        });
    }
};
