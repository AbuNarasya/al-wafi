@extends('layouts.app')

@php
    $pendapatanOpts = [
        '' => '—', 'di_bawah_5' => '< Rp 5 juta', 'juta_5_10' => 'Rp 5–10 juta',
        'juta_10_15' => 'Rp 10–15 juta', 'juta_15_25' => 'Rp 15–25 juta', 'di_atas_25' => '> Rp 25 juta',
    ];
@endphp

@section('title', $baru ? 'Tambah Wali' : 'Ubah Wali ' . $wali->nama)

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('wali.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('wali.store') : route('wali.update', $wali->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="kontak_utama" label="Kontak Utama" :value="$wali->kontak_utama ?? 'ayah'"
                         :options="['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali']" required
                         hint="Nama & telepon wali diambil dari kontak utama ini." />
                <x-field name="nik" label="NIK" :value="$wali->nik" />
                <x-field name="status" label="Status" :value="$wali->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />
            </div>

            @foreach (['ayah' => 'Data Ayah', 'ibu' => 'Data Ibu', 'wali' => 'Data Wali (bila bukan orang tua)'] as $p => $judul)
                <fieldset class="rounded-lg border border-gray-200 p-4">
                    <legend class="px-2 text-sm font-semibold text-gray-700">{{ $judul }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-field :name="'nama_' . $p" label="Nama" :value="$wali->{'nama_' . $p}" />
                        <x-field :name="'telepon_' . $p" label="Telepon" :value="$wali->{'telepon_' . $p}" />
                        <x-field :name="'email_' . $p" label="Email" type="email" :value="$wali->{'email_' . $p}" />
                        <x-field :name="'pekerjaan_' . $p" label="Pekerjaan" :value="$wali->{'pekerjaan_' . $p}" />
                        <x-field :name="'pendapatan_' . $p" label="Range Pendapatan" :value="$wali->{'pendapatan_' . $p}" :options="$pendapatanOpts" />
                    </div>
                </fieldset>
            @endforeach

            <label class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3 text-sm text-gray-700">
                <input type="hidden" name="auto_debet" value="0">
                <input type="checkbox" name="auto_debet" value="1" @checked(old('auto_debet', $wali->auto_debet))
                       class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                <span>
                    Izinkan auto-debet Dompet Wali
                    <span class="mt-0.5 block text-xs font-normal text-gray-400">Atas PERMINTAAN WALI. Bila aktif, tagihan SPP &amp; tagihan lain otomatis dipotong dari saldo Dompet Wali begitu terbit — tertua dulu, boleh sebagian bila saldonya kurang. Jangan dinyalakan tanpa persetujuan walinya.</span>
                </span>
            </label>

            <x-field name="alamat" label="Alamat Keluarga" :value="$wali->alamat" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('wali.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
