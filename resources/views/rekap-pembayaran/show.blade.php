@extends('layouts.app')

@section('title', 'Rekap Pembayaran — ' . $santri->nama)

@php
    $labelTipe = ['registrasi' => 'bg-blue-100 text-blue-700', 'uang_pangkal' => 'bg-purple-100 text-purple-700', 'perlengkapan' => 'bg-amber-100 text-amber-700', 'spp' => 'bg-emerald-100 text-emerald-700', 'lain' => 'bg-gray-100 text-gray-600'];
    $labelStatusTagihan = ['belum_bayar' => 'bg-amber-100 text-amber-700', 'sebagian' => 'bg-blue-100 text-blue-700', 'lunas' => 'bg-emerald-100 text-emerald-700', 'batal' => 'bg-gray-100 text-gray-500', 'dihapus' => 'bg-gray-100 text-gray-500'];
    $labelStatusBayar = ['menunggu_verifikasi' => 'bg-amber-100 text-amber-700', 'terverifikasi' => 'bg-emerald-100 text-emerald-700', 'ditolak' => 'bg-red-100 text-red-700', 'void' => 'bg-gray-100 text-gray-500'];
@endphp

@section('content')
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('rekap_pembayaran.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Pilih santri lain</a>
        <div class="flex items-center gap-2">
            <a href="{{ route('santri.show', $santri->id) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Detail Santri</a>
            <a href="{{ route('rekap_pembayaran.cetak', $santri->id) }}" target="_blank"
               class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak Rekap</a>
        </div>
    </div>

    {{-- Identitas --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="text-lg font-semibold text-gray-900">{{ $santri->nama }}</div>
        <div class="mt-1 grid gap-2 text-sm text-gray-600 sm:grid-cols-4">
            <div><span class="text-xs text-gray-400">No. Daftar</span><div>{{ $santri->no_pendaftaran }}</div></div>
            <div><span class="text-xs text-gray-400">NIS</span><div>{{ $santri->nis ?? '—' }}</div></div>
            <div><span class="text-xs text-gray-400">Jenjang / T.A</span><div>{{ $santri->jenjang?->nama ?? $santri->kode_jenjang ?? '—' }} · {{ $santri->tahun_ajaran ?? '—' }}</div></div>
            <div><span class="text-xs text-gray-400">Wali</span><div>{{ $santri->wali?->nama ?? '—' }}</div></div>
        </div>
    </div>

    {{-- Ringkasan --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-gray-400">Total Tagihan</div>
            <div class="mt-1 text-lg font-bold tabular-nums text-gray-900">@rp($ringkasan['tagihan'])</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-gray-400">Sudah Dibayar</div>
            <div class="mt-1 text-lg font-bold tabular-nums text-emerald-700">@rp($ringkasan['terbayar'])</div>
            <div class="text-[11px] text-gray-400">{{ $ringkasan['jumlah_pembayaran'] }} pembayaran terverifikasi</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-gray-400">Sisa</div>
            <div class="mt-1 text-lg font-bold tabular-nums {{ (float) $ringkasan['sisa'] > 0 ? 'text-amber-700' : 'text-gray-900' }}">@rp($ringkasan['sisa'])</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-gray-400">Menunggu Verifikasi</div>
            <div class="mt-1 text-lg font-bold tabular-nums {{ (float) $ringkasan['menunggu'] > 0 ? 'text-amber-600' : 'text-gray-300' }}">@rp($ringkasan['menunggu'])</div>
            <div class="text-[11px] text-gray-400">belum diakui sebagai uang masuk</div>
        </div>
    </div>

    {{-- Tagihan --}}
    <div class="mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Tagihan</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Terbayar</th><th class="px-4 py-3 text-right">Menunggu</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($tagihan as $t)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $labelTipe[$t['tipe']] ?? '' }}">{{ str_replace('_', ' ', ucfirst($t['tipe'])) }}</span>
                            <div class="text-gray-700">{{ $t['jenis'] }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $t['periode'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($t['nominal'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-700">@rp($t['terbayar'])</td>
                        {{-- Sudah disetor, belum diakui keuangan — sengaja kolom sendiri
                             dan TIDAK dijumlahkan ke "Terbayar" maupun mengurangi "Sisa". --}}
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $t['menunggu'] > 0 ? 'text-amber-600' : 'text-gray-300' }}">@rp($t['menunggu'])</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($t['sisa'])</td>
                        <td class="px-4 py-3 text-gray-500">{{ $t['jatuh_tempo'] ? \Illuminate\Support\Carbon::parse($t['jatuh_tempo'])->format('d/m/Y') : '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatusTagihan[$t['status']] ?? '' }}">{{ str_replace('_', ' ', ucfirst($t['status'])) }}</span>
                            @if ((float) $t['menunggu'] > 0)
                                <div class="mt-0.5 text-[11px] text-amber-600">menunggu verifikasi</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Belum ada tagihan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Riwayat pembayaran --}}
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Riwayat Pembayaran</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Untuk</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Metode</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Kuitansi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($pembayaran as $p)
                    @php $rt = in_array($p->tagihan?->jenis?->tipe, ['registrasi', 'uang_pangkal'], true) ? 'pembayaran_ppsb' : 'pembayaran_kesantrian'; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600">{{ $p->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $p->nomor }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $p->tagihan?->jenis?->nama ?? '—' }}{{ $p->tagihan?->periode ? " ({$p->tagihan->periode})" : '' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($p->nominal)</td>
                        <td class="px-4 py-3 text-gray-500">{{ $p->metode ?: ($p->sumber === 'dompet_wali' ? 'Dompet Wali' : '—') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatusBayar[$p->status] ?? '' }}">{{ ucfirst(str_replace('_', ' ', $p->status)) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if ($p->status === 'terverifikasi')
                                <a href="{{ route($rt . '.kuitansi', $p->id) }}" target="_blank" class="text-brand hover:underline">🖨 Cetak</a>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
