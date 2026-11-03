@extends('layouts.app')

@section('title', 'Monitor Kelebihan Laundry')

@php
    $kg = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $bln = fn ($p) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $p.'-01')->locale('id')->translatedFormat('M Y');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    $saringBersama = array_filter(['bagian' => $f['bagian'], 'jenjang' => $f['jenjang'], 'kelas' => $f['kelas'], 'q' => $f['q'] ?: null]);
@endphp

@section('content')
    <div class="mb-3">
        <h2 class="text-xl font-semibold text-gray-900">Monitor Kelebihan Laundry</h2>
        <p class="text-sm text-gray-500">Pemakaian dibanding kuota (matriks jenjang × ikhwan/akhwat), tagihan kelebihan yang sudah terbit, dan tunggakannya.</p>
    </div>

    <div class="mb-3 flex flex-wrap gap-2 text-sm">
        @foreach (\App\Http\Controllers\LaundryMonitorController::TAMPIL as $k => $label)
            <a href="{{ route('laundry.monitor', ['tampil' => $k] + $saringBersama) }}" @class(['rounded-full px-4 py-1.5 font-semibold',
                'bg-brand text-white' => $tampil === $k, 'bg-white text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50' => $tampil !== $k])>{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <input type="hidden" name="tampil" value="{{ $tampil }}">
        @if ($tampil === 'pemakaian')
            <label class="text-xs text-gray-500">Bulan<input type="month" name="periode" value="{{ $f['periode'] }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
            <label class="text-xs text-gray-500">Tampilkan<select name="saring" class="mt-1 block rounded-lg border-gray-300 text-sm">
                <option value="">semua yang menyetor</option>
                <option value="over" @selected($f['saring'] === 'over')>melebihi kuota</option>
                <option value="dekat" @selected($f['saring'] === 'dekat')>≥ 80% kuota</option></select></label>
        @elseif ($tampil === 'tagihan')
            <label class="text-xs text-gray-500">Dari<input type="month" name="dari" value="{{ $f['dari'] }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
            <label class="text-xs text-gray-500">Sampai<input type="month" name="sampai" value="{{ $f['sampai'] }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
            <label class="text-xs text-gray-500">Status<select name="status" class="mt-1 block rounded-lg border-gray-300 text-sm">
                <option value="">semua</option>
                <option value="lunas" @selected($f['status'] === 'lunas')>sudah lunas</option>
                <option value="belum" @selected($f['status'] === 'belum')>belum lunas</option></select></label>
        @endif
        <label class="text-xs text-gray-500">Bagian<select name="bagian" class="mt-1 block rounded-lg border-gray-300 text-sm">
            <option value="">ikhwan &amp; akhwat</option>
            @foreach (\App\Support\BagianLaundry::BAGIAN as $k => $b)<option value="{{ $k }}" @selected($f['bagian'] === $k)>{{ $b['label'] }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">Jenjang<select name="jenjang" class="mt-1 block rounded-lg border-gray-300 text-sm">
            <option value="">semua</option>
            @foreach ($opsiJenjang as $k => $n)<option value="{{ $k }}" @selected($f['jenjang'] === $k)>{{ $n }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">Kelas<select name="kelas" class="mt-1 block rounded-lg border-gray-300 text-sm">
            <option value="">semua</option>
            @foreach ($opsiKelas as $k => $n)<option value="{{ $k }}" @selected((string) $f['kelas'] === (string) $k)>{{ $n }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">Cari<input name="q" value="{{ $f['q'] }}" placeholder="nama / NIS" class="mt-1 block w-48 rounded-lg border-gray-300 text-sm"></label>
        <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm font-semibold text-white">Saring</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        @if ($tampil === 'pemakaian')
            <p class="border-b border-gray-100 px-4 py-2 text-sm text-gray-600">{{ $bln($f['periode']) }} · {{ $rows->count() }} santri ·
                melebihi kuota <b>{{ $rows->where('lebih', '>', 0)->count() }}</b> · perkiraan tagihan <b>{{ $rp($rows->sum('perkiraan')) }}</b></p>
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-4 py-2">Santri</th><th class="px-4 py-2 text-right">Kantong</th><th class="px-4 py-2 text-right">Terpakai</th>
                    <th class="px-4 py-2 text-right">Kuota</th><th class="px-4 py-2">Pemakaian</th><th class="px-4 py-2 text-right">Lebih</th><th class="px-4 py-2 text-right">Perkiraan</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-4 py-2">{{ $r->nama }} <span class="text-xs text-gray-400">{{ $r->kelas ?? $r->nis }} · {{ $r->jk === 'P' ? 'akhwat' : 'ikhwan' }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $r->kantong }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $kg($r->kg) }} kg</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-gray-500">{{ $kg($r->kuota) }} kg</td>
                            <td class="px-4 py-2"><div class="h-2 w-32 overflow-hidden rounded-full bg-gray-100">
                                <div @class(['h-full rounded-full', 'bg-red-500' => $r->lebih > 0, 'bg-amber-500' => $r->lebih <= 0 && $r->persen >= 80, 'bg-cyan-600' => $r->persen < 80])
                                     style="width: {{ min(100, round($r->persen)) }}%"></div></div>
                                <span class="text-[11px] text-gray-500">{{ round($r->persen) }}%</span></td>
                            <td @class(['whitespace-nowrap px-4 py-2 text-right tabular-nums', 'font-semibold text-red-700' => $r->lebih > 0])>{{ $r->lebih > 0 ? $kg($r->lebih).' kg' : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $r->perkiraan !== null ? $rp($r->perkiraan) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @elseif ($tampil === 'tagihan')
            <p class="border-b border-gray-100 px-4 py-2 text-sm text-gray-600">{{ $rows->count() }} tagihan · total <b>{{ $rp($rows->sum('nominal')) }}</b> ·
                lunas <b class="text-emerald-700">{{ $rp($rows->where('status_bayar', 'lunas')->sum('nominal')) }}</b> ·
                sisa belum dibayar <b class="text-amber-700">{{ $rp($rows->where('status_bayar', '!=', 'lunas')->sum('sisa')) }}</b></p>
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-4 py-2">Periode</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2 text-right">Pemakaian / kuota</th>
                    <th class="px-4 py-2 text-right">Lebih</th><th class="px-4 py-2 text-right">Tagihan</th><th class="px-4 py-2 text-right">Sisa</th>
                    <th class="px-4 py-2">Jatuh tempo</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        @php $lewat = $r->status_bayar !== 'lunas' && $r->jatuh_tempo && \Illuminate\Support\Carbon::parse($r->jatuh_tempo)->lt(today()); @endphp
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $bln($r->periode) }}</td>
                            <td class="px-4 py-2">{{ $r->nama }} <span class="text-xs text-gray-400">{{ $r->kelas ?? $r->nis }}</span></td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-gray-600">{{ $kg($r->kg_total) }} / {{ $kg($r->kuota_kg) }} kg</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $kg($r->kg_lebih) }} kg</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $rp($r->nominal) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $r->status_bayar === 'lunas' ? '—' : $rp($r->sisa) }}</td>
                            <td @class(['whitespace-nowrap px-4 py-2', 'font-semibold text-red-700' => $lewat])>{{ $tgl($r->jatuh_tempo) }}</td>
                            <td class="px-4 py-2"><span @class(['rounded px-1.5 py-0.5 text-[11px]',
                                'bg-emerald-100 text-emerald-800' => $r->status_bayar === 'lunas', 'bg-red-100 text-red-800' => $lewat,
                                'bg-amber-100 text-amber-800' => $r->status_bayar !== 'lunas' && ! $lewat])>{{ $r->status_bayar === 'lunas' ? 'lunas' : ($lewat ? 'lewat jatuh tempo' : ($r->status_bayar === 'sebagian' ? 'dibayar sebagian' : 'belum dibayar')) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Tidak ada tagihan kelebihan untuk saringan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <p class="border-b border-gray-100 px-4 py-2 text-sm text-gray-600">Tagihan kelebihan yang <b>sudah lewat jatuh tempo</b> dan belum lunas ·
                {{ $rows->count() }} santri · total <b class="text-red-700">{{ $rp($rows->sum('sisa')) }}</b></p>
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-4 py-2">Santri</th><th class="px-4 py-2 text-right">Periode menunggak</th><th class="px-4 py-2">Bulan</th>
                    <th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2">Jatuh tempo terlama</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-4 py-2">{{ $r->nama }} <span class="text-xs text-gray-400">{{ $r->kelas ?? $r->nis }} · {{ $r->jk === 'P' ? 'akhwat' : 'ikhwan' }}</span></td>
                            <td class="px-4 py-2 text-right font-semibold tabular-nums text-red-700">{{ $r->jumlah }}</td>
                            <td class="px-4 py-2 text-xs text-gray-600">{{ collect($r->periode)->map($bln)->implode(', ') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $rp($r->sisa) }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $tgl($r->terlama) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Tidak ada tunggakan kelebihan laundry.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
@endsection
