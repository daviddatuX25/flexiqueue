---
name: laravel-testing-expert
description: "Laravel PHPUnit testing: feature tests, unit tests, factories, mocks, TDD. Use when writing or reviewing Laravel tests, PHPUnit feature/unit tests, factories, mocks, RefreshDatabase, assertRedirect, assertJsonPath, TDD, Laravel test."
---

# Laravel Testing Expert (PHPUnit)

This project uses **PHPUnit only** for backend tests. Use the Laravel Testing Expert skill when writing or reviewing feature tests, unit tests, factories, and mocks.

**Triggers:** PHPUnit, feature test, unit test, factory, mock, RefreshDatabase, assertRedirect, assertJsonPath, TDD, Laravel test.

---

## Framework

- **PHPUnit only** — see [composer.json](../../composer.json) and [phpunit.xml](../../phpunit.xml). Do not use or reference Pest.
- **Run command:** `./vendor/bin/sail artisan test` (or `php artisan test` when not using Sail).

---

## Feature tests

- Extend `Tests\TestCase`.
- Use `$this->get()`, `$this->post()`, `$this->put()`, `$this->delete()` etc.
- Assert: `assertStatus()`, `assertRedirect()`, `assertSessionHas()`, `assertDatabaseHas()`, `assertDatabaseMissing()`.
- For JSON API routes: `assertJsonPath()`, `assertJsonFragment()`, `assertJsonStructure()`.
- Use `RefreshDatabase` or `DatabaseMigrations` in the test class or `setUp()` so each test has a clean DB.

```php
// Example
$this->post('/login', ['email' => $user->email, 'password' => 'password'])
    ->assertRedirect(route('admin.dashboard'));
$this->assertAuthenticatedAs($user);
```

---

## Unit tests

- Isolate logic with mocks. Use Laravel's `Mockery` or PHPUnit test doubles.
- For external HTTP: `Http::fake()` and then assert the request/response.
- Mock services by binding a fake in the container or passing a double into the class under test.

---

## Factories and state

- Use model factories; define states for common scenarios (e.g. `User::factory()->staff()->create()`).
- Match [docs/plans/QUALITY-GATES.md](../../docs/plans/QUALITY-GATES.md) Section 3 for test data setup.

---

## Test naming

- **Pattern:** `test_{action}_{scenario}_{expected_outcome}` (per QUALITY-GATES Section 3.2).

```php
public function test_login_with_valid_credentials_redirects_by_role(): void
public function test_login_with_invalid_credentials_returns_422(): void
public function test_staff_cannot_access_admin_programs_route(): void
```

---

## API assertions

- Assert status and response shape per [docs/architecture/08-API-SPEC-PHASE1.md](../../docs/architecture/08-API-SPEC-PHASE1.md) when the endpoint is specified there.

---

## Transaction log

- Per QUALITY-GATES Section 3.4: every feature test that **mutates session state** must assert that a transaction log entry was created (`assertDatabaseHas('transaction_logs', [...])`).

---

## Code style

- Run Laravel Pint on test files when touching them: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint tests/` (or equivalent).
