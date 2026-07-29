@extends('layouts.app')

@section('title', $baru ? 'Tambah Jenjang' : 'Ubah Jenjang ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('jenjang.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('jenjang.store') : route('jenjang.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode" label="Kode Jenjang" :value="old('kode', $row->kode)" required placeholder="mis. SD"
                         hint="Huruf/angka/underscore. Dipakai sebagai kode jenjang di modul lain — tidak bisa diubah setelah disimpan." />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Jenjang</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama" label="Nama Jenjang" :value="old('nama', $row->nama)" required placeholder="mis. Sekolah Dasar" />
                <x-field name="jumlah_tingkat" label="Jumlah Tingkat" type="number" :value="old('jumlah_tingkat', $row->jumlah_tingkat)"
                         placeholder="mis. 6"
                         hint="Berapa tingkat (kelas) yang ada di jenjang ini — SDTQ 6, SMP 3, SMA 3. Menentukan pilihan Tingkat saat calon santri didaftarkan." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_jenjang_lanjutan" label="Jenjang Lanjutan" :value="old('kode_jenjang_lanjutan', $row->kode_jenjang_lanjutan)"
                         :options="\App\Support\Referensi::withEmpty(collect(\App\Support\Referensi::jenjang())->except($row->kode ?? '')->all(), '— jenjang terakhir —')"
                         hint="Jenjang berikutnya saat santri tamat di sini (SDTQ→SMP, SMP→SMA). Dikosongkan berarti jenjang terakhir: santrinya menjadi alumni, bukan naik." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="urutan" label="Urutan Tampil" type="number" :value="old('urutan', $row->urutan ?? 0)"
                         hint="Angka kecil tampil lebih dulu (mis. SD 1, SMP 2, SMA 3)." />
                <x-field name="status" label="Status" :value="old('status', $row->status ?? 'aktif')" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']"
                         hint="Nonaktif = tidak muncul di dropdown, data lama tetap utuh." />
            </div>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan', $row->keterangan)" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('jenjang.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
