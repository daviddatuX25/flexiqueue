<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per central-edge B.4: Programs are scoped by site_id; cross-site isolation.
 */
class ProgramSiteScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_of_site_a_sees_only_site_a_programs_in_index(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $adminA = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $adminB = User::factory()->admin()->create(['site_id' => $siteB->id]);

        $programA1 = Program::create([
            'site_id' => $siteA->id,
            'name' => 'Program A1',
            'description' => null,
            'is_active' => false,
            'created_by' => $adminA->id,
        ]);
        $programA2 = Program::create([
            'site_id' => $siteA->id,
            'name' => 'Program A2',
            'description' => null,
            'is_active' => true,
            'created_by' => $adminA->id,
        ]);
        $programB1 = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B1',
            'description' => null,
            'is_active' => false,
            'created_by' => $adminB->id,
        ]);

        $responseA = $this->actingAs($adminA)->getJson('/api/admin/programs');
        $responseA->assertStatus(200);
        $idsA = collect($responseA->json('programs'))->pluck('id')->all();
        $this->assertContains($programA1->id, $idsA);
        $this->assertContains($programA2->id, $idsA);
        $this->assertNotContains($programB1->id, $idsA);

        $responseB = $this->actingAs($adminB)->getJson('/api/admin/programs');
        $responseB->assertStatus(200);
        $idsB = collect($responseB->json('programs'))->pluck('id')->all();
        $this->assertContains($programB1->id, $idsB);
        $this->assertNotContains($programA1->id, $idsB);
        $this->assertNotContains($programA2->id, $idsB);
    }

    public function test_admin_of_site_a_accessing_site_b_program_by_id_gets_404(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminA = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $programB = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => User::factory()->admin()->create(['site_id' => $siteB->id])->id,
        ]);

        $this->actingAs($adminA)->getJson("/api/admin/programs/{$programB->id}")->assertStatus(404);
        $this->actingAs($adminA)->putJson("/api/admin/programs/{$programB->id}", [
            'name' => 'Hacked',
            'description' => null,
        ])->assertStatus(404);
        $this->actingAs($adminA)->deleteJson("/api/admin/programs/{$programB->id}")->assertStatus(404);
    }

    public function test_admin_with_null_site_id_sees_empty_program_list(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);
        Program::create([
            'site_id' => $site->id,
            'name' => 'Some Program',
            'description' => null,
            'is_active' => true,
            'created_by' => User::factory()->admin()->create(['site_id' => $site->id])->id,
        ]);

        $response = $this->actingAs($adminNoSite)->getJson('/api/admin/programs');
        $response->assertStatus(200);
        $this->assertSame([], $response->json('programs'));
    }

    public function test_admin_with_null_site_id_cannot_create_program_gets_403(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $response = $this->actingAs($adminNoSite)->postJson('/api/admin/programs', [
            'name' => 'New Program',
            'description' => 'Desc',
        ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('programs', ['name' => 'New Program']);
    }

    public function test_admin_with_null_site_id_accessing_program_by_id_gets_403(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Some Program',
            'description' => null,
            'is_active' => false,
            'created_by' => User::factory()->admin()->create(['site_id' => $site->id])->id,
        ]);

        $this->actingAs($adminNoSite)->getJson("/api/admin/programs/{$program->id}")->assertStatus(403);
    }

    public function test_store_sets_site_id_from_authenticated_user(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->postJson('/api/admin/programs', [
            'name' => 'New Program',
            'description' => 'Desc',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('programs', [
            'name' => 'New Program',
            'site_id' => $site->id,
        ]);
    }
}
