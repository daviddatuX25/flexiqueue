<?php
namespace Tests\Unit\Providers;
use App\Providers\AppServiceProvider;
use Tests\TestCase;
class SqlCipherConfigTest extends TestCase
{
  public function test_key_derivation_returns_64_hex_chars(): void
  {
    $appKey = 'base64:' . base64_encode(str_repeat('a', 32));
    $hexKey = AppServiceProvider::deriveSqlCipherKey($appKey);
    $this->assertSame(64, strlen($hexKey));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hexKey);
}
  public function test_different_app_keys_produce_different_cipher_keys(): void
  {
    $key1 = AppServiceProvider::deriveSqlCipherKey('base64:' . base64_encode(str_repeat('a', 32)));
    $key2 = AppServiceProvider::deriveSqlCipherKey('base64:' . base64_encode(str_repeat('b', 32)));
    $this->assertNotSame($key1, $key2);
}
  public function test_same_app_key_always_produces_same_cipher_key(): void
  {
    $appKey = 'base64:' . base64_encode(str_repeat('x', 32));
    $this->assertSame(
      AppServiceProvider::deriveSqlCipherKey($appKey),
      AppServiceProvider::deriveSqlCipherKey($appKey),
);}
}