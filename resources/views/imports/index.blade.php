@extends('layouts.app')

@section('title', 'Impor Mingguan')

@section('content')
<div class="mx-auto max-w-6xl space-y-6 px-4 py-6">
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h1 class="text-xl font-semibold text-slate-900">Impor Mingguan</h1>

        <form id="weekly-snapshot-form" class="mt-5 space-y-4">
            <div>
                <label for="weekly-excel" class="block text-sm font-medium text-slate-700">
                    File Excel
                </label>
                <input
                    id="weekly-excel"
                    name="file"
                    type="file"
                    accept=".xls,.xlsx,.xlsm"
                    required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                >
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="weekly-snapshot-date" class="block text-sm font-medium text-slate-700">
                        Tanggal snapshot
                    </label>
                    <input
                        id="weekly-snapshot-date"
                        name="snapshot_date"
                        type="date"
                        required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    >
                </div>

                <div>
                    <label for="weekly-actual-date" class="block text-sm font-medium text-slate-700">
                        Actual sampai tanggal
                    </label>
                    <select
                        id="weekly-actual-date"
                        name="actual_until_date"
                        aria-describedby="weekly-preview-status weekly-actual-note"
                        disabled
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
                    >
                        <option value="">Pilih file dan isi tanggal snapshot</option>
                    </select>
                    <p id="weekly-preview-status" role="status" class="mt-1 text-sm text-slate-600"></p>
                    <p id="weekly-actual-note" class="mt-1 text-xs text-slate-500">
                        Pilihan Actual tersedia untuk pratinjau.
                    </p>
                </div>
            </div>

            <button
                id="weekly-save-button"
                type="submit"
                class="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50"
            >
                Simpan Impor
            </button>

            <p id="weekly-save-status" role="status" class="text-sm text-slate-700"></p>
        </form>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold text-slate-900">Riwayat Impor</h2>
        <p id="weekly-history-status" role="status" class="mt-2 text-sm text-slate-600"></p>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-slate-700">
                    <tr>
                        <th scope="col" class="px-3 py-2">Tanggal snapshot</th>
                        <th scope="col" class="px-3 py-2">Nama file</th>
                        <th scope="col" class="px-3 py-2">Status</th>
                        <th scope="col" class="px-3 py-2">Produk</th>
                        <th scope="col" class="px-3 py-2">Periode</th>
                        <th scope="col" class="px-3 py-2">Tindakan</th>
                    </tr>
                </thead>
                <tbody id="weekly-history-rows" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <nav class="mt-4 flex items-center gap-3" aria-label="Halaman riwayat impor">
            <button
                id="weekly-history-prev"
                type="button"
                disabled
                class="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-40"
            >
                Sebelumnya
            </button>
            <span id="weekly-history-page" class="text-sm text-slate-600"></span>
            <button
                id="weekly-history-next"
                type="button"
                disabled
                class="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-40"
            >
                Berikutnya
            </button>
        </nav>
    </section>
    @include('imports.recap-download')
</div>

<dialog
    id="weekly-actual-dialog"
    aria-labelledby="weekly-actual-dialog-title"
    class="rounded-xl border border-slate-200 bg-white p-6 shadow-lg"
    style="width: min(28rem, calc(100% - 2rem)); margin: auto;"
>
    <form id="weekly-actual-editor" class="space-y-4">
        <h2 id="weekly-actual-dialog-title" class="text-lg font-semibold">
            Atur Actual
        </h2>

        <p id="weekly-actual-file" class="text-sm text-slate-600"></p>

        <div>
            <label for="weekly-actual-editor-date" class="block text-sm font-medium">
                Actual sampai tanggal
            </label>

            <select
                id="weekly-actual-editor-date"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2"
                required
                disabled
            ></select>
        </div>

        <p id="weekly-actual-editor-status" role="status" class="text-sm text-slate-600"></p>

        <div class="flex gap-3">
            <button
                id="weekly-actual-editor-save"
                type="submit"
                class="rounded-md px-4 py-2 text-sm font-medium disabled:opacity-50"
                style="background-color: #1d4ed8; color: #ffffff;"
                disabled
            >
                Simpan tanggal
            </button>

            <button
                id="weekly-actual-editor-close"
                type="button"
                class="rounded-md border border-slate-300 px-4 py-2 text-sm"
            >
                Tutup
            </button>
        </div>
    </form>
</dialog>

<script type="module" src="{{ asset('js/weekly-import.js') }}"></script>
@endsection