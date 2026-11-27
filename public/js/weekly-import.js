const form = document.getElementById('weekly-snapshot-form');
const fileInput = document.getElementById('weekly-excel');
const snapshotInput = document.getElementById('weekly-snapshot-date');
const actualSelect = document.getElementById('weekly-actual-date');
const previewStatus = document.getElementById('weekly-preview-status');
const saveButton = document.getElementById('weekly-save-button');
const saveStatus = document.getElementById('weekly-save-status');

const historyRows = document.getElementById('weekly-history-rows');
const historyStatus = document.getElementById('weekly-history-status');
const previousButton = document.getElementById('weekly-history-prev');
const nextButton = document.getElementById('weekly-history-next');
const pageLabel = document.getElementById('weekly-history-page');

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const numberFormatter = new Intl.NumberFormat('id-ID');

let previewRequest;
let saving = false;
let historyPage = 1;
let historyLastPage = 1;

function formatDate(value) {
    const isoDate = String(value ?? '').slice(0, 10);

    if (!isoDate) {
        return '—';
    }

    const date = new Date(`${isoDate}T00:00:00Z`);
    return Number.isNaN(date.getTime()) ? isoDate : dateFormatter.format(date);
}

function excelFormData() {
    const data = new FormData();
    data.append('file', fileInput.files[0]);
    data.append('snapshot_date', snapshotInput.value);

    return data;
}

async function requestImportApi(path, options = {}) {
    const token = sessionStorage.getItem('variance_token');

    if (!token) {
        throw new Error('Silakan login kembali.');
    }

    const response = await fetch(path, {
        ...options,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (response.status === 401) {
        throw new Error('Sesi login berakhir. Silakan login kembali.');
    }

    if (response.status === 403) {
        throw new Error('Akun Anda tidak memiliki izin untuk tindakan ini.');
    }

    if (response.status === 413) {
        throw new Error('Ukuran file melebihi batas unggahan server.');
    }

    let result;

    try {
        result = await response.json();
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }

        throw new Error(`Server gagal mengembalikan hasil (HTTP ${response.status}).`);
    }

    if (!response.ok || result.success === false) {
        const fieldError = Object.values(result.errors ?? {}).flat()[0];
        throw new Error(fieldError || result.message || 'Permintaan impor gagal.');
    }

    return result.data;
}

function resetPreview() {
    previewRequest?.abort();
    previewRequest = undefined;

    actualSelect.disabled = true;
    actualSelect.replaceChildren(
        new Option('Pilih file dan isi tanggal snapshot', '')
    );
    previewStatus.textContent = '';
}

async function loadPreviewDates() {
    resetPreview();

    if (!fileInput.files[0] || !snapshotInput.value) {
        return;
    }

    const snapshotDate = snapshotInput.value;
    const request = new AbortController();
    previewRequest = request;
    previewStatus.textContent = 'Membaca SR ISSUE DATE dari Excel…';

    try {
        const data = await requestImportApi('/api/imports/preview', {
            method: 'POST',
            body: excelFormData(),
            signal: request.signal,
        });

        if (request.signal.aborted) {
            return;
        }

        if (!Array.isArray(data?.periods) || data.periods.length === 0) {
            throw new Error('SR ISSUE DATE tidak ditemukan pada file ini.');
        }

        actualSelect.replaceChildren(new Option('Pilih tanggal Actual', ''));

        for (const period of data.periods) {
            actualSelect.add(new Option(formatDate(period), period));
        }

        if (data.periods.includes(snapshotDate)) {
            actualSelect.value = snapshotDate;
        }

        actualSelect.disabled = false;
        previewStatus.textContent = `${data.periods.length} tanggal SR ISSUE DATE tersedia.`;
    } catch (error) {
        if (!request.signal.aborted) {
            previewStatus.textContent = error.message;
        }
    }
}

function renderHistory(imports) {
    const rows = document.createDocumentFragment();

    for (const record of imports) {
        const row = document.createElement('tr');

        const values = [
            formatDate(record.snapshot_date),
            record.original_filename ?? record.filename ?? '—',
            record.status ?? '—',
            record.total_products == null ? '—' : numberFormatter.format(record.total_products),
            record.total_periods == null ? '—' : numberFormatter.format(record.total_periods),
        ];

        for (const value of values) {
            const cell = document.createElement('td');
            cell.className = 'px-3 py-2 text-slate-700';
            cell.textContent = value;
            row.append(cell);
        }

        const actionCell = document.createElement('td');
            actionCell.className = 'px-3 py-2';

            if (record.import_type === 'weekly' && record.status === 'completed' && record.snapshot_date) {
                const actionButton = document.createElement('button');
                actionButton.type = 'button';
                actionButton.className = 'text-sm font-medium underline';
                actionButton.textContent = 'Atur Actual';
                actionButton.addEventListener('click', () => openActualEditor(record));

                actionCell.append(actionButton);
            } else {
                actionCell.textContent = '—';
            }

            row.append(actionCell);

        rows.append(row);
    }

    historyRows.replaceChildren(rows);
}

async function loadHistory(page = 1) {
    previousButton.disabled = true;
    nextButton.disabled = true;
    historyStatus.textContent = 'Memuat riwayat impor…';

    try {
        const data = await requestImportApi(`/api/imports?page=${page}`);

        renderHistory(data.data);
        historyPage = Number(data.current_page) || page;
        historyLastPage = Number(data.last_page) || 1;

        pageLabel.textContent = `Halaman ${historyPage} dari ${historyLastPage}`;
        historyStatus.textContent = data.data.length ? '' : 'Belum ada data impor.';
    } catch (error) {
        historyStatus.textContent = error.message;
    } finally {
        previousButton.disabled = historyPage <= 1;
        nextButton.disabled = historyPage >= historyLastPage;
    }
}

async function saveImport(event) {
    event.preventDefault();

    if (saving || !fileInput.files[0] || !snapshotInput.value) {
        return;
    }

    const data = excelFormData();

    saving = true;
    saveButton.disabled = true;
    fileInput.disabled = true;
    snapshotInput.disabled = true;
    saveStatus.textContent = 'Menyimpan snapshot mingguan…';

    try {
        const result = await requestImportApi('/api/imports', {
            method: 'POST',
            body: data,
        });

        const message = result?.variance_warning
            ? `Snapshot tersimpan. ${result.variance_warning}`
            : 'Snapshot mingguan berhasil disimpan.';

        form.reset();
        resetPreview();
        saveStatus.textContent = message;
        await loadHistory();
    } catch (error) {
        saveStatus.textContent = error.message;
    } finally {
        saving = false;
        saveButton.disabled = false;
        fileInput.disabled = false;
        snapshotInput.disabled = false;
    }
}

fileInput.addEventListener('change', loadPreviewDates);
snapshotInput.addEventListener('change', loadPreviewDates);
form.addEventListener('submit', saveImport);
previousButton.addEventListener('click', () => loadHistory(historyPage - 1));
nextButton.addEventListener('click', () => loadHistory(historyPage + 1));

const actualDialog = document.getElementById('weekly-actual-dialog');
const actualEditor = document.getElementById('weekly-actual-editor');
const actualEditorDate = document.getElementById('weekly-actual-editor-date');
const actualEditorFile = document.getElementById('weekly-actual-file');
const actualEditorStatus = document.getElementById('weekly-actual-editor-status');
const actualEditorSave = document.getElementById('weekly-actual-editor-save');
const actualEditorClose = document.getElementById('weekly-actual-editor-close');

let activeActualEditor = null;
let savingActualDate = false;

async function openActualEditor(record) {
    const editor = { importId: record.id };
    activeActualEditor = editor;

    actualEditorFile.textContent = record.original_filename ?? record.filename ?? '';
    actualEditorDate.replaceChildren(new Option('Pilih tanggal Actual', ''));
    actualEditorDate.disabled = true;
    actualEditorSave.disabled = true;
    actualEditorStatus.textContent = 'Membaca SR ISSUE DATE dari snapshot…';
    actualDialog.showModal();

    try {
        const data = await requestImportApi(`/api/imports/${editor.importId}/actual`);

        if (activeActualEditor !== editor || !actualDialog.open) {
            return;
        }

        for (const period of data.periods) {
            actualEditorDate.add(new Option(formatDate(period), period));
        }

        if (data.periods.includes(data.actual_until_date)) {
            actualEditorDate.value = data.actual_until_date;
        } else if (data.periods.includes(data.snapshot_date)) {
            actualEditorDate.value = data.snapshot_date;
        }

        actualEditorDate.disabled = false;
        actualEditorSave.disabled = false;
        actualEditorStatus.textContent = data.actual_until_date
            ? `Batas Actual tersimpan: ${formatDate(data.actual_until_date)}.`
            : 'Pilih tanggal, lalu simpan batas Actual untuk snapshot ini.';
    } catch (error) {
        if (activeActualEditor === editor && actualDialog.open) {
            actualEditorStatus.textContent = error.message;
        }
    }
}

        actualEditor.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!activeActualEditor || !actualEditorDate.value || savingActualDate) {
                return;
            }

            const importId = activeActualEditor.importId;
            const actualUntilDate = actualEditorDate.value;

            savingActualDate = true;
            actualEditorDate.disabled = true;
            actualEditorSave.disabled = true;
            actualEditorClose.disabled = true;
            actualEditorStatus.textContent = 'Menyimpan batas Actual…';

            try {
                const data = await requestImportApi(`/api/imports/${importId}/actual`, {
                    method: 'PATCH',
                    body: new URLSearchParams({ actual_until_date: actualUntilDate }),
                });

                actualEditorStatus.textContent =
                    `Batas Actual tersimpan: ${formatDate(data.actual_until_date)}.`;
            } catch (error) {
                actualEditorStatus.textContent = error.message;
            } finally {
                savingActualDate = false;
                actualEditorDate.disabled = false;
                actualEditorSave.disabled = false;
                actualEditorClose.disabled = false;
            }
        });

        actualEditorClose.addEventListener('click', () => actualDialog.close());

        actualDialog.addEventListener('cancel', (event) => {
            if (savingActualDate) {
                event.preventDefault();
            }
        });

        actualDialog.addEventListener('close', () => {
            activeActualEditor = null;
        });

loadHistory();