<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollValidationTest extends TestCase
{
    #[DataProvider('hourFields')]
    public function test_store_rejects_hours_exceeding_744(string $field): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');
        $countBefore = DB::table('payroll')->count();

        $response = $this->postJson('/api/payroll', [
            'employee_id' => $employeeId,
            'pay_period_start' => '2026-08-16',
            'pay_period_end' => '2026-08-31',
            'regular_hours' => 88,
            'overtime_hours' => 0,
            $field => 745,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('payroll', $countBefore);
    }

    #[DataProvider('hourFields')]
    public function test_store_accepts_hours_at_the_744_boundary(string $field): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');

        $response = $this->postJson('/api/payroll', [
            'employee_id' => $employeeId,
            'pay_period_start' => '2026-08-16',
            'pay_period_end' => '2026-08-31',
            'regular_hours' => 88,
            'overtime_hours' => 0,
            $field => 744,
        ]);

        $response->assertCreated();
    }

    public static function hourFields(): array
    {
        return [
            'regular_hours' => ['regular_hours'],
            'overtime_hours' => ['overtime_hours'],
            'rest_day_ot_hours' => ['rest_day_ot_hours'],
            'special_day_ot_hours' => ['special_day_ot_hours'],
            'holiday_ot_hours' => ['holiday_ot_hours'],
        ];
    }

    /**
     * The Laravel `max:744` rule only guards the one entry point that runs
     * through PayrollController::store(). This proves the safety net still
     * in place underneath it: the payroll table's CHECK constraints reject
     * an out-of-range value even when written directly (e.g. a raw insert,
     * an import script, or a future code path that bypasses the controller).
     */
    public function test_database_check_constraint_rejects_hours_exceeding_744_independently_of_http_validation(): void
    {
        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CHECK constraint failed/');

        DB::table('payroll')->insert([
            'employee_id' => $employeeId,
            'pay_period_start' => '2026-08-16',
            'pay_period_end' => '2026-08-31',
            'regular_hours' => 745,
        ]);
    }
}
