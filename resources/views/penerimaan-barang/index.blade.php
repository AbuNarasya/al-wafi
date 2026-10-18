@extends('layouts.app')

@section('title', 'Penerimaan Barang')

@section('content')
    @unless ($akunSiap)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Akun <b>Barang Diterima Belum Ditagih</b> belum ditetapkan. Pilih dulu di
            <a href="{{ route('company_settings.edit') }}" class="font-medium underline">Pengaturan Perusahaan</a> sebelum mencatat penerimaan.
        </div>
    @endunless

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs text-gray-500">Barang diterima, tagihan vendor belum datang</div>
            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($belumDitagih)</div>
            <div class="text-[11px] text-gray-400">= saldo akun Barang Diterima Belum Ditagih</div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $q }}" placeholder="Cari nomor / surat jalan…" class="rounded-lg border-gray-300 text-sm">
            <select name="tagih" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua</option>
                <option value="belum" @selected($filter['tagih'] === 'belum')>Belum ditagih</option>
            </select>
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua status</option>
                <option value="aktif" @selected($filter['status'] === 'aktif')>Aktif</option>
                <option value="batal" @selected($filter['status'] === 'batal')>Batal</option>
            </select>
        </form>
        @if (\App\Support\Akses::boleh('penerimaan-barang', 'buat'))
            <a href="{{ route('penerimaan_barang.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Terima Barang</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Vendor</th><th class="px-4 py-3">Lokasi</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3">Tagihan</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php
                        $ditagih = $r->details->sum(fn ($d) => (float) $d->qty_ditagih);
                        $semua = $r->details->every(fn ($d) => (float) $d->qty_ditagih >= (float) $d->kuantiti);
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><a href="{{ route('penerimaan_barang.show', $r->id_penerimaan) }}" class="font-mono font-medium text-brand hover:underline">{{ $r->nomor }}</a>
                            @if ($r->nomor_surat_jalan)<div class="text-xs text-gray-500">SJ {{ $r->nomor_surat_jalan }}</div>@endif</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ $r->vendor?->nama_vendor }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->lokasi?->nama }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->total)</td>
                        <td class="px-4 py-3">
                            @if ($r->status === 'batal')
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Batal</span>
                            @elseif ($semua)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Sudah ditagih</span>
                            @elseif ($ditagih > 0)
                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">Sebagian</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Belum ditagih</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada penerimaan barang.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
