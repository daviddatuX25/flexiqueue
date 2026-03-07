<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemStorageTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'password' => Hash::make('secret'),
        ]);
    }

    public function test_admin_can_view_system_storage_snapshot(): void
    {
        $admin = $this->createAdmin();

        // Create a small fake TTS file so the category is non-zero.
        $ttsDir = storage_path('app/private/tts/test');
        if (! is_dir($ttsDir)) {
            mkdir($ttsDir, 0777, true);
        }
        file_put_contents($ttsDir.'/sample.mp3', random_bytes(1024));

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/system/storage');

        $response->assertOk();

        $response->assertJsonStructure([
            'disk' => [
                'total_bytes',
                'free_bytes',
                'used_bytes',
                'used_percent',
            ],
            'categories' => [
                'tts_audio' => ['bytes', 'file_count', 'orphaned_bytes', 'orphaned_file_count'],
                'profile_avatars' => ['bytes', 'file_count'],
                'print_images' => ['bytes', 'file_count'],
                'logs' => ['bytes', 'file_count'],
                'database' => ['bytes', 'file_count'],
            ],
            'generated_at',
        ]);

        $data = $response->json();

        $this->assertIsInt($data['disk']['total_bytes']);
        $this->assertIsInt($data['disk']['used_bytes']);
        $this->assertIsNumeric($data['disk']['used_percent']);

        $this->assertGreaterThanOrEqual(
            1,
            $data['categories']['tts_audio']['file_count'],
            'Expected at least one TTS file to be counted.'
        );
        $this->assertGreaterThan(
            0,
            $data['categories']['tts_audio']['bytes'],
            'Expected TTS bytes to be greater than zero.'
        );
        // Sample file is not referenced by any token/station, so it is orphaned
        $this->assertGreaterThanOrEqual(
            1,
            $data['categories']['tts_audio']['orphaned_file_count'],
            'Expected unreferenced TTS file to be counted as orphaned.'
        );
        $this->assertGreaterThanOrEqual(
            1024,
            $data['categories']['tts_audio']['orphaned_bytes'],
            'Expected orphaned bytes to include the test file size.'
        );
    }

    public function test_non_admin_cannot_access_system_storage(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/system/storage')
            ->assertForbidden();
    }

    public function test_admin_can_clear_tts_audio_storage(): void
    {
        $admin = $this->createAdmin();

        $ttsDir = storage_path('app/private/tts');
        if (! is_dir($ttsDir)) {
            mkdir($ttsDir, 0777, true);
        }
        $filePath = $ttsDir.'/sample.mp3';
        file_put_contents($filePath, random_bytes(512));
        $this->assertFileExists($filePath);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->tts_audio_path = 'tts/tokens/1.mp3';
        $token->tts_status = 'pre_generated';
        $token->tts_settings = [
            'languages' => [
                'en' => ['audio_path' => 'tts/tokens/1_en.mp3', 'status' => 'ready'],
            ],
        ];
        $token->save();

        $this->actingAs($admin)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $response = $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear', ['category' => 'tts_audio']);

        $response->assertOk();
        $response->assertJsonStructure([
            'cleared' => ['bytes', 'file_count'],
            'message',
        ]);
        $data = $response->json();
        $this->assertGreaterThanOrEqual(512, $data['cleared']['bytes']);
        $this->assertGreaterThanOrEqual(1, $data['cleared']['file_count']);

        $this->assertFileDoesNotExist($filePath);
        $token->refresh();
        $this->assertNull($token->tts_audio_path);
        $this->assertNull($token->tts_status);
        $this->assertNull($token->tts_settings['languages']['en']['audio_path'] ?? null);
        $this->assertNull($token->tts_settings['languages']['en']['status'] ?? null);
    }

    public function test_non_admin_cannot_clear_storage(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($user)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $this->actingAs($user)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear', ['category' => 'tts_audio'])
            ->assertForbidden();
    }

    public function test_clear_storage_rejects_invalid_category(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear', ['category' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
    }

    public function test_legacy_tts_path_app_private_app_tts_is_counted_and_cleared(): void
    {
        $admin = $this->createAdmin();

        $legacyDir = storage_path('app/private/app/tts');
        if (! is_dir($legacyDir)) {
            mkdir($legacyDir, 0777, true);
        }
        $legacyFile = $legacyDir.'/legacy.mp3';
        $legacySize = 2048;
        file_put_contents($legacyFile, random_bytes($legacySize));
        $this->assertFileExists($legacyFile);

        $response = $this->actingAs($admin)->getJson('/api/admin/system/storage');
        $response->assertOk();
        $data = $response->json();
        $this->assertGreaterThanOrEqual($legacySize, $data['categories']['tts_audio']['bytes']);
        $this->assertGreaterThanOrEqual(1, $data['categories']['tts_audio']['file_count']);
        $this->assertGreaterThanOrEqual(1, $data['categories']['tts_audio']['orphaned_file_count']);
        $this->assertGreaterThanOrEqual($legacySize, $data['categories']['tts_audio']['orphaned_bytes']);

        $this->actingAs($admin)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $clearResponse = $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear', ['category' => 'tts_audio']);

        $clearResponse->assertOk();
        $clearData = $clearResponse->json();
        $this->assertGreaterThanOrEqual($legacySize, $clearData['cleared']['bytes']);
        $this->assertGreaterThanOrEqual(1, $clearData['cleared']['file_count']);
        $this->assertFileDoesNotExist($legacyFile);
    }

    public function test_admin_can_clear_orphaned_tts_only_deletes_unreferenced_files(): void
    {
        $admin = $this->createAdmin();

        $ttsBase = storage_path('app/private/tts');
        if (! is_dir($ttsBase)) {
            mkdir($ttsBase, 0777, true);
        }
        $referencedPath = $ttsBase.'/tokens/1.mp3';
        $orphanPath = $ttsBase.'/tokens/99_orphan.mp3';
        if (! is_dir(dirname($referencedPath))) {
            mkdir(dirname($referencedPath), 0777, true);
        }
        file_put_contents($referencedPath, random_bytes(100));
        file_put_contents($orphanPath, random_bytes(200));
        $this->assertFileExists($referencedPath);
        $this->assertFileExists($orphanPath);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->tts_audio_path = 'tts/tokens/1.mp3';
        $token->tts_status = 'ready';
        $token->save();

        $this->actingAs($admin)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $response = $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear-orphaned-tts');

        $response->assertOk();
        $response->assertJsonStructure([
            'cleared' => ['bytes', 'file_count'],
            'message',
        ]);
        $data = $response->json();
        $this->assertGreaterThanOrEqual(200, $data['cleared']['bytes'], 'Expected at least orphan file size to be cleared.');
        $this->assertGreaterThanOrEqual(1, $data['cleared']['file_count'], 'Expected at least one orphan file to be deleted.');

        $this->assertFileDoesNotExist($orphanPath);
        $this->assertFileExists($referencedPath);

        $token->refresh();
        $this->assertSame('tts/tokens/1.mp3', $token->tts_audio_path);
        $this->assertSame('ready', $token->tts_status);
    }

    public function test_clear_orphaned_tts_does_not_modify_station_tts_references(): void
    {
        $admin = $this->createAdmin();
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $admin->id,
        ]);

        $ttsBase = storage_path('app/private/tts');
        if (! is_dir($ttsBase)) {
            mkdir($ttsBase, 0777, true);
        }
        $stationDir = $ttsBase.'/stations';
        if (! is_dir($stationDir)) {
            mkdir($stationDir, 0777, true);
        }
        $referencedFile = $stationDir.'/1_en.mp3';
        $orphanFile = $stationDir.'/99_orphan.mp3';
        file_put_contents($referencedFile, random_bytes(150));
        file_put_contents($orphanFile, random_bytes(250));
        $this->assertFileExists($referencedFile);
        $this->assertFileExists($orphanFile);

        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['audio_path' => 'tts/stations/1_en.mp3', 'status' => 'ready'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $response = $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear-orphaned-tts');

        $response->assertOk();
        $data = $response->json();
        $this->assertGreaterThanOrEqual(1, $data['cleared']['file_count']);

        $station->refresh();
        $this->assertSame('tts/stations/1_en.mp3', $station->settings['tts']['languages']['en']['audio_path'] ?? null);
        $this->assertSame('ready', $station->settings['tts']['languages']['en']['status'] ?? null);
    }

    public function test_non_admin_cannot_clear_orphaned_tts(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($user)->get('/admin/settings');
        $csrf = $this->app['session']->token();
        $this->actingAs($user)
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/admin/system/storage/clear-orphaned-tts')
            ->assertForbidden();
    }
}

