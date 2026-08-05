@extends('layouts.app')

@section('title', $baru ? 'Tambah Gelombang' : 'Ubah Gelombang ' . $row->nama)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('gelombang.index', ['ta' => $row->tahun_ajaran]) }}"
           class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('gelombang.store') : route('gelombang.update', $row->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tahun_ajaran" label="Tahun Ajaran" :value="old('tahun_ajaran', $row->tahun_ajaran)"
                         :options="['' => '— pilih tahun ajaran —'] + $opsiTa" required />
                <x-field name="kode" label="Kode Gelombang" :value="old('kode', $row->kode)" required
                         hint="Bebas: G1, G2, atau nama seperti TAHFIZH. Dipakai sebagai penunjuk di data santri." />
            </div>

            <x-field name="nama" label="Nama Gelombang" :value="old('nama', $row->nama)"
                     hint="Inilah yang muncul di dropdown registrasi. Dikosongkan → memakai kodenya." />

            <div class="rounded-lg border border-gray-200 p-3">
                <div class="text-sm font-medium text-gray-700">Periode Gelombang <span class="text-xs font-normal text-gray-400">(opsional)</span></div>
                <p class="mb-3 mt-0.5 text-xs text-gray-400">
                    Di luar rentang ini gelombangnya tidak lagi ditawarkan saat registrasi dan potongannya tidak dipakai —
                    tanpa perlu dimatikan manual. <b>Memperpanjang tanggal selesai langsung menghidupkannya kembali.</b>
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="berlaku_mulai" label="Mulai" type="date" :value="old('berlaku_mulai', $row->berlaku_mulai?->toDateString())" />
                    <x-field name="berlaku_sampai" label="Sampai" type="date" :value="old('berlaku_sampai', $row->berlaku_sampai?->toDateString())" />
                </div>
                @unless ($baru)
                    @php $keadaan = $row->keadaan(); @endphp
                    @if ($keadaan === 'kedaluwarsa')
                        <p class="mt-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Periode gelombang ini sudah lewat, jadi saat ini <b>tidak ditawarkan</b>. Majukan tanggal selesainya untuk memberlakukannya lagi.
                        </p>
                    @elseif ($keadaan === 'belum_mulai')
                        <p class="mt-2 rounded bg-blue-50 px-3 py-2 text-xs text-blue-800">
                            Periodenya belum mulai, jadi gelombang ini <b>belum ditawarkan</b> sampai tanggal mulainya tiba.
                        </p>
                    @endif
                @endunless
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="masa_berlaku_hari" label="Masa Berlaku (hari)" type="number"
                         :value="old('masa_berlaku_hari', $row->masa_berlaku_hari ?? 7)" required
                         hint="Tenggat tiap calon membayar ≥50% agar potongannya tidak hangus. Beda dari periode di atas." />
                <x-field name="status" label="Status" :value="old('status', $row->status ?? 'aktif')"
                         :options="['aktif' => 'Aktif', 'arsip' => 'Arsip']" required
                         hint="Arsip = tidak ditawarkan lagi, tapi data santri lama tetap utuh." />
            </div>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan', $row->keterangan)" />

            @unless ($baru)
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    Perubahan di sini hanya berlaku untuk tagihan uang pangkal yang terbit <strong>sesudah</strong> ini.
                    Tagihan yang sudah terbit membawa salinan potongannya sendiri, jadi angka yang sudah dijanjikan ke wali tidak ikut berubah.
                </p>
            @endunless

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('gelombang.index', ['ta' => $row->tahun_ajaran]) }}"
                   class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
