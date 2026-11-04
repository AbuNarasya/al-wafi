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
            <p class="text-sm text-gray-500">Mis. santri sakit mendadak dan wali menelepon. Batas waktu pengajuan tidak berlaku; persetujuan mengikuti pengaturan jenis izinnya. Walinya dikabari.</p>
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
        @php $wakilWajib = \App\Services\Modules\PerizinanService::pengaturan()->wakil_wajib; $tanda = fn ($k) => in_array($k, $wakilWajib, true) ? ' *' : ''; @endphp
        <div x-data="{ jenis: @js(old('penjemput_jenis', '')) }" class="space-y-3 rounded-lg border border-gray-200 p-4">
            <div class="flex flex-wrap items-end gap-4">
                <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Penjemput</span>
                    <select name="penjemput_jenis" x-model="jenis" class="rounded-lg border-gray-300 text-sm">
                        <option value="">Belum diketahui</option><option value="wali">Dijemput wali</option><option value="wakil">Diwakilkan</option>
                    </select></label>
                <label x-show="jenis === 'wali'" x-cloak class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Siapa</span>
                    <select name="penjemput_peran" class="rounded-lg border-gray-300 text-sm">
                        @foreach (\App\Models\Wali::PERAN as $k => $l)<option value="{{ $k }}" @selected(old('penjemput_peran') === $k)>{{ $l }}</option>@endforeach
                    </select></label>
                <p x-show="jenis === 'wali'" x-cloak class="text-xs text-gray-500">Nama &amp; HP diambil dari data keluarga santri.</p>
            </div>
            <div x-show="jenis === 'wakil'" x-cloak class="grid gap-3 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block text-gray-700">Nama{{ $tanda('nama') }}</span><input name="penjemput_nama" value="{{ old('penjemput_nama') }}" maxlength="255" class="w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="mb-1 block text-gray-700">Hubungan{{ $tanda('hubungan') }}</span><input name="penjemput_hubungan" value="{{ old('penjemput_hubungan') }}" maxlength="100" placeholder="mis. paman" class="w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="mb-1 block text-gray-700">No. HP{{ $tanda('hp') }}</span><input name="penjemput_hp" value="{{ old('penjemput_hp') }}" inputmode="tel" class="w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="mb-1 block text-gray-700">NIK / no. identitas{{ $tanda('identitas') }}</span><input name="penjemput_identitas" value="{{ old('penjemput_identitas') }}" maxlength="50" class="w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm sm:col-span-2"><span class="mb-1 block text-gray-700">Foto KTP / identitas{{ $tanda('foto_identitas') }}</span><input type="file" name="penjemput_foto" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm"></label>
                <label class="block text-sm sm:col-span-3"><span class="mb-1 block text-gray-700">Keterangan{{ $tanda('keterangan') }}</span><input name="penjemput_keterangan" value="{{ old('penjemput_keterangan') }}" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm"></label>
            </div>
        </div>
        <label class="block text-sm"><span class="mb-1 block font-medium text-gray-700">Lampiran (opsional, maks. {{ \App\Support\Unggahan::maksLabel() }})</span>
            <input type="file" name="lampiran" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm"></label>
        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Catat izin</button>
    </form>
@endsection
