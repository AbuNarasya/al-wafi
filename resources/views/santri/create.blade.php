@extends('layouts.app')

@section('title', 'Daftarkan Calon Santri')

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('santri.calon') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if (empty($waliOptions))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                Belum ada wali aktif. Tambahkan <a href="{{ route('wali.create') }}" class="underline">Wali</a> terlebih dahulu.
            </div>
        @endif

        @if (empty($taOptions))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                Belum ada tahun ajaran aktif. Tambahkan <a href="{{ route('tahun_ajaran.create') }}" class="underline">Tahun Ajaran</a> terlebih dahulu — pendaftaran membutuhkannya.
            </div>
        @endif

        {{-- Keadaan dipegang di tingkat FORM karena tiga isian yang saling terkait
             (tahun ajaran, jenjang, jalur) tersebar di beberapa grid. Dulu
             `jenjang` dideklarasikan di x-data dalam, sehingga tak terjangkau
             oleh isian jalur di bawahnya. --}}
        <form method="POST" action="{{ route('santri.store') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
              x-data="{
                  jenjang: @js(old('kode_jenjang', '')),
                  tingkat: @js((string) old('tingkat', '')),
                  ta: @js(old('tahun_ajaran', $taDefault ?? '')),
                  peta: @js(\App\Models\Jenjang::petaTingkat()),
                  jalurTutup: @js($jalurNonaktif),
                  get rentang() { return this.peta[this.jenjang] ?? null },
                  get jumlah() { return this.rentang ? this.rentang.akhir - this.rentang.mulai + 1 : 0 },
                  get tingkatOpsi() { return this.rentang ? Array.from({ length: this.jumlah }, (_, k) => this.rentang.mulai + k) : [] },
                  get tutup() { return this.jalurTutup[this.ta + '|' + this.jenjang] ?? [] },
              }"
              x-init="$watch('jenjang', () => { if (!tingkatOpsi.includes(Number(tingkat))) tingkat = '' })">
            @csrf

            <x-field name="id_wali" label="Wali / Keluarga" :value="old('id_wali')" :options="['' => '— pilih wali —'] + $waliOptions" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama" label="Nama Calon Santri" :value="old('nama', $santri->nama)" required />
                <x-field name="jenis_kelamin" label="Jenis Kelamin" :value="old('jenis_kelamin', $santri->jenis_kelamin)" :options="['L' => 'Laki-laki', 'P' => 'Perempuan']" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tempat_lahir" label="Tempat Lahir" :value="old('tempat_lahir')" />
                <x-field name="tanggal_lahir" label="Tanggal Lahir" type="date" :value="old('tanggal_lahir')" />
                <x-field name="nisn" label="NISN" :value="old('nisn')" />
            </div>

            {{-- Jenjang & tingkat menyatu: pilihan tingkat diturunkan dari jumlah
                 tingkat jenjang yang dipilih (SDTQ 6, SMP 3, SMA 3 — diatur di
                 master Jenjang, bukan dipaku di sini). --}}
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenjang <span class="text-red-500">*</span></label>
                    <select name="kode_jenjang" x-model="jenjang" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih jenjang —</option>
                        @foreach (\App\Support\Referensi::jenjang() as $kodeJenjang => $namaJenjang)
                            <option value="{{ $kodeJenjang }}">{{ $namaJenjang }}</option>
                        @endforeach
                    </select>
                    @error('kode_jenjang')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tingkat <span class="text-red-500">*</span></label>
                    <select name="tingkat" x-model="tingkat" required :disabled="!jenjang"
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100">
                        <option value="">— pilih tingkat —</option>
                        <template x-for="i in tingkatOpsi" :key="i">
                            <option :value="i" x-text="'Tingkat ' + i" :selected="String(i) === tingkat"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-xs text-gray-400" x-show="!jenjang" x-cloak>Pilih jenjang dulu.</p>
                    <p class="mt-1 text-xs text-amber-600" x-show="jenjang && jumlah === 0" x-cloak>
                        Jumlah tingkat jenjang ini belum diisi di Setting Awal → Jenjang Pendidikan.
                    </p>
                    @error('tingkat')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-field name="asal_sekolah" label="Asal Sekolah" :value="old('asal_sekolah')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="alamat_sekolah_asal" label="Alamat Sekolah Asal" :value="old('alamat_sekolah_asal')" textarea />
                <x-field name="kepala_sekolah_asal" label="Nama Kepala Sekolah Asal" :value="old('kepala_sekolah_asal')" />
                <x-field name="cp_kepala_sekolah_asal" label="CP Kepala Sekolah Asal" :value="old('cp_kepala_sekolah_asal')" placeholder="mis. 0812xxxxxxx" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <select name="tahun_ajaran" x-model="ta" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih tahun ajaran —</option>
                        @foreach ($taOptions as $kodeTa)
                            <option value="{{ $kodeTa }}" @selected(old('tahun_ajaran', $taDefault ?? '') === $kodeTa)>{{ $kodeTa }}{{ $kodeTa === ($taDefault ?? null) ? ' (default)' : '' }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Menentukan jenis biaya, potongan gelombang, dan target yang berlaku.</p>
                </div>
                <div>
                    {{-- Jalur berlaku lintas tahun ajaran, TAPI bisa dinonaktifkan
                         untuk (T.A, jenjang) tertentu — mis. SDTQ tak punya jalur
                         OSS. Seluruh pilihan tetap DIRENDER di server lalu yang tak
                         berlaku di-disable & disembunyikan Alpine; kalau dirender
                         Alpine sepenuhnya, isi dropdown tak bisa diperiksa test
                         maupun tampil saat JavaScript mati. --}}
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jalur <span class="text-red-500">*</span></label>
                    <select name="jalur" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih jalur —</option>
                        @forelse ($jalurOptions as $kodeJalur => $namaJalur)
                            <option value="{{ $kodeJalur }}" @selected(old('jalur') === (string) $kodeJalur) x-bind:disabled="tutup.includes('{{ $kodeJalur }}')" x-bind:hidden="tutup.includes('{{ $kodeJalur }}')">{{ $namaJalur }}</option>
                        @empty
                            <option value="" disabled>— belum ada jalur aktif —</option>
                        @endforelse
                    </select>
                    @if (empty($jalurOptions))
                        <p class="mt-1 text-xs text-gray-400">
                            Tambahkan jalurnya di menu PPSB → Setting Awal → Jalur Pendaftaran (berlaku untuk semua tahun ajaran).
                        </p>
                    @else
                        <p class="mt-1 text-xs text-gray-400" x-show="tutup.length > 0" x-cloak>
                            <span x-text="tutup.length"></span> jalur disembunyikan karena tidak berlaku di jenjang &amp; tahun ajaran ini
                            (diatur di Setting Awal &rarr; Tarif).
                        </p>
                    @endif
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3" x-data="{ sumber: '{{ old('sumber_informasi') }}' }">
                {{-- Gelombang dipilih dari master Potongan Gelombang (kodenya bebas:
                     angka maupun nama), atau "Tanpa Gelombang" untuk pindahan &
                     kasus khusus. Seluruh kode dirender di server lalu disaring per
                     T.A oleh Alpine — supaya isinya tetap bisa diperiksa test dan
                     tetap tampil saat JavaScript mati. --}}
                @php $tanpaGelombang = \App\Http\Controllers\SantriController::TANPA_GELOMBANG; @endphp
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Gelombang <span class="text-red-500">*</span></label>
                    <select name="gelombang" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih gelombang —</option>
                        <option value="{{ $tanpaGelombang }}" @selected(old('gelombang') === $tanpaGelombang)>Tanpa Gelombang</option>
                        @foreach ($gelombangOptions as $kodeTa => $daftar)
                            @foreach ($daftar as $gel)
                                <option value="{{ $gel['kode'] }}" @selected(old('gelombang') === (string) $gel['kode'])
                                        x-bind:hidden="ta !== '{{ $kodeTa }}'" x-bind:disabled="ta !== '{{ $kodeTa }}'">{{ $gel['nama'] }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    @if (empty($gelombangOptions))
                        <p class="mt-1 text-xs text-gray-400">
                            Belum ada gelombang yang sedang berjalan. Tambahkan di menu PPSB &rarr; Gelombang; sementara ini hanya "Tanpa Gelombang" yang bisa dipilih.
                        </p>
                    @else
                        <p class="mt-1 text-xs text-gray-400">
                            "Tanpa Gelombang" untuk pindahan &amp; kasus di luar skema — tidak mendapat potongan.
                        </p>
                    @endif
                    @error('gelombang')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Sumber Informasi</label>
                    {{-- Pilihan dari master Sumber Informasi (PPSB → Setting Awal);
                         isian teks bebas muncul untuk sumber yang ditandai
                         "minta keterangan", bukan lagi khusus kode "lainnya". --}}
                    @php $sumberMaster = \App\Models\SumberInformasi::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get(); @endphp
                    <select name="sumber_informasi" x-model="sumber"
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">—</option>
                        @foreach ($sumberMaster as $s)
                            <option value="{{ $s->kode }}" @selected(old('sumber_informasi') === $s->kode)>{{ $s->nama }}{{ $s->butuh_keterangan ? ' (sebutkan)' : '' }}</option>
                        @endforeach
                    </select>
                    <div x-show="@js($sumberMaster->where('butuh_keterangan', true)->pluck('kode')->values()->all()).includes(sumber)" x-cloak class="mt-2">
                        <input type="text" name="sumber_informasi_lain" value="{{ old('sumber_informasi_lain') }}"
                               placeholder="Sebutkan sumber informasi"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-400">Tagihan registrasi otomatis diterbitkan dari master Jenis Biaya (tipe registrasi) sesuai tahun ajaran &amp; jenjang yang dipilih.</p>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('santri.calon') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Daftarkan</button>
            </div>
        </form>
    </div>
@endsection
