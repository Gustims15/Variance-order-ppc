import { apiRequest } from './auth.js';

const numberFormat = new Intl.NumberFormat('id-ID');

function formatNumber(value) {
    return numberFormat.format(Number(value) || 0);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[char]);
}

function formatDate(value) {
    if (!value) return '-';

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '-'
        : date.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
}

function renderKpi(label, value, description, color) {
    const colors = {
        blue: 'bg-blue-50 text-blue-700',
        green: 'bg-green-50 text-green-700',
        red: 'bg-red-50 text-red-700',
        amber: 'bg-amber-50 text-amber-700',
        purple: 'bg-purple-50 text-purple-700',
        slate: 'bg-slate-100 text-slate-700',
    };

    return `
        <div class="bg-white border border-slate-200 rounded-xl p-5">
            <div class="text-sm text-slate-500">${label}</div>
            <div class="text-3xl font-bold text-slate-900 mt-3">
                ${formatNumber(value)}
            </div>
            <div class="mt-3">
                <span class="inline-flex px-2.5 py-1 rounded-full
                    text-xs font-semibold ${colors[color]}">
                    ${description}
                </span>
            </div>
        </div>
    `;
}

function renderDepartments(departments) {
    if (!departments.length) {
        return '<p class="text-sm text-slate-500">Belum ada data department.</p>';
    }

    return `
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs uppercase text-slate-500 bg-slate-50">
                    <tr>
                        <th class="px-4 py-3">Department</th>
                        <th class="px-4 py-3 text-right">Products</th>
                        <th class="px-4 py-3 text-right">Increased</th>
                        <th class="px-4 py-3 text-right">Decreased</th>
                        <th class="px-4 py-3 text-right">Mixed</th>
                        <th class="px-4 py-3 text-right">Review</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    ${departments.map(item => `
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold">
                                ${escapeHtml(item.department)}
                            </td>
                            <td class="px-4 py-3 text-right">
                                ${formatNumber(item.total_products)}
                            </td>
                            <td class="px-4 py-3 text-right text-green-700">
                                ${formatNumber(item.increased)}
                            </td>
                            <td class="px-4 py-3 text-right text-red-700">
                                ${formatNumber(item.decreased)}
                            </td>
                            <td class="px-4 py-3 text-right">
                                ${formatNumber(item.mixed)}
                            </td>
                            <td class="px-4 py-3 text-right text-amber-700">
                                ${formatNumber(item.needs_review)}
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function renderTrends(trends) {
    if (!trends.length) {
        return '<p class="text-sm text-slate-500">Data tren belum tersedia.</p>';
    }

    const maximum = Math.max(
        1,
        ...trends.flatMap(item => [
            Number(item.forecast_quantity) || 0,
            Number(item.actual_quantity) || 0,
        ])
    );

    return `
        <div class="space-y-5">
            ${trends.map(item => {
                const forecast = Number(item.forecast_quantity) || 0;
                const actual = Number(item.actual_quantity) || 0;

                return `
                    <div>
                        <div class="flex justify-between text-xs mb-2">
                            <span class="font-semibold text-slate-700">
                                ${formatDate(item.sr_issue_date)}
                            </span>
                            <span class="text-slate-500">
                                Variance: ${formatNumber(item.total_variance)}
                            </span>
                        </div>

                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <span class="w-16 text-xs text-slate-500">
                                    Forecast
                                </span>
                                <div class="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full"
                                         style="width:${forecast / maximum * 100}%">
                                    </div>
                                </div>
                                <span class="w-20 text-right text-xs font-medium">
                                    ${formatNumber(forecast)}
                                </span>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="w-16 text-xs text-slate-500">
                                    Actual
                                </span>
                                <div class="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-emerald-500 rounded-full"
                                         style="width:${actual / maximum * 100}%">
                                    </div>
                                </div>
                                <span class="w-20 text-right text-xs font-medium">
                                    ${formatNumber(actual)}
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

function renderDashboard(data, trends) {
    const root = document.getElementById('dashboard-root');

    const { report, summary, departments, periods } = data;

    root.innerHTML = `
        <div class="mb-7">
            <div class="flex flex-wrap justify-between items-start gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">
                        Production Overview
                    </h1>
                    <p class="text-sm text-slate-500 mt-2">
                        Forecast vs Actual Production Performance
                    </p>
                </div>

                <button id="dashboard-refresh"
                    class="px-4 py-2 rounded-lg bg-blue-600 text-white
                           text-sm font-semibold hover:bg-blue-700">
                    Refresh Data
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-5 mb-6">
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <p class="text-xs uppercase text-slate-500 font-semibold">
                        Active Variance Report #${escapeHtml(report.id)}
                    </p>
                    <p class="font-semibold mt-2 text-slate-900">
                        ${escapeHtml(report.status).toUpperCase()}
                    </p>
                </div>

                <div class="text-sm text-slate-500">
                    Generated: ${formatDate(report.generated_at)}
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mt-5">
                <div class="bg-blue-50 rounded-lg p-4">
                    <p class="text-xs text-blue-700 font-semibold">FORECAST</p>
                    <p class="text-sm font-medium mt-2 break-all">
                        ${escapeHtml(report.forecast?.filename)}
                    </p>
                </div>

                <div class="bg-emerald-50 rounded-lg p-4">
                    <p class="text-xs text-emerald-700 font-semibold">ACTUAL</p>
                    <p class="text-sm font-medium mt-2 break-all">
                        ${escapeHtml(report.actual?.filename)}
                    </p>
                </div>
            </div>

            <div class="mt-4 text-xs text-slate-500">
                Period: ${formatDate(periods.first_date)}
                – ${formatDate(periods.last_date)}
                (${formatNumber(periods.total_periods)} periods)
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            ${renderKpi('Total Products', summary.total_products, 'Products analyzed', 'blue')}
            ${renderKpi('Increased', summary.increased, 'Higher quantity', 'green')}
            ${renderKpi('Decreased', summary.decreased, 'Lower quantity', 'red')}
            ${renderKpi('Needs Review', summary.needs_review, 'Requires investigation', 'amber')}
            ${renderKpi('Mixed', summary.mixed, 'Mixed period changes', 'purple')}
            ${renderKpi('Unchanged', summary.unchanged, 'No quantity changes', 'slate')}
            ${renderKpi('Retained', summary.retained ?? 0, 'Missing from latest snapshot; kept in history', 'slate')}
            ${renderKpi('New Orders', summary.new_order, 'New products', 'blue')}
            ${renderKpi('Pending Reviews', summary.pending_reviews, 'Awaiting resolution', 'amber')}
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <section class="bg-white border border-slate-200 rounded-xl p-5">
                <h2 class="font-bold text-slate-900 mb-2">
                    Forecast vs Actual Trend
                </h2>
                <p class="text-xs text-slate-500 mb-6">
                    Production quantity by period
                </p>

                <div class="flex gap-5 text-xs mb-5">
                    <span class="text-blue-600">■ Forecast</span>
                    <span class="text-emerald-600">■ Actual</span>
                </div>

                ${renderTrends(trends)}
            </section>

            <section class="bg-white border border-slate-200 rounded-xl p-5">
                <h2 class="font-bold text-slate-900 mb-2">
                    Department Summary
                </h2>
                <p class="text-xs text-slate-500 mb-6">
                    Product variance distribution by department
                </p>

                ${renderDepartments(departments)}
            </section>
        </div>

        <div class="text-xs text-slate-400 text-right">
            Data sourced from Laravel API and SQL Server.
        </div>
    `;

    document.getElementById('dashboard-refresh')
        ?.addEventListener('click', initializeDashboard);
}

export async function initializeDashboard() {
    const root = document.getElementById('dashboard-root');

    if (!root) return;

    root.innerHTML = `
        <div class="bg-white border border-slate-200 rounded-xl p-8">
            <p class="text-sm text-slate-500">
                Loading production data...
            </p>
        </div>
    `;

    try {
        const response = await apiRequest('dashboard');

        if (!response.success || !response.data?.report) {
            throw new Error('Dashboard report tidak tersedia.');
        }

        const data = response.data;
        let trends = [];

        try {
            const trendResponse = await apiRequest(
                `dashboard/trends?report_id=${encodeURIComponent(data.report.id)}`
            );

            if (trendResponse.success) {
                trends = trendResponse.data || [];
            }
        } catch (error) {
            console.error('Trend API error:', error);
        }

        renderDashboard(data, trends);

    } catch (error) {
        root.innerHTML = `
            <div class="bg-white border border-red-200 rounded-xl p-6">
                <h2 class="font-bold text-red-700">
                    Dashboard Data Error
                </h2>
                <p class="text-sm text-slate-600 mt-2">
                    ${escapeHtml(error.message)}
                </p>
                <button id="dashboard-retry"
                    class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">
                    Try Again
                </button>
            </div>
        `;

        document.getElementById('dashboard-retry')
            ?.addEventListener('click', initializeDashboard);
    }
}
