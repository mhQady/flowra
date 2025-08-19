<?php

namespace Flowra\Tests;

class GenerateWorkflowTest extends TestCase
{
    /** @test */
    public function it_registers_the_install_command()
    {
        $this->artisan('list')
            ->expectsOutputToContain('flowra:generate')
            ->assertSuccessful();
    }

    /** @test */
    public function it_runs_install_command_successfully()
    {
        $this->artisan('flowra:generate')
            ->expectsOutputToContain('Installing Flowra')
            ->assertSuccessful();
    }
}
