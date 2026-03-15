<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use App\Services\TokenPrintService;
use Database\Seeders\TokenPrintTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Per docs/plans/QR-TOKEN-PRINT-SYSTEM.md QR-1, QR-2: Token print template and QR generation.
 * Per site-scoping-migration-spec §2: print token list scoped by admin's site_id.
 */
class TokenPrintTest extends TestCase
{
    use RefreshDatabase;

    private ?Site $site = null;

    private function site(): Site
    {
        if ($this->site === null) {
            $this->site = Site::create([
                'name' => 'Default Site',
                'slug' => 'default',
                'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => [],
            ]);
        }

        return $this->site;
    }

    /** @param bool $withSite When true (default), assign site_id so controller resolveTokens finds them; when false, site_id null for legacy URL in service-only tests. */
    private function createToken(string $physicalId = 'A1', ?string $qrHash = null, bool $withSite = true): Token
    {
        $token = new Token;
        $token->qr_code_hash = $qrHash ?? hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        if ($withSite) {
            $token->site_id = $this->site()->id;
        }
        $token->status = 'available';
        $token->save();

        return $token;
    }

    /** Mock TokenPrintService so QR image generation does not require GD; use in tests that hit the print controller or call the service. */
    private function mockQrGeneration(): void
    {
        $service = Mockery::mock(TokenPrintService::class)->makePartial();
        $service->shouldReceive('generateQrDataUri')->andReturn('data:image/png;base64,fake');
        $this->app->instance(TokenPrintService::class, $service);
    }

    public function test_print_preview_returns_200_for_admin_with_valid_tokens(): void
    {
        $this->seed(TokenPrintTestSeeder::class);
        $this->mockQrGeneration();

        $admin = User::where('email', TokenPrintTestSeeder::ADMIN_EMAIL)->firstOrFail();
        $tokens = Token::where('site_id', $admin->site_id)->whereIn('physical_id', ['A1', 'A2'])->orderBy('physical_id')->get();
        $this->assertCount(2, $tokens, 'Seeder should create 2 tokens for the admin site');
        $t1 = $tokens[0];
        $t2 = $tokens[1];

        $response = $this->actingAs($admin)->get(route('admin.tokens.print').'?ids='.$t1->id.','.$t2->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Tokens/Print')
            ->has('cards', 2)
            ->where('cards.0.physical_id', 'A1')
            ->where('cards.1.physical_id', 'A2')
        );
        $props = $response->viewData('page')['props'];
        $this->assertStringStartsWith('data:image/png;base64,', $props['cards'][0]['qr_data_uri']);
    }

    public function test_print_preview_excludes_token_with_empty_qr_code_hash(): void
    {
        $this->mockQrGeneration();
        $admin = \App\Models\User::factory()->admin()->create(['site_id' => $this->site()->id]);
        $t1 = $this->createToken('A1', null, false);
        $t2 = new Token;
        $t2->qr_code_hash = '';
        $t2->physical_id = 'A2';
        $t2->site_id = $this->site()->id;
        $t2->status = 'available';
        $t2->save();

        $result = app(TokenPrintService::class)->prepareTokensForPrint(
            collect([$t1, $t2])
        );

        $this->assertCount(1, $result['cards']);
        $this->assertSame('A1', $result['cards'][0]['physical_id']);
        $this->assertSame(1, $result['skipped']);
        $this->assertArrayHasKey($t2->id, $result['skip_reasons']);
        $this->assertSame('empty_hash', $result['skip_reasons'][$t2->id]);
    }

    public function test_prepare_tokens_for_print_generates_qr_data_uri(): void
    {
        $this->mockQrGeneration();
        $token = $this->createToken('B15', null, false);
        $service = app(TokenPrintService::class);

        $result = $service->prepareTokensForPrint(collect([$token]));

        $this->assertCount(1, $result['cards']);
        $this->assertSame('B15', $result['cards'][0]['physical_id']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['cards'][0]['qr_data_uri']);
        $this->assertSame($token->qr_code_hash, $result['cards'][0]['qr_hash']);
    }

    public function test_print_preview_shows_empty_state_when_no_tokens(): void
    {
        $admin = \App\Models\User::factory()->admin()->create(['site_id' => $this->site()->id]);

        $response = $this->actingAs($admin)->get(route('admin.tokens.print'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Tokens/Print')
            ->has('cards')
            ->where('cards', [])
            ->where('skipped', 0)
        );
    }

    public function test_non_admin_cannot_access_print_preview(): void
    {
        $staff = \App\Models\User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get(route('admin.tokens.print'));

        $response->assertStatus(403);
    }

    public function test_prepare_tokens_accepts_custom_base_url(): void
    {
        $this->mockQrGeneration();
        $token = $this->createToken('C1', null, false);
        $service = app(TokenPrintService::class);

        $result = $service->prepareTokensForPrint(collect([$token]), 'https://example.local');

        $this->assertCount(1, $result['cards']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['cards'][0]['qr_data_uri']);
    }
}
