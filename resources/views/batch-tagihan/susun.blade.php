@extends('layouts.app')

@section('title', 'Susun Batch Tagihan')

@section('content')
    <p class="mb-4 text-sm text-gray-500">
        Pilih sasarannya, lalu simpan sebagai draft. <b>Belum ada tagihan yang terbit</b> &mdash; draft ini
        masih bisa Anda periksa baris per baris sebelum diotorisasi.
    </p>

    <form method="POST" action="{{ route('batch_tagihan.store') }}"
          x-data="{ modul: '{{ old('modul', $bolehSusun[0] ?? '') }}', sumber: '{{ old('sumber', 'peserta') }}' }"
          class="max-w-3xl rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        @csrf

        <div class="mb-5">
            <label class="mb-1 block text-xs font-medium text-gray-600">Modul <span class="text-red-500">*</span></label>
            <select name="modul" x-model="modul" required
                    class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm sm:max-w-xs">
                @foreach (\App\Models\BatchTagihan::MODUL as $kode => $nama)
                    @if (in_array($kode, $bolehSusun, true))
                        <option value="{{ $kode }}">{{ $nama }}</option>
                    @endif
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-400">
                Hanya modul yang memang berhak Anda terbitkan yang muncul di sini.
            </p>
        </div>

        {{-- ── SPP ── --}}
        <div x-show="modul === 'spp'" x-cloak class="mb-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Periode <span class="text-red-500">*</span></label>
                <input type="month" name="periode" value="{{ old('periode') }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-gray-600">Alasan bila lintas tahun ajaran</label>
                <input type="text" name="alasan_lintas_ta" value="{{ old('alasan_lintas_ta') }}"
                       placeholder="Wajib diisi bila periodenya di luar tahun ajaran berjalan"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
        </div>

        {{-- ── Daftar ulang ── --}}
        <div x-show="modul === 'daftar_ulang'" x-cloak class="mb-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">T.A Tagihan <span class="text-red-500">*</span></label>
                <select name="tahun_ajaran" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">— pilih —</option>
                    @foreach ($opsiTa as $kode => $teks)
                        <option value="{{ $kode }}" @selected(old('tahun_ajaran') === $kode)>{{ $teks }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Jenjang <span class="text-red-500">*</span></label>
                <select name="kode_jenjang" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">— pilih —</option>
                    @foreach ($opsiJenjang as $kode => $nama)
                        <option value="{{ $kode }}" @selected(old('kode_jenjang') === $kode)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Angkatan (saringan)</label>
                <select name="angkatan" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">semua angkatan</option>
                    @foreach ($opsiTa as $kode => $teks)
                        <option value="{{ $kode }}" @selected(old('angkatan') === $kode)>{{ $teks }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tingkat (saringan)</label>
                <input type="number" name="tingkat" min="1" value="{{ old('tingkat') }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
        </div>

        {{-- ── Tagihan lain-lain ── --}}
        <div x-show="modul === 'tagihan_lain'" x-cloak class="mb-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Jenis biaya <span class="text-red-500">*</span></label>
                <select name="kode_jenis" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">— pilih —</option>
                    @foreach ($opsiJenisLain as $j)
                        <option value="{{ $j->kode }}" @selected(old('kode_jenis') === $j->kode)>{{ $j->nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Sumber peserta <span class="text-red-500">*</span></label>
                <select name="sumber" x-model="sumber" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="peserta">Daftar peserta (ekskul, kegiatan)</option>
                    <option value="pemakaian">Pemakaian (laundry)</option>
                </select>
                <p class="mt-1 text-xs text-gray-400" x-show="sumber === 'pemakaian'" x-cloak>
                    Nominalnya dihitung dari timbangan yang sudah masuk sampai akhir periode, dikurangi kuota gratis.
                </p>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">
                    Periode <span x-show="sumber === 'pemakaian'" class="text-red-500">*</span>
                </label>
                <input type="month" name="periode" value="{{ old('periode') }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label>
                <input type="text" name="keterangan" value="{{ old('keterangan') }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
        </div>

        {{-- ── Berlaku untuk semua modul ── --}}
        <div class="grid gap-4 border-t border-gray-100 pt-5 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Judul batch</label>
                <input type="text" name="judul" value="{{ old('judul') }}"
                       placeholder="Dikosongkan = dibuatkan sendiri"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Jatuh tempo tagihan</label>
                <input type="date" name="jatuh_tempo" value="{{ old('jatuh_tempo') }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>
            {{-- Tanggal & jam DIPISAH, bukan satu `datetime-local`: widget gabungan
                 bawaan peramban sulit dipakai (kolom jam & menitnya tak selalu bisa
                 diubah), sedangkan `type="date"` dan `type="time"` sudah terbukti di
                 puluhan layar lain aplikasi ini. Digabung kembali di controller. --}}
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-gray-600">Waktu rilis otomatis</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="date" name="rilis_tanggal" value="{{ old('rilis_tanggal') }}"
                           class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <span class="text-sm text-gray-500">pukul</span>
                    <input type="time" name="rilis_jam" value="{{ old('rilis_jam', '00:00') }}"
                           class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                </div>
                <p class="mt-1 text-xs text-gray-400">
                    <b>Tanggal dikosongkan</b> = batch hanya terbit bila tombol Rilis ditekan. Diisi = ia terbit
                    sendiri pada waktu itu, dan <b>tanggal jurnalnya mengikuti waktu ini</b>, bukan saat penjadwal
                    benar-benar jalan. Waktu rilis masih bisa diubah saat mengotorisasi.
                </p>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                Susun Draft
            </button>
            <a href="{{ route('batch_tagihan.index') }}" class="text-sm text-gray-500 hover:underline">Batal</a>
        </div>
    </form>
@endsection
