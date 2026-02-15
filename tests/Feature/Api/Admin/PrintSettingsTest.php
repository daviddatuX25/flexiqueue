<?php

namespace Tests\Feature\Api\Admin;

use App\Models\PrintSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_default_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/print-settings');

        $response->assertStatus(200);
        $response->assertJsonPath('print_settings.cards_per_page', 6);
        $response->assertJsonPath('print_settings.paper', 'a4');
        $response->assertJsonPath('print_settings.orientation', 'portrait');
        $response->assertJsonPath('print_settings.show_hint', true);
        $response->assertJsonPath('print_settings.show_cut_lines', true);
    }

    public function test_update_saves_settings(): void
    {
        $admin = User::factory()->admin()->create();
        PrintSetting::instance();

        $response = $this->actingAs($admin)->putJson('/api/admin/print-settings', [
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
            'logo_url' => 'https://example.com/logo.png',
            'footer_text' => 'Office rules apply',
            'bg_image_url' => 'https://example.com/bg.png',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('print_settings.cards_per_page', 8);
        $response->assertJsonPath('print_settings.paper', 'letter');
        $response->assertJsonPath('print_settings.orientation', 'landscape');
        $response->assertJsonPath('print_settings.show_hint', false);
        $response->assertJsonPath('print_settings.show_cut_lines', false);
        $response->assertJsonPath('print_settings.logo_url', 'https://example.com/logo.png');
        $response->assertJsonPath('print_settings.footer_text', 'Office rules apply');
        $response->assertJsonPath('print_settings.bg_image_url', 'https://example.com/bg.png');
    }

    public function test_non_admin_cannot_access(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->getJson('/api/admin/print-settings')->assertStatus(403);
        $this->actingAs($staff)->putJson('/api/admin/print-settings', [])->assertStatus(403);
    }
}
