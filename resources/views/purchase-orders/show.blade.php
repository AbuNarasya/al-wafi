@extends('layouts.app')

@section('title', 'PO ' . $po->nomor_po)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('purchase_orders.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="flex items-center gap-2">
                <a href="{{ route('purchase_orders.print', $po->id_po) }}" target="_blank"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">🖨 Cetak PO</a>
                @if (in_array($po->status, ['open', 'sebagian'], true) && \App\Support\Akses::boleh('purchase-orders', 'hapus'))
                    <form method="POST" action="{{ route('purchase_orders.cancel', $po->id_po) }}" onsubmit="return confirm('Batalkan PO {{ $po->nomor_po }}?')">
                        @csrf @method('DELETE')
                        <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Batalkan PO</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor PO</div><div class="font-semibold text-gray-900">{{ $po->nomor_po }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $po->tanggal_po->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Vendor</div><div>{{ $po->vendor?->nama_vendor ?? $po->kode_vendor }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $po->status === 'selesai' ? 'bg-emerald-100 text-emerald-700' : ($po->status === 'batal' ? 'bg-gray-100 text-gray-500' : ($po->status === 'sebagian' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700')) }}">{{ ucfirst($po->status) }}</span></div>
            @if ($po->keterangan)<div class="sm:col-span-2"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $po->keterangan }}</div></div>@endif
            <div><div class="text-xs text-gray-400">Total PO</div><div class="font-semibold tabular-nums">@rp($po->total_po)</div></div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Akun / Item</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-right">Qty Invoiced</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Subtotal</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($po->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->kode_coa }} — {{ $d->nama_coa }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $d->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $d->kuantiti, 4, ',', '.'), '0'), ',') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ rtrim(rtrim(number_format((float) $d->qty_invoiced, 4, ',', '.'), '0'), ',') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->harga_satuan)</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->total)</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2.5" colspan="5">Total</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($po->total_po)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
