@extends('layouts.app')

@section('title', 'Penerimaan ' . $doc->nomor)

@section('content')
    @php $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ','); @endphp
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('penerimaan_barang.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($doc->status === 'aktif' && ! $doc->sudahDitagih() && \App\Support\Akses::boleh('penerimaan-barang', 'hapus'))
                <form method="POST" action="{{ route('penerimaan_barang.batal', $doc->id_penerimaan) }}" x-data="{ buka: false }" class="relative">
                    @csrf @method('DELETE')
                    <button type="button" @click="buka = !buka" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Batalkan Penerimaan</button>
                    <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        <p class="text-xs text-gray-600">Stok ditarik kembali dari lokasi &amp; jurnalnya dibalik. Ditolak bila barangnya sudah terpakai/terjual.</p>
                        <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Batalkan</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor</div><div class="font-mono font-semibold text-gray-900">{{ $doc->nomor }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $doc->tanggal->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Vendor</div><div>{{ $doc->vendor?->nama_vendor }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $doc->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($doc->status) }}</span>
                @if ($doc->alasan_batal)<div class="text-xs text-gray-500">{{ $doc->alasan_batal }}</div>@endif
            </div>
            <div><div class="text-xs text-gray-400">Lokasi</div><div>{{ $doc->lokasi?->nama }}</div></div>
            <div><div class="text-xs text-gray-400">Unit Bisnis</div><div>{{ $doc->unit?->nama_unit }}</div></div>
            <div><div class="text-xs text-gray-400">Purchase Order</div>
                @if ($doc->po)<a href="{{ route('purchase_orders.show', $doc->id_po) }}" class="font-mono text-brand hover:underline">{{ $doc->po->nomor_po }}</a>@else<span class="text-gray-400">—</span>@endif
            </div>
            <div><div class="text-xs text-gray-400">Surat Jalan</div><div>{{ $doc->nomor_surat_jalan ?: '—' }}</div></div>
            @if ($doc->keterangan)<div class="sm:col-span-3"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $doc->keterangan }}</div></div>@endif
            <div><div class="text-xs text-gray-400">Dicatat oleh</div><div>{{ $doc->pengguna?->nama ?? '—' }}</div></div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Barang</th><th class="px-4 py-3 text-right">Diterima</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right">Sudah Ditagih</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($doc->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->persediaan?->nama_persediaan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $angka($d->kuantiti_input) }} {{ $d->satuan }}
                                @if ((float) $d->isi > 1)<div class="text-xs text-gray-500">= {{ $angka($d->kuantiti) }} {{ $d->persediaan?->satuan }}</div>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->harga_input)</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->total)</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ $angka($d->qty_ditagih) }} / {{ $angka($d->kuantiti) }} {{ $d->persediaan?->satuan }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2.5" colspan="3">Total</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($doc->total)</td><td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($invoice->isNotEmpty())
            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <div class="mb-2 font-semibold text-gray-800">Ditagih lewat invoice</div>
                @foreach ($invoice as $inv)
                    <div><a href="{{ route('invoices.show', $inv->id_invoice) }}" class="font-mono text-brand hover:underline">{{ $inv->nomor_ref_internal }}</a>
                        <span class="text-gray-500">— {{ $inv->nomor_invoice }} · {{ $inv->status }}</span></div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
