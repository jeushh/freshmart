<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CsvExportService;
use App\Services\ReportFilterService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function show(
        Request $request,
        string $report,
        ReportFilterService $filters,
        ReportService $reports,
    ): array {
        return $reports->report($report, $filters->validate($request, $report));
    }

    public function export(
        Request $request,
        string $report,
        ReportFilterService $filters,
        ReportService $reports,
        CsvExportService $csv,
    ): StreamedResponse {
        abort_unless(
            $request->user()->hasAnyPermission('reports.export'),
            403,
            'Report export permission is required.',
        );
        $validated = $filters->validate($request, $report);
        $columns = $reports->columns($report);
        $filename = "freshmart-{$report}-report-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use (
            $columns,
            $csv,
            $report,
            $reports,
            $validated,
        ) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_column($columns, 'label'));
            foreach ($reports->exportRows($report, $validated) as $row) {
                fputcsv($output, array_map(
                    fn ($column) => $csv->safeCell($row->{$column['key']} ?? null),
                    $columns,
                ));
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Row-Limit' => '10000',
        ]);
    }
}
