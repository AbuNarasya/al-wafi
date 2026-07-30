@extends('layouts.app')

@section('title', 'Kenaikan Tingkat & Kelulusan')

@php
    $warna = [
        'naik' => 'bg-emerald-100 text-emerald-700',
        'mengulang' => 'bg-amber-100 text-amber-700',
        'lulus' => 'bg-blue-100 text-blue-700',
        'lewati' => 'bg-gray-100 text-gray-600',
    ];
@endphp

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Menaikkan tingkat satu angkatan sekaligus dalam <b>satu jenjang</b>, atau <b>meluluskan</b> santri di tingkat terakhir.
        Tiap baris bisa diubah sendiri: naik, mengulang, atau dilewati.
    </p>
    <p class="mb-4 text-xs text-gray-500">
        Naik <b>jenjang</b> tidak di sini &mdash; ia melewati seleksi PPSB lewat <b>Pendaftaran Lanjutan</b> di halaman detail santri.
        Penagihan <a href="{{ route('tagihan_massal.index') }}" class="font-medium text-brand hover:underline">daftar ulang</a>
        juga terpisah dan dijalankan <b>sesudah</b> kenaikan ini, karena tarifnya mengikuti tingkat yang baru.
    </p>

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
                        <option value="{{ $kode }}" @selected(($filter['kode_jenjang'] ?? old('kode_jenjang')) === $kode)>{{ \App\Support\Referensi::label($kode, $label) }}</option>
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
            <form method="POST" action="{{ route('kenaikan_tingkat.eksekusi') }}"
                  onsubmit="return confirm('Jalankan kenaikan tingkat & kelulusan sesuai pilihan di layar?')">
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

                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-3">Santri</th>
                                <th class="px-3 py-3">Tingkat</th>
                                <th class="px-3 py-3">T.A Berjalan</th>
                                <th class="px-3 py-3">Keputusan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($hasil['baris'] as $b)
                                <tr class="{{ $b['usul'] === 'lewati' ? 'bg-gray-50/60' : '' }}">
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
                                                class="w-44 rounded-lg border border-gray-400 px-2 py-1 text-sm">
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
                        </tbody>
                    </table>
                </div>

                @if (\App\Support\Akses::boleh('kenaikan-tingkat', 'buat'))
                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Tanggal lulus <span class="text-gray-400">(untuk yang diluluskan)</span></label>
                            <input type="date" name="tanggal_lulus" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            <p class="mt-2 max-w-2xl text-xs text-gray-500">
                                3. Berjalan dalam <b>satu transaksi</b>: tingkat &amp; tahun ajaran berjalan diperbarui, riwayat
                                tingkat ditulis, dan yang diluluskan berstatus alumni. Tunggakan yang masih bersisa
                                <b>tidak dihapus</b> &mdash; alumni tetap bisa ditagih.
                            </p>
                        </div>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                            Jalankan Kenaikan
                        </button>
                    </div>
                @endif
            </form>
        @endif
    @endif
@endsection
