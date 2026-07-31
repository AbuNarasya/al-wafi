@extends('layouts.app')

@section('title', 'SPP')

@php $bolehUbah = \App\Support\Akses::boleh('spp', 'ubah') || \App\Support\Akses::boleh('spp', 'buat'); @endphp

@section('content')
    <div x-data="{ khusus: false, prabayar: false }">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold text-gray-900">SPP</h2>
            @if ($bolehUbah)
                <div class="flex gap-2">
                    <button type="button" @click="khusus = true" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Nominal Khusus Santri</button>
                    <button type="button" @click="prabayar = true" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Setoran Prabayar</button>
                </div>
            @endif
        </div>
        {{-- Tarif SPP kini terpusat di master Jenis Biaya (tipe `spp`), bukan lagi di halaman ini. --}}
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800">
            Tarif SPP per jenjang diatur di
            <a href="{{ route('jenis_biaya.index') }}" class="font-semibold underline">PPSB → Jenis Biaya</a>
            (jenis bertipe <b>SPP</b>). Nominal khusus per santri di bawah tetap mengalahkan tarif jenjang.
        </div>

        <div>
        {{-- Generate tagihan SPP --}}
        <div>
            <h3 class="mb-3 text-sm font-semibold text-gray-800">Terbitkan Tagihan SPP</h3>
            <form method="GET" action="{{ route('spp.index') }}" class="mb-3 flex items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div><label class="mb-1 block text-xs font-medium text-gray-500">Periode (YYYY-MM)</label>
                    <input type="month" name="periode" value="{{ $periode }}" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm"></div>
                <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Pratinjau</button>
            </form>

            {{-- Tahun ajaran periode ini disebut TERANG-TERANGAN. Selama ia tak
                 pernah tampil, tagihan yang tercap tahun yang salah tak akan
                 pernah ketahuan oleh siapa pun. --}}
            @if ($periksa)
                @if ($periksa['lintas'])
                    <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <b>Lintas tahun ajaran.</b> Periode {{ $periode }} termasuk <b>T.A {{ $periksa['ta']->kode }}</b>,
                        sedangkan yang sedang berjalan <b>T.A {{ $periksa['berjalan']?->kode ?? '—' }}</b>.
                        Penerbitannya tetap boleh — mis. menyusul bulan yang terlewat — tetapi
                        <b>alasannya wajib diisi</b> dan akan tercatat di log aktivitas.
                    </div>
                @else
                    <div class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-600">
                        Periode {{ $periode }} termasuk <b>Tahun Ajaran {{ $periksa['ta']->kode }}</b> (tahun ajaran berjalan).
                    </div>
                @endif
            @endif

            @if ($pratinjau !== null)
                @php
                    $siap = collect($pratinjau)->where('status', 'siap');
                    $sudah = collect($pratinjau)->where('status', 'sudah_ada');
                    $tanpa = collect($pratinjau)->where('status', 'tanpa_tarif');
                @endphp
                {{-- Rekap per jenjang, DI ATAS daftarnya: yang pertama ditanyakan
                     sebelum menerbitkan adalah "berapa yang akan terbit", dan
                     angka itu tak bisa dibaca dari daftar berisi ratusan baris.
                     Jumlah yang TERTAHAN ikut disebut supaya jenjang yang sebagian
                     selnya belum diisi tidak tampak bertotal kecil tanpa sebab. --}}
                @if ($rekapJenjang !== [])
                    @php
                        $totalSemua = collect($rekapJenjang)->reduce(fn ($t, $r) => \App\Support\Money::add($t, $r['total']), '0');
                        // Satu warna per jenjang, dibagi mengikuti URUTAN MASTER
                        // (SDTQ → SMP → SMA) supaya jatah warna tak bergeser saat
                        // jenjang baru ditambahkan di belakang. Kelasnya ditulis
                        // utuh di Blade — Tailwind memindai berkas ini, bukan app/.
                        $paletJenjang = [
                            ['blok' => 'border-sky-200 bg-sky-50', 'judul' => 'text-sky-900', 'angka' => 'text-sky-900', 'ket' => 'text-sky-700/70'],
                            ['blok' => 'border-violet-200 bg-violet-50', 'judul' => 'text-violet-900', 'angka' => 'text-violet-900', 'ket' => 'text-violet-700/70'],
                            ['blok' => 'border-teal-200 bg-teal-50', 'judul' => 'text-teal-900', 'angka' => 'text-teal-900', 'ket' => 'text-teal-700/70'],
                            ['blok' => 'border-orange-200 bg-orange-50', 'judul' => 'text-orange-900', 'angka' => 'text-orange-900', 'ket' => 'text-orange-700/70'],
                            ['blok' => 'border-fuchsia-200 bg-fuchsia-50', 'judul' => 'text-fuchsia-900', 'angka' => 'text-fuchsia-900', 'ket' => 'text-fuchsia-700/70'],
                            ['blok' => 'border-lime-200 bg-lime-50', 'judul' => 'text-lime-900', 'angka' => 'text-lime-900', 'ket' => 'text-lime-700/70'],
                        ];
                    @endphp
                    <div class="mb-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-800">
                                Total Tagihan per Jenjang
                                @if ($periksa)<span class="ml-1 font-normal text-gray-500">· T.A {{ $periksa['ta']->kode }}</span>@endif
                            </h4>
                            <div class="text-sm text-gray-500">
                                Total seluruhnya <b class="text-base tabular-nums text-gray-900">@rp($totalSemua)</b>
                            </div>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($rekapJenjang as $i => $r)
                                @php $w = $paletJenjang[$i % count($paletJenjang)]; @endphp
                                <div class="rounded-lg border {{ $w['blok'] }} px-3 py-2">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-sm font-semibold {{ $w['judul'] }}">{{ $r['nama'] }}</span>
                                        <span class="text-sm font-semibold tabular-nums {{ $w['angka'] }}">@rp($r['total'])</span>
                                    </div>
                                    <div class="mt-0.5 text-[11px] {{ $w['ket'] }}">
                                        {{ $r['siap'] }} santri siap terbit
                                        @if ($r['tanpa_tarif'] > 0)
                                            · <span class="font-semibold text-amber-700">{{ $r['tanpa_tarif'] }} tanpa tarif</span>
                                        @endif
                                        @if ($r['sudah_ada'] > 0)
                                            · {{ $r['sudah_ada'] }} sudah ada
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Pratinjau bisa memuat ratusan baris, jadi disaring di browser
                     (rowFilter): seluruhnya sudah dirender, tak perlu bolak-balik
                     ke server. Kolom Status ikut bisa disaring — itulah cara cepat
                     menemukan siapa saja yang "tanpa tarif". --}}
                <div x-data="rowFilter" x-cloak>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <x-filter-bar placeholder="Cari nama santri…" />
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-emerald-100 px-3 py-1 font-medium text-emerald-700">{{ $siap->count() }} siap terbit</span>
                            <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-600">{{ $sudah->count() }} sudah ada</span>
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-amber-700">{{ $tanpa->count() }} tanpa tarif</span>
                        </div>
                    </div>

                    <div class="max-h-72 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-2">NIS</th><th class="px-4 py-2">Santri</th>
                                    <th class="px-4 py-2">Jenjang</th><th class="px-4 py-2">Tingkat</th>
                                    <th class="px-4 py-2 text-right">Nominal</th><th class="px-4 py-2">Status</th>
                                </tr>
                                <tr class="bg-white">
                                    <x-fcol :col="0" placeholder="Filter NIS" /><x-fcol :col="1" placeholder="Filter nama" />
                                    <x-fcol :col="2" type="select" /><x-fcol :col="3" type="select" />
                                    <x-fcol type="blank" /><x-fcol :col="5" type="select" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pratinjau as $p)
                                    <tr data-row>
                                        <td class="px-4 py-1.5 font-mono text-xs text-gray-600">{{ $p['nis'] ?: '—' }}</td>
                                        <td class="px-4 py-1.5">{{ $p['nama'] }}</td>
                                        <td class="px-4 py-1.5 text-gray-600">{{ $p['jenjang'] ?: '—' }}</td>
                                        <td class="px-4 py-1.5 text-gray-600">{{ $p['tingkat'] ? 'Tingkat '.$p['tingkat'] : '—' }}</td>
                                        <td class="px-4 py-1.5 text-right tabular-nums">@if ($p['nominal'])@rp($p['nominal'])@else—@endif</td>
                                        <td class="px-4 py-1.5">
                                            <span class="rounded-full px-2 py-0.5 text-xs {{ $p['status'] === 'siap' ? 'bg-emerald-100 text-emerald-700' : ($p['status'] === 'sudah_ada' ? 'bg-gray-100 text-gray-500' : 'bg-amber-100 text-amber-700') }}">{{ str_replace('_', ' ', $p['status']) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Tidak ada baris yang cocok dengan filter.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($siap->count() > 0 && $bolehUbah)
                    <form method="POST" action="{{ route('spp.generate') }}" class="mt-3 flex items-end gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
                          onsubmit="return confirm('Terbitkan {{ $siap->count() }} tagihan SPP periode {{ $periode }}?')">
                        @csrf
                        <input type="hidden" name="periode" value="{{ $periode }}">
                        <div><label class="mb-1 block text-xs font-medium text-emerald-700">Tanggal Terbit</label>
                            <input type="date" name="tanggal" value="{{ now()->toDateString() }}" class="rounded-lg border-emerald-300 px-3 py-1.5 text-sm"></div>
                        {{-- Isian ini HANYA muncul saat memang lintas tahun ajaran —
                             menampilkannya selalu akan melatih orang mengabaikannya. --}}
                        @if ($periksa && $periksa['lintas'])
                            <div class="min-w-64 flex-1">
                                <label class="mb-1 block text-xs font-medium text-amber-800">
                                    Alasan menerbitkan di luar T.A berjalan <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="alasan_lintas_ta" required maxlength="255"
                                       value="{{ old('alasan_lintas_ta') }}"
                                       placeholder="mis. menyusul SPP Juni yang terlewat"
                                       class="w-full rounded-lg border-amber-300 px-3 py-1.5 text-sm">
                            </div>
                        @endif
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan {{ $siap->count() }} Tagihan</button>
                    </form>
                @endif
            @else
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-sm text-gray-400">Pilih periode lalu klik Pratinjau untuk melihat calon tagihan.</div>
            @endif
        </div>
        </div>

        {{-- Modal: Nominal Khusus Santri --}}
        <div x-show="khusus" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="khusus = false">
            <div class="my-10 w-full max-w-md rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">Nominal SPP Khusus</h3>
                    <button type="button" @click="khusus = false" class="text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                {{-- Santri dikirim sebagai isian biasa (id_santri), bukan disisipkan
                     ke URL: dropdown-yang-bisa-dicari menaruh nilainya di input
                     hidden, dan hidden input tak bisa dibaca dari scope Alpine induk. --}}
                <form method="POST" action="{{ route('spp.nominal_khusus') }}" class="space-y-3 p-5">
                    @csrf @method('PUT')
                    <p class="rounded bg-slate-50 px-3 py-2 text-xs text-gray-500">Jalur beasiswa/keringanan/tahfizh. Nominal <b>0</b> = beasiswa penuh (tagihan tetap terbit senilai nol). <b>Kosongkan</b> nominal = kembali ke tarif jenjang.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Santri</label>
                        <x-search-select name="id_santri" :options="$santriOptions" placeholder="— pilih santri —" required />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Nominal SPP</label><x-input-rupiah name="nominal_spp" class="px-3 py-2" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Alasan</label><input type="text" name="keterangan_spp" placeholder="mis. beasiswa 50%" class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" @click="khusus = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal: Setoran Prabayar --}}
        <div x-show="prabayar" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="prabayar = false">
            <div class="my-10 w-full max-w-md rounded-xl bg-white shadow-xl" x-data="{ sumber: 'kas' }">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">Setoran SPP / Prabayar</h3>
                    <button type="button" @click="prabayar = false" class="text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                <form method="POST" action="{{ route('spp.prabayar') }}" class="space-y-3 p-5">
                    @csrf <input type="hidden" name="tanggal" value="{{ now()->toDateString() }}">
                    <p class="rounded bg-slate-50 px-3 py-2 text-xs text-gray-500">Melunasi <b>tunggakan tertua</b> lebih dulu; kelebihannya menjadi saldo prabayar (Pendapatan Diterima Dimuka), otomatis dipakai saat tagihan berikutnya terbit.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Santri</label>
                        <x-search-select name="id_santri" :options="$santriOptions" placeholder="— pilih santri —" required />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Nominal</label><x-input-rupiah name="nominal" required class="px-3 py-2" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Sumber Dana</label>
                            <select name="sumber" x-model="sumber" class="w-full rounded border-gray-300 px-3 py-2 text-sm"><option value="kas">Setoran tunai / transfer</option><option value="dompet_wali">Dompet Wali</option></select></div>
                    </div>
                    <div x-show="sumber === 'kas'">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Kas/Rekening</label>
                        <select name="kode_rekening" class="w-full rounded border-gray-300 px-3 py-2 text-sm"><option value="">— pilih —</option>@foreach ($rekeningOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" @click="prabayar = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terima Setoran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
