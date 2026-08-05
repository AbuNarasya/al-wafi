@extends('layouts.app')

@section('title', 'Kenaikan Tingkat & Kelulusan')

@php
    $warna = [
        'naik' => 'bg-emerald-100 text-emerald-700',
        'mengulang' => 'bg-amber-100 text-amber-700',
        'melanjutkan' => 'bg-indigo-100 text-indigo-700',
        'lulus' => 'bg-blue-100 text-blue-700',
        'lewati' => 'bg-gray-100 text-gray-600',
    ];
@endphp

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Menaikkan tingkat satu angkatan sekaligus dalam <b>satu jenjang</b>, atau <b>meluluskan</b> santri di tingkat terakhir.
        Tiap baris bisa diubah sendiri: naik, mengulang, atau dilewati.
    </p>

    {{-- Inti perubahan alurnya, disebut paling atas: yang ditekan petugas
         MENJADWALKAN, bukan mengubah. Tanpa kalimat ini ia akan menekan tombol
         lalu bingung melihat tingkat santri tak berubah di daftar. --}}
    <p class="mb-4 rounded-lg border-2 border-indigo-500 bg-white px-4 py-2.5 text-sm text-indigo-950 shadow-sm">
        <b class="uppercase tracking-wide text-indigo-700">Ditetapkan sekarang, berlaku nanti.</b>
        Keputusan di halaman ini <b>dijadwalkan</b> &mdash; data santri baru berubah saat
        <b>tahun ajaran tujuan benar-benar dimulai</b>. Itulah sebabnya keputusannya memang harus
        ditetapkan <b>sebelum</b> tahun barunya jalan, dan tingkat maupun tarif santri tidak pernah
        mendahului kalender.
    </p>

    <p class="mb-4 text-xs text-gray-500">
        Santri di <b>tingkat terakhir</b> bisa dipilih <b>Melanjutkan</b>: siklus PPSB-nya (seleksi &amp; med check)
        dibuka <b>seketika</b> supaya keluarganya punya waktu mengurus dan mencicil &mdash; jenjang &amp; jalur
        tujuannya mengikuti master, dan datanya masuk ke
        <a href="{{ route('santri.calon') }}" class="font-medium text-brand hover:underline">Calon Santri</a>.
        Perpindahannya sendiri <b>tetap menunggu jadwal yang sama</b>, dan hanya menyala bila PPSB-nya
        sudah tuntas: perpindahan jenjang menuntut uang pangkal, med check, dan nominal yang diketik
        petugas &mdash; tak satu pun bisa dilewati hanya karena tanggalnya tiba.
        Penagihan <a href="{{ route('tagihan_massal.index') }}" class="font-medium text-brand hover:underline">daftar ulang</a>
        terpisah dan dijalankan <b>sesudah</b> penetapan ini, karena tarifnya mengikuti tingkat yang baru.
    </p>

    {{-- DAFTAR KERJA: apa yang sudah ditetapkan tapi belum menyala. Tanpa ini,
         siklus yang menggantung (mis. PPSB belum tuntas menjelang 1 Juli) hanya
         ketahuan kalau ada yang membuka santrinya satu per satu. --}}
    @if ($terjadwal->isNotEmpty())
        <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700">
                Perubahan Terjadwal <span class="font-normal text-gray-400">({{ $terjadwal->count() }} belum berlaku)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2">Santri</th><th class="px-4 py-2">Sekarang</th>
                            <th class="px-4 py-2">Keputusan</th><th class="px-4 py-2">Berlaku T.A</th>
                            <th class="px-4 py-2">Keadaan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($terjadwal as $j)
                            <tr class="border-t border-gray-100">
                                <td class="px-4 py-2">
                                    <a href="{{ route('santri.show', $j->id_santri) }}" class="font-medium text-brand hover:underline">{{ $j->santri?->nama ?? '—' }}</a>
                                    <span class="block text-xs text-gray-400">{{ $j->santri?->nis ?: $j->santri?->no_pendaftaran }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    {{ $j->santri?->jenjang?->nama ?? $j->santri?->kode_jenjang }}{{ $j->santri?->tingkat ? ' · Tingkat '.$j->santri->tingkat : '' }}
                                </td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$j->keputusan] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $opsiKeputusan[$j->keputusan] ?? $j->keputusan }}
                                    </span>
                                    @if ($j->jenjangTujuan)
                                        <span class="block text-xs text-gray-400">&rarr; {{ $j->jenjangTujuan->nama }}{{ $j->tingkat_tujuan ? ' tingkat '.$j->tingkat_tujuan : '' }}</span>
                                    @elseif ($j->tingkat_tujuan)
                                        <span class="block text-xs text-gray-400">&rarr; tingkat {{ $j->tingkat_tujuan }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $j->tahun_ajaran }}</td>
                                <td class="px-4 py-2">
                                    @if ($j->status === 'siap')
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Siap &mdash; menunggu tanggalnya</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Menunggu PPSB</span>
                                        @if ($j->pendaftaran)
                                            <span class="block text-xs text-gray-400">{{ $j->pendaftaran->nomor }} &middot; tahap {{ \App\Services\Ppsb\Tahap::labelStatus((string) $j->pendaftaran->status) }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Langkah 1 — saringan --}}
    <form method="POST" action="{{ route('kenaikan_tingkat.pratinjau') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        @csrf
        <div class="mb-3 text-sm font-semibold text-gray-700">1. Pilih sasaran</div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">T.A Tujuan <span class="text-red-500">*</span></label>
                <select name="tahun_ajaran" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiTa as $kode => $label)
                        <option value="{{ $kode }}" @selected(($filter['tahun_ajaran'] ?? old('tahun_ajaran')) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-gray-400">Tahun yang akan dijalani sesudah naik.</p>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Jenjang <span class="text-red-500">*</span></label>
                <select name="kode_jenjang" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiJenjang as $kode => $label)
                        <option value="{{ $kode }}" @selected(($filter['kode_jenjang'] ?? old('kode_jenjang')) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tingkat sekarang</label>
                <input type="number" name="tingkat" min="1" value="{{ $filter['tingkat'] ?? '' }}"
                       placeholder="semua tingkat" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
        </div>
        <div class="mt-4 flex justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Susun Pratinjau</button>
        </div>
    </form>

    {{-- Langkah 2 & 3 --}}
    @if ($hasil !== null)
        @if ($hasil['baris'] === [])
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
                Tidak ada santri aktif yang cocok dengan saringan itu.
            </div>
        @else
            {{-- Satu jenjang bisa berisi ratusan santri, jadi pratinjaunya disaring
                 di browser (rowFilter) — barisnya sudah dirender semua.
                 MENYARING HANYA MENYEMBUNYIKAN: keputusan pada baris yang sedang
                 tersembunyi tetap ikut terkirim, dan itu memang yang diinginkan
                 (menyaring bukan cara membatalkan). Disebutkan di layar juga. --}}
            <form method="POST" action="{{ route('kenaikan_tingkat.tetapkan') }}" x-data="rowFilter" x-cloak
                  onsubmit="return confirm('Tetapkan perubahan sesuai pilihan di layar? Data santri belum berubah sekarang — perubahannya berlaku saat T.A {{ $filter['tahun_ajaran'] }} dimulai.')">
                @csrf
                <input type="hidden" name="tahun_ajaran" value="{{ $filter['tahun_ajaran'] }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-gray-700">
                        2. Periksa keputusannya
                        <span class="ml-1 font-normal text-gray-500">— menuju T.A {{ $filter['tahun_ajaran'] }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        @foreach ($hasil['ringkas'] as $k => $jumlah)
                            <span class="rounded-full px-2 py-0.5 font-medium {{ $warna[$k] }}">
                                {{ $opsiKeputusan[$k] }}: {{ $jumlah }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <x-filter-bar placeholder="Cari nama / NIS…" />
                    <span class="text-xs text-gray-400">Menyaring hanya menyembunyikan baris — keputusan pada baris tersembunyi tetap ikut terkirim.</span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-3">Santri</th>
                                <th class="px-3 py-3">Tingkat</th>
                                <th class="px-3 py-3">T.A Berjalan</th>
                                <th class="px-3 py-3">Keputusan</th>
                            </tr>
                            <tr class="bg-white">
                                <x-fcol :col="0" placeholder="Filter nama / NIS" />
                                <x-fcol :col="1" type="select" />
                                <x-fcol :col="2" type="select" />
                                <x-fcol type="blank" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($hasil['baris'] as $b)
                                <tr data-row class="{{ $b['usul'] === 'lewati' ? 'bg-gray-50/60' : '' }}">
                                    <td class="px-3 py-3 align-top">
                                        <div class="font-medium text-gray-900">{{ $b['nama'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $b['nis'] ?: $b['no_pendaftaran'] }} · angkatan {{ $b['angkatan'] }}</div>
                                    </td>
                                    <td class="px-3 py-3 align-top text-gray-600">
                                        {{ $b['tingkat'] ?? '—' }}
                                        @if ($b['tingkat'] && $b['tingkat'] >= $hasil['tingkat_terakhir'] && $hasil['tingkat_terakhir'] > 0)
                                            <span class="ml-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">terakhir</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top text-gray-500">{{ $b['ta_berjalan'] ?? '—' }}</td>
                                    <td class="px-3 py-3 align-top">
                                        <select name="keputusan[{{ $b['id'] }}]"
                                                class="w-64 rounded-lg border border-gray-400 px-2 py-1 text-sm">
                                            @foreach ($b['pilihan'] as $p)
                                                <option value="{{ $p }}" @selected($p === $b['usul'])>{{ $opsiKeputusan[$p] }}</option>
                                            @endforeach
                                        </select>
                                        @if ($b['alasan'])
                                            <div class="mt-1 max-w-md text-[11px] text-gray-500">{{ $b['alasan'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr data-empty style="display:none"><td colspan="4" class="px-3 py-10 text-center text-gray-400">Tidak ada baris yang cocok dengan filter.</td></tr>
                        </tbody>
                    </table>
                </div>

                @if (\App\Support\Akses::boleh('kenaikan-tingkat', 'buat'))
                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Tanggal lulus <span class="text-gray-400">(untuk yang diluluskan)</span></label>
                            <input type="date" name="tanggal_lulus" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            <p class="mt-2 max-w-2xl text-xs text-gray-500">
                                3. Tersimpan dalam <b>satu transaksi</b> sebagai perubahan <b>terjadwal</b> &mdash; data santri
                                belum berubah. Saat T.A {{ $filter['tahun_ajaran'] }} dimulai, tingkat &amp; tahun ajaran
                                berjalan diperbarui, riwayat tingkat ditulis, dan yang diluluskan berstatus alumni.
                                Yang <b>Melanjutkan</b> pendaftarannya dibuka <b>sekarang</b> (tagihan registrasinya ikut
                                terbit bila tarifnya diisi), tetapi perpindahannya menunggu PPSB-nya tuntas.
                                Tunggakan yang masih bersisa <b>tidak dihapus</b> &mdash; alumni tetap bisa ditagih.
                            </p>
                        </div>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                            Tetapkan Perubahan
                        </button>
                    </div>
                @endif
            </form>
        @endif
    @endif
@endsection
