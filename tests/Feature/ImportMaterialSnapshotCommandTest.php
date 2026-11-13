<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Tests\TestCase;

class ImportMaterialSnapshotCommandTest extends TestCase
{
    public function test_falsey_invalid_reference_date_is_not_replaced_with_today(): void
    {
        $this->artisan('materials:import', [
            'file' => 'missing.xlsx',
            '--date' => '0',
        ])
            ->expectsOutputToContain('Data invalida; use o formato AAAA-MM-DD.')
            ->assertExitCode(Command::FAILURE);
    }
}
