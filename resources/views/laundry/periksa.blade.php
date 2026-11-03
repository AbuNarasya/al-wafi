@extends('layouts.app')

@section('title', 'Periksa '.$l->nomor)

@php $s = $l->santri; @endphp

@section('content')
    <a href="{{ route('laundry.pemeriksaan') }}" class="text-sm text-brand hover:underline">&larr; Pemeriksaan Cucian</a>

    {{-- Kuota: pemakaian bulan ini sudah memuat berat penerimaan kantong ini — diganti berat timbang ulang. --}}
    <form method="POST" action="{{ route('laundry.simpan_periksa', $l->id) }}" data-no-confirm
          class="mt-3 max-w-3xl space-y-4 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm"
          x-data="{ berat: @js(old('berat', '')), awal: @js((float) $l->berat),
                    lain: @js((float) $kuota['terpakai'] - (float) $l->berat), kuota: @js((float) $kuota['kuota']),
                    get sesudah() { return this.lain + (parseFloat(this.berat) || this.awal) },
                    get selisih() { return (parseFloat(this.berat) || 0) - this.awal } }">
        @csrf
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-base font-semibold text-gray-900"><span class="font-mono">{{ $l->nomor }}</span> · {{ $s?->nama }}
                <span class="text-sm font-normal text-gray-500">{{ $s?->nis }}{{ $s?->kelas ? ' · '.$s->kelas->nama : '' }}</span></p>
            <span class="text-xs text-gray-500">Diterima {{ $l->diterima_pada?->timezone('Asia/Jakarta')->format('d/m H:i') }} · <b>{{ $l->berat }} kg</b></span>
        </div>
        @if ($l->catatan)<p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">Catatan penerimaan: {{ $l->catatan }}</p>@endif

        <div class="flex flex-wrap items-end gap-4">
            <label class="block"><span class="text-xs text-gray-500">Berat timbang ulang (kg) *</span>
                <input name="berat" type="number" step="0.01" min="0.01" max="{{ \App\Services\Modules\LaundryService::BERAT_MAKS }}" required autofocus
                       x-model="berat" class="w-40 rounded-lg border-gray-300 text-lg font-semibold tabular-nums"></label>
            <p class="pb-2 text-xs" x-show="berat" :class="Math.abs(selisih) >= 0.5 ? 'font-semibold text-amber-700' : 'text-gray-500'">
                selisih dari penerimaan: <span x-text="(selisih > 0 ? '+' : '') + selisih.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></span> kg</p>
        </div>
        <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
            Kuota bulan ini {{ number_format((float) $kuota['kuota'], 1, ',', '.') }} kg · terpakai sesudah diperiksa
            <b x-text="sesudah.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></b> kg
            <span x-show="sesudah > kuota" class="font-semibold text-amber-700">— melebihi kuota</span></p>

        @include('laundry._isian-pakaian', ['jenis' => $jenis, 'awal' => $l->item, 'judul' => 'Jenis & jumlah pakaian * — dicocokkan lagi saat diserahkan'])

        <label class="block"><span class="text-xs text-gray-500">Catatan pemeriksaan (noda, sobek, barang tertinggal di saku, …)</span>
            <textarea name="catatan" rows="2" maxlength="1000" class="w-full rounded-lg border-gray-300 text-sm">{{ old('catatan') }}</textarea></label>
        <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Simpan pemeriksaan</button>
    </form>
@endsection
