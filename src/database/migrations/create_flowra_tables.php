<?php

use Flowra\Enums\TransitionTypesEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('flowra.tables.statuses', 'statuses'), function (Blueprint $table) {
            $this->commonSchema($table);

            $table->unique(['owner_type', 'owner_id', 'workflow'], 'statuses_owner_workflow_uq');
        });

        Schema::create(config('flowra.tables.registry', 'statuses_registry'), function (Blueprint $table) {
            $this->commonSchema($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('flowra.tables.registry', 'statuses'));
        Schema::dropIfExists(config('flowra.tables.statuses', 'statuses_registry'));
    }

    /**
     * @param  Blueprint  $table
     * @return void
     */
    private function commonSchema(Blueprint $table): void
    {
        $table->uuid('id')->primary();

        $table->morphs('owner');
        $table->string('workflow');
        $table->string('transition');
        $table->string('from')->nullable();
        $table->string('to');
        $table->json('comment')->nullable()->default(null);
        $table->foreignId('applied_by')->nullable();
        $table->unsignedTinyInteger('type')->default(TransitionTypesEnum::TRANSITION->value);

        $table->timestamps();
    }
};
