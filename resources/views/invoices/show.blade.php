@extends('layouts.app')

@section('title', 'Invoice ' . $inv->nomor_invoice)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($inv->status !== 'void' && \App\Support\Akses::boleh('invoices', 'hapus'))
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Void</button>
                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('invoices.void', $inv->id_invoice) }}"
                          onsubmit="return confirm('Void invoice {{ $inv->nomor_invoice }}? Hanya bila belum ada pembayaran.')"
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
            <div><div class="text-xs text-gray-400">Nomor Invoice</div><div class="font-semibold text-gray-900">{{ $inv->nomor_invoice }}</div></div>
            <div><div class="text-xs text-gray-400">Ref Internal</div><div>{{ $inv->nomor_ref_internal }}</div></div>
            <div><div class="text-xs text-gray-400">Vendor</div><div>{{ $inv->vendor?->nama_vendor ?? $inv->kode_vendor }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $inv->status === 'lunas' ? 'bg-emerald-100 text-emerald-700' : ($inv->status === 'void' ? 'bg-gray-100 text-gray-500' : ($inv->status === 'sebagian' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700')) }}">{{ ucfirst(str_replace('_', ' ', $inv->status)) }}</span></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $inv->tanggal_invoice->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Jatuh Tempo</div><div>{{ $inv->tanggal_jatuh_tempo?->format('d M Y') ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Total</div><div class="tabular-nums">@rp($inv->total)</div></div>
            <div><div class="text-xs text-gray-400">Sisa Hutang</div><div class="font-semibold tabular-nums">@rp($inv->sisa_hutang)</div></div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Subtotal</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($inv->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->kode_coa }} — {{ $d->nama_coa }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $d->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $d->kuantiti, 4, ',', '.'), '0'), ',') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->harga_satuan)</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->total)</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2.5" colspan="4">Total (Kredit Hutang)</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($inv->total)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
