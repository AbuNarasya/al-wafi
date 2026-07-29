@extends('layouts.app')

@section('title', $baru ? 'Tambah Jalur Pendaftaran' : 'Ubah Jalur ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('jalur_pendaftaran.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('jalur_pendaftaran.store') : route('jalur_pendaftaran.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode" label="Kode" :value="$row->kode" required placeholder="mis. reguler" hint="Huruf/angka/underscore. Dipakai sebagai nilai jalur santri." />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
            @endif

            <x-field name="nama" label="Nama Jalur" :value="$row->nama" required placeholder="mis. Reguler"
                     hint="Jalur berlaku untuk SEMUA tahun ajaran. Tarif yang berbeda tiap tahun diatur di Jenis Biaya, bukan di sini." />
            {{-- Dua isian di bawah dipakai proses KENAIKAN JENJANG. --}}
            <x-field name="kode_jalur_lanjutan" label="Jalur Setelah Naik Jenjang" :value="$row->kode_jalur_lanjutan"
                     :options="\App\Support\Referensi::withEmpty(\App\Support\Referensi::jalur(), '— jalurnya tidak berubah —')"
                     hint="Jalur yang berlaku setelah santri naik ke jenjang berikutnya — inilah yang menentukan tarif uang pangkal lanjutannya. Boleh menunjuk jalur ini sendiri." />

            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="hidden" name="bebas_uang_pangkal" value="0">
                <input type="checkbox" name="bebas_uang_pangkal" value="1" @checked(old('bebas_uang_pangkal', $row->bebas_uang_pangkal))
                       class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                <span>
                    Bebas uang pangkal
                    <span class="block text-xs text-gray-400">
                        Santri berjalur ini tidak ditagih uang pangkal — baik saat mendaftar maupun saat naik jenjang.
                        Tagihan perlengkapan tetap terbit seperti biasa.
                    </span>
                </span>
            </label>

            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />
            <x-field name="status" label="Status" :value="$row->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('jalur_pendaftaran.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
