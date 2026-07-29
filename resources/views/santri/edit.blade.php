@extends('layouts.app')

@section('title', 'Ubah Data — ' . $santri->nama)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ $kembali }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail</a>

        <form method="POST" action="{{ route('santri.update', $santri->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @method('PUT')

            {{-- Identitas dokumen: dirujuk tagihan, kuitansi, dan berkas cetak. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">No. Pendaftaran</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 font-mono text-sm text-gray-600">{{ $santri->no_pendaftaran }}</div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Status</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ \App\Services\Ppsb\Tahap::labelStatus($santri->status) }}</div>
                </div>
            </div>

            <x-field name="id_wali" label="Wali / Keluarga" :value="old('id_wali', $santri->id_wali)" :options="$waliOptions" required
                     hint="Memindahkan santri ke wali lain sekaligus memindahkan tagihannya ke dompet wali itu." />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama" label="Nama Santri" :value="old('nama', $santri->nama)" required />
                <x-field name="jenis_kelamin" label="Jenis Kelamin" :value="old('jenis_kelamin', $santri->jenis_kelamin)"
                         :options="['L' => 'Laki-laki', 'P' => 'Perempuan']" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nis" label="NIS" :value="old('nis', $santri->nis)"
                         hint="Terbit otomatis saat daftar ulang; koreksi hanya bila salah. Harus unik." />
                <x-field name="nisn" label="NISN" :value="old('nisn', $santri->nisn)" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tempat_lahir" label="Tempat Lahir" :value="old('tempat_lahir', $santri->tempat_lahir)" />
                <x-field name="tanggal_lahir" label="Tanggal Lahir" type="date"
                         :value="old('tanggal_lahir', optional($santri->tanggal_lahir)->format('Y-m-d'))" />
            </div>

            {{-- Jenjang & tingkat menyatu: pilihan tingkat mengikuti jenjangnya. --}}
            <div class="grid gap-4 sm:grid-cols-2"
                 x-data="{
                     jenjang: @js(old('kode_jenjang', $santri->kode_jenjang)),
                     tingkat: @js((string) old('tingkat', $santri->tingkat)),
                     peta: @js(\App\Models\Jenjang::petaTingkat()),
                     get jumlah() { return this.peta[this.jenjang] ?? 0 },
                 }"
                 x-init="$watch('jenjang', () => { if (Number(tingkat) > jumlah) tingkat = '' })">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenjang <span class="text-red-500">*</span></label>
                    <select name="kode_jenjang" x-model="jenjang" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih jenjang —</option>
                        @foreach (\App\Support\Referensi::jenjang() as $kodeJenjang => $namaJenjang)
                            <option value="{{ $kodeJenjang }}">{{ $namaJenjang }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Menentukan tarif SPP yang dipakai saat tagihan BERIKUTNYA terbit; tagihan yang sudah ada tidak dihitung ulang.</p>
                    @error('kode_jenjang')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tingkat <span class="text-red-500">*</span></label>
                    <select name="tingkat" x-model="tingkat" required :disabled="!jenjang"
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100">
                        <option value="">— pilih tingkat —</option>
                        <template x-for="i in jumlah" :key="i">
                            <option :value="i" x-text="'Tingkat ' + i" :selected="String(i) === tingkat"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-xs text-amber-600" x-show="jenjang && jumlah === 0" x-cloak>
                        Jumlah tingkat jenjang ini belum diisi di Setting Awal &rarr; Jenjang Pendidikan.
                    </p>
                    @error('tingkat')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <fieldset class="rounded-lg border border-gray-200 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-700">Sekolah Asal</legend>
                <div class="space-y-3">
                    <x-field name="asal_sekolah" label="Asal Sekolah" :value="old('asal_sekolah', $santri->asal_sekolah)" />
                    <x-field name="alamat_sekolah_asal" label="Alamat Sekolah Asal" :value="old('alamat_sekolah_asal', $santri->alamat_sekolah_asal)" textarea />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="kepala_sekolah_asal" label="Nama Kepala Sekolah Asal" :value="old('kepala_sekolah_asal', $santri->kepala_sekolah_asal)" />
                        <x-field name="cp_kepala_sekolah_asal" label="CP Kepala Sekolah Asal" :value="old('cp_kepala_sekolah_asal', $santri->cp_kepala_sekolah_asal)" placeholder="mis. 0812xxxxxxx" />
                    </div>
                </div>
            </fieldset>

            {{-- Meniru form pendaftaran: isian teks bebas muncul untuk sumber yang
                 ditandai "minta keterangan" di masternya. --}}
            @php $sumberMaster = \App\Models\SumberInformasi::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get(); @endphp
            <div x-data="{ sumber: @js(old('sumber_informasi', $santri->sumber_informasi ?? '')) }">
                <label class="mb-1 block text-sm font-medium text-gray-700">Sumber Informasi</label>
                <select name="sumber_informasi" x-model="sumber"
                        class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    <option value="">—</option>
                    @foreach ($sumberMaster as $s)
                        <option value="{{ $s->kode }}" @selected(old('sumber_informasi', $santri->sumber_informasi) === $s->kode)>{{ $s->nama }}{{ $s->butuh_keterangan ? ' (sebutkan)' : '' }}</option>
                    @endforeach
                </select>
                <div x-show="@js($sumberMaster->where('butuh_keterangan', true)->pluck('kode')->values()->all()).includes(sumber)" x-cloak class="mt-2">
                    <input type="text" name="sumber_informasi_lain" value="{{ old('sumber_informasi_lain', $santri->sumber_informasi_lain) }}"
                           placeholder="Sebutkan sumber informasi"
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                </div>
            </div>

            {{-- Yang PPSB tidak ikut disunting: mengubahnya tak menghitung ulang
                 tagihan yang sudah terbit, jadi datanya akan bertentangan dengan
                 tagihannya sendiri. --}}
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-500">
                <b>Tidak disunting di sini:</b>
                Tahun Ajaran ({{ $santri->tahun_ajaran ?? '—' }}),
                Jalur ({{ $santri->jalurPendaftaran?->nama ?? $santri->jalur ?? '—' }}),
                Gelombang ({{ $santri->gelombang ?? 'tanpa gelombang' }}),
                dan Status.
                Ketiganya menentukan tarif saat tagihan terbit — mengubahnya belakangan tidak menghitung ulang
                tagihan yang sudah ada. Status berpindah lewat tombol tahapan di halaman detail.
                Nominal SPP khusus diatur di menu SPP.
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ $kembali }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Perubahan</button>
            </div>
        </form>
    </div>
@endsection
