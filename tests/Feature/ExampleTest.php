<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Per HOMEPAGE-PLAN: / is public (Option B — public landing + auth strip)
        $response = $this->get('/');
        $response->assertStatus(200);

        // Login page is public and returns 200
        $response = $this->get('/login');
        $response->assertStatus(200);
    }
}
