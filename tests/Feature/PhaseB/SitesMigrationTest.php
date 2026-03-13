<?php

namespace Tests\Feature\PhaseB;

use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class SitesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sites_table_schema_matches_spec_and_has_no_raw_api_key_column(): void
    {
        $this->assertTrue(Schema::hasTable('sites'));

        $columns = Schema::getColumnListing('sites');

        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('slug', $columns);
        $this->assertContains('api_key_hash', $columns);
        $this->assertContains('settings', $columns);
        $this->assertContains('edge_settings', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);

        $this->assertNotContains('api_key', $columns);
    }

    public function test_programs_and_users_have_nullable_site_id_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('programs', 'site_id'));
        $this->assertTrue(Schema::hasColumn('users', 'site_id'));
    }

    public function test_default_site_seeder_assigns_existing_programs_and_users(): void
    {
        // Use the main seeder to create baseline programs/users.
        $this->seed();

        // Re-run the default site seeder explicitly to ensure idempotence.
        $this->seed(DefaultSiteSeeder::class);

        $this->assertDatabaseHas('sites', ['slug' => 'default']);

        $siteId = $this->app['db']->table('sites')->where('slug', 'default')->value('id');

        $this->assertDatabaseMissing('programs', ['site_id' => null]);
        $this->assertDatabaseMissing('users', ['site_id' => null]);

        $this->assertDatabaseHas('programs', ['site_id' => $siteId]);
        $this->assertDatabaseHas('users', ['site_id' => $siteId]);

        $edgeSettings = $this->app['db']->table('sites')->where('id', $siteId)->value('edge_settings');

        $decoded = is_string($edgeSettings) ? json_decode($edgeSettings, true) : $edgeSettings;

        $this->assertIsArray($decoded);

        AssertableJson::fromArray($decoded)
            ->where('bridge_enabled', false)
            ->where('sync_clients', false)
            ->where('sync_client_scope', 'program_history')
            ->where('sync_tokens', true)
            ->where('sync_tts', true)
            ->where('offline_binding_mode_override', 'optional')
            ->where('scheduled_sync_time', '17:00')
            ->where('offline_allow_client_creation', true)
            ->etc();
    }
}

