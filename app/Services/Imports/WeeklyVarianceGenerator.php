<?php

namespace App\Services\Imports;

use App\Models\WeeklyImport;
use App\Models\VarianceReport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WeeklyVarianceGenerator
{
    public function __construct(
        private readonly PemiIsuzuReader $reader,
        private readonly RollingVarianceService $engine,
        private readonly VariancePersistenceService $persister,
    ) {}

    public function generateForImport(WeeklyImport $current): ?VarianceReport
    {
        if ($current->status !== 'completed' || $current->import_type !== 'weekly') {
            throw new RuntimeException('Snapshot terbaru harus berstatus completed dan bertipe weekly.');
        }

        $previous = WeeklyImport::query()
            ->where('import_type', 'weekly')
            ->where('status', 'completed')
            ->where('snapshot_date', '<', $current->snapshot_date->format('Y-m-d'))
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->first();

        if (!$previous) {
            return null;
        }

        return $this->generatePair($previous, $current);
    }

    public function generatePair(
        WeeklyImport|int $previous,
        WeeklyImport|int $current
    ): VarianceReport {
        $previous = $previous instanceof WeeklyImport
            ? $previous
            : WeeklyImport::findOrFail($previous);

        $current = $current instanceof WeeklyImport
            ? $current
            : WeeklyImport::findOrFail($current);

        if ($previous->id === $current->id) {
            throw new RuntimeException('Snapshot sebelumnya dan terbaru tidak boleh sama.');
        }

        if (
            $previous->import_type !== 'weekly' ||
            $current->import_type !== 'weekly' ||
            $previous->status !== 'completed' ||
            $current->status !== 'completed'
        ) {
            throw new RuntimeException('Kedua data harus merupakan Weekly Snapshot berstatus completed.');
        }

        if (
            !$previous->snapshot_date ||
            !$current->snapshot_date ||
            $previous->snapshot_date->greaterThanOrEqualTo($current->snapshot_date)
        ) {
            throw new RuntimeException('Tanggal snapshot sebelumnya harus lebih awal dari snapshot terbaru.');
        }

        $disk = Storage::disk('local');
        $previousPath = data_get($previous->metadata, 'storage_path');
        $currentPath = data_get($current->metadata, 'storage_path');

        if (
            !$previousPath || !$currentPath ||
            !$disk->exists($previousPath) || !$disk->exists($currentPath)
        ) {
            throw new RuntimeException('File sumber snapshot sebelumnya atau terbaru tidak ditemukan.');
        }

        $previousSnapshot = $this->reader->read(
            $disk->path($previousPath),
            $previous->snapshot_date->format('Y-m-d')
        );

        $currentSnapshot = $this->reader->read(
            $disk->path($currentPath),
            $current->snapshot_date->format('Y-m-d')
        );

        $result = $this->engine->compare($previousSnapshot, $currentSnapshot);

        $result['previous_snapshot_date'] = $previous->snapshot_date->format('Y-m-d');
        $result['current_snapshot_date'] = $current->snapshot_date->format('Y-m-d');

        return $this->persister->save($previous->id, $current->id, $result);
    }
}
