<?php

use Flowra\Enums\TransitionTypesEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('flowra.tables.statuses', 'statuses'), function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('workflow');
            $table->string('transition');
            $table->string('from')->nullable();
            $table->string('to');
            $table->json('comment')->nullable()->default(null);
            $table->foreignId('applied_by')->nullable();
            $table->unsignedTinyInteger('type')->default(TransitionTypesEnum::TRANSITION->value);

            $table->string('parent_workflow')->nullable();
            $table->string('bound_state')->nullable();
            
            $table->unique(['owner_type', 'owner_id', 'workflow'], 'statuses_owner_workflow_uq');
            $table->index(['parent_workflow', 'bound_state'], 'statuses_parent_bound_idx');

            $table->timestamps();


        });

        Schema::create(config('flowra.tables.registry', 'statuses_registry'), function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('workflow');
            $table->string('transition');
            $table->string('from')->nullable();
            $table->string('to');
            $table->json('comment')->nullable()->default(null);
            $table->foreignId('applied_by')->nullable();
            $table->unsignedTinyInteger('type')->default(TransitionTypesEnum::TRANSITION->value);

            $table->string('parent_workflow')->nullable();
            $table->string('bound_state')->nullable();

            $table->index(['parent_workflow', 'bound_state'], 'registry_parent_bound_idx');

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('flowra.tables.registry', 'statuses'));
        Schema::dropIfExists(config('flowra.tables.statuses', 'statuses_registry'));
    }
};
