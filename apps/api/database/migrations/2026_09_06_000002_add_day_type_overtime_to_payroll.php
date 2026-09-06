<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V1 pragmatic day-type overtime support (per DEVELOPMENT_LOG V58/V59).
 *
 * `overtime_hours` / `overtime_pay` remain "Ordinary OT" (worked beyond 8h on an
 * ordinary working day), unchanged, still at 1.25x hourly_rate.
 *
 * Three new buckets are added for OT worked on other statutory day types, each
 * manually entered and each computed server-side at its correct multiplier:
 *   - rest_day_ot_hours / rest_day_ot_pay       -> 1.69x hourly_rate
 *   - special_day_ot_hours / special_day_ot_pay -> 1.69x hourly_rate
 *   - holiday_ot_hours / holiday_ot_pay         -> 2.60x hourly_rate
 *
 * Known V1 scope limits (not covered by this migration or the buckets above):
 *   - Combined day types (special day falling on rest day = 1.95x, regular
 *     holiday falling on rest day = 3.38x) are not distinguished; work on those
 *     days must currently be recorded under one of the four buckets above and
 *     will be paid at that bucket's rate rather than the combined rate.
 *   - Night shift differential (10PM-6AM, x1.10 multiplicative) is not applied
 *     to any bucket yet.
 *   - The statutory day-rate premium for the first 8 *regular* (non-OT) hours
 *     worked on a rest/special/holiday day is not captured — only the OT
 *     portion beyond 8h is bucketed here, consistent with this item's original
 *     "overtime calculation" scope.
 * These are flagged as follow-up items, not silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN rest_day_ot_hours REAL NOT NULL DEFAULT 0 '
            .'CHECK (rest_day_ot_hours >= 0)'
        );
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN rest_day_ot_pay REAL NOT NULL DEFAULT 0 '
            .'CHECK (rest_day_ot_pay >= 0)'
        );
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN special_day_ot_hours REAL NOT NULL DEFAULT 0 '
            .'CHECK (special_day_ot_hours >= 0)'
        );
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN special_day_ot_pay REAL NOT NULL DEFAULT 0 '
            .'CHECK (special_day_ot_pay >= 0)'
        );
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN holiday_ot_hours REAL NOT NULL DEFAULT 0 '
            .'CHECK (holiday_ot_hours >= 0)'
        );
        DB::statement(
            'ALTER TABLE payroll ADD COLUMN holiday_ot_pay REAL NOT NULL DEFAULT 0 '
            .'CHECK (holiday_ot_pay >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payroll DROP COLUMN holiday_ot_pay');
        DB::statement('ALTER TABLE payroll DROP COLUMN holiday_ot_hours');
        DB::statement('ALTER TABLE payroll DROP COLUMN special_day_ot_pay');
        DB::statement('ALTER TABLE payroll DROP COLUMN special_day_ot_hours');
        DB::statement('ALTER TABLE payroll DROP COLUMN rest_day_ot_pay');
        DB::statement('ALTER TABLE payroll DROP COLUMN rest_day_ot_hours');
    }
};
