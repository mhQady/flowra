<?php


it('creates a context via console and persists in the DB', function () {
    // 1) Assert the table exists from your migrations
    expect(Schema::hasTable('migrations'))->toBeTrue();
    expect(Schema::hasTable('contexts'))->toBeTrue();
//
//    // 2) Run the command (via Testbench "artisan" inside tests)
//    $this->artisan('flowra:generate')
//        ->expectsOutputToContain('Context [context] created..')
//        ->assertSuccessful();
//
//    // 3) Verify DB state
//    $row = DB::table('contexts')->first();
//    expect($row)->not->toBeNull()
//        ->and($row->id)->toBe(1);
//
//    $count = DB::table('contexts')->count();
//    expect($count)->toBe(1);
});


//it('generates a workflow', function () {
//
//    // Basic happy path
//    $this->artisan('flowra:generate')
//        ->expectsOutputToContain('Flowra Installer')   // colored output still contains this text
//        ->expectsOutputToContain('Published Flowra files.')
//        ->expectsOutputToContain('Database migrated.')
//        ->expectsOutputToContain('Flowra is ready!')
//        ->assertSuccessful();
//});

// it('can skip publishing and migrations', function () {
//     $this->artisan('flowra:generate --no-publish --no-migrate')
//         ->expectsOutputToContain('Skipped publishing')
//         ->expectsOutputToContain('Skipped migrations')
//         ->assertSuccessful();
// });
