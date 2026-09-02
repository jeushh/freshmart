<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendancePaginationTest extends TestCase
{
    /**
     * Test database isolation.
     *
     * Database isolation is provided by Tests\TestCase::setUp(), which creates a
     * fresh temporary SQLite database (tempnam) per test and runs
     * `migrate:fresh --seed` against it. The persistent development database at
     * apps/api/database/database.sqlite is never targeted. Each test starts from
     * the deterministic seeded state and the temp file is deleted in tearDown().
     *
     * This matches the convention used by every other Feature test in the
     * project; none use RefreshDatabase or DatabaseTransactions because the
     * custom temp-database bootstrap in the base TestCase already guarantees
     * full isolation and row cleanup between tests.
     */
    public function test_attendance_page_two_is_distinct_and_reachable(): void
    {
        $employeeId = $this->createEmployee('PAGED');
        $this->createAttendanceEntries($employeeId, 25, '2040-03');
        $this->actingAs(User::where('username', 'hr')->firstOrFail());

        $pageOne = $this->getJson($this->attendanceUrl($employeeId, 1))
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(20, 'data');
        $pageTwo = $this->getJson($this->attendanceUrl($employeeId, 2))
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(5, 'data');

        $pageOneIds = collect($pageOne->json('data'))->pluck('id')->all();
        $pageTwoIds = collect($pageTwo->json('data'))->pluck('id')->all();

        $this->assertEmpty(array_intersect($pageOneIds, $pageTwoIds));
    }

    public function test_attendance_employee_filter_returns_only_selected_employee_with_correct_metadata(): void
    {
        $selectedEmployeeId = $this->createEmployee('SELECTED');
        $otherEmployeeId = $this->createEmployee('OTHER');
        $this->createAttendanceEntries($selectedEmployeeId, 25, '2040-04');
        $this->createAttendanceEntries($otherEmployeeId, 3, '2040-04');
        $this->actingAs(User::where('username', 'hr')->firstOrFail());

        $response = $this->getJson($this->attendanceUrl($selectedEmployeeId, 2, '2040-04-01', '2040-04-25'))
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(5, 'data');

        foreach ($response->json('data') as $record) {
            $this->assertSame($selectedEmployeeId, $record['employee_id']);
        }
    }

    public function test_attendance_date_filters_narrow_results(): void
    {
        $employeeId = $this->createEmployee('DATES');
        $this->createAttendanceEntries($employeeId, 10, '2040-05');
        $this->actingAs(User::where('username', 'hr')->firstOrFail());

        $response = $this->getJson(
            '/api/attendance?from=2040-05-03&to=2040-05-05&per_page=20',
        )->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $record) {
            $this->assertGreaterThanOrEqual('2040-05-03', $record['log_date']);
            $this->assertLessThanOrEqual('2040-05-05', $record['log_date']);
        }
    }

    public function test_attendance_view_authorization_is_unchanged(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/attendance')->assertForbidden();

        $this->actingAs(User::where('username', 'hr')->firstOrFail());
        $this->getJson('/api/attendance')->assertOk();
    }

    private function attendanceUrl(
        int $employeeId,
        int $page,
        ?string $from = null,
        ?string $to = null,
    ): string {
        $parameters = [
            'employee_id' => $employeeId,
            'per_page' => 20,
            'page' => $page,
        ];

        if ($from !== null) {
            $parameters['from'] = $from;
        }
        if ($to !== null) {
            $parameters['to'] = $to;
        }

        return '/api/attendance?'.http_build_query($parameters);
    }

    private function createEmployee(string $suffix): int
    {
        return DB::table('employees')->insertGetId([
            'employee_no' => "PAGE-{$suffix}",
            'full_name' => "Pagination {$suffix}",
            'position' => 'Test Analyst',
            'department' => 'Human Resources',
            'hire_date' => '2040-01-01',
        ]);
    }

    private function createAttendanceEntries(int $employeeId, int $count, string $month): void
    {
        foreach (range(1, $count) as $day) {
            DB::table('attendance_logs')->insert([
                'employee_id' => $employeeId,
                'log_date' => sprintf('%s-%02d', $month, $day),
                'time_in' => '08:00',
                'time_out' => '17:00',
                'status' => 'Present',
            ]);
        }
    }
}
