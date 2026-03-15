<?php

namespace Tests\Feature\Api;

use App\Models\DeviceUnlockRequest;
use App\Models\Program;
use App\Models\Site;
use App\Support\DeviceLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/public/device-unlock-requests/{id}/consume: clear device lock only after approved unlock (Option B).
 */
class DeviceUnlockConsumeTest extends TestCase
{
    use RefreshDatabase;

    private function defaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)), 'settings' => [], 'edge_settings' => []]
        );
    }

    public function test_consume_returns_200_with_redirect_url_and_clears_cookie_when_approved(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $program->refresh();

        $req = DeviceUnlockRequest::create([
            'program_id' => $program->id,
            'request_token' => Str::random(64),
            'status' => DeviceUnlockRequest::STATUS_APPROVED,
        ]);

        $response = $this->postJson('/api/public/device-unlock-requests/'.$req->id.'/consume', [
            'request_token' => $req->request_token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('redirect_url', '/site/'.$site->slug.'/program/'.$program->slug.'/devices');

        $cookie = $response->headers->getCookies()[0] ?? null;
        $this->assertNotNull($cookie);
        $this->assertSame(DeviceLock::COOKIE_NAME, $cookie->getName());
        $this->assertSame('', $cookie->getValue());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
    }

    public function test_consume_returns_403_when_request_not_approved(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $program->refresh();

        $req = DeviceUnlockRequest::create([
            'program_id' => $program->id,
            'request_token' => Str::random(64),
            'status' => DeviceUnlockRequest::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/public/device-unlock-requests/'.$req->id.'/consume', [
            'request_token' => $req->request_token,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Request not approved.');
    }

    public function test_consume_returns_404_when_token_mismatch(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $program->refresh();

        $req = DeviceUnlockRequest::create([
            'program_id' => $program->id,
            'request_token' => Str::random(64),
            'status' => DeviceUnlockRequest::STATUS_APPROVED,
        ]);

        $response = $this->postJson('/api/public/device-unlock-requests/'.$req->id.'/consume', [
            'request_token' => Str::random(64),
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Not found.');
    }
}
