@extends('layouts.app')

@section('title', 'Dashboard Laundry')

@php
    $kg = fn ($v) => number_format((float) $v, 1, ',', '.');
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $angka = fn ($v, $d = 0) => number_format((float) $v, $d, ',', '.');
    /** Selisih terhadap bulan lalu: ▲ / ▼ persen, atau kosong bila bulan lalu nol. */
    $banding = function ($kini, $dulu) {
        if (! $dulu) {
            return null;
        }
        $p = ($kini - $dulu) / $dulu * 100;

        return ($p >= 0 ? '▲ ' : '▼ ').number_format(abs($p), 0, ',', '.').'% dari bulan lalu';
    };

    // ---- Grafik: batang kg bertumpuk (ikhwan / akhwat) + garis jumlah kantong ----
    $W = 760; $H = 280; $kiri = 48; $kanan = 44; $atas = 16; $bawah = 34;
    $lebarArea = $W - $kiri - $kanan; $tinggiArea = $H - $atas - $bawah;
    $maksKg = max(1, $baris->max('kg')); $maksKantong = max(1, $baris->max('kantong'));
    // Skala "rapi": 4 garis bantu.
    $langkah = (function ($m) { $p = 10 ** floor(log10($m / 4)); foreach ([1, 2, 2.5, 5, 10] as $k) { if ($m / ($k * $p) <= 4) return $k * $p; } return 10 * $p; })($maksKg);
    $puncakKg = $langkah * 4;
    $slot = $lebarArea / 12; $lebarBatang = $slot * 0.56;
    $y = fn ($v) => $atas + $tinggiArea - ($v / $puncakKg) * $tinggiArea;
    $yK = fn ($v) => $atas + $tinggiArea - ($v / $maksKantong) * $tinggiArea;
    $warnaIkhwan = '#164a9e'; $warnaAkhwat = '#db2777';
@endphp

@section('content')
    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
        <label><span class="block text-xs text-gray-500">12 bulan sampai</span>
            <input type="month" name="sampai" value="{{ $sampai }}" class="rounded-lg border-gray-300 text-sm"></label>
        <label><span class="block text-xs text-gray-500">Bagian</span>
            <select name="bagian" class="rounded-lg border-gray-300 text-sm">
                <option value="">Ikhwan &amp; Akhwat</option>
                @foreach (\App\Support\BagianLaundry::BAGIAN as $k => $b)<option value="{{ $k }}" @selected($bagian === $k)>{{ $b['label'] }}</option>@endforeach
            </select></label>
        <button class="rounded-lg border border-gray-300 px-3 py-2 hover:bg-gray-50">Tampilkan</button>
    </form>

    {{-- ---- Kartu ringkasan bulan pilihan ---- --}}
    <p class="mt-5 text-sm font-semibold text-gray-700">{{ $labelIni }}{{ $bagian ? ' · '.\App\Support\BagianLaundry::BAGIAN[$bagian]['label'] : '' }}</p>
    <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Total cucian', $kg($ini->kg).' kg', $angka($ini->kantong).' kantong', $banding($ini->kg, $lalu->kg)],
            ['Santri pemakai', $angka($ini->santri), 'dari '.$angka($santriAktif).' santri aktif ('.($santriAktif ? $angka($ini->santri / $santriAktif * 100) : 0).'%)', $banding($ini->santri, $lalu->santri)],
            ['Rata-rata penyerahan', $angka($ini->serah_per_santri, 1).'× per santri', $angka($ini->serah).' penyerahan · '.$kg($ini->kg_per_santri).' kg/santri', $banding($ini->serah_per_santri, $lalu->serah_per_santri)],
            ['Kelebihan kuota', ($ini->perkiraan ? '≈ ' : '').$kg($ini->kg_lebih).' kg', $angka($ini->santri_lebih).' santri · '.($ini->perkiraan ? '≈ ' : '').$rp($ini->ditagih), $ini->perkiraan ? 'perkiraan — belum disusun' : null],
        ] as [$judul, $nilai, $sub, $catatan])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $judul }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ $nilai }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ $sub }}</p>
                @if ($catatan)<p class="mt-1 text-xs text-gray-400">{{ $catatan }}</p>@endif
            </div>
        @endforeach
    </div>
    {{-- Bisa diklik → rinciannya di Monitor Kelebihan Laundry (paket 2, 3 Nov 2026), bila petugas berhak membukanya. --}}
    @php
        $bisaMonitor = \App\Support\Akses::boleh('laundry-monitor', 'lihat');
        $rentang = ['tampil' => 'tagihan', 'dari' => $baris->first()->bulan, 'sampai' => $sampai] + array_filter(['bagian' => $bagian]);
        $kartu = [
            ['Tagihan kelebihan 12 bulan', $rp($baris->where('perkiraan', false)->sum('ditagih')), 'text-gray-900', $rentang],
            ['Sudah lunas', $rp($baris->sum('lunas')), 'text-emerald-700', $rentang + ['status' => 'lunas']],
            ['Belum lunas (semua bulan)', $rp($belumLunas), 'text-amber-700', ['tampil' => 'tagihan', 'status' => 'belum'] + array_filter(['bagian' => $bagian])],
        ];
    @endphp
    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        @foreach ($kartu as [$judul, $nilai, $warna, $saring])
            @if ($bisaMonitor)
                <a href="{{ route('laundry.monitor', $saring) }}" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm transition hover:border-brand hover:shadow">
                    <span>{{ $judul }}: <b class="tabular-nums {{ $warna }}">{{ $nilai }}</b></span><span class="text-xs text-brand">rincian &rarr;</span></a>
            @else
                <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm">{{ $judul }}: <b class="tabular-nums {{ $warna }}">{{ $nilai }}</b></div>
            @endif
        @endforeach
    </div>

    {{-- ---- Grafik 12 bulan ---- --}}
    <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold text-gray-900">Pemakaian 12 bulan</p>
            <div class="flex flex-wrap gap-4 text-xs text-gray-600">
                @if (! $bagian || $bagian === 'ikhwan')<span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" style="background: {{ $warnaIkhwan }}"></span>Ikhwan (kg)</span>@endif
                @if (! $bagian || $bagian === 'akhwat')<span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" style="background: {{ $warnaAkhwat }}"></span>Akhwat (kg)</span>@endif
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-amber-500"></span>Jumlah kantong</span>
            </div>
        </div>
        <div class="mt-3 overflow-x-auto">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full min-w-[640px]" role="img" aria-label="Grafik pemakaian laundry 12 bulan">
                {{-- garis bantu & sumbu kg --}}
                @for ($i = 0; $i <= 4; $i++)
                    @php $v = $langkah * $i; $yy = $y($v); @endphp
                    <line x1="{{ $kiri }}" x2="{{ $W - $kanan }}" y1="{{ $yy }}" y2="{{ $yy }}" stroke="#e5e7eb" stroke-width="1" />
                    <text x="{{ $kiri - 6 }}" y="{{ $yy + 4 }}" text-anchor="end" font-size="11" fill="#6b7280">{{ $angka($v) }}</text>
                @endfor
                <text x="{{ $kiri - 6 }}" y="{{ $atas - 4 }}" text-anchor="end" font-size="10" fill="#9ca3af">kg</text>
                <text x="{{ $W - $kanan + 6 }}" y="{{ $atas - 4 }}" font-size="10" fill="#9ca3af">kantong</text>
                <text x="{{ $W - $kanan + 6 }}" y="{{ $yK($maksKantong) + 4 }}" font-size="11" fill="#b45309">{{ $angka($maksKantong) }}</text>
                <text x="{{ $W - $kanan + 6 }}" y="{{ $yK(0) + 4 }}" font-size="11" fill="#b45309">0</text>

                @foreach ($baris as $i => $r)
                    @php
                        $x = $kiri + $slot * $i + ($slot - $lebarBatang) / 2;
                        $hI = $r->kg_ikhwan / $puncakKg * $tinggiArea; $hA = $r->kg_akhwat / $puncakKg * $tinggiArea;
                        $dasar = $atas + $tinggiArea;
                    @endphp
                    <g>
                        <title>{{ $r->label }}: {{ $kg($r->kg) }} kg (ikhwan {{ $kg($r->kg_ikhwan) }}, akhwat {{ $kg($r->kg_akhwat) }}) · {{ $r->kantong }} kantong</title>
                        @if ($hI > 0)<rect x="{{ $x }}" y="{{ $dasar - $hI }}" width="{{ $lebarBatang }}" height="{{ $hI }}" fill="{{ $warnaIkhwan }}" rx="2" />@endif
                        @if ($hA > 0)<rect x="{{ $x }}" y="{{ $dasar - $hI - $hA }}" width="{{ $lebarBatang }}" height="{{ $hA }}" fill="{{ $warnaAkhwat }}" rx="2" />@endif
                        @if ($r->kg > 0)<text x="{{ $x + $lebarBatang / 2 }}" y="{{ $dasar - $hI - $hA - 4 }}" text-anchor="middle" font-size="10" fill="#374151">{{ $kg($r->kg) }}</text>@endif
                    </g>
                    <text x="{{ $kiri + $slot * $i + $slot / 2 }}" y="{{ $H - 12 }}" text-anchor="middle" font-size="11" @if ($r->bulan === $sampai) font-weight="700" fill="#111827" @else fill="#6b7280" @endif>{{ $r->label }}</text>
                @endforeach

                {{-- garis jumlah kantong --}}
                <polyline fill="none" stroke="#f59e0b" stroke-width="2"
                          points="{{ $baris->map(fn ($r, $i) => round($kiri + $slot * $i + $slot / 2, 1).','.round($yK($r->kantong), 1))->implode(' ') }}" />
                @foreach ($baris as $i => $r)
                    <circle cx="{{ $kiri + $slot * $i + $slot / 2 }}" cy="{{ $yK($r->kantong) }}" r="3" fill="#f59e0b"><title>{{ $r->label }}: {{ $r->kantong }} kantong</title></circle>
                @endforeach
            </svg>
        </div>
    </div>

    {{-- ---- Tabel 12 bulan ---- --}}
    <div class="mt-5 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full whitespace-nowrap text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2">Bulan</th>
                    <th class="px-3 py-2 text-right">Kantong</th>
                    <th class="px-3 py-2 text-right">Total kg</th>
                    @unless ($bagian)<th class="px-3 py-2 text-right">Ikhwan / Akhwat kg</th>@endunless
                    <th class="px-3 py-2 text-right">Santri</th>
                    <th class="px-3 py-2 text-right">kg / santri</th>
                    <th class="px-3 py-2 text-right">Penyerahan / santri</th>
                    <th class="px-3 py-2 text-right">Lama proses</th>
                    <th class="px-3 py-2 text-right">Ada kurang</th>
                    <th class="px-3 py-2 text-right">Melebihi kuota</th>
                    <th class="px-3 py-2 text-right">kg kelebihan</th>
                    <th class="px-3 py-2 text-right">Tagihan</th>
                    <th class="px-3 py-2 text-right">Lunas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 tabular-nums">
                @foreach ($baris->reverse() as $r)
                    <tr @class(['bg-blue-50/40 font-semibold' => $r->bulan === $sampai])>
                        <td class="px-3 py-2 text-left font-medium text-gray-900">{{ $r->label }}</td>
                        <td class="px-3 py-2 text-right">{{ $angka($r->kantong) }}</td>
                        <td class="px-3 py-2 text-right">{{ $kg($r->kg) }}</td>
                        @unless ($bagian)<td class="px-3 py-2 text-right text-gray-600">{{ $kg($r->kg_ikhwan) }} / {{ $kg($r->kg_akhwat) }}</td>@endunless
                        <td class="px-3 py-2 text-right">{{ $angka($r->santri) }}</td>
                        <td class="px-3 py-2 text-right">{{ $kg($r->kg_per_santri) }}</td>
                        <td class="px-3 py-2 text-right">{{ $angka($r->serah_per_santri, 1) }}×</td>
                        <td class="px-3 py-2 text-right">{{ $r->jam_proses !== null ? $angka($r->jam_proses, 1).' jam' : '—' }}</td>
                        <td @class(['px-3 py-2 text-right', 'text-red-700' => $r->kurang > 0])>{{ $angka($r->kurang) }}</td>
                        <td class="px-3 py-2 text-right">{{ $angka($r->santri_lebih) }}</td>
                        <td class="px-3 py-2 text-right">{{ $r->perkiraan && $r->kg_lebih > 0 ? '≈ ' : '' }}{{ $kg($r->kg_lebih) }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($r->perkiraan)
                                <span class="text-gray-400" title="Perkiraan — tagihan bulan ini belum disusun">{{ $r->ditagih ? '≈ '.$rp($r->ditagih) : '—' }}</span>
                            @else
                                {{ $rp($r->ditagih) }}@if ($r->draf > 0)<span class="block text-xs font-normal text-amber-700">+ draf {{ $rp($r->draf) }}</span>@endif
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right text-emerald-700">{{ $r->perkiraan ? '—' : $rp($r->lunas) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 bg-gray-50 font-semibold tabular-nums">
                <tr>
                    <td class="px-3 py-2">12 bulan</td>
                    <td class="px-3 py-2 text-right">{{ $angka($baris->sum('kantong')) }}</td>
                    <td class="px-3 py-2 text-right">{{ $kg($baris->sum('kg')) }}</td>
                    @unless ($bagian)<td class="px-3 py-2 text-right">{{ $kg($baris->sum('kg_ikhwan')) }} / {{ $kg($baris->sum('kg_akhwat')) }}</td>@endunless
                    <td class="px-3 py-2 text-right text-xs font-normal text-gray-500" colspan="5">rata-rata {{ $kg($baris->avg('kg')) }} kg / bulan</td>
                    <td class="px-3 py-2 text-right">{{ $angka($baris->sum('santri_lebih')) }}</td>
                    <td class="px-3 py-2 text-right">{{ $kg($baris->sum('kg_lebih')) }}</td>
                    <td class="px-3 py-2 text-right">{{ $rp($baris->where('perkiraan', false)->sum('ditagih')) }}</td>
                    <td class="px-3 py-2 text-right text-emerald-700">{{ $rp($baris->sum('lunas')) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="mt-2 text-xs text-gray-500">
        Pemakaian dihitung menurut tanggal cucian <b>diterima</b>; penyerahan menurut tanggal <b>diserahkan</b>.
        <b>Penyerahan / santri</b> = jumlah penyerahan dibagi santri yang menerima cucian bulan itu.
        <b>Lama proses</b> = rata-rata dari diterima sampai siap diambil.
        Tanda <b>≈</b> = perkiraan dari kuota sekarang, karena tagihan bulan itu belum disusun.
    </p>

    {{-- ---- Rincian bulan pilihan ---- --}}
    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <p class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900">Per jenjang · {{ $labelIni }}</p>
            <table class="w-full text-sm tabular-nums">
                <thead class="text-xs text-gray-500"><tr><th class="px-4 py-2 text-left">Jenjang</th><th class="px-4 py-2 text-right">Santri</th><th class="px-4 py-2 text-right">Kantong</th><th class="px-4 py-2 text-right">kg</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($perJenjang as $j)
                        <tr><td class="px-4 py-2">{{ $j->nama }}</td><td class="px-4 py-2 text-right">{{ $angka($j->santri) }}</td><td class="px-4 py-2 text-right">{{ $angka($j->kantong) }}</td><td class="px-4 py-2 text-right">{{ $kg($j->kg) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Belum ada cucian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <p class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900">10 pemakai terbanyak · {{ $labelIni }}</p>
            <table class="w-full text-sm tabular-nums">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($teratas as $i => $s)
                        <tr>
                            <td class="w-6 px-4 py-2 text-gray-400">{{ $i + 1 }}</td>
                            <td class="py-2"><span class="font-medium text-gray-900">{{ $s->nama }}</span><span class="block text-xs text-gray-500">{{ $s->kelas ?? '—' }}{{ $s->nis ? ' · NIS '.$s->nis : '' }}</span></td>
                            <td class="px-4 py-2 text-right">{{ $kg($s->kg) }} kg<span class="block text-xs text-gray-500">{{ $s->kantong }} kantong</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">Belum ada cucian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <p class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900">Jenis pakaian terbanyak · {{ $labelIni }}</p>
            @php $maksPotong = max(1, $pakaian->max('potong')); @endphp
            <div class="space-y-2 p-4 text-sm">
                @forelse ($pakaian as $p)
                    <div>
                        <div class="flex justify-between"><span>{{ $p->nama }}</span><span class="tabular-nums text-gray-600">{{ $angka($p->potong) }} potong</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-gray-100"><div class="h-full rounded-full bg-brand" style="width: {{ round($p->potong / $maksPotong * 100) }}%"></div></div>
                    </div>
                @empty
                    <p class="py-4 text-center text-gray-400">Belum ada cucian.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
