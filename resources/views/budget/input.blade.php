@extends('layouts.app')

@section('title', 'Input Anggaran')

@php
    // Label 12 kolom mengikuti urutan Tahun Anggaran; tandai tahun bila lintas tahun.
    $NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $urut = $grid['bulan_urut'] ?? array_map(fn ($m) => ['tahun' => $tahun, 'bulan' => $m], range(1, 12));
    $lintasTahun = count(array_unique(array_column($urut, 'tahun'))) > 1;
    $bulanLabels = array_map(
        fn ($u) => $lintasTahun ? $NAMA_BULAN[$u['bulan'] - 1] . " '" . substr((string) $u['tahun'], 2) : $NAMA_BULAN[$u['bulan'] - 1],
        $urut,
    );
    $terkunci = in_array($tahun, $lockedYears, true);
    // Baris grid untuk Alpine (bulanan sebagai angka bersih; 0 → string kosong).
    $rowsJson = collect($grid['rows'] ?? [])->map(fn ($r) => [
        'kode_coa' => $r['kode_coa'],
        'nama_coa' => $r['nama_coa'],
        'bulanan' => array_map(fn ($v) => (float) $v == 0.0 ? '' : rtrim(rtrim((string) $v, '0'), '.'), $r['bulanan']),
    ])->values();
@endphp

@section('content')
    <div x-data="budgetGrid({
        rows: {{ Illuminate\Support\Js::from($rowsJson) }},
        tahun: {{ $tahun }},
        kodeBagian: @js($kodeBagian),
        kodeUnit: @js($kodeUnit),
    })">
        <div class="mb-3">
            <h2 class="text-xl font-semibold text-gray-900">Input Anggaran</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">
                Anggaran <b>akun Beban</b> untuk 12 bulan Tahun Anggaran · <b>bagian</b> · unit. Kosong = tanpa anggaran.
                Pendapatan tidak dianggarkan; pengakuan non-kas (depresiasi, accrue, jurnal penutup) tidak dihitung sebagai realisasi.
                @if ($isAdmin)
                    Sebagai admin Anda menyimpan langsung (jalur darurat).
                @else
                    Grid ini hanya-baca untuk Anda; anggaran ditetapkan admin.
                @endif
                Realisasi vs anggaran ada di menu <a href="{{ route('budget.realisasi') }}" class="text-brand hover:underline">Realisasi Anggaran</a>.
            </p>
        </div>

        {{-- Filter scope (GET) --}}
        <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Anggaran</label>
                <input type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100"
                       class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                @if (($grid['bulan_awal'] ?? 1) > 1)<div class="mt-0.5 text-[10px] text-gray-500">TA {{ $labelTa }}</div>@endif
            </div>
            <div class="min-w-[14rem]">
                <label class="mb-1 block text-xs font-medium text-gray-600">Bagian <span class="text-red-500">*</span></label>
                <select name="kode_bagian" @disabled(! $isAdmin)
                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand disabled:bg-gray-100">
                    <option value="">— pilih bagian —</option>
                    @foreach ($bagian as $b)
                        <option value="{{ $b->kode_bagian }}" @selected($b->kode_bagian === $kodeBagian)>{{ $b->kode_bagian }} — {{ $b->nama_bagian }}</option>
                    @endforeach
                </select>
                @unless ($isAdmin)<div class="mt-0.5 text-[10px] text-gray-500">Bagian Anda (dari profil)</div>@endunless
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Unit</label>
                <select name="kode_unit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                    <option value="">Semua Unit</option>
                    @foreach ($units as $u)
                        <option value="{{ $u->kode_unit }}" @selected($u->kode_unit === $kodeUnit)>{{ $u->nama_unit }}</option>
                    @endforeach
                </select>
            </div>
            <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Muat</button>
        </form>

        @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

        {{-- Kunci anggaran sengaja DI LUAR blok "sudah pilih bagian": kuncinya
             berlaku per TAHUN ANGGARAN untuk seluruh bagian & unit, jadi
             menyembunyikannya di balik pemilihan satu bagian menyesatkan
             (seakan yang terkunci cuma bagian itu). --}}
        @if ($terkunci)
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded bg-gray-100 px-3 py-2 text-sm text-gray-600">
                <span>🔒 <b>Anggaran TA {{ $labelTa }} terkunci.</b> Tidak dapat diubah oleh siapa pun{{ $isAdmin ? ' — buka kunci dulu untuk menyunting.' : '. Hubungi administrator bila perlu perubahan.' }}</span>
                @if ($isAdmin)
                    <form method="POST" action="{{ route('budget.unlock', $tahun) }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="kode_bagian" value="{{ $kodeBagian }}">
                        <input type="hidden" name="kode_unit" value="{{ $kodeUnit }}">
                        <button class="whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">🔓 Buka Kunci</button>
                    </form>
                @endif
            </div>
        @elseif ($isAdmin)
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                <span>Anggaran TA <b>{{ $labelTa }}</b> masih terbuka. Menguncinya membekukan anggaran <b>seluruh bagian &amp; unit</b> tahun itu — pengajuan baru ditolak, dan simpan-langsung ikut terkunci bagi admin sendiri.</span>
                <form method="POST" action="{{ route('budget.lock') }}"
                      onsubmit="return confirm('Kunci anggaran TA {{ $labelTa }}? Setelah terkunci tak seorang pun (termasuk admin) bisa mengubahnya sampai dibuka.')">
                    @csrf
                    <input type="hidden" name="tahun" value="{{ $tahun }}">
                    <input type="hidden" name="kode_bagian" value="{{ $kodeBagian }}">
                    <input type="hidden" name="kode_unit" value="{{ $kodeUnit }}">
                    <button class="whitespace-nowrap rounded-lg border border-amber-300 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-50">🔒 Kunci Anggaran</button>
                </form>
            </div>
        @endif

        @if ($kodeBagian === '')
            <div class="mb-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-700">
                Pilih <b>Bagian</b> terlebih dahulu — anggaran selalu dimiliki satu bagian.
            </div>
        @else
            {{-- Toolbar aksi (admin) --}}
            @if ($isAdmin)
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <div class="min-w-[18rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Tambah Akun ke Anggaran</label>
                        <select @change="tambahAkun($event.target.value); $event.target.value=''" @disabled($terkunci)
                                class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand disabled:bg-gray-100">
                            <option value="">— cari & pilih akun —</option>
                            @foreach ($bebanAkun as $a)
                                <option value="{{ $a->kode_coa }}" data-nama="{{ $a->nama_coa }}">{{ $a->kode_coa }} — {{ $a->nama_coa }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Kunci/buka kunci ada di bilah atas (berlaku per TA, bukan per bagian). --}}
                    <button type="button" @click="simpan()" @disabled($terkunci)
                            class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">
                        Simpan Langsung
                    </button>
                </div>
            @endif

            {{-- Form tersembunyi untuk submit payload --}}
            <form method="POST" action="{{ route('budget.save') }}" x-ref="saveForm" class="hidden">
                @csrf @method('PUT')
                <input type="hidden" name="payload" x-ref="payload">
            </form>

            {{-- Grid --}}
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-left uppercase text-gray-500">
                        <tr>
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 min-w-[13rem]">Akun</th>
                            @foreach ($bulanLabels as $bl)
                                <th class="px-2 py-2 text-right min-w-[6rem]">{{ $bl }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right min-w-[7rem]">Total</th>
                            @if ($isAdmin)<th class="px-2 py-2"></th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-if="rows.length === 0">
                            <tr><td colspan="{{ $isAdmin ? 15 : 14 }}" class="px-4 py-6 text-center text-gray-400">Belum ada akun beranggaran. @if ($isAdmin) Tambahkan akun di atas. @endif</td></tr>
                        </template>
                        <template x-for="(r, ri) in rows" :key="r.kode_coa">
                            <tr class="hover:bg-gray-50">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5">
                                    <div class="font-medium text-gray-800" x-text="r.nama_coa"></div>
                                    <div class="font-mono text-[10px] text-gray-400" x-text="r.kode_coa"></div>
                                </td>
                                <template x-for="(v, mi) in r.bulanan" :key="mi">
                                    <td class="px-1 py-1">
                                        <input type="text" inputmode="numeric" x-model="r.bulanan[mi]"
                                               @if ($terkunci || ! $isAdmin) readonly @endif
                                               class="w-full rounded border border-gray-200 px-1.5 py-1 text-right text-xs focus:border-brand focus:ring-brand read-only:bg-gray-50 read-only:text-gray-500">
                                    </td>
                                </template>
                                <td class="px-3 py-1.5 text-right font-mono font-semibold" x-text="formatRp(rowTotal(r))"></td>
                                @if ($isAdmin)
                                    <td class="px-2 py-1.5 text-center">
                                        @unless ($terkunci)
                                            <button type="button" class="text-red-500 hover:text-red-700" title="Hapus akun" @click="hapusAkun(r.kode_coa)">×</button>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                        </template>
                    </tbody>
                    <tfoot x-show="rows.length > 0">
                        <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                            <td class="sticky left-0 z-10 bg-gray-50 px-3 py-2">Total</td>
                            <template x-for="mi in 12" :key="mi">
                                <td class="px-2 py-2 text-right font-mono" x-text="formatRp(colTotal(mi - 1))"></td>
                            </template>
                            <td class="px-3 py-2 text-right font-mono" x-text="formatRp(grandTotal())"></td>
                            @if ($isAdmin)<td></td>@endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <script>
        function budgetGrid(init) {
            return {
                rows: init.rows,
                tahun: init.tahun,
                kodeBagian: init.kodeBagian,
                kodeUnit: init.kodeUnit,
                originalCodes: init.rows.map((r) => r.kode_coa),

                num(v) { const n = Number(String(v).replace(/[^0-9.-]/g, '')); return Number.isFinite(n) ? n : 0; },
                rowTotal(r) { return r.bulanan.reduce((s, v) => s + this.num(v), 0); },
                colTotal(mi) { return this.rows.reduce((s, r) => s + this.num(r.bulanan[mi]), 0); },
                grandTotal() { return this.rows.reduce((s, r) => s + this.rowTotal(r), 0); },
                formatRp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); },

                tambahAkun(kode) {
                    if (!kode || this.rows.some((r) => r.kode_coa === kode)) return;
                    const opt = document.querySelector(`option[value="${kode}"][data-nama]`);
                    this.rows.push({ kode_coa: kode, nama_coa: opt?.dataset.nama ?? kode, bulanan: Array(12).fill('') });
                },
                hapusAkun(kode) { this.rows = this.rows.filter((r) => r.kode_coa !== kode); },

                simpan() {
                    const items = [];
                    for (const r of this.rows) {
                        r.bulanan.forEach((v, i) => items.push({ kode_coa: r.kode_coa, bulan: i + 1, nominal: String(this.num(v)) }));
                    }
                    // Akun yang dihapus → kirim 12 bulan nol agar terhapus di server.
                    const current = new Set(this.rows.map((r) => r.kode_coa));
                    for (const code of this.originalCodes) {
                        if (!current.has(code)) for (let b = 1; b <= 12; b++) items.push({ kode_coa: code, bulan: b, nominal: '0' });
                    }
                    this.$refs.payload.value = JSON.stringify({ tahun: this.tahun, kode_bagian: this.kodeBagian, kode_unit: this.kodeUnit, items });
                    this.$refs.saveForm.submit();
                },
            };
        }
    </script>
@endsection
