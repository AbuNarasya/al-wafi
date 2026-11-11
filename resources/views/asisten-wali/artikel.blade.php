@extends('layouts.app')

@section('title', $a ? 'Ubah Artikel Bantuan' : 'Artikel Bantuan Baru')

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <a href="{{ route('asisten_wali.edit', ['audiens' => $audiens]) }}" class="text-sm text-brand hover:underline">&larr; Asisten Bantuan</a>
        <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $a ? 'Ubah artikel' : 'Artikel baru' }}</h2>
    </div>

    <form method="POST" action="{{ $a ? route('asisten_wali.artikel.update', $a->id) : route('asisten_wali.artikel.store') }}"
          class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        @csrf
        @if ($a) @method('PUT') @endif
        @if ($portalWali)
            <div>
                <label class="block text-sm font-medium text-gray-700">Pembaca</label>
                <select name="audiens" class="mt-1 rounded-lg border-gray-300 text-sm">
                    @foreach (\App\Services\PortalWali\AsistenWali::AUDIENS as $k => $label)
                        <option value="{{ $k }}" @selected(old('audiens', $audiens) === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <input type="hidden" name="audiens" value="staf">
        @endif
        <div>
            <label class="block text-sm font-medium text-gray-700">Judul <span class="font-normal text-gray-400">— tulis sebagai pertanyaan wali</span></label>
            <input name="judul" value="{{ old('judul', $a->judul ?? '') }}" required maxlength="200" placeholder="{{ $audiens === 'wali' ? 'Bagaimana cara membayar tagihan SPP?' : 'Bagaimana cara menerbitkan tagihan SPP bulanan?' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Isi</label>
            <textarea name="isi" rows="10" required maxlength="5000" class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('isi', $a->isi ?? '') }}</textarea>
            <p class="mt-1 text-xs text-gray-500">Langkah bernomor, sebut jalur menu persis seperti di layar. Jangan menulis tarif, nomor rekening, nomor HP, atau nama staf.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Kata kunci <span class="font-normal text-gray-400">(tidak wajib)</span></label>
            <input name="kata_kunci" value="{{ old('kata_kunci', $a->kata_kunci ?? '') }}" maxlength="1000" placeholder="bayar, spp, transfer, bukti, va" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            <p class="mt-1 text-xs text-gray-500">Kata yang mungkin diketik wali, dipisah koma — termasuk istilah sehari-hari.</p>
        </div>
        <div class="flex flex-wrap items-center gap-6">
            <label class="text-sm text-gray-700">Urutan <input type="number" name="urutan" min="0" max="9999" value="{{ old('urutan', $a->urutan ?? 0) }}" class="ml-2 w-24 rounded-lg border-gray-300 text-sm"></label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="aktif" value="0">
                <input type="checkbox" name="aktif" value="1" @checked(old('aktif', $a->aktif ?? true)) class="rounded border-gray-300 text-brand focus:ring-brand"> Aktif
            </label>
        </div>
        <div class="flex justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
        </div>
    </form>

    @if ($a && \App\Support\Akses::boleh('asisten-wali', 'hapus'))
        <form method="POST" action="{{ route('asisten_wali.artikel.destroy', $a->id) }}" data-confirm="Hapus artikel ini? Gunakan centang Aktif bila hanya ingin menyembunyikan.">
            @csrf @method('DELETE')
            <button class="text-sm text-red-600 hover:underline">Hapus artikel</button>
        </form>
    @endif
</div>
@endsection
