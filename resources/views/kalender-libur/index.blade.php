@extends('layouts.app')

@section('title', 'Kalender Libur')

@section('content')
    @php
        $bolehBuat = \App\Support\Akses::boleh('kalender-libur', 'buat');
        $bolehUbah = \App\Support\Akses::boleh('kalender-libur', 'ubah');
        $bolehHapus = \App\Support\Akses::boleh('kalender-libur', 'hapus');
        $tampilForm = $ubah ? $bolehUbah : $bolehBuat;
    @endphp
    <div class="mx-auto max-w-5xl space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-600 shadow-sm">
            Hari libur dipakai menu <b>Absensi</b> di Portal Wali: tanggal tanpa catatan absen pada hari libur tampil
            <b>"Hari Libur"</b> beserta namanya, bukan "Tidak ada data absen &amp; keterangan". <b>Sabtu &amp; Ahad sudah otomatis libur sekolah</b> — tidak perlu diisi.
            Isi di sini libur nasional, libur semester, kepulangan santri, dan sejenisnya. Kosongkan jenjang bila berlaku untuk semua jenjang.
        </div>

        @if ($tampilForm)
            <form method="POST" action="{{ $ubah ? route('kalender_libur.update', $ubah->id) : route('kalender_libur.store') }}"
                  class="grid gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-6" data-form-libur>
                @csrf
                @if ($ubah) @method('PUT') @endif
                <h2 class="text-base font-semibold text-gray-900 sm:col-span-6">{{ $ubah ? 'Ubah libur' : 'Tambah libur' }}</h2>
                <label class="text-sm sm:col-span-6">Nama libur <span class="text-red-600">*</span>
                    <input name="nama" required maxlength="150" value="{{ old('nama', $ubah?->nama) }}" placeholder="mis. Libur Semester Gasal, Maulid Nabi"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="text-sm sm:col-span-2">Mulai <span class="text-red-600">*</span>
                    <input type="date" name="mulai" required value="{{ old('mulai', $ubah?->mulai?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="text-sm sm:col-span-2">Selesai <span class="text-red-600">*</span>
                    <input type="date" name="selesai" required value="{{ old('selesai', $ubah?->selesai?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="text-sm sm:col-span-2">Jenjang
                    <select name="kode_jenjang" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Semua jenjang</option>
                        @foreach ($jenjang as $j)
                            <option value="{{ $j->kode }}" @selected(old('kode_jenjang', $ubah?->kode_jenjang) === $j->kode)>{{ $j->nama }}</option>
                        @endforeach
                    </select></label>
                <label class="text-sm sm:col-span-2">Berlaku untuk
                    <select name="cakupan" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        @foreach (\App\Models\KalenderLibur::CAKUPAN as $k => $l)
                            <option value="{{ $k }}" @selected(old('cakupan', $ubah?->cakupan ?? 'semua') === $k)>{{ $l }}</option>
                        @endforeach
                    </select></label>
                <label class="text-sm sm:col-span-4">Keterangan
                    <input name="keterangan" maxlength="500" value="{{ old('keterangan', $ubah?->keterangan) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <p class="text-xs text-gray-500 sm:col-span-6"><b>Sekolah saja</b> = libur kegiatan sekolah, santri tetap di asrama (mis. libur nasional).
                    <b>Asrama saja / Sekolah &amp; asrama</b> = santri pulang (mis. libur semester), absen asrama juga libur.</p>
                <div class="flex gap-2 sm:col-span-6">
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">{{ $ubah ? 'Simpan perubahan' : 'Tambah' }}</button>
                    @if ($ubah)<a href="{{ route('kalender_libur.index', ['tahun' => $tahun]) }}" class="rounded-lg px-4 py-2 text-sm text-gray-600 ring-1 ring-gray-300">Batal</a>@endif
                </div>
            </form>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                <h2 class="text-base font-semibold text-gray-900">Daftar libur {{ $tahun }}</h2>
                <div class="flex gap-1 text-sm">
                    <a href="{{ route('kalender_libur.index', ['tahun' => $tahun - 1]) }}" class="rounded-lg px-3 py-1 ring-1 ring-gray-300">&larr; {{ $tahun - 1 }}</a>
                    <a href="{{ route('kalender_libur.index', ['tahun' => $tahun + 1]) }}" class="rounded-lg px-3 py-1 ring-1 ring-gray-300">{{ $tahun + 1 }} &rarr;</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-daftar-libur>
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr>
                        <th class="px-4 py-2 text-start">Tanggal</th><th class="px-4 py-2 text-start">Nama</th><th class="px-4 py-2 text-start">Jenjang</th>
                        <th class="px-4 py-2 text-start">Berlaku untuk</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($daftar as $l)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2">{{ $l->mulai->translatedFormat('j M Y') }}@if (! $l->mulai->equalTo($l->selesai)) – {{ $l->selesai->translatedFormat('j M Y') }}@endif
                                    <span class="block text-xs text-gray-400">{{ $l->mulai->diffInDays($l->selesai) + 1 }} hari</span></td>
                                <td class="px-4 py-2">{{ $l->nama }}@if ($l->keterangan)<span class="block text-xs text-gray-500">{{ $l->keterangan }}</span>@endif</td>
                                <td class="px-4 py-2">{{ $l->jenjang?->nama ?? 'Semua jenjang' }}</td>
                                <td class="px-4 py-2">{{ \App\Models\KalenderLibur::CAKUPAN[$l->cakupan] ?? $l->cakupan }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end">
                                    @if ($bolehUbah)<a href="{{ route('kalender_libur.index', ['tahun' => $tahun, 'ubah' => $l->id]) }}" class="text-brand">Ubah</a>@endif
                                    @if ($bolehHapus)
                                        <form method="POST" action="{{ route('kalender_libur.destroy', $l->id) }}" class="inline" data-confirm="Hapus libur &quot;{{ $l->nama }}&quot;?">
                                            @csrf @method('DELETE')
                                            <button class="ms-3 text-red-700">Hapus</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Belum ada hari libur di tahun {{ $tahun }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
