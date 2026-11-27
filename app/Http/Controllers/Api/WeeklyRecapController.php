<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyImport;
use App\Services\Exports\WeeklyRecapEtdReader;
use App\Services\Exports\WeeklyRecapWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WeeklyRecapController extends Controller
{
    public function __invoke(
        Request $request,
        WeeklyRecapWorkbook $workbook,
        WeeklyRecapEtdReader $etdReader
    ): BinaryFileResponse {
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $imports = WeeklyImport::query()
            ->where('import_type', 'weekly')
            ->where('status', 'completed')
            ->whereBetween('snapshot_date', [$range['from'], $range['to']])
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get(['id', 'snapshot_date', 'original_filename', 'metadata'])
            ->keyBy('id');

        if ($imports->isEmpty()) {
            throw ValidationException::withMessages([
                'from' => 'Tidak ada snapshot mingguan dalam rentang tanggal ini.',
            ]);
        }

        $source = DB::table('imported_orders as orders')
            ->join('order_periods as periods', 'periods.imported_order_id', '=', 'orders.id')
            ->whereIntegerInRaw('orders.weekly_import_id', $imports->modelKeys());

        $requestedDates = [];

        foreach ((clone $source)->distinct()->get([
            'orders.weekly_import_id',
            'orders.department',
            'periods.sr_issue_date',
        ]) as $period) {
            $sr = substr((string) $period->sr_issue_date, 0, 10);
            $requestedDates[$period->weekly_import_id][$period->department][] = $sr;
        }

        if ($requestedDates === []) {
            throw ValidationException::withMessages([
                'from' => 'Snapshot dalam rentang ini belum memiliki data periode.',
            ]);
        }

        [$etdIndex, $periodColumns] = $this->buildEtdIndex(
            $imports,
            $requestedDates,
            $etdReader
        );

        $rows = $source
            ->join('weekly_imports as imports', 'imports.id', '=', 'orders.weekly_import_id')
            ->orderBy('orders.department')
            ->orderBy('orders.product_code')
            ->orderBy('imports.snapshot_date')
            ->orderBy('imports.id')
            ->orderBy('orders.id')
            ->orderBy('periods.sr_issue_date')
            ->select([
                'orders.id as order_id',
                'orders.weekly_import_id',
                'orders.department',
                'orders.product_code',
                'orders.car_model',
                'orders.family',
                'periods.sr_issue_date',
                'periods.quantity',
                'periods.cumulative_quantity',
            ])
            ->cursor();

        $path = $workbook->write(
            $periodColumns,
            $this->orderRows($rows, $imports, $etdIndex)
        );

        $filename = "rekap-mingguan-{$range['from']}-{$range['to']}.xlsx";
        clearstatcache(true, $path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    private function buildEtdIndex(
        Collection $imports,
        array $requestedDates,
        WeeklyRecapEtdReader $reader
    ): array {
        $disk = Storage::disk('local');
        $index = [];
        $columnKeys = [];

        foreach ($requestedDates as $importId => $departmentDates) {
            $import = $imports->get($importId);
            $filename = $import->original_filename;
            $storagePath = data_get($import->metadata, 'storage_path');

            if (!is_string($storagePath) || $storagePath === '' || !$disk->exists($storagePath)) {
                throw ValidationException::withMessages([
                    'from' => "File sumber {$filename} tidak ditemukan.",
                ]);
            }

            try {
                $etdByDepartment = $reader->read($disk->path($storagePath), $departmentDates);
            } catch (RuntimeException | SpreadsheetException $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'from' => "Baris ETD pada {$filename} gagal dibaca.",
                ]);
            }

            foreach ($departmentDates as $department => $dates) {
                foreach ($dates as $sr) {
                    $etd = $etdByDepartment[$department][$sr] ?? null;

                    if ($etd === null) {
                        throw ValidationException::withMessages([
                            'from' => "ETD tidak ditemukan untuk SR ISSUE DATE {$sr} "
                                . "di worksheet {$department}, file {$filename}.",
                        ]);
                    }

                    $key = $etd . '|' . $sr;
                    $index[$importId][$department][$sr] = $key;
                    $columnKeys[$key] = true;
                }
            }
        }

        $columns = array_keys($columnKeys);
        sort($columns, SORT_STRING);

        return [$index, $columns];
    }

    private function orderRows(
        iterable $periods,
        Collection $imports,
        array $etdIndex
    ): iterable {
        $orderId = null;
        $order = null;

        foreach ($periods as $period) {
            if ($orderId !== $period->order_id) {
                if ($order !== null) {
                    yield $order;
                }

                $orderId = $period->order_id;
                $import = $imports->get($period->weekly_import_id);
                $cutoff = data_get($import->metadata, 'actual_until_date');

                $order = [
                    'department' => $period->department,
                    'product' => $period->product_code,
                    'car_name' => $period->car_model,
                    'family' => $period->family,
                    'snapshot' => $import->snapshot_date->toDateString(),
                    'filename' => pathinfo($import->original_filename, PATHINFO_FILENAME),
                    'cutoff' => is_string($cutoff) && $cutoff !== '' ? $cutoff : null,
                    'values' => [],
                ];
            }

            $sr = substr((string) $period->sr_issue_date, 0, 10);
            $key = $etdIndex[$period->weekly_import_id][$period->department][$sr];

            $order['values'][$key] = [
                'Qty' => $period->quantity,
                'Cum' => $period->cumulative_quantity,
            ];
        }

        if ($order !== null) {
            yield $order;
        }
    }
}