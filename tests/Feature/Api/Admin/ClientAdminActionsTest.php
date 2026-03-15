<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Client;
use App\Models\ClientIdAuditLog;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: client admin actions use phone-based audit.
 */
class ClientAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default',
                'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => [],
            ]
        );
    }

    public function test_delete_client_returns_200_when_no_audit_log_exists(): void
    {
        $site = $this->site();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/clients/{$client->id}");

        $res->assertStatus(200)->assertJson(['deleted' => true]);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_client_returns_409_when_audit_log_exists(): void
    {
        $site = $this->site();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan Cruz', 1985, $site->id, '09171234567');

        ClientIdAuditLog::create([
            'client_id' => $client->id,
            'staff_user_id' => $admin->id,
            'action' => 'phone_reveal',
            'mobile_last2' => '67',
            'reason' => 'test',
        ]);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/clients/{$client->id}");

        $res->assertStatus(409);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }
}
