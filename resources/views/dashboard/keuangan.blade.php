{{--
  Tab KEUANGAN pada Dashboard (isi persis dashboard lama, kini jadi partial agar
  bisa berdampingan dengan tab PPSB). Dirender hanya bila pengguna punya hak
  modul `dashboard` — lihat DashboardController::index().
--}}
@php
    $data = [
        'kas' => $kas, 'hutang' => $hutang,
        'cashFlow' => $cashFlow, 'cashFlowUnit' => $cashFlowUnit,
        'labaRugiUnit' => $labaRugiUnit, 'pencapaian' => $pencapaian,
    ];
@endphp

<div x-data="dashboard(@js($data))" class="space-y-5">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Selamat datang, {{ auth()->user()->nama }} 👋</h2>
        <p class="mt-1 text-sm text-gray-500">Ringkasan posisi keuangan · {{ $perusahaan?->nama_perusahaan ?? 'AL Wafi' }}</p>
    </div>

    {{-- ===== Kartu headline ===== --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500">Saldo Kas &amp; Rekening</div>
            <div class="mt-2 text-2xl font-bold text-brand">@rp($summary['saldo_kas'])</div>
            <button type="button" @click="modal = 'kas'" class="mt-2 self-start text-xs text-brand hover:underline">Lihat rincian →</button>
        </div>
        @foreach (['pendek' => 'Hutang Jangka Pendek', 'panjang' => 'Hutang Jangka Panjang', 'pajak' => 'Hutang Pajak'] as $j => $label)
            <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                <div class="mt-2 text-2xl font-bold text-red-600">@rp($summary['hutang_' . $j])</div>
                <button type="button" @click="modal = 'hutang'; hutangJenis = '{{ $j }}'" class="mt-2 self-start text-xs text-brand hover:underline">Lihat rincian →</button>
            </div>
        @endforeach
    </div>

    {{-- ===== Resume Cash Flow ===== --}}
    <template x-for="panel in panels" :key="panel.key">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                <h3 class="text-sm font-semibold text-gray-700" x-text="panel.title"></h3>
                <div class="flex items-center gap-2">
                    <div class="inline-flex overflow-hidden rounded border border-gray-200 text-xs">
                        <button type="button" @click="mode[panel.key] = 'total'" :class="mode[panel.key] === 'total' ? 'bg-brand text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" class="px-2.5 py-1">Total</button>
                        <button type="button" @click="mode[panel.key] = 'bulanan'" :class="mode[panel.key] === 'bulanan' ? 'bg-brand text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" class="px-2.5 py-1">Bulanan</button>
                    </div>
                    <template x-for="f in ['csv','xlsx','pdf']" :key="f">
                        <a :href="`{{ url('dashboard/export') }}/${panel.dl}?format=${f}&mode=${mode[panel.key]}`"
                           class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] text-gray-600 hover:bg-gray-100" x-text="f === 'xlsx' ? 'Excel' : f.toUpperCase()"></a>
                    </template>
                </div>
            </div>
            <div class="overflow-x-auto p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs uppercase text-gray-500">
                            <template x-for="c in panel.cols" :key="c.k"><th class="py-1" :class="c.num ? 'text-right' : ''" x-text="c.label"></th></template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="rows(panel).length === 0"><tr><td class="py-3 text-gray-400" :colspan="panel.cols.length">Belum ada data.</td></tr></template>
                        <template x-for="(row, i) in rows(panel)" :key="i">
                            <tr class="border-b border-gray-100">
                                <template x-for="c in panel.cols" :key="c.k">
                                    <td class="py-1.5" :class="c.num ? 'text-right font-mono tabular-nums' : ''"
                                        x-text="c.money ? rp(row[c.k]) : (c.pct ? row[c.k] + '%' : row[c.k])"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- ===== Resume Outstanding Approval ===== --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700">Resume Outstanding Approval</div>
        <div class="grid grid-cols-3 gap-3 p-4">
            @foreach (['void' => 'Void', 'edit' => 'Edit', 'posting' => 'Posting'] as $k => $label)
                <div class="rounded border border-gray-200 p-3">
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                    <div class="text-xl font-bold text-amber-600">{{ $approvals[$k]['count'] }}</div>
                    <div class="text-[11px] text-gray-400">@rp($approvals[$k]['nominal'])</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===== Modal drill-down: Kas & Rekening ===== --}}
    <div x-show="modal === 'kas'" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="modal = null">
        <div class="my-10 w-full max-w-2xl rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <h3 class="font-semibold text-gray-800">Rincian Saldo Kas &amp; Rekening</h3>
                <button type="button" @click="modal = null" class="text-gray-400 hover:text-gray-700">&times;</button>
            </div>
            <div class="p-5">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-xs uppercase text-gray-500"><th class="py-1">Rekening</th><th>Jenis</th><th class="text-right">Saldo</th></tr></thead>
                    <tbody>
                        <template x-for="(r, i) in d.kas.rincian" :key="i">
                            <tr class="border-b border-gray-100">
                                <td class="py-1.5" x-text="r.nama + (r.nama_bank ? ' — ' + r.nama_bank : '')"></td>
                                <td class="capitalize" x-text="r.jenis"></td>
                                <td class="text-right font-mono" x-text="rp(r.saldo)"></td>
                            </tr>
                        </template>
                        <tr class="font-semibold"><td class="py-2" colspan="2">Total</td><td class="text-right font-mono text-brand" x-text="rp(d.kas.total)"></td></tr>
                    </tbody>
                </table>
                <div class="mt-3 flex justify-end gap-1">
                    <template x-for="f in ['csv','xlsx','pdf']" :key="f">
                        <a :href="`{{ url('dashboard/export') }}/kas-rekening?format=${f}`" class="rounded border border-gray-200 px-2 py-0.5 text-[10px] text-gray-600 hover:bg-gray-100" x-text="f === 'xlsx' ? 'Excel' : f.toUpperCase()"></a>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Modal drill-down: Hutang ===== --}}
    <div x-show="modal === 'hutang'" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="modal = null">
        <div class="my-10 w-full max-w-3xl rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <h3 class="font-semibold text-gray-800" x-text="'Rincian ' + hutangLabel()"></h3>
                <button type="button" @click="modal = null" class="text-gray-400 hover:text-gray-700">&times;</button>
            </div>
            <div class="overflow-x-auto p-5">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-xs uppercase text-gray-500"><th class="py-1">Akun</th><th class="text-right">Penambahan</th><th class="text-right">Pengurangan</th><th class="text-right">Saldo</th></tr></thead>
                    <tbody>
                        <template x-for="(r, i) in d.hutang[hutangJenis].rincian" :key="i">
                            <tr class="border-b border-gray-100">
                                <td class="py-1.5" x-text="r.nama_coa"></td>
                                <td class="text-right font-mono text-emerald-700" x-text="rp(r.penambahan)"></td>
                                <td class="text-right font-mono text-red-600" x-text="rp(r.pengurangan)"></td>
                                <td class="text-right font-mono font-medium" x-text="rp(r.saldo)"></td>
                            </tr>
                        </template>
                        <tr class="font-semibold">
                            <td class="py-2">Total</td>
                            <td class="text-right font-mono text-emerald-700" x-text="rp(d.hutang[hutangJenis].total_penambahan)"></td>
                            <td class="text-right font-mono text-red-600" x-text="rp(d.hutang[hutangJenis].total_pengurangan)"></td>
                            <td class="text-right font-mono text-brand" x-text="rp(d.hutang[hutangJenis].total)"></td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">Penambahan = kredit (hutang bertambah), Pengurangan = debet (hutang dibayar/berkurang).</p>
                <div class="mt-3 flex justify-end gap-1">
                    <template x-for="f in ['csv','xlsx','pdf']" :key="f">
                        <a :href="`{{ url('dashboard/export') }}/hutang?jenis=${hutangJenis}&format=${f}`" class="rounded border border-gray-200 px-2 py-0.5 text-[10px] text-gray-600 hover:bg-gray-100" x-text="f === 'xlsx' ? 'Excel' : f.toUpperCase()"></a>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function dashboard(d) {
        return {
            d,
            modal: null,
            hutangJenis: 'pendek',
            mode: { cf: 'total', cfu: 'total', lru: 'total', pc: 'total' },
            panels: [
                { key: 'cf', title: 'Resume Cash Flow', src: 'cashFlow', dl: 'cash-flow',
                  cols: [{ k: 'periode', label: 'Periode' }, { k: 'masuk', label: 'Masuk', num: true, money: true }, { k: 'keluar', label: 'Keluar', num: true, money: true }, { k: 'net', label: 'Bersih', num: true, money: true }] },
                { key: 'cfu', title: 'Resume Cash Flow per Unit Bisnis', src: 'cashFlowUnit', dl: 'cash-flow-unit',
                  cols: [{ k: 'nama_unit', label: 'Unit Bisnis' }, { k: 'periode', label: 'Periode' }, { k: 'masuk', label: 'Masuk', num: true, money: true }, { k: 'keluar', label: 'Keluar', num: true, money: true }, { k: 'net', label: 'Bersih', num: true, money: true }] },
                { key: 'lru', title: 'Resume Laba Rugi per Unit Bisnis', src: 'labaRugiUnit', dl: 'laba-rugi-unit',
                  cols: [{ k: 'nama_unit', label: 'Unit Bisnis' }, { k: 'periode', label: 'Periode' }, { k: 'pendapatan', label: 'Pendapatan', num: true, money: true }, { k: 'beban', label: 'Beban', num: true, money: true }, { k: 'laba', label: 'Laba/Rugi', num: true, money: true }] },
                { key: 'pc', title: 'Resume Pencapaian Pendapatan (Piutang vs Realisasi)', src: 'pencapaian', dl: 'pencapaian',
                  cols: [{ k: 'periode', label: 'Periode' }, { k: 'pengakuan', label: 'Pengakuan Piutang', num: true, money: true }, { k: 'realisasi', label: 'Realisasi Bayar', num: true, money: true }, { k: 'outstanding', label: 'Outstanding', num: true, money: true }, { k: 'persen_realisasi', label: '% Realisasi', num: true, pct: true }] },
            ],
            rows(panel) { return (this.d[panel.src][this.mode[panel.key]]) || []; },
            hutangLabel() { return { pendek: 'Hutang Jangka Pendek', panjang: 'Hutang Jangka Panjang', pajak: 'Hutang Pajak' }[this.hutangJenis]; },
            rp(n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); },
        };
    }
</script>
