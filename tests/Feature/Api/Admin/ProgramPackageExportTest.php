<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Client;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\SiteApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Per docs/final-edge-mode-rush-plann.md [DF-20]: Program package export and TTS stream.
 */
class ProgramPackageExportTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Program $program;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Export Site',
            'slug' => 'export-site',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [
                'sync_tokens' => true,
                'sync_clients' => true,
                'sync_tts' => false,
            ],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->program = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Export Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $this->program->id, 'name' => 'P1', 'description' => null]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        User::factory()->create(['site_id' => $this->site->id, 'role' => 'staff']);
    }

    private function getPackageWithSiteKey(): TestResponse
    {
        $key = app(SiteApiKeyService::class)->assignNewKey($this->site);

        return $this->getJson("/api/admin/programs/{$this->program->id}/package", [
            'Authorization' => 'Bearer '.$key,
        ]);
    }

    public function test_admin_can_export_package_and_response_has_all_required_keys(): void
    {
        $response = $this->getPackageWithSiteKey();

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('manifest', $data);
        $this->assertArrayHasKey('program', $data);
        $this->assertArrayHasKey('tracks', $data);
        $this->assertArrayHasKey('steps', $data);
        $this->assertArrayHasKey('processes', $data);
        $this->assertArrayHasKey('stations', $data);
        $this->assertArrayHasKey('station_process', $data);
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('tokens', $data);
        $this->assertArrayHasKey('program_token', $data);
        $this->assertArrayHasKey('clients', $data);
        $this->assertArrayHasKey('tts_files', $data);
        $this->assertArrayHasKey('tts_asset_references', $data);
    }

    public function test_manifest_contains_correct_sha256_checksums_for_each_section(): void
    {
        $response = $this->getPackageWithSiteKey();
        $response->assertStatus(200);
        $data = $response->json();
        $checksums = $data['manifest']['checksums'] ?? [];

        $sections = ['program', 'tracks', 'steps', 'processes', 'stations', 'station_process', 'users', 'tokens', 'clients', 'tts_asset_references'];
        foreach ($sections as $section) {
            $this->assertArrayHasKey($section, $checksums);
            $expected = hash('sha256', json_encode($data[$section]));
            $this->assertSame($expected, $checksums[$section], "Checksum mismatch for section: {$section}");
        }
    }

    public function test_client_records_in_response_never_contain_mobile_encrypted_or_mobile_hash(): void
    {
        Client::create([
            'site_id' => $this->site->id,
            'first_name' => 'Test',
            'last_name' => 'Client',
            'birth_date' => '1990-01-01',
            'mobile_encrypted' => 'encrypted',
            'mobile_hash' => 'hash',
        ]);
        $this->site->update(['edge_settings' => array_merge($this->site->edge_settings ?? [], ['sync_clients' => true])]);

        $response = $this->getPackageWithSiteKey();
        $response->assertStatus(200);
        $clients = $response->json('clients');
        $this->assertNotEmpty($clients);
        foreach ($clients as $client) {
            $this->assertArrayNotHasKey('mobile_encrypted', $client);
            $this->assertArrayNotHasKey('mobile_hash', $client);
        }
    }

    public function test_returns_404_when_program_belongs_to_different_site(): void
    {
        $otherSite = Site::create([
            'name' => 'Other',
            'slug' => 'other',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $otherProgram = Program::create([
            'site_id' => $otherSite->id,
            'name' => 'Other Program',
            'description' => null,
            'is_active' => true,
            'created_by' => User::factory()->admin()->create(['site_id' => $otherSite->id])->id,
        ]);

        $key = app(SiteApiKeyService::class)->assignNewKey($this->site);
        $response = $this->getJson("/api/admin/programs/{$otherProgram->id}/package", [
            'Authorization' => 'Bearer '.$key,
        ]);

        $response->assertStatus(404);
    }

    public function test_site_api_key_authentication_works_for_package_export(): void
    {
        $siteApiKeyService = app(SiteApiKeyService::class);
        $key = $siteApiKeyService->assignNewKey($this->site);

        $response = $this->getJson("/api/admin/programs/{$this->program->id}/package", [
            'Authorization' => 'Bearer '.$key,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('manifest.program_id', $this->program->id);
        $response->assertJsonPath('manifest.site_id', $this->site->id);
    }

    public function test_tts_file_stream_returns_200_for_valid_token_tts_file_in_program(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'tts-test-'.uniqid());
        $token->physical_id = 'T1';
        $token->site_id = $this->site->id;
        $token->status = 'available';
        $token->save();
        DB::table('program_token')->insert([
            'program_id' => $this->program->id,
            'token_id' => $token->id,
            'created_at' => now(),
        ]);

        $path = "tts/tokens/{$token->id}/audio.mp3";
        Storage::disk('local')->put($path, 'fake audio content');

        $siteApiKeyService = app(SiteApiKeyService::class);
        $key = $siteApiKeyService->assignNewKey($this->site);

        $response = $this->get("/api/admin/programs/{$this->program->id}/tts-files/{$path}", [
            'Authorization' => 'Bearer '.$key,
        ]);

        $response->assertStatus(200);
    }

    public function test_tts_file_stream_returns_403_when_token_does_not_belong_to_program(): void
    {
        $otherSite = Site::create([
            'name' => 'Other',
            'slug' => 'other',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $otherProgram = Program::create([
            'site_id' => $otherSite->id,
            'name' => 'Other',
            'description' => null,
            'is_active' => true,
            'created_by' => User::factory()->admin()->create(['site_id' => $otherSite->id])->id,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'other-'.uniqid());
        $token->physical_id = 'O1';
        $token->site_id = $otherSite->id;
        $token->status = 'available';
        $token->save();
        DB::table('program_token')->insert([
            'program_id' => $otherProgram->id,
            'token_id' => $token->id,
            'created_at' => now(),
        ]);

        $path = "tts/tokens/{$token->id}/audio.mp3";
        Storage::disk('local')->put($path, 'fake');

        $siteApiKeyService = app(SiteApiKeyService::class);
        $key = $siteApiKeyService->assignNewKey($this->site);

        $response = $this->get("/api/admin/programs/{$this->program->id}/tts-files/{$path}", [
            'Authorization' => 'Bearer '.$key,
        ]);

        $response->assertStatus(403);
    }

    public function test_tts_file_stream_returns_403_for_path_with_directory_traversal(): void
    {
        $siteApiKeyService = app(SiteApiKeyService::class);
        $key = $siteApiKeyService->assignNewKey($this->site);

        $response = $this->get("/api/admin/programs/{$this->program->id}/tts-files/../etc/passwd", [
            'Authorization' => 'Bearer '.$key,
        ]);

        $response->assertStatus(403);
    }
}
