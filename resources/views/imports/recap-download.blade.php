<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-lg font-semibold text-slate-900">Unduh Rekap Mingguan</h2>

    <form id="weekly-recap-download" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="weekly-recap-from" class="block text-sm font-medium">
                Dari tanggal snapshot
            </label>
            <input
                id="weekly-recap-from"
                name="from"
                type="date"
                required
                class="mt-1 rounded-md border border-slate-300 px-3 py-2"
            >
        </div>

        <div>
            <label for="weekly-recap-to" class="block text-sm font-medium">
                Sampai tanggal snapshot
            </label>
            <input
                id="weekly-recap-to"
                name="to"
                type="date"
                required
                class="mt-1 rounded-md border border-slate-300 px-3 py-2"
            >
        </div>

        <button
            type="submit"
            class="rounded-md px-4 py-2 text-sm font-medium disabled:opacity-50"
            style="background-color: #1d4ed8; color: #ffffff;"
        >
            Unduh Excel
        </button>
    </form>

    <p id="weekly-recap-status" class="mt-2 text-sm text-slate-600" role="status"></p>
</section>

<script type="module">
    const form = document.getElementById('weekly-recap-download');
    const status = document.getElementById('weekly-recap-status');
    const downloadButton = form.querySelector('button');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (downloadButton.disabled) {
            return;
        }

        const data = new FormData(form);
        const from = data.get('from');
        const to = data.get('to');

        if (!from || !to || to < from) {
            status.textContent = 'Isi rentang tanggal snapshot yang valid.';
            return;
        }

        const token = sessionStorage.getItem('variance_token');

        if (!token) {
            status.textContent = 'Silakan login kembali.';
            return;
        }

        const query = new URLSearchParams({ from, to });
        downloadButton.disabled = true;
        status.textContent = 'Menyiapkan file Excel…';

        try {
            const response = await fetch(`/api/imports/weekly-recap?${query}`, {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                },
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                const firstError = Object.values(error.errors ?? {}).flat()[0];
                throw new Error(firstError || error.message || 'Rekap gagal dibuat.');
            }

            const mimeType = response.headers.get('content-type') ?? '';
            if (!mimeType.includes('spreadsheetml.sheet')) {
                throw new Error('Server belum mengembalikan file Excel.');
            }

            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `rekap-mingguan-${from}-${to}.xlsx`;
            document.body.append(link);
            link.click();
            link.remove();

            setTimeout(() => URL.revokeObjectURL(url), 30_000);
            status.textContent = 'File Excel sudah dikirim ke browser.';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            downloadButton.disabled = false;
        }
    });
</script>
