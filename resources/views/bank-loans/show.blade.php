@extends('layouts.app')

@php
    $fmtTgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';
@endphp

@section('title', 'Pembiayaan ' . $loan['nama_bank'])

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('bank_loans.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($loan['status'] === 'aktif' && \App\Support\Akses::boleh('bank-loans', 'hapus'))
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Void</button>
                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('bank_loans.void', $loan['id']) }}"
                          onsubmit="return confirm('Void pembiayaan ini? Hanya bila belum ada angsuran.')"
                          class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        @csrf @method('DELETE')
                        <label class="block text-xs font-medium text-gray-600">Alasan void</label>
                        <input type="text" name="alasan" required maxlength="255" placeholder="mis. salah input" class="w-full rounded border-gray-300 text-sm">
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Konfirmasi Void</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Bank / Lembaga</div><div class="font-semibold text-gray-900">{{ $loan['nama_bank'] }}</div></div>
            <div><div class="text-xs text-gray-400">Nomor Kontrak</div><div>{{ $loan['nomor_kontrak'] ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Akad</div><div>{{ str_replace('_', ' ', ucfirst($loan['jenis_akad'])) }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $loan['status'] === 'aktif' ? 'bg-emerald-100 text-emerald-700' : ($loan['status'] === 'lunas' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500') }}">{{ ucfirst($loan['status']) }}</span></div>
            <div><div class="text-xs text-gray-400">Pokok Awal</div><div class="tabular-nums">@rp($loan['pokok_awal'])</div></div>
            <div><div class="text-xs text-gray-400">Terbayar</div><div class="tabular-nums">@rp($loan['pokok_terbayar'])</div></div>
            <div><div class="text-xs text-gray-400">Sisa Pokok</div><div class="font-semibold tabular-nums">@rp($loan['sisa_pokok'])</div></div>
            <div><div class="text-xs text-gray-400">Tenor</div><div>{{ $loan['tenor_bulan'] ? $loan['tenor_bulan'] . ' bulan' : '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal Mulai</div><div>{{ $fmtTgl($loan['tanggal_mulai']) }}</div></div>
            <div><div class="text-xs text-gray-400">Jatuh Tempo</div><div>{{ $fmtTgl($loan['tanggal_jatuh_tempo']) }}</div></div>
            @if ($loan['keterangan'])<div class="sm:col-span-2"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $loan['keterangan'] }}</div></div>@endif
        </div>

        <h3 class="mb-2 text-sm font-semibold text-gray-700">Riwayat Angsuran</h3>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Voucher</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3 text-right">Pokok</th><th class="px-4 py-3 text-right">Margin</th><th class="px-4 py-3 text-right">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($loan['angsuran'] as $a)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-medium text-gray-900">{{ $a['nomor_transaksi'] }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $fmtTgl($a['tanggal']) }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $a['rekening'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($a['pokok'])</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($a['margin'])</td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium">@rp($a['total'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada angsuran. Bayar lewat menu Kas Keluar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
