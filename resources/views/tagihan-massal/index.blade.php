@extends('layouts.app')

@section('title', 'Terbitkan Tagihan Massal')

@php
    $warna = [
        'terbit' => 'bg-emerald-100 text-emerald-700',
        'bebas' => 'bg-gray-100 text-gray-600',
        'dilewati' => 'bg-blue-50 text-blue-700',
        'terhalang' => 'bg-red-100 text-red-700',
    ];
    $ikon = ['terbit' => '✅', 'bebas' => '⊘', 'dilewati' => '⏭', 'terhalang' => '⛔'];
@endphp

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Menerbitkan <b>daftar ulang tahunan</b> untuk santri yang <b>naik tingkat</b> (1&rarr;2, 2&rarr;3, dan seterusnya).
        Angka diusulkan dari <a href="{{ route('tarif.index') }}" class="font-medium text-brand hover:underline">grid Tarif</a>
        menurut kenaikan masing-masing santri, tetapi tiap baris masih bisa Anda ubah atau lepas centangnya.
    </p>
    <p class="mb-1 text-xs text-gray-500">
        Jalankan <b>setelah</b> <a href="{{ route('kenaikan_tingkat.index') }}" class="font-medium text-brand hover:underline">Kenaikan Tingkat</a>
        &mdash; tarifnya mengikuti tingkat BARU santri. Dilewati otomatis: santri yang masih di <b>tingkat 1</b>
        dan santri pada <b>tahun masuknya</b> (baru maupun pindahan; mereka membayar registrasi, uang pangkal, &amp; perlengkapan).
    </p>
    <p class="mb-4 text-xs text-gray-400">
        Uang pangkal &amp; perlengkapan tidak diterbitkan di sini &mdash; keduanya per santri lewat alur PPSB di halaman
        detail santri. SPP punya modulnya sendiri di <a href="{{ route('spp.index') }}" class="underline">Kependidikan &rarr; SPP</a>.
    </p>

    {{-- Langkah 1 — saringan. Tanpa jalur & gelombang: keduanya hanya bermakna
         saat santri masuk, sedangkan daftar ulang urusan yang sudah bersekolah. --}}
    <form method="POST" action="{{ route('tagihan_massal.pratinjau') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        @csrf
        <div class="mb-3 text-sm font-semibold text-gray-700">1. Pilih sasaran</div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">T.A Tagihan <span class="text-red-500">*</span></label>
                <select name="tahun_ajaran" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiTa as $kode => $label)
                        <option value="{{ $kode }}" @selected(($filter['tahun_ajaran'] ?? old('tahun_ajaran')) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-gray-400">Tahun yang ditagih &mdash; dipakai mencari tarif.</p>
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
                <label class="mb-1 block text-xs font-medium text-gray-600">Tingkat</label>
                <input type="number" name="tingkat" min="1" value="{{ $filter['tingkat'] ?? '' }}"
                       placeholder="semua tingkat" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
            <div>
                {{-- Angkatan = tahun MASUK. Sengaja dipisah dari T.A tagihan:
                     santri angkatan 2026 ditagih daftar ulang T.A 2028. --}}
                <label class="mb-1 block text-xs font-medium text-gray-600">Angkatan</label>
                <select name="angkatan" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">— semua angkatan —</option>
                    @foreach ($opsiTa as $kode => $label)
                        <option value="{{ $kode }}" @selected(($filter['angkatan'] ?? '') === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-gray-400">Tahun santri masuk &mdash; hanya menyaring.</p>
            </div>
        </div>

        <div class="mt-4 flex justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Susun Pratinjau</button>
        </div>
    </form>

    {{-- Langkah 2 & 3 — pratinjau + terbitkan --}}
    @if ($hasil !== null)
        @if ($hasil['baris'] === [])
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
                Tidak ada santri aktif yang cocok dengan saringan itu.
            </div>
        @else
            <form method="POST" action="{{ route('tagihan_massal.terbitkan') }}"
                  onsubmit="return confirm('Terbitkan tagihan daftar ulang untuk baris yang dicentang?')">
                @csrf
                {{-- T.A tagihan dibawa apa adanya dari pratinjau: kalau diambil ulang
                     dari saringan, tagihan bisa tercap tahun yang berbeda dari yang
                     dipakai mencari tarifnya. --}}
                <input type="hidden" name="tahun_ajaran" value="{{ $filter['tahun_ajaran'] }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-gray-700">
                        2. Periksa &amp; ubah bila perlu
                        <span class="ml-1 font-normal text-gray-500">— daftar ulang T.A {{ $filter['tahun_ajaran'] }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        @foreach ($hasil['ringkas'] as $keputusan => $jumlah)
                            <span class="rounded-full px-2 py-0.5 font-medium {{ $warna[$keputusan] }}">
                                {{ $ikon[$keputusan] }} {{ ucfirst($keputusan) }}: {{ $jumlah }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-3">
                                    {{-- Centang semua: hanya menyentuh baris yang memang bisa terbit. --}}
                                    <input type="checkbox" checked
                                           onclick="this.closest('table').querySelectorAll('input[data-pilih]').forEach(c => c.checked = this.checked)"
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                </th>
                                <th class="px-3 py-3">Santri</th>
                                <th class="px-3 py-3">Kenaikan</th>
                                <th class="px-3 py-3">Daftar Ulang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($hasil['baris'] as $b)
                                @php $c = $b['daftar_ulang']; @endphp
                                <tr class="{{ $b['ada_yang_terbit'] ? '' : 'bg-gray-50/60' }}">
                                    <td class="px-3 py-3 align-top">
                                        @if ($b['ada_yang_terbit'])
                                            <input type="checkbox" data-pilih name="pilih[]" value="{{ $b['id'] }}" checked
                                                   class="rounded border-gray-300 text-brand focus:ring-brand">
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="font-medium text-gray-900">{{ $b['nama'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $b['nis'] ?: $b['no_pendaftaran'] }} · angkatan {{ $b['angkatan'] }}</div>
                                    </td>
                                    <td class="px-3 py-3 align-top text-gray-600">
                                        {{ $b['tingkat'] > 1 ? ($b['tingkat'] - 1).' → '.$b['tingkat'] : ($b['tingkat'] ?? '—') }}
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <span class="rounded px-1.5 py-0.5 text-[11px] font-medium {{ $warna[$c['keputusan']] }}">
                                            {{ $ikon[$c['keputusan']] }} {{ ucfirst($c['keputusan']) }}
                                        </span>
                                        @if ($c['keputusan'] === 'terbit')
                                            <input type="number" step="0.01" min="0"
                                                   name="nominal[{{ $b['id'] }}]"
                                                   value="{{ rtrim(rtrim($c['nominal'], '0'), '.') }}"
                                                   class="mt-1 w-36 rounded-lg border border-gray-400 px-2 py-1 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand">
                                        @endif
                                        @if ($c['asal'])
                                            <div class="mt-1 text-[11px] text-gray-400">{{ $c['asal'] }}</div>
                                        @endif
                                        @if ($c['alasan'])
                                            <div class="mt-1 max-w-md text-[11px] text-gray-500">{{ $c['alasan'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (\App\Support\Akses::boleh('tagihan-massal', 'buat'))
                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Jatuh tempo <span class="text-gray-400">(opsional, untuk semua)</span></label>
                            <input type="date" name="jatuh_tempo" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            <p class="mt-2 max-w-xl text-xs text-gray-500">
                                3. Penerbitan berjalan dalam <b>satu transaksi</b> dan langsung <b>diakui akrual</b>
                                (D Piutang / K Pendapatan). Bila ada satu baris yang gagal, seluruh batch dibatalkan.
                            </p>
                        </div>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                            Terbitkan Tagihan
                        </button>
                    </div>
                @endif
            </form>
        @endif
    @endif
@endsection
