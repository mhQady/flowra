<?php

it('generates a workflow', function () {
    // Basic happy path
    $this->artisan('flowra:generate')
        ->expectsOutputToContain('Flowra Installer')   // colored output still contains this text
        ->expectsOutputToContain('Published Flowra files.')
        ->expectsOutputToContain('Database migrated.')
        ->expectsOutputToContain('Flowra is ready!')
        ->assertSuccessful();
});

// it('can skip publishing and migrations', function () {
//     $this->artisan('flowra:generate --no-publish --no-migrate')
//         ->expectsOutputToContain('Skipped publishing')
//         ->expectsOutputToContain('Skipped migrations')
//         ->assertSuccessful();
// });