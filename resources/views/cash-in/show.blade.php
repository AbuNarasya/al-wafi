@extends('layouts.app')

@section('title', 'Kas Masuk ' . $rec->nomor_transaksi)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('cash_in.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($rec->status === 'aktif' && \App\Support\Akses::boleh('cash-in', 'hapus'))
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Void</button>
                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('cash_in.void', $rec->kode_transaksi) }}"
                          onsubmit="return confirm('Void {{ $rec->nomor_transaksi }}? Jurnal akan dibalik.')"
                          class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        @csrf @method('DELETE')
                        <label class="block text-xs font-medium text-gray-600">Alasan void</label>
                        <input type="text" name="alasan" required maxlength="255" placeholder="mis. salah input"
                               class="w-full rounded border-gray-300 text-sm">
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Konfirmasi Void</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor</div><div class="font-semibold text-gray-900">{{ $rec->nomor_transaksi }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $rec->tanggal->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Rekening</div><div>{{ $rec->rekening?->nama_rekening ?? $rec->kode_rekening }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $rec->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($rec->status) }}</span></div>
            <div><div class="text-xs text-gray-400">Customer</div><div>{{ $rec->customer?->nama_customer ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Unit</div><div>{{ $rec->unit?->nama_unit ?? $rec->kode_unit }}</div></div>
            <div class="sm:col-span-2"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $rec->keterangan }}</div></div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rec->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->kode_coa }} — {{ $d->nama_coa }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $d->jenis_kas_masuk === 'uang_muka' ? 'Uang Muka' : 'Pendapatan' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $d->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->nominal)</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2.5" colspan="3">Total (Debit {{ $rec->rekening?->nama_rekening ?? $rec->kode_rekening }})</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($rec->nominal)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
