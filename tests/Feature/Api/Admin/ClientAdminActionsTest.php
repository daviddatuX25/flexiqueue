<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Client;
use App\Models\ClientIdAuditLog;
use App\Models\ClientIdDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ClientAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_client_returns_200_when_no_audit_log_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();

        $res = $this->actingAs($admin)->deleteJson("/api/admin/clients/{$client->id}");

        $res->assertStatus(200)->assertJson(['deleted' => true]);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_client_returns_409_when_audit_log_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $doc = ClientIdDocument::factory()->create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('ABC-123'),
            'id_number_hash' => str_repeat('a', 64),
        ]);

        ClientIdAuditLog::create([
            'client_id' => $client->id,
            'client_id_document_id' => $doc->id,
            'staff_user_id' => $admin->id,
            'action' => 'id_reveal',
            'reason' => 'test',
            'id_type' => $doc->id_type,
            'id_last4' => '0123',
            'created_at' => now(),
        ]);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/clients/{$client->id}");

        $res->assertStatus(409);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_delete_id_document_returns_200_when_no_audit_log_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $doc = ClientIdDocument::factory()->create([
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('ABC-123'),
            'id_number_hash' => str_repeat('b', 64),
        ]);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/client-id-documents/{$doc->id}");

        $res->assertStatus(200)->assertJson(['deleted' => true]);
        $this->assertDatabaseMissing('client_id_documents', ['id' => $doc->id]);
    }

    public function test_delete_id_document_returns_409_when_audit_log_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $doc = ClientIdDocument::factory()->create([
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('ABC-123'),
            'id_number_hash' => str_repeat('c', 64),
        ]);

        ClientIdAuditLog::create([
            'client_id' => $doc->client_id,
            'client_id_document_id' => $doc->id,
            'staff_user_id' => $admin->id,
            'action' => 'id_reveal',
            'reason' => 'test',
            'id_type' => $doc->id_type,
            'id_last4' => '0123',
            'created_at' => now(),
        ]);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/client-id-documents/{$doc->id}");

        $res->assertStatus(409);
        $this->assertDatabaseHas('client_id_documents', ['id' => $doc->id]);
    }

    public function test_reassign_id_document_returns_200_and_updates_client_id(): void
    {
        $admin = User::factory()->admin()->create();
        $from = Client::factory()->create();
        $to = Client::factory()->create();
        $doc = ClientIdDocument::factory()->create([
            'client_id' => $from->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('ABC-123'),
            'id_number_hash' => str_repeat('d', 64),
        ]);

        $res = $this->actingAs($admin)->postJson("/api/admin/client-id-documents/{$doc->id}/reassign", [
            'target_client_id' => $to->id,
        ]);

        $res->assertStatus(200)->assertJsonPath('client_id_document.client_id', $to->id);
        $this->assertDatabaseHas('client_id_documents', ['id' => $doc->id, 'client_id' => $to->id]);
    }
}

