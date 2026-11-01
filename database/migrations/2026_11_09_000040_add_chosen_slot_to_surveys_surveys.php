<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The date fixed for an availability survey once it closes: one of its slots, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->string('chosen_slot_id')->nullable();
            $table->timestamp('chosen_slot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys_surveys', function (Blueprint $table): void {
            $table->dropColumn(['chosen_slot_id', 'chosen_slot_at']);
        });
    }
};
