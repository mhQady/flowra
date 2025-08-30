<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('flowra.tables.statuses'), function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('workflow');
            $table->string('transition');
            $table->string('from');
            $table->string('to');
            $table->json('comment')->nullable()->default(null);
//            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create(config('flowra.tables.registry'), function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('workflow');
            $table->string('transition');
            $table->string('from');
            $table->string('to');
            $table->json('comment')->nullable()->default(null);
//            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('flowra.tables.registry'));
        Schema::dropIfExists(config('flowra.tables.statuses'));
    }
};
