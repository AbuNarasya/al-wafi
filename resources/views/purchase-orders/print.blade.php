<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak PO {{ $po->nomor_po }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 14mm; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body class="bg-white text-gray-900">
    <div class="mx-auto max-w-3xl p-8">
        {{-- Toolbar (tidak ikut cetak) --}}
        <div class="no-print mb-6 flex items-center justify-between">
            <a href="{{ route('purchase_orders.show', $po->id_po) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak</button>
        </div>

        {{-- Kop --}}
        <div class="flex items-start justify-between border-b-2 border-gray-800 pb-4">
            <div>
                <div class="text-xl font-bold text-gray-900">{{ $company->nama_perusahaan ?? 'Perusahaan' }}</div>
                @if ($company?->alamat)<div class="mt-0.5 text-xs text-gray-600">{{ $company->alamat }}</div>@endif
                <div class="text-xs text-gray-600">
                    @if ($company?->telepon)Telp: {{ $company->telepon }}@endif
                    @if ($company?->email) · {{ $company->email }}@endif
                    @if ($company?->npwp) · NPWP: {{ $company->npwp }}@endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-lg font-bold uppercase tracking-wide text-gray-800">Purchase Order</div>
                <div class="mt-1 font-mono text-sm text-gray-700">{{ $po->nomor_po }}</div>
            </div>
        </div>

        {{-- Info --}}
        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <div class="text-xs uppercase text-gray-400">Kepada (Vendor)</div>
                <div class="font-semibold">{{ $po->vendor?->nama_vendor ?? $po->kode_vendor }}</div>
                @if ($po->vendor?->telepon)<div class="text-xs text-gray-600">{{ $po->vendor->telepon }}</div>@endif
                @if ($po->vendor?->alamat)<div class="text-xs text-gray-600">{{ $po->vendor->alamat }}</div>@endif
            </div>
            <div class="text-right">
                <table class="ml-auto text-sm">
                    <tr><td class="pr-3 text-gray-500">Tanggal</td><td class="font-medium">{{ $po->tanggal_po->format('d M Y') }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Unit</td><td class="font-medium">{{ $po->unit?->nama_unit ?? $po->kode_unit }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Status</td><td class="font-medium">{{ ucfirst($po->status) }}</td></tr>
                </table>
            </div>
        </div>

        @if ($po->keterangan)
            <div class="mt-4 text-sm"><span class="text-gray-500">Keterangan:</span> {{ $po->keterangan }}</div>
        @endif

        {{-- Item --}}
        <table class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-y border-gray-300 bg-gray-100 text-left text-xs uppercase text-gray-600">
                    <th class="px-3 py-2 w-8">No</th>
                    <th class="px-3 py-2">Item / Keterangan</th>
                    <th class="px-3 py-2 text-right">Qty</th>
                    <th class="px-3 py-2 text-right">Harga</th>
                    <th class="px-3 py-2 text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($po->details as $idx => $d)
                    <tr class="border-b border-gray-200 align-top">
                        <td class="px-3 py-2 text-gray-500">{{ $idx + 1 }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $d->nama_coa }}</div>
                            @if ($d->keterangan)<div class="text-xs text-gray-500">{{ $d->keterangan }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $d->kuantiti, 4, ',', '.'), '0'), ',') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($d->harga_satuan)</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($d->total)</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-800 font-bold">
                    <td class="px-3 py-2.5 text-right" colspan="4">TOTAL</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">@rp($po->total_po)</td>
                </tr>
            </tfoot>
        </table>

        {{-- Tanda tangan --}}
        <div class="mt-12 grid grid-cols-2 gap-8 text-center text-sm">
            <div>
                <div class="text-gray-500">Dibuat oleh,</div>
                <div class="mt-16 border-t border-gray-400 pt-1 text-gray-700">( __________________ )</div>
            </div>
            <div>
                <div class="text-gray-500">Disetujui oleh,</div>
                <div class="mt-16 border-t border-gray-400 pt-1 text-gray-700">( __________________ )</div>
            </div>
        </div>

        <div class="mt-8 text-center text-[10px] text-gray-400">Dicetak {{ now()->format('d M Y H:i') }} · {{ $po->nomor_po }}</div>
    </div>

    <script>
        // Auto-buka dialog cetak saat halaman siap.
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
