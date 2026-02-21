<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_url_returns_null_when_no_avatar_path(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->assertNull($user->avatar_url);
    }

    public function test_avatar_url_returns_storage_url_when_avatar_path_exists(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/abc123.jpg', 'fake-image');

        $user = User::factory()->create(['avatar_path' => 'abc123.jpg']);

        $url = $user->avatar_url;
        $this->assertNotNull($url);
        $this->assertStringContainsString('avatars/abc123.jpg', $url);
    }
}
