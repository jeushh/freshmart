<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPaymentController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'accounts_payable_id' => 'sometimes|integer|exists:accounts_payable,id',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = $this->baseQuery();
        if (isset($data['supplier_id'])) {
            $query->where('supplier_payments.supplier_id', $data['supplier_id']);
        }
        if (isset($data['accounts_payable_id'])) {
            $query->where('supplier_payments.accounts_payable_id', $data['accounts_payable_id']);
        }
        if (isset($data['from'])) {
            $query->whereDate('supplier_payments.payment_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('supplier_payments.payment_date', '<=', $data['to']);
        }

        return [
            'payments' => $query
                ->orderByDesc('supplier_payments.payment_date')
                ->orderByDesc('supplier_payments.id')
                ->paginate($data['per_page'] ?? 20),
        ];
    }

    public function show(int $id)
    {
        $payment = $this->baseQuery()->where('supplier_payments.id', $id)->first();
        abort_unless($payment, 404);

        return ['payment' => $payment];
    }

    public function forPayable(int $accountsPayable)
    {
        abort_unless(DB::table('accounts_payable')->where('id', $accountsPayable)->exists(), 404);

        return [
            'payments' => $this->baseQuery()
                ->where('supplier_payments.accounts_payable_id', $accountsPayable)
                ->orderBy('supplier_payments.payment_date')
                ->orderBy('supplier_payments.id')
                ->get(),
        ];
    }

    public function store(Request $request, int $accountsPayable)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'payment_method' => 'required|string|max:100',
            'reference_number' => 'nullable|string|max:255',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'required|string|max:255',
        ]);
        $payload = $this->normalizePayload($data, $accountsPayable);
        abort_if($payload['amount_cents'] <= 0, 422, 'Payment amount must be at least 0.01.');
        abort_if($payload['payment_method'] === '', 422, 'Payment method is required.');
        abort_if($payload['idempotency_key'] === '', 422, 'Idempotency key is required.');

        try {
            $result = DB::transaction(function () use ($payload, $request) {
                $existing = DB::table('supplier_payments')
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->first();
                if ($existing) {
                    return $this->replayResult($existing, $payload);
                }

                $payable = DB::table('accounts_payable')
                    ->where('id', $payload['accounts_payable_id'])
                    ->lockForUpdate()
                    ->first();
                abort_unless($payable, 404);

                $totalCents = $this->cents($payable->total_amount);
                $paidCents = $this->cents($payable->amount_paid);
                $outstandingCents = $totalCents - $paidCents;
                abort_if($outstandingCents <= 0, 409, 'Accounts payable is already fully paid.');
                abort_if(
                    $payload['amount_cents'] > $outstandingCents,
                    409,
                    'Payment amount exceeds the outstanding balance.',
                );

                $paymentId = DB::table('supplier_payments')->insertGetId([
                    'accounts_payable_id' => $payable->id,
                    'supplier_id' => $payable->supplier_id,
                    'purchase_order_id' => $payable->purchase_order_id,
                    'supplier_invoice_id' => $payable->supplier_invoice_id,
                    'amount' => $payload['amount_cents'] / 100,
                    'payment_method' => $payload['payment_method'],
                    'reference_number' => $payload['reference_number'],
                    'payment_date' => $payload['payment_date'],
                    'notes' => $payload['notes'],
                    'idempotency_key' => $payload['idempotency_key'],
                    'created_by' => $request->user()->username,
                ]);

                $newPaidCents = $paidCents + $payload['amount_cents'];
                $status = $newPaidCents === $totalCents ? 'Paid' : 'Partially Paid';

                DB::table('accounts_payable')->where('id', $payable->id)->update([
                    'amount_paid' => $newPaidCents / 100,
                    'status' => $status,
                ]);

                DB::table('financial_transactions')->insert([
                    'transaction_type' => 'Supplier Payment',
                    'amount' => $payload['amount_cents'] / 100,
                    'direction' => 'Out',
                    'reference_type' => 'supplier_payment',
                    'reference_id' => (string) $paymentId,
                    'description' => "Accounts payable settlement #{$payable->id}",
                    'category' => 'Accounts Payable Settlement',
                    'payment_method' => $payload['payment_method'],
                    'created_by' => $request->user()->username,
                ]);

                AuditLogger::record(
                    $request,
                    'supplier_payment.created',
                    'supplier_payment',
                    $paymentId,
                    [
                        'accounts_payable_id' => $payable->id,
                        'amount' => $payload['amount_cents'] / 100,
                        'previous_amount_paid' => $paidCents / 100,
                        'new_amount_paid' => $newPaidCents / 100,
                        'resulting_status' => $status,
                    ],
                );

                return $this->result($paymentId, $payable->id, false);
            });
        } catch (QueryException $exception) {
            $existing = DB::table('supplier_payments')
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();
            if (! $existing) {
                throw $exception;
            }

            $result = $this->replayResult($existing, $payload);
        }

        return response()->json($result, $result['idempotent_replay'] ? 200 : 201);
    }

    private function replayResult(object $existing, array $payload): array
    {
        abort_unless($this->sameLogicalPayment($existing, $payload), 409, 'Idempotency key is already in use.');

        return $this->result($existing->id, $existing->accounts_payable_id, true);
    }

    private function sameLogicalPayment(object $existing, array $payload): bool
    {
        return (int) $existing->accounts_payable_id === $payload['accounts_payable_id']
            && $this->cents($existing->amount) === $payload['amount_cents']
            && $existing->payment_method === $payload['payment_method']
            && $existing->reference_number === $payload['reference_number']
            && $existing->payment_date === $payload['payment_date']
            && $existing->notes === $payload['notes'];
    }

    private function result(int $paymentId, int $accountsPayableId, bool $replay): array
    {
        $payable = $this->payable($accountsPayableId);

        return [
            'payment' => $this->baseQuery()->where('supplier_payments.id', $paymentId)->first(),
            'payable' => $payable,
            'outstanding_balance' => $payable->outstanding_balance,
            'idempotent_replay' => $replay,
        ];
    }

    private function normalizePayload(array $data, int $accountsPayable): array
    {
        return [
            'accounts_payable_id' => $accountsPayable,
            'amount_cents' => $this->cents($data['amount']),
            'payment_method' => trim($data['payment_method']),
            'reference_number' => $this->optionalString($data['reference_number'] ?? null),
            'payment_date' => CarbonImmutable::parse($data['payment_date'])->format('Y-m-d'),
            'notes' => $this->optionalString($data['notes'] ?? null),
            'idempotency_key' => trim($data['idempotency_key']),
        ];
    }

    private function optionalString(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function cents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function baseQuery()
    {
        return DB::table('supplier_payments')
            ->leftJoin('suppliers', 'supplier_payments.supplier_id', '=', 'suppliers.id')
            ->leftJoin('purchase_orders', 'supplier_payments.purchase_order_id', '=', 'purchase_orders.id')
            ->leftJoin('supplier_invoices', 'supplier_payments.supplier_invoice_id', '=', 'supplier_invoices.id')
            ->leftJoin('accounts_payable', 'supplier_payments.accounts_payable_id', '=', 'accounts_payable.id')
            ->select(
                'supplier_payments.id',
                'supplier_payments.accounts_payable_id',
                'supplier_payments.supplier_id',
                'suppliers.name as supplier_name',
                'supplier_payments.purchase_order_id',
                'purchase_orders.po_number',
                'supplier_payments.supplier_invoice_id',
                DB::raw('COALESCE(supplier_invoices.invoice_number, accounts_payable.invoice_number) as invoice_number'),
                'supplier_payments.amount',
                'supplier_payments.payment_method',
                'supplier_payments.reference_number',
                'supplier_payments.payment_date',
                'supplier_payments.notes',
                'supplier_payments.created_by',
                'supplier_payments.created_at',
            );
    }

    private function payable(int $id): object
    {
        $row = DB::table('accounts_payable')
            ->leftJoin('suppliers', 'accounts_payable.supplier_id', '=', 'suppliers.id')
            ->leftJoin('purchase_orders', 'accounts_payable.purchase_order_id', '=', 'purchase_orders.id')
            ->select(
                'accounts_payable.*',
                'suppliers.name as supplier_name',
                'purchase_orders.po_number',
            )
            ->where('accounts_payable.id', $id)
            ->first();
        abort_unless($row, 404);

        $outstandingCents = max(0, $this->cents($row->total_amount) - $this->cents($row->amount_paid));
        $row->outstanding_balance = $outstandingCents / 100;
        $row->source = $row->supplier_invoice_id !== null ? 'structured' : 'legacy';
        $row->overdue = $row->status !== 'Paid'
            && $row->due_date !== null
            && $row->due_date < now()->format('Y-m-d');

        return $row;
    }
}
