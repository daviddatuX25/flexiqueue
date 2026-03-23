<?php

namespace Tests\Feature;

use App\Models\DeviceAuthorization;
use App\Models\IdentityRegistration;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\ClientService;
use App\Services\DeviceAuthorizationService;
use App\Services\MobileCryptoService;
use App\Support\DeviceLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Self-service kiosk: GET /site/{site}/kiosk/..., legacy /public-triage redirects; GET /api/public/token-lookup, POST /api/public/sessions/bind.
 * No auth. 403 when kiosk surface is disabled (no self-service and no status checker).
 */
class KioskSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function defaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make(Str::random(40)), 'settings' => [], 'edge_settings' => []]
        );
    }

    private function createProgramWithTracks(bool $allowPublicTriage = true, array $extraSettings = []): array
    {
        $site = $this->defaultSite();
        $user = User::factory()->create();
        $settings = array_merge(['allow_public_triage' => $allowPublicTriage], $extraSettings);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => $settings,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P1', 'description' => null]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        return ['program' => $program, 'track' => $track, 'station' => $station, 'process' => $process];
    }

    /** Per public-site plan: known_sites cookie required for /site/* routes. */
    private function withKnownSiteCookie(Site $site): static
    {
        $value = json_encode([['slug' => $site->slug, 'name' => $site->name]]);

        return $this->withUnencryptedCookie('known_sites', $value);
    }

    /** Per plan: request canonical kiosk URL with device auth + device lock (kiosk) so we get 200 Kiosk/Start. */
    private function getTriageWithDeviceAuth(Site $site, Program $program): TestResponse
    {
        $service = app(DeviceAuthorizationService::class);
        $result = $service->authorize($program, 'test-device-'.$program->id, DeviceAuthorization::SCOPE_SESSION);
        $name = DeviceAuthorizationService::cookieNameForProgram($program);
        $lockCookie = DeviceLock::encode($site->slug, $program->slug, DeviceLock::TYPE_KIOSK, null);
        $lockValue = $lockCookie->getValue();

        return $this->withKnownSiteCookie($site)
            ->withCookie($name, $result['cookie_value'])
            ->withUnencryptedCookie(DeviceLock::COOKIE_NAME, $lockValue)
            ->get('/site/'.$site->slug.'/kiosk/'.$program->slug);
    }

    private function createToken(string $physicalId = 'A1', ?int $siteId = null): Token
    {
        $token = new Token;
        $siteId = $siteId ?? Site::first()?->id;
        if ($siteId !== null) {
            $token->site_id = $siteId;
        }
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        return $token;
    }

    public function test_triage_start_returns_200_with_allowed_true_when_program_allows_public_triage(): void
    {
        $site = $this->defaultSite();
        ['program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);

        $response = $this->withKnownSiteCookie($site)->get('/site/'.$site->slug.'/public-triage');

        $response->assertRedirect('/site/'.$site->slug.'/kiosk');
        $response = $this->getTriageWithDeviceAuth($site, $program);
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kiosk/Start')
            ->where('allowed', true)
            ->where('program_id', $program->id)
            ->has('program_name')
            ->has('tracks')
            ->has('date')
        );
        $props = $response->viewData('page')['props'];
        $this->assertSame('Test Program', $props['program_name']);
        $this->assertIsArray($props['tracks']);
        $this->assertCount(1, $props['tracks']);
        $this->assertSame('Default', $props['tracks'][0]['name']);
        $this->assertTrue($props['allow_unverified_entry']);
    }

    /** Per plan: legacy /public-triage with no slug redirects to site kiosk; with no program allowing kiosk, shows allowed false. */
    public function test_triage_start_returns_200_with_allowed_false_when_no_program(): void
    {
        $site = $this->defaultSite();

        $response = $this->get('/public-triage');

        $response->assertRedirect('/site/'.$site->slug.'/kiosk');

        $response = $this->withKnownSiteCookie($site)->get('/site/'.$site->slug.'/kiosk');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kiosk/Start')
            ->where('allowed', false)
        );
    }

    public function test_triage_start_returns_200_with_allowed_false_when_program_disallows_public_triage(): void
    {
        $site = $this->defaultSite();
        $this->createProgramWithTracks(false);

        $response = $this->withKnownSiteCookie($site)->get('/site/'.$site->slug.'/kiosk');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kiosk/Start')
            ->where('allowed', false)
        );
    }

    public function test_public_token_lookup_returns_200_when_allowed_and_token_exists(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('qr_hash', $token->qr_code_hash);
        $response->assertJsonPath('status', 'available');
    }

    public function test_public_token_lookup_returns_403_when_public_triage_disabled(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Kiosk is not available for this program.');
    }

    public function test_public_token_lookup_returns_200_when_only_status_checker_enabled(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create();
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Status Only',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => [
                'allow_public_triage' => false,
                'kiosk_self_service_triage_enabled' => false,
                'kiosk_status_checker_enabled' => true,
                'enable_public_triage_hid_barcode' => true,
                'enable_public_triage_camera_scanner' => true,
            ],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P1', 'description' => null]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
    }

    public function test_public_token_lookup_returns_404_when_token_not_found(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);

        $response = $this->getJson('/api/public/token-lookup?physical_id=Z99&program_id='.$program->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_public_bind_creates_session_returns_201_when_allowed(): void
    {
        // Plain bind (no client_binding) allowed when identity disabled + allow_unverified so binding not required.
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'disabled',
            'allow_unverified_entry' => true,
        ]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.alias', 'A1');
        $response->assertJsonPath('session.client_category', 'Regular');
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('token.physical_id', 'A1');
        $response->assertJsonPath('token.status', 'in_use');
        $this->assertDatabaseHas('queue_sessions', ['alias' => 'A1', 'status' => 'waiting']);
        $this->assertDatabaseHas('tokens', ['id' => $token->id, 'status' => 'in_use']);
    }

    public function test_public_bind_returns_403_when_disabled(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Public self-serve triage is not available.');
        $this->assertDatabaseMissing('queue_sessions', ['alias' => 'A1']);
    }

    public function test_public_bind_returns_409_when_token_in_use(): void
    {
        ['track' => $track, 'station' => $station, 'program' => $program] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'disabled',
            'allow_unverified_entry' => true,
        ]);
        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Token is already in use.');
    }

    public function test_public_bind_transaction_log_has_null_staff_user_id(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'disabled',
            'allow_unverified_entry' => true,
        ]);
        $token = $this->createToken('A1');

        $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $log = TransactionLog::where('action_type', 'bind')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->staff_user_id);
    }

    public function test_public_bind_identity_registration_request_mutually_exclusive_with_client_binding_returns_422(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_binding' => ['client_id' => 1, 'source' => 'phone_match'],
            'identity_registration_request' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['identity_registration_request']);
    }

    public function test_public_bind_returns_403_when_allow_unverified_false_and_no_registration_request(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'required',
            'allow_unverified_entry' => false,
        ]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Token binding is not available. Please verify your identity or submit a registration for staff to process.');
        $this->assertDatabaseMissing('queue_sessions', ['token_id' => $token->id]);
    }

    public function test_public_bind_identity_registration_request_allow_unverified_false_creates_registration_no_session(): void
    {
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'required',
            'allow_unverified_entry' => false,
        ]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'birth_date' => '1990-01-01',
                'client_category' => 'Regular',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('request_submitted', true);
        $this->assertDatabaseHas('identity_registrations', [
            'program_id' => $program->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'client_category' => 'Regular',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('queue_sessions', ['token_id' => $token->id]);
        $token->refresh();
        $this->assertSame('available', $token->status);
    }

    public function test_public_bind_returns_409_when_token_has_pending_identity_registration(): void
    {
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'required',
            'allow_unverified_entry' => true,
        ]);
        $token = $this->createToken('A1');
        IdentityRegistration::create([
            'program_id' => $program->id,
            'request_type' => 'registration',
            'token_id' => $token->id,
            'track_id' => $track->id,
            'first_name' => 'Jane',
            'last_name' => null,
            'birth_date' => '1990-01-01',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'This token already has a pending verification. Please see a staff member.');
        $this->assertDatabaseMissing('queue_sessions', ['token_id' => $token->id]);
    }

    public function test_public_bind_identity_registration_request_without_token_creates_registration_no_session(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $mobile = '09171234567';

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'identity_registration_request' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'birth_date' => '1990-01-01',
                'client_category' => 'Regular',
                'mobile' => $mobile,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('request_submitted', true);
        $reg = IdentityRegistration::where('program_id', $program->id)
            ->where('first_name', 'Jane')
            ->where('last_name', 'Doe')
            ->where('client_category', 'Regular')
            ->where('status', 'pending')
            ->whereNull('session_id')
            ->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->mobile_hash);
        $this->assertNotNull($reg->mobile_encrypted);
    }

    public function test_public_bind_identity_registration_request_allow_unverified_true_creates_session_and_registration(): void
    {
        ['track' => $track, 'program' => $program, 'station' => $station] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'birth_date' => '1990-01-01',
                'client_category' => 'PWD / Senior / Pregnant',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.alias', 'A1');
        $response->assertJsonPath('session.client_category', 'PWD / Senior / Pregnant');
        $response->assertJsonPath('unverified', true);
        $sessionId = $response->json('session.id');
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $sessionId,
            'token_id' => $token->id,
            'client_id' => null,
            'client_category' => 'PWD / Senior / Pregnant',
        ]);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'pending')->first();
        $this->assertNotNull($reg);
        $this->assertSame($sessionId, $reg->session_id);
        $this->assertSame('Jane', $reg->first_name);
        $this->assertSame('Doe', $reg->last_name);
        $token->refresh();
        $this->assertSame('in_use', $token->status);
    }

    public function test_public_bind_reuses_existing_pending_identity_registration_for_same_mobile_when_allow_unverified_true(): void
    {
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $token = $this->createToken('A1');

        $mobile = '09171234567';
        $mobileHash = app(MobileCryptoService::class)->hash($mobile);
        $mobileEncrypted = app(MobileCryptoService::class)->encrypt($mobile);

        $existing = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'mobile_encrypted' => $mobileEncrypted,
            'mobile_hash' => $mobileHash,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'birth_date' => '1990-01-01',
                'client_category' => 'Regular',
                'mobile' => $mobile,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('unverified', true);

        $this->assertSame(1, IdentityRegistration::query()
            ->where('program_id', $program->id)
            ->where('status', 'pending')
            ->where('mobile_hash', $mobileHash)
            ->count());

        $existing->refresh();
        $this->assertNotNull($existing->session_id);
    }

    public function test_public_bind_when_mobile_matches_existing_client_creates_session_without_new_registration(): void
    {
        $site = Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make('key'), 'settings' => [], 'edge_settings' => []]
        );
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $program->update(['site_id' => $site->id]);

        $clientService = app(ClientService::class);
        $mobile = '09181112222';
        $client = $clientService->createClient('Already', 'Registered', '1988-01-01', $site->id, $mobile);

        $token = $this->createToken('C1');
        $token->update(['site_id' => $site->id]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'first_name' => 'Already',
                'last_name' => 'Registered',
                'birth_date' => '1988-01-01',
                'client_category' => 'Regular',
                'mobile' => $mobile,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('client_already_registered', true);

        $session = Session::where('token_id', $token->id)->first();
        $this->assertNotNull($session);
        $this->assertSame($client->id, $session->client_id);

        $this->assertSame(0, IdentityRegistration::query()
            ->where('program_id', $program->id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_public_bind_returns_409_when_client_already_queued(): void
    {
        ['track' => $track, 'program' => $program, 'station' => $station] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'required',
            'allow_unverified_entry' => true,
        ]);

        $clientService = app(ClientService::class);
        $site = Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make('key'), 'settings' => [], 'edge_settings' => []]
        );
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $site->id, '09171234567');

        $firstToken = $this->createToken('A1');
        $existingSession = Session::create([
            'token_id' => $firstToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_id' => $client->id,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $firstToken->update(['status' => 'in_use', 'current_session_id' => $existingSession->id]);

        $secondToken = $this->createToken('B1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $secondToken->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'phone_match',
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'client_already_queued');
        $response->assertJsonPath('active_session.alias', 'A1');
        $this->assertDatabaseMissing('queue_sessions', [
            'token_id' => $secondToken->id,
        ]);
    }

    // --- A.2.3: Public triage program from URL (GET /public/triage/{program}) ---

    public function test_public_triage_page_returns_200_with_program_id_when_active_and_allow_public_triage(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $site = $program->site;

        // Per plan: with device lock, only site-prefixed paths are allowed; use per-site triage URL.
        $response = $this->getTriageWithDeviceAuth($site, $program);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kiosk/Start')
            ->where('allowed', true)
            ->where('program_id', $program->id)
            ->has('program_name')
            ->has('tracks')
        );
    }

    public function test_public_triage_page_returns_404_for_inactive_program(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $program->update(['is_active' => false]);

        $response = $this->get('/public/triage/'.$program->id);

        $response->assertStatus(404);
    }

    public function test_public_triage_page_returns_404_for_missing_program(): void
    {
        $response = $this->get('/public/triage/99999');

        $response->assertStatus(404);
    }

    public function test_public_triage_page_returns_200_with_allowed_false_when_allow_public_triage_false(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(false);
        $site = $program->site;

        $response = $this->withKnownSiteCookie($site)->get('/site/'.$site->slug.'/kiosk/'.$program->slug);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kiosk/Start')
            ->where('allowed', false)
            ->where('program_id', $program->id)
        );
    }

    public function test_bind_with_program_id_sets_session_program_id(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'disabled',
            'allow_unverified_entry' => true,
        ]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => 'A1',
            'program_id' => $program->id,
        ]);
    }

    public function test_bind_without_program_id_returns_403_or_422(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_bind_with_invalid_program_id_returns_403_or_422(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => 99999,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_token_lookup_with_valid_program_id_returns_200_when_token_found(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('status', 'available');
    }

    public function test_token_lookup_with_invalid_program_id_returns_403(): void
    {
        $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id=99999');

        $response->assertStatus(403);
    }

    public function test_token_lookup_with_inactive_program_id_returns_403(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $program->update(['is_active' => false]);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(403);
    }

    public function test_token_lookup_without_program_id_returns_403(): void
    {
        $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash));

        $response->assertStatus(403);
    }

    /** A.6.3: In multi-program setup, public triage binds sessions to the correct program_id. */
    public function test_public_triage_multi_program_bind_sessions_are_scoped_to_correct_programs(): void
    {
        ['program' => $programA, 'track' => $trackA] = $this->createProgramWithTracks(true, [
            'allow_unverified_entry' => true,
        ]);
        ['program' => $programB, 'track' => $trackB] = $this->createProgramWithTracks(true, [
            'allow_unverified_entry' => true,
        ]);

        $tokenA = $this->createToken('PA1');
        $tokenB = $this->createToken('PB1');

        // Bind in Program A
        $responseA = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $programA->id,
            'qr_hash' => $tokenA->qr_code_hash,
            'track_id' => $trackA->id,
            'client_category' => 'Regular',
        ]);
        $responseA->assertStatus(201);
        $aliasA = $responseA->json('session.alias');

        // Bind in Program B
        $responseB = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $programB->id,
            'qr_hash' => $tokenB->qr_code_hash,
            'track_id' => $trackB->id,
            'client_category' => 'Regular',
        ]);
        $responseB->assertStatus(201);
        $aliasB = $responseB->json('session.alias');

        // Each session must be bound to its own program
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => $aliasA,
            'program_id' => $programA->id,
        ]);
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => $aliasB,
            'program_id' => $programB->id,
        ]);

        // Cross-contamination checks
        $this->assertDatabaseMissing('queue_sessions', [
            'alias' => $aliasA,
            'program_id' => $programB->id,
        ]);
        $this->assertDatabaseMissing('queue_sessions', [
            'alias' => $aliasB,
            'program_id' => $programA->id,
        ]);
    }
}
