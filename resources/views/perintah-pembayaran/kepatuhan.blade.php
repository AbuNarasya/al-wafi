@extends('layouts.app')

@section('title', 'Kepatuhan Perintah Pembayaran')

@php
    $metode = \App\Models\PerintahPembayaran::METODE;
    $warna = [
        'diotorisasi' => 'bg-indigo-100 text-indigo-700', 'sebagian' => 'bg-blue-100 text-blue-700',
        'terbayar' => 'bg-emerald-100 text-emerald-700', 'selesai' => 'bg-emerald-100 text-emerald-700',
    ];
@endphp

@section('content')
<div class="space-y-4">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Kepatuhan Perintah Pembayaran</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-600">
            Yang diperintahkan versus yang benar-benar terjadi. Perbedaan rekening, tanggal, dan metode
            <b>boleh</b> terjadi — bank gangguan atau saldo tak cukup itu kenyataan sehari-hari. Yang penting
            selisihnya terlihat.
        </p>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Tanggal bayar dari</label>
            <input type="date" name="dari" value="{{ $filter['dari'] }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Sampai</label>
            <input type="date" name="sampai" value="{{ $filter['sampai'] }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="selisih" value="1" @checked($filter['hanya_selisih'])
                   class="rounded border-gray-300 text-brand focus:ring-brand">
            Hanya yang ada selisih
        </label>
        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Terapkan</button>
        @if ($filter['dari'] || $filter['sampai'] || $filter['hanya_selisih'])
            <a href="{{ route('perintah_pembayaran.kepatuhan') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
        @endif
        <span class="ml-auto text-xs text-gray-500">{{ count($rows) }} perintah</span>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Perintah</th>
                    <th class="px-4 py-3 text-right">Diotorisasi</th>
                    <th class="px-4 py-3 text-right">Terealisasi</th>
                    <th class="px-4 py-3 text-right">Sisa</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Rekening</th>
                    <th class="px-4 py-3">Metode</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php
                        // Baris berwarna = ada yang menyimpang dari perintahnya.
                        $adaSelisih = $r['rekening_beda'] || $r['metode_beda']
                            || ($r['selisih_hari'] !== null && $r['selisih_hari'] !== 0)
                            || $r['terlambat_hari'] !== null;
                        $latar = $r['terlambat_hari'] !== null ? 'bg-red-50'
                            : ($adaSelisih ? 'bg-amber-50' : ((float) $r['sisa'] > 0 ? 'bg-blue-50/60' : ''));
                    @endphp
                    <tr class="{{ $latar }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('perintah_pembayaran.show', $r['kode_transaksi']) }}" class="font-mono font-medium text-brand hover:underline">{{ $r['nomor'] }}</a>
                            <div class="text-xs text-gray-500">{{ $r['keterangan'] }}</div>
                            @if ($r['jumlah_voucher'] > 0)
                                <div class="mt-0.5 text-[11px] text-gray-400">
                                    {{ $r['jumlah_voucher'] }} voucher:
                                    {{ collect($r['voucher'])->pluck('nomor')->implode(', ') }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['diotorisasi'])</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">@rp($r['terealisasi'])</td>
                        <td class="px-4 py-2 text-right tabular-nums {{ (float) $r['sisa'] > 0 ? 'text-blue-700' : 'text-gray-400' }}">@rp($r['sisa'])</td>
                        <td class="px-4 py-2 text-xs">
                            @if ($r['terlambat_hari'] !== null)
                                <span class="font-medium text-red-700">lewat {{ $r['terlambat_hari'] }} hari</span>
                                <div class="text-gray-500">belum dibayar</div>
                            @elseif ($r['selisih_hari'] === null)
                                <span class="text-gray-400">—</span>
                            @elseif ($r['selisih_hari'] === 0)
                                <span class="text-gray-500">tepat</span>
                            @else
                                <span class="font-medium text-amber-800">{{ $r['selisih_hari'] > 0 ? '+' : '' }}{{ $r['selisih_hari'] }} hari</span>
                            @endif
                            @if ($r['tanggal_bayar'])
                                <div class="text-gray-400">rencana {{ $r['tanggal_bayar']->format('d/m/Y') }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs">
                            @if ($r['rekening_beda'])
                                <span class="font-medium text-amber-800">{{ implode(', ', $r['rekening_dipakai']) }}</span>
                                <div class="text-gray-500">rencana {{ $r['rekening_rencana'] }}</div>
                            @elseif (empty($r['rekening_dipakai']))
                                <span class="text-gray-400">—</span>
                            @else
                                <span class="text-gray-500">sesuai</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs">
                            @if ($r['metode_beda'])
                                <span class="font-medium text-amber-800">{{ collect($r['metode_dipakai'])->map(fn ($m) => $metode[$m] ?? $m)->implode(', ') }}</span>
                                <div class="text-gray-500">rencana {{ $metode[$r['metode_rencana']] ?? '—' }}</div>
                            @elseif (empty($r['metode_dipakai']))
                                <span class="text-gray-400">tak dicatat</span>
                            @else
                                <span class="text-gray-500">sesuai</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$r['status']] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ \App\Models\PerintahPembayaran::STATUS[$r['status']] ?? $r['status'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">
                        Tidak ada perintah yang cocok. Perintah yang masih draf atau menunggu otorisasi tidak masuk laporan ini.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-gray-500">
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-red-50 ring-1 ring-red-200"></span> lewat tanggal, belum dibayar</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-amber-50 ring-1 ring-amber-200"></span> ada yang berbeda dari perintahnya</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-blue-50 ring-1 ring-blue-200"></span> masih bersisa</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-white ring-1 ring-gray-200"></span> persis seperti yang diotorisasi</span>
    </div>
</div>
@endsection
