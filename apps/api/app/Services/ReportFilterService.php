<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReportFilterService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    public function validate(Request $request, string $report): array
    {
        $settings = $this->settings->all();
        $now = CarbonImmutable::now($settings['timezone']);
        $input = $request->query();
        $input['from'] ??= $now->startOfMonth()->toDateString();
        $input['to'] ??= $now->toDateString();

        $rules = array_merge([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ], match ($report) {
            'sales' => [
                'cashier' => ['sometimes', 'string', 'max:60'],
                'payment_method' => ['sometimes', 'in:Cash,Card,QR'],
                'transaction_status' => ['sometimes', 'in:Finalized,Refunded'],
                'product_id' => ['sometimes', 'integer', 'exists:products,id'],
                'category' => ['sometimes', 'string', 'max:80'],
            ],
            'inventory' => [
                'category' => ['sometimes', 'string', 'max:80'],
                'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
                'stock_state' => ['sometimes', 'in:all,low,out,above_max'],
                'movement_type' => [
                    'sometimes',
                    'in:Sale,Refund,Stock In,Stock Out,Adjustment,Purchase,Receiving',
                ],
            ],
            'procurement' => [
                'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
                'approval_status' => [
                    'sometimes',
                    'in:Draft,Submitted,Approved,Rejected,Cancelled',
                ],
                'operational_status' => [
                    'sometimes',
                    'in:Pending,Approved,Ordered,Partially Received,Fully Received,Cancelled',
                ],
            ],
            'hr' => [
                'employee_status' => ['sometimes', 'in:Active,On Leave,Terminated'],
                'department' => ['sometimes', 'string', 'max:80'],
                'attendance_status' => ['sometimes', 'in:Present,Late,Absent,On Leave'],
                'hr_request_status' => ['sometimes', 'in:Pending,Approved,Rejected'],
            ],
            'payroll' => [
                'department' => ['sometimes', 'string', 'max:80'],
                'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
                'payroll_status' => [
                    'sometimes',
                    'in:Draft,Pending Approval,Approved,Paid',
                ],
            ],
            'finance' => [
                'category' => ['sometimes', 'string', 'max:100'],
                'direction' => ['sometimes', 'in:In,Out'],
                'finance_status' => [
                    'sometimes',
                    'in:Posted,Unpaid,Partially Paid,Paid,Overdue',
                ],
            ],
            default => throw ValidationException::withMessages([
                'report' => ['Unsupported report type.'],
            ]),
        });

        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($input, $settings) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            $days = CarbonImmutable::parse($input['from'])
                ->diffInDays(CarbonImmutable::parse($input['to'])) + 1;
            if ($days > $settings['report_max_date_range_days']) {
                $validator->errors()->add(
                    'to',
                    "Date range cannot exceed {$settings['report_max_date_range_days']} days.",
                );
            }
        });

        return $validator->validate();
    }

    public function descriptions(array $filters): array
    {
        return collect($filters)
            ->except(['page', 'per_page'])
            ->map(fn ($value, $key) => [
                'key' => $key,
                'label' => str($key)->replace('_', ' ')->title()->toString(),
                'value' => (string) $value,
            ])
            ->values()
            ->all();
    }
}
