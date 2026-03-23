<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md H1: credentials (local), username login, recovery_gmail.
 * Keeps users.password in sync with user_credentials.secret via UserLocalCredentialService (UserProvisioningService on relevant saves).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique();
            $table->string('recovery_gmail')->nullable();
        });

        $this->backfillUsernamesAndRecoveryGmail();

        Schema::create('user_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('identifier');
            $table->text('secret')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'identifier']);
            $table->index(['user_id', 'provider']);
        });

        $now = now();
        foreach (DB::table('users')->orderBy('id')->cursor() as $user) {
            if ($user->username === null || $user->username === '') {
                continue;
            }
            DB::table('user_credentials')->insert([
                'user_id' => $user->id,
                'provider' => 'local',
                'identifier' => $user->username,
                'secret' => $user->password,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'recovery_gmail']);
        });
    }

    private function backfillUsernamesAndRecoveryGmail(): void
    {
        $rows = DB::table('users')->orderBy('id')->get();
        $used = [];

        foreach ($rows as $row) {
            $email = (string) $row->email;
            $local = Str::before($email, '@');
            $base = Str::slug($local) ?: 'user';
            $base = strlen($base) > 0 ? $base : 'user';
            $username = $base;
            $n = 0;
            while (isset($used[$username])) {
                $username = $base.'.'.(++$n);
            }
            $used[$username] = true;

            $lower = strtolower($email);
            $recovery = (str_ends_with($lower, '@gmail.com') || str_ends_with($lower, '@googlemail.com'))
                ? $email
                : null;

            DB::table('users')->where('id', $row->id)->update([
                'username' => $username,
                'recovery_gmail' => $recovery,
                'updated_at' => now(),
            ]);
        }
    }
};
