<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per client-data-atomization plan: replace name/birth_year with first_name, middle_name,
 * last_name, birth_date; add structured address on identity_registrations. Preserve all
 * existing columns (program_id, request_type, session_id, token_id, track_id,
 * client_category, mobile_*, id_verified, status, client_id, etc.); only add/drop name and birth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('track_id');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
            $table->date('birth_date')->nullable()->after('last_name');
            $table->string('address_line_1', 255)->nullable()->after('birth_date');
            $table->string('address_line_2', 255)->nullable()->after('address_line_1');
            $table->string('city', 100)->nullable()->after('address_line_2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->string('country', 100)->nullable()->after('postal_code');
        });

        $this->backfillIdentityRegistrations();

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropColumn(['name', 'birth_year']);
        });
    }

    public function down(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('track_id');
            $table->unsignedSmallInteger('birth_year')->nullable()->after('name');
        });

        DB::table('identity_registrations')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $name = trim(implode(' ', array_filter([
                    $row->first_name ?? '',
                    $row->middle_name ?? '',
                    $row->last_name ?? '',
                ]))) ?: null;
                $birthYear = $row->birth_date ? (int) substr($row->birth_date, 0, 4) : null;
                DB::table('identity_registrations')->where('id', $row->id)->update([
                    'name' => $name,
                    'birth_year' => $birthYear,
                ]);
            }
        });

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'birth_date',
                'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
            ]);
        });
    }

    private function backfillIdentityRegistrations(): void
    {
        $rows = DB::table('identity_registrations')->get(['id', 'name', 'birth_year']);
        foreach ($rows as $row) {
            $parts = $row->name ? preg_split('/\s+/', trim($row->name), 3, PREG_SPLIT_NO_EMPTY) : [];
            $first = $parts[0] ?? null;
            $middle = isset($parts[2]) ? $parts[1] : null;
            $last = $parts[2] ?? ($parts[1] ?? null);
            $birthDate = $row->birth_year ? sprintf('%d-01-01', $row->birth_year) : null;

            DB::table('identity_registrations')->where('id', $row->id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'birth_date' => $birthDate,
            ]);
        }
    }
};
