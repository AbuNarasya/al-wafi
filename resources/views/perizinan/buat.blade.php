@extends('layouts.app')

@section('title', 'Izin atas Nama Wali')

@section('content')
    <a href="{{ route('perizinan.index') }}" class="text-sm text-brand hover:underline">&larr; Perizinan Insidental</a>

    @php $wajib = $tipe->where('wajib_keperluan', true)->pluck('kode')->values(); $harian = $tipe->where('pulang', false)->pluck('kode')->values(); @endphp
    <form method="POST" action="{{ route('perizinan.simpan') }}" enctype="multipart/form-data" data-confirm="Catat izin atas nama wali?"
          class="mt-3 max-w-3xl space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
          x-data="{ tipe: @js(old('kode_tipe', $tipe->first()?->kode)), wajib: @js($wajib), harian: @js($harian) }">
        @csrf
        <div>
            <h2 class="text-base font-semibold text-gray-900">Catat izin atas nama wali</h2>
            <p class="text-sm text-gray-500">Mis. santri sakit mendadak dan wali menelepon. Batas waktu pengajuan tidak berlaku; tetap melalui persetujuan. Walinya dikabari.</p>
        </div>
        <x-field name="id_santri" label="Santri" :value="old('id_santri')" :options="$santriOptions" required />
        <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Tipe izin <span class="text-red-500">*</span></span>
            <select name="kode_tipe" x-model="tipe" required class="w-full rounded-lg border-gray-300 text-sm">
                @foreach ($tipe as $t)<option value="{{ $t->kode }}">{{ $t->nama }}</option>@endforeach
            </select></label>
        <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Keperluan <span x-show="wajib.includes(tipe)" class="text-red-500">*</span></span>
            <textarea name="keperluan" rows="2" maxlength="1000" :required="wajib.includes(tipe)" class="w-full rounded-lg border-gray-300 text-sm">{{ old('keperluan') }}</textarea></label>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Rencana keluar <span class="text-red-500">*</span></span>
                <input type="datetime-local" name="rencana_keluar" required value="{{ old('rencana_keluar') }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Rencana kembali <span class="text-red-500">*</span></span>
                <input type="datetime-local" name="rencana_kembali" required value="{{ old('rencana_kembali') }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
        </div>
        <p x-show="harian.includes(tipe)" x-cloak class="text-xs text-amber-700">Keluar lingkungan ma'had: kembali di hari yang sama.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Penjemput</span>
                <input name="penjemput_nama" value="{{ old('penjemput_nama') }}" maxlength="255" class="w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Hubungan</span>
                <input name="penjemput_hubungan" value="{{ old('penjemput_hubungan') }}" maxlength="100" placeholder="mis. ayah" class="w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">No. HP penjemput</span>
                <input name="penjemput_hp" value="{{ old('penjemput_hp') }}" inputmode="tel" class="w-full rounded-lg border-gray-300 text-sm"></label>
        </div>
        <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Lampiran (opsional, maks. {{ \App\Support\Unggahan::maksLabel() }})</span>
            <input type="file" name="lampiran" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm"></label>
        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Catat izin</button>
    </form>
@endsection
