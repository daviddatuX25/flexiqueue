<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use App\Repositories\PrintSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintPlatformDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_show_platform_print_defaults(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);

        $response = $this->actingAs($super)->getJson('/api/admin/print-platform-default-settings');

        $response->assertStatus(200);
        $response->assertJsonPath('print_settings.cards_per_page', 6);
        $response->assertJsonPath('print_settings.paper', 'a4');
    }

    public function test_super_admin_can_update_platform_print_defaults(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);
        $this->app->make(PrintSettingRepository::class)->getPlatformTemplate();

        $this->actingAs($super);
        Session::start();
        $token = Session::token();

        $response = $this->withHeader('X-CSRF-TOKEN', $token)->putJson('/api/admin/print-platform-default-settings', [
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
            'logo_url' => 'https://example.com/logo.png',
            'footer_text' => 'Platform footer',
            'bg_image_url' => 'https://example.com/bg.png',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('print_settings.cards_per_page', 8);
        $response->assertJsonPath('print_settings.paper', 'letter');
        $this->assertDatabaseHas('print_settings', [
            'site_id' => null,
            'cards_per_page' => 8,
        ]);
    }

    public function test_site_admin_cannot_access_platform_print_defaults(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $this->actingAs($admin)->getJson('/api/admin/print-platform-default-settings')->assertStatus(403);
        $this->actingAs($admin);
        Session::start();
        $token = Session::token();
        $this->withHeader('X-CSRF-TOKEN', $token)->putJson('/api/admin/print-platform-default-settings', [
            'cards_per_page' => 6,
            'paper' => 'a4',
            'orientation' => 'portrait',
            'show_hint' => true,
            'show_cut_lines' => true,
            'logo_url' => null,
            'footer_text' => null,
            'bg_image_url' => null,
        ])->assertStatus(403);
    }

    public function test_super_admin_can_upload_platform_print_image(): void
    {
        Storage::fake('public');
        $super = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);
        $this->app->make(PrintSettingRepository::class)->getPlatformTemplate();

        $this->actingAs($super);
        Session::start();
        $token = Session::token();

        $response = $this->withHeader('X-CSRF-TOKEN', $token)->post('/api/admin/print-platform-default-settings/image', [
            'image' => UploadedFile::fake()->image('logo.png', 200, 200),
            'type' => 'logo',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('type', 'logo');
        $this->assertNotEmpty($response->json('url'));
    }
}
