<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per client-data-atomization plan: replace name/birth_year with first_name, middle_name,
 * last_name, birth_date; add structured address. Preserve site_id, mobile_encrypted,
 * mobile_hash, identity_hash, timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('site_id');
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

        $this->backfillClients();

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['name', 'birth_year']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('site_id');
            $table->unsignedSmallInteger('birth_year')->nullable()->after('name');
        });

        // Reverse backfill: concat name, extract year from birth_date
        DB::table('clients')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $name = trim(implode(' ', array_filter([
                    $row->first_name ?? '',
                    $row->middle_name ?? '',
                    $row->last_name ?? '',
                ]))) ?: 'Unknown';
                $birthYear = $row->birth_date ? (int) substr($row->birth_date, 0, 4) : null;
                DB::table('clients')->where('id', $row->id)->update([
                    'name' => $name,
                    'birth_year' => $birthYear,
                ]);
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'birth_date',
                'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
            ]);
        });
    }

    private function backfillClients(): void
    {
        $rows = DB::table('clients')->get(['id', 'name', 'birth_year']);
        foreach ($rows as $row) {
            $parts = $row->name ? preg_split('/\s+/', trim($row->name), 3, PREG_SPLIT_NO_EMPTY) : [];
            $first = $parts[0] ?? 'Unknown';
            $middle = isset($parts[2]) ? $parts[1] : null;
            $last = $parts[2] ?? ($parts[1] ?? 'Unknown');
            $birthDate = $row->birth_year ? sprintf('%d-01-01', $row->birth_year) : null;

            DB::table('clients')->where('id', $row->id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'birth_date' => $birthDate,
            ]);
        }
    }
};
