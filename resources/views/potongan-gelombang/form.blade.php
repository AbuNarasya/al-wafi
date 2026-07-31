@extends('layouts.app')

@section('title', $baru ? 'Tambah Potongan Gelombang' : 'Ubah Potongan Gelombang ' . $row->gelombang)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('potongan_gelombang.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('potongan_gelombang.store') : route('potongan_gelombang.update', $row->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tahun_ajaran" label="Tahun Ajaran" :value="old('tahun_ajaran', $row->tahun_ajaran)"
                         :options="['' => '— pilih tahun ajaran —'] + (new \App\Services\Modules\TahunAjaranService)->opsiAktif()" required />
                <x-field name="gelombang" label="Gelombang" type="number" :value="old('gelombang', $row->gelombang)" required />
            </div>
            <x-field name="kode_jenjang" label="Jenjang" :value="old('kode_jenjang', $row->kode_jenjang)"
                     :options="\App\Support\Referensi::withEmpty(\App\Support\Referensi::jenjang(), '— Semua jenjang —')"
                     hint="Kosongkan untuk semua jenjang." />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="potongan" label="Nominal Potongan" type="number" :value="old('potongan', $row->potongan)" required
                         hint="Harus lebih kecil dari uang pangkalnya, kalau tidak penagihan ditolak." />
                <x-field name="masa_berlaku_hari" label="Masa Berlaku (hari)" type="number" :value="old('masa_berlaku_hari', $row->masa_berlaku_hari)" required />
            </div>
            <label class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3 text-sm text-gray-700">
                <input type="hidden" name="aktif" value="0">
                <input type="checkbox" name="aktif" value="1" @checked(old('aktif', $row->aktif))
                       class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                <span>
                    Aktif (kebijakan berlaku)
                    <span class="mt-0.5 block text-xs font-normal text-gray-400">Bila dicentang, baris lain di gelombang &amp; jenjang yang sama otomatis menjadi arsip.</span>
                </span>
            </label>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan', $row->keterangan)" />

            @unless ($baru)
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    Perubahan di sini hanya berlaku untuk tagihan uang pangkal yang terbit
                    <strong>sesudah</strong> ini. Tagihan yang sudah terbit membawa salinan potongannya sendiri,
                    jadi angka yang sudah dijanjikan ke wali tidak ikut berubah.
                </p>
            @endunless

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('potongan_gelombang.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
