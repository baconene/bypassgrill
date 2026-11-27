<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 80);
            // regular | special (special non-working) | special_working (a working day; not a day off)
            $table->string('type', 16);
            // Unconfirmed: the date still has to be checked against that year's proclamation
            $table->boolean('confirmed')->default(true);
            $table->timestamps();
        });

        // Philippine holidays. Dates fixed by law are confirmed; those set by each year's proclamation
        // (Eid dates, some special days, all of 2027 beyond the fixed ones) are left to confirm in Settings.
        $holidays = [
            ['2026-01-01', "New Year's Day", 'regular', true],
            ['2026-02-17', 'Chinese New Year', 'special', true],
            ['2026-03-20', "Eid'l Fitr", 'regular', false],
            ['2026-04-02', 'Maundy Thursday', 'regular', true],
            ['2026-04-03', 'Good Friday', 'regular', true],
            ['2026-04-04', 'Black Saturday', 'special', true],
            ['2026-04-09', 'Araw ng Kagitingan', 'regular', true],
            ['2026-05-01', 'Labor Day', 'regular', true],
            ['2026-05-27', "Eid'l Adha", 'regular', false],
            ['2026-06-12', 'Independence Day', 'regular', true],
            ['2026-08-21', 'Ninoy Aquino Day', 'special', true],
            ['2026-08-31', 'National Heroes Day', 'regular', true],
            ['2026-11-01', "All Saints' Day", 'special', true],
            ['2026-11-02', "All Souls' Day", 'special', false],
            ['2026-11-30', 'Bonifacio Day', 'regular', true],
            ['2026-12-08', 'Immaculate Conception', 'special', true],
            ['2026-12-24', 'Christmas Eve', 'special', false],
            ['2026-12-25', 'Christmas Day', 'regular', true],
            ['2026-12-30', 'Rizal Day', 'regular', true],
            ['2026-12-31', 'Last day of the year', 'special', false],
            ['2027-01-01', "New Year's Day", 'regular', true],
            ['2027-02-06', 'Chinese New Year', 'special', false],
            ['2027-03-10', "Eid'l Fitr", 'regular', false],
            ['2027-03-25', 'Maundy Thursday', 'regular', true],
            ['2027-03-26', 'Good Friday', 'regular', true],
            ['2027-03-27', 'Black Saturday', 'special', false],
            ['2027-04-09', 'Araw ng Kagitingan', 'regular', true],
            ['2027-05-01', 'Labor Day', 'regular', true],
            ['2027-05-17', "Eid'l Adha", 'regular', false],
            ['2027-06-12', 'Independence Day', 'regular', true],
            ['2027-08-21', 'Ninoy Aquino Day', 'special', false],
            ['2027-08-30', 'National Heroes Day', 'regular', true],
            ['2027-11-01', "All Saints' Day", 'special', false],
            ['2027-11-30', 'Bonifacio Day', 'regular', true],
            ['2027-12-08', 'Immaculate Conception', 'special', false],
            ['2027-12-24', 'Christmas Eve', 'special', false],
            ['2027-12-25', 'Christmas Day', 'regular', true],
            ['2027-12-30', 'Rizal Day', 'regular', true],
            ['2027-12-31', 'Last day of the year', 'special', false],
        ];
        $now = now();
        DB::table('holidays')->insert(array_map(fn ($h) => [
            'date' => $h[0], 'name' => $h[1], 'type' => $h[2], 'confirmed' => $h[3],
            'created_at' => $now, 'updated_at' => $now,
        ], $holidays));
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
