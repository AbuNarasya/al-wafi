@extends('layouts.app')

@section('title', $baru ? 'Tambah Level Pengajuan' : 'Ubah Level Pengajuan (Peringkat ' . $row->peringkat . ')')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('level_pengajuan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('level_pengajuan.store') : route('level_pengajuan.update', $row) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="peringkat" label="Peringkat" type="number" :value="old('peringkat', $row->peringkat)" required
                         hint="1 = tertinggi. Boleh disisipkan di tengah susunan selama angkanya belum dipakai. Tak bisa diubah setelah disimpan — data pengguna & tahap rantai merujuknya." />
            @else
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Peringkat</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->peringkat }} (1 = tertinggi)</div>
                    <p class="mt-1 text-xs text-gray-400">Tak bisa diubah — dirujuk data pengguna &amp; tahap rantai persetujuan.</p>
                </div>
            @endif

            <x-field name="nama" label="Nama" :value="old('nama', $row->nama)" required />

            <x-field name="status" label="Status" :value="old('status', $row->status ?? 'aktif')"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']"
                     hint="Level nonaktif tidak memegang peran apa pun — wewenangnya benar-benar padam, bukan sekadar tersembunyi." />

            <fieldset class="rounded-lg border border-gray-200 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-700">Peran</legend>
                <p class="mb-3 text-xs text-gray-500">
                    Inilah yang menentukan wewenang — bukan angka peringkatnya. Level tanpa satu pun
                    centang adalah anak tangga penyetuju biasa, dan itu memang keadaan yang paling lazim.
                </p>
                <div class="space-y-3">
                    @foreach (\App\Models\LevelPengajuan::PERAN as $kolom => $label)
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="{{ $kolom }}" value="1"
                                   @checked(old($kolom, $row->{$kolom}))
                                   class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                            <span>
                                {{ $label }}
                                <span class="mt-0.5 block text-xs font-normal text-gray-400">
                                    @switch($kolom)
                                        @case('boleh_ajukan_pembayaran')
                                            Boleh membuat pengajuan pembayaran, uang muka, dan penyelesaiannya. Harus ada setidaknya satu level aktif yang memegangnya.
                                            @break
                                        @case('boleh_ajukan_anggaran')
                                            Boleh mengajukan anggaran bagiannya. Yang sekaligus pemegang tahap pertama rantai akan melewati tahapnya sendiri.
                                            @break
                                        @case('terikat_bagian')
                                            Penggunanya WAJIB ditempatkan di sebuah Bagian, dan data yang dilihatnya terbatas pada bagian itu.
                                            @break
                                        @case('lingkup_semua')
                                            Melihat seluruh pengajuan anggaran yayasan, bukan hanya bagiannya.
                                            @break
                                    @endswitch
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan', $row->keterangan)" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('level_pengajuan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
