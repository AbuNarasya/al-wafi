@extends('layouts.app')

@section('title', 'Ajukan Anggaran')

@php
    $NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $urut = $grid['bulan_urut'] ?? array_map(fn ($m) => ['tahun' => $tahun, 'bulan' => $m], range(1, 12));
    $lintasTahun = count(array_unique(array_column($urut, 'tahun'))) > 1;
    $bulanLabels = array_map(
        fn ($u) => $lintasTahun ? $NAMA_BULAN[$u['bulan'] - 1] . " '" . substr((string) $u['tahun'], 2) : $NAMA_BULAN[$u['bulan'] - 1],
        $urut,
    );
    // Pra-isi dari anggaran yang SEDANG berlaku (0 → kosong agar mudah diketik).
    $rowsJson = collect($grid['rows'] ?? [])->map(fn ($r) => [
        'kode_coa' => $r['kode_coa'],
        'nama_coa' => $r['nama_coa'],
        'bulanan' => array_map(fn ($v) => (float) $v == 0.0 ? '' : rtrim(rtrim((string) $v, '0'), '.'), $r['bulanan']),
    ])->values();
@endphp

@section('content')
    <div x-data="ajuanGrid({
        rows: {{ Illuminate\Support\Js::from($rowsJson) }},
        tahun: {{ $tahun }},
        kodeUnit: @js($kodeUnit),
    })">
        <div class="mb-3">
            <h2 class="text-xl font-semibold text-gray-900">Ajukan Anggaran</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">
                Susun anggaran <b>akun Beban</b> untuk 12 bulan Tahun Anggaran, lalu ajukan ke rantai persetujuan
                (Mudir Bagian → Mudir Umum → Ketua Yayasan). Anggaran baru berlaku setelah rantai tuntas.
            </p>
        </div>

        @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

        @if ($kodeBagian === '')
            <div class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-700">
                Akun Anda belum ditempatkan di bagian mana pun, sehingga belum bisa mengajukan anggaran.
                Minta administrator mengisi <b>Bagian</b> pada profil Anda di menu Pengguna.
            </div>
        @else
            {{-- Scope (GET) — bagian TIDAK dipilih: selalu bagian pemohon. --}}
            <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Anggaran</label>
                    <input type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100"
                           class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                    <div class="mt-0.5 text-[10px] text-gray-500">TA {{ $labelTa }}</div>
                </div>
                <div class="min-w-[14rem]">
                    <label class="mb-1 block text-xs font-medium text-gray-600">Bagian</label>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-700">{{ $namaBagian }}</div>
                    <div class="mt-0.5 text-[10px] text-gray-500">Dari profil Anda — tak bisa diganti</div>
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

            @if ($terkunci)
                <div class="mb-3 rounded bg-gray-100 px-3 py-2 text-sm text-gray-600">
                    🔒 <b>Anggaran TA {{ $labelTa }} terkunci.</b> Tak ada pengajuan baru yang bisa dikirim untuk tahun ini sampai administrator membukanya.
                </div>
            @else
                {{-- Peringatan yang menentukan cara memakai halaman ini. --}}
                <div class="mb-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    ⚠️ Usulan ini adalah <b>snapshot penuh</b>: saat disetujui ia <b>menggantikan seluruh anggaran</b>
                    {{ $namaBagian }} · TA {{ $labelTa }} · {{ $kodeUnit ?: 'Semua Unit' }}. Akun yang tidak tercantum di
                    bawah akan <b>terhapus</b>. Karena itu tabel sudah diisi anggaran yang berlaku sekarang — ubah
                    seperlunya, jangan dikosongkan.
                </div>

                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <div class="min-w-[18rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Tambah Akun ke Usulan</label>
                        <select @change="tambahAkun($event.target.value); $event.target.value=''"
                                class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                            <option value="">— cari & pilih akun —</option>
                            @foreach ($bebanAkun as $a)
                                <option value="{{ $a->kode_coa }}" data-nama="{{ $a->nama_coa }}">{{ $a->kode_coa }} — {{ $a->nama_coa }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-[18rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label>
                        <input type="text" x-ref="keterangan" maxlength="255" value="{{ old('keterangan') }}"
                               placeholder="mis. Usulan anggaran rutin TA {{ $labelTa }}"
                               class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                    </div>
                    <button type="button" @click="ajukan()"
                            class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                        Ajukan untuk Disetujui
                    </button>
                </div>

                <form method="POST" action="{{ route('budget.pengajuan.store') }}" x-ref="ajuanForm" class="hidden">
                    @csrf
                    <input type="hidden" name="payload" x-ref="payload">
                    <input type="hidden" name="keterangan" x-ref="keteranganKirim">
                </form>
            @endif

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
                            @unless ($terkunci)<th class="px-2 py-2"></th>@endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-if="rows.length === 0">
                            <tr><td colspan="{{ $terkunci ? 14 : 15 }}" class="px-4 py-6 text-center text-gray-400">Belum ada akun. Tambahkan akun di atas.</td></tr>
                        </template>
                        <template x-for="r in rows" :key="r.kode_coa">
                            <tr class="hover:bg-gray-50">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5">
                                    <div class="font-medium text-gray-800" x-text="r.nama_coa"></div>
                                    <div class="font-mono text-[10px] text-gray-400" x-text="r.kode_coa"></div>
                                </td>
                                <template x-for="(v, mi) in r.bulanan" :key="mi">
                                    <td class="px-1 py-1">
                                        <input type="text" inputmode="numeric" x-model="r.bulanan[mi]"
                                               @if ($terkunci) readonly @endif
                                               class="w-full rounded border border-gray-200 px-1.5 py-1 text-right text-xs focus:border-brand focus:ring-brand read-only:bg-gray-50 read-only:text-gray-500">
                                    </td>
                                </template>
                                <td class="px-3 py-1.5 text-right font-mono font-semibold" x-text="formatRp(rowTotal(r))"></td>
                                @unless ($terkunci)
                                    <td class="px-2 py-1.5 text-center">
                                        <button type="button" class="text-red-500 hover:text-red-700" title="Buang akun dari usulan" @click="hapusAkun(r.kode_coa)">×</button>
                                    </td>
                                @endunless
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
                            @unless ($terkunci)<td></td>@endunless
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <script>
        function ajuanGrid(init) {
            return {
                rows: init.rows,
                tahun: init.tahun,
                kodeUnit: init.kodeUnit,

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

                ajukan() {
                    // Hanya baris bernilai yang dikirim — server pun membuang yang 0.
                    // Akun yang dibuang cukup TIDAK dikirim: usulan adalah snapshot
                    // penuh, penerapannya menghapus scope lama lebih dulu.
                    const items = [];
                    for (const r of this.rows) {
                        r.bulanan.forEach((v, i) => {
                            const n = this.num(v);
                            if (n > 0) items.push({ kode_coa: r.kode_coa, bulan: i + 1, nominal: String(n) });
                        });
                    }
                    if (items.length === 0) {
                        alert('Belum ada nominal yang diisi — tidak ada yang bisa diajukan.');
                        return;
                    }
                    if (!confirm('Ajukan usulan ini? Bila disetujui, seluruh anggaran scope ini digantikan isinya.')) return;

                    this.$refs.payload.value = JSON.stringify({ tahun: this.tahun, kode_unit: this.kodeUnit, items });
                    this.$refs.keteranganKirim.value = this.$refs.keterangan?.value ?? '';
                    this.$refs.ajuanForm.submit();
                },
            };
        }
    </script>
@endsection
