<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_home_page_redirects_to_materials(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/')
            ->assertRedirect(route('materials.index'));
    }
}
