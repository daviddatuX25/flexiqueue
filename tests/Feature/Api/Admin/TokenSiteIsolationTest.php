<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

/**
 * Per site-scoping-migration-spec §2 & §7: Token API and bulk assign scoped by site.
 * Site admin A sees only site A tokens; create sets site_id; bulk assign same-site only; super_admin optional ?site_id= or all.
 */
class TokenSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->adminA = User::factory()->admin()->create(['site_id' => $this->siteA->id]);
        $this->adminB = User::factory()->admin()->create(['site_id' => $this->siteB->id]);
        $this->superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
    }

    private function createTokenForSite(string $physicalId, int $siteId, string $status = 'available'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->site_id = $siteId;
        $token->status = $status;
        $token->save();

        return $token;
    }

    public function test_site_admin_a_lists_only_site_a_tokens(): void
    {
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('A2', $this->siteA->id);
        $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->getJson('/api/admin/tokens');

        $response->assertStatus(200);
        $tokens = $response->json('tokens');
        $this->assertCount(2, $tokens);
        $physicalIds = collect($tokens)->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $physicalIds);
    }

    public function test_site_admin_b_lists_only_site_b_tokens(): void
    {
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('B1', $this->siteB->id);
        $this->createTokenForSite('B2', $this->siteB->id);

        $response = $this->actingAs($this->adminB)->getJson('/api/admin/tokens');

        $response->assertStatus(200);
        $tokens = $response->json('tokens');
        $this->assertCount(2, $tokens);
        $physicalIds = collect($tokens)->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['B1', 'B2'], $physicalIds);
    }

    public function test_token_create_sets_site_id(): void
    {
        $response = $this->actingAs($this->adminA)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'X',
            'count' => 2,
            'start_number' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 2);
        $ids = collect($response->json('tokens'))->pluck('id')->all();
        foreach ($ids as $id) {
            $this->assertDatabaseHas('tokens', [
                'id' => $id,
                'site_id' => $this->siteA->id,
            ]);
        }
    }

    public function test_site_admin_with_null_site_id_gets_403_on_index(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);
        $this->createTokenForSite('A1', $this->siteA->id);

        $response = $this->actingAs($adminNoSite)->getJson('/api/admin/tokens');

        $response->assertStatus(403);
    }

    public function test_site_admin_with_null_site_id_gets_403_on_batch_create(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $response = $this->actingAs($adminNoSite)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 1,
            'start_number' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function test_site_admin_cannot_update_token_from_other_site(): void
    {
        $tokenB = $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->putJson("/api/admin/tokens/{$tokenB->id}", [
            'status' => 'deactivated',
        ]);

        $response->assertStatus(404);
    }

    public function test_site_admin_cannot_delete_token_from_other_site(): void
    {
        $tokenB = $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->deleteJson("/api/admin/tokens/{$tokenB->id}");

        $response->assertStatus(404);
    }

    public function test_batch_delete_only_deletes_tokens_in_site_others_403(): void
    {
        $tokenA = $this->createTokenForSite('A1', $this->siteA->id);
        $tokenB = $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->postJson('/api/admin/tokens/batch-delete', [
            'ids' => [$tokenA->id, $tokenB->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('tokens', ['id' => $tokenA->id]);
        $this->assertDatabaseHas('tokens', ['id' => $tokenB->id]);
    }

    public function test_bulk_assign_only_attaches_same_site_tokens(): void
    {
        $programA = Program::create([
            'site_id' => $this->siteA->id,
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->adminA->id,
        ]);
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('A2', $this->siteA->id);
        $this->createTokenForSite('A99', $this->siteB->id); // same pattern, different site

        $response = $this->actingAs($this->adminA)->postJson("/api/admin/programs/{$programA->id}/tokens/bulk", [
            'pattern' => 'A*',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 2);
        $ids = $response->json('token_ids');
        $this->assertCount(2, $ids);
        $physicalIds = Token::whereIn('id', $ids)->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $physicalIds);
    }

    /**
     * Super_admin can filter by ?site_id= (controller behavior; routes may restrict to role:admin).
     */
    public function test_super_admin_can_filter_by_site_id(): void
    {
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->withoutMiddleware(PermissionMiddleware::class)
            ->actingAs($this->superAdmin)
            ->getJson('/api/admin/tokens?site_id='.$this->siteA->id);

        $response->assertStatus(200);
        $tokens = $response->json('tokens');
        $this->assertCount(1, $tokens);
        $this->assertSame('A1', $tokens[0]['physical_id']);
    }

    /**
     * Super_admin sees all tokens when no ?site_id= (controller behavior).
     */
    public function test_super_admin_can_see_all_tokens_without_site_id_param(): void
    {
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('B1', $this->siteB->id);

        $response = $this->withoutMiddleware(PermissionMiddleware::class)
            ->actingAs($this->superAdmin)
            ->getJson('/api/admin/tokens');

        $response->assertStatus(200);
        $tokens = $response->json('tokens');
        $this->assertCount(2, $tokens);
    }
}
