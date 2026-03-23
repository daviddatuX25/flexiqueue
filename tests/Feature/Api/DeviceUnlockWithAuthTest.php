<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Support\DeviceLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/public/device-unlock-with-auth: unlock device with same PIN/QR as device authorization.
 */
class DeviceUnlockWithAuthTest extends TestCase
{
    use RefreshDatabase;

    private function defaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make(Str::random(40)), 'settings' => [], 'edge_settings' => []]
        );
    }

    public function test_unlock_with_pin_returns_200_and_redirect_url_when_locked_and_pin_valid(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('123456'),
        ]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'slug' => 'test-program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $program->refresh();
        $this->grantProgramTeamSuperviseForTests($user, $program);

        $lock = [
            'site_slug' => $site->slug,
            'program_slug' => $program->slug,
            'device_type' => DeviceLock::TYPE_DISPLAY,
        ];

        $response = $this->withSession([DeviceLock::SESSION_KEY => $lock])
            ->postJson('/api/public/device-unlock-with-auth', ['pin' => '123456']);

        $response->assertStatus(200);
        $response->assertJsonPath('redirect_url', '/site/'.$site->slug.'/program/'.$program->slug.'/devices');
        $clearCookie = $response->headers->getCookies()[0] ?? null;
        $this->assertNotNull($clearCookie);
        $this->assertSame(DeviceLock::COOKIE_NAME, $clearCookie->getName());
        $this->assertSame('', $clearCookie->getValue());
    }

    public function test_unlock_with_auth_returns_400_when_not_locked(): void
    {
        $response = $this->postJson('/api/public/device-unlock-with-auth', [
            'pin' => '123456',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'No device lock to unlock.');
    }

    public function test_unlock_with_auth_returns_401_when_pin_invalid(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'slug' => 'test-program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $program->refresh();

        $lock = [
            'site_slug' => $site->slug,
            'program_slug' => $program->slug,
            'device_type' => DeviceLock::TYPE_DISPLAY,
        ];

        $response = $this->withSession([DeviceLock::SESSION_KEY => $lock])
            ->postJson('/api/public/device-unlock-with-auth', ['pin' => '000000']);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid PIN or QR code.');
    }
}
