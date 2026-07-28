@extends('layouts.app')

@section('title', 'Realisasi Anggaran')

@php
    $NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $urut = $data['bulan_urut'] ?? array_map(fn ($m) => ['tahun' => $tahun, 'bulan' => $m], range(1, 12));
    $lintasTahun = count(array_unique(array_column($urut, 'tahun'))) > 1;
    $bulanLabels = array_map(
        fn ($u) => $lintasTahun ? $NAMA_BULAN[$u['bulan'] - 1] . " '" . substr((string) $u['tahun'], 2) : $NAMA_BULAN[$u['bulan'] - 1],
        $urut,
    );
    $rows = $data['rows'] ?? [];
    // Kelompokkan per kelompok_label untuk subtotal.
    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['kelompok_label'] ?: 'Lainnya'][] = $r;
    }

    // Helper varians "menguntungkan": Pendapatan(4) real≥ang baik; Beban(5) real≤ang baik.
    $variansTone = function (string $kelompok, float $varians): string {
        if ($varians == 0.0) return 'text-gray-500';
        $favorable = $kelompok === '4' ? $varians > 0 : ($kelompok === '5' ? $varians < 0 : null);
        if ($favorable === null) return 'text-gray-600';
        return $favorable ? 'text-emerald-600' : 'text-red-600';
    };
    $pct = function ($real, $ang): string {
        $real = (float) $real; $ang = (float) $ang;
        if ($ang == 0.0) return $real == 0.0 ? '—' : '∞';
        return round($real / $ang * 100) . '%';
    };
@endphp

@section('content')
    <div class="mb-3">
        <h2 class="text-xl font-semibold text-gray-900">Realisasi Anggaran</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Perbandingan anggaran vs realisasi aktual (dari mutasi jurnal) beserta varians. Klik akun untuk rincian bulanan.
            @if ($data && ($data['boleh_semua'] ?? false))
                <b>Semua Bagian</b> menjumlahkan seluruh mutasi — termasuk jurnal lama yang belum berdimensi bagian ("Tanpa Bagian").
            @elseif ($data)
                Anda melihat realisasi <b>bagian dalam wewenang Anda</b>.
            @endif
        </p>
    </div>

    @if ($error)
        <div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">🔒 {{ $error }}</div>
    @endif

    <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Anggaran</label>
            <input type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100"
                   class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
            @if ($data && ($data['bulan_awal'] ?? 1) > 1)<div class="mt-0.5 text-[10px] text-gray-500">TA {{ $data['label_ta'] }}</div>@endif
        </div>
        @php
            $unitOpts = ['' => 'Semua Unit'] + collect($units)->mapWithKeys(fn ($u) => [$u->kode_unit => $u->nama_unit])->all();
            $bagianOpts = ['' => 'Semua Bagian'] + collect($data['bagian_opsi'] ?? [])->mapWithKeys(fn ($b) => [$b['kode_bagian'] => $b['nama_bagian']])->all();
        @endphp
        <div class="w-48">
            <label class="mb-1 block text-xs font-medium text-gray-600">Unit</label>
            <x-search-select name="kode_unit" :options="$unitOpts" :value="$kodeUnit" placeholder="Semua Unit" />
        </div>
        <div class="w-56">
            <label class="mb-1 block text-xs font-medium text-gray-600">Bagian</label>
            <x-search-select name="kode_bagian" :options="$bagianOpts" :value="$kodeBagian" placeholder="Semua Bagian" />
        </div>
        <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Tampilkan</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm" x-data="{ open: {} }">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2">Akun</th>
                    <th class="px-4 py-2 text-right">Anggaran</th>
                    <th class="px-4 py-2 text-right">Realisasi</th>
                    <th class="px-4 py-2 text-right">Varians</th>
                    <th class="px-4 py-2 text-right">Capaian</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @if (count($rows) === 0)
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Belum ada akun beranggaran untuk tahun/unit ini.</td></tr>
                @endif
                @foreach ($groups as $label => $grp)
                    @php
                        $subAng = array_sum(array_map(fn ($r) => (float) $r['total_anggaran'], $grp));
                        $subReal = array_sum(array_map(fn ($r) => (float) $r['total_realisasi'], $grp));
                    @endphp
                    <tr class="bg-gray-100/60"><td colspan="5" class="px-4 py-1.5 text-xs font-bold uppercase text-gray-600">{{ $label }}</td></tr>
                    @foreach ($grp as $r)
                        @php $key = $r['kode_coa']; @endphp
                        <tr class="cursor-pointer hover:bg-gray-50" @click="open['{{ $key }}'] = !open['{{ $key }}']">
                            <td class="px-4 py-2">
                                <span class="mr-1 inline-block w-3 text-gray-400" x-text="open['{{ $key }}'] ? '▾' : '▸'">▸</span>
                                {{ $r['nama_coa'] }} <span class="font-mono text-[10px] text-gray-400">{{ $r['kode_coa'] }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">@rp($r['total_anggaran'])</td>
                            <td class="px-4 py-2 text-right font-mono">@rp($r['total_realisasi'])</td>
                            <td class="px-4 py-2 text-right font-mono {{ $variansTone($r['kelompok'], (float) $r['total_varians']) }}">@rp($r['total_varians'])</td>
                            <td class="px-4 py-2 text-right">{{ $pct($r['total_realisasi'], $r['total_anggaran']) }}</td>
                        </tr>
                        <tr x-show="open['{{ $key }}']" x-cloak class="bg-gray-50/50">
                            <td colspan="5" class="px-4 py-2">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-[11px]">
                                        <thead class="text-left uppercase text-gray-400">
                                            <tr><th class="px-2 py-1">Ukuran</th>@foreach ($bulanLabels as $bl)<th class="px-2 py-1 text-right">{{ $bl }}</th>@endforeach</tr>
                                        </thead>
                                        <tbody>
                                            <tr><td class="px-2 py-1 text-gray-500">Anggaran</td>@foreach ($r['bulanan'] as $m)<td class="px-2 py-1 text-right font-mono">@rp($m['anggaran'])</td>@endforeach</tr>
                                            <tr><td class="px-2 py-1 text-gray-500">Realisasi</td>@foreach ($r['bulanan'] as $m)<td class="px-2 py-1 text-right font-mono">@rp($m['realisasi'])</td>@endforeach</tr>
                                            <tr><td class="px-2 py-1 text-gray-500">Varians</td>@foreach ($r['bulanan'] as $m)<td class="px-2 py-1 text-right font-mono {{ $variansTone($r['kelompok'], (float) $m['varians']) }}">@rp($m['varians'])</td>@endforeach</tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-200 font-semibold">
                        <td class="px-4 py-1.5 text-right text-xs text-gray-500">Subtotal {{ $label }}</td>
                        <td class="px-4 py-1.5 text-right font-mono">@rp($subAng)</td>
                        <td class="px-4 py-1.5 text-right font-mono">@rp($subReal)</td>
                        <td class="px-4 py-1.5 text-right font-mono">@rp($subReal - $subAng)</td>
                        <td class="px-4 py-1.5 text-right">{{ $pct($subReal, $subAng) }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if ($data && count($rows) > 0)
                <tfoot>
                    <tr class="border-t-2 border-gray-300 bg-gray-50 font-bold">
                        <td class="px-4 py-2">TOTAL</td>
                        <td class="px-4 py-2 text-right font-mono">@rp($data['total']['anggaran'])</td>
                        <td class="px-4 py-2 text-right font-mono">@rp($data['total']['realisasi'])</td>
                        <td class="px-4 py-2 text-right font-mono">@rp($data['total']['varians'])</td>
                        <td class="px-4 py-2 text-right">{{ $pct($data['total']['realisasi'], $data['total']['anggaran']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endsection
