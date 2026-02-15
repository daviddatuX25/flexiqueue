<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Unauthenticated: / redirects to login (BD-005 auth middleware)
        $response = $this->get('/');
        $response->assertRedirect('/login');

        // Login page is public and returns 200
        $response = $this->get('/login');
        $response->assertStatus(200);
    }
}
