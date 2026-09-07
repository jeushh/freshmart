<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollOvertimeCalculationTest extends TestCase
{
    public function test_day_type_overtime_buckets_use_correct_statutory_multipliers(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        // EMP-0001 (seeded): pay_type Monthly, basic_salary 16000, hourly_rate 100.
        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');

        $response = $this->postJson('/api/payroll', [
            'employee_id' => $employeeId,
            'pay_period_start' => '2026-08-16',
            'pay_period_end' => '2026-08-31',
            'regular_hours' => 88,
            'overtime_hours' => 2,       // Ordinary OT: 2 x 100 x 1.25   = 300.00
            'rest_day_ot_hours' => 3,    // Rest day OT: 3 x 100 x 1.69   = 608.40
            'special_day_ot_hours' => 1, // Special day OT: 1 x 100 x 1.69 = 202.80
            'holiday_ot_hours' => 4,     // Holiday OT: 4 x 100 x 2.60    = 1248.00
        ]);

        $response->assertCreated()
            ->assertJsonPath('overtime_pay', 312.5)
            ->assertJsonPath('rest_day_ot_pay', 633.75)
            ->assertJsonPath('special_day_ot_pay', 211.25)
            ->assertJsonPath('holiday_ot_pay', 1300);

        // Monthly-paid: basic pay is the flat semi-monthly salary share (16000 / 2),
        // regardless of hours worked; only the OT buckets add on top of it.
        // net = 8000 (basic) + 312.5 + 633.75 + 211.25 + 1300 = 10457.50
        $this->assertSame(10457.50, (float) $response->json('net_pay'));
    }

    public function test_day_type_overtime_buckets_default_to_zero_when_omitted(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');

        $response = $this->postJson('/api/payroll', [
            'employee_id' => $employeeId,
            'pay_period_start' => '2026-09-01',
            'pay_period_end' => '2026-09-15',
            'regular_hours' => 88,
            'overtime_hours' => 0,
            // rest_day_ot_hours / special_day_ot_hours / holiday_ot_hours intentionally omitted
        ]);

        $response->assertCreated()
            ->assertJsonPath('rest_day_ot_pay', 0)
            ->assertJsonPath('special_day_ot_pay', 0)
            ->assertJsonPath('holiday_ot_pay', 0);
    }
}
