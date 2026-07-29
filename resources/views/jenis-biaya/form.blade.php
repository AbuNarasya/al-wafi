@extends('layouts.app')

@section('title', $baru ? 'Tambah Jenis Biaya' : 'Ubah ' . $jb->kode)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('jenis_biaya.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        {{-- Tipe kini dari master. Yang menentukan tampilan form adalah PERILAKU
             tipe, bukan kodenya — tipe buatan sendiri ikut menampilkan isian yang
             sesuai dengan alur yang dipilihnya. --}}
        @php
            $opsiTipe = \App\Models\TipeBiaya::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get();
            $petaPerilaku = $opsiTipe->pluck('perilaku', 'kode')->all();
            $tipeAwal = old('tipe', $jb->tipe ?? $opsiTipe->first()?->kode ?? 'registrasi');
        @endphp
        <form method="POST" action="{{ $baru ? route('jenis_biaya.store') : route('jenis_biaya.update', $jb->kode) }}"
              x-data="{ peta: @js($petaPerilaku), kodeTipe: @js((string) $tipeAwal),
                        get tipe() { return this.peta[this.kodeTipe] ?? 'lain'; } }"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode" label="Kode" :value="$jb->kode" required placeholder="mis. REG-2026, SPP-REGULER" />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label><div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $jb->kode }}</div></div>
                @endif
                <x-field name="nama" label="Nama" :value="$jb->nama" required />
            </div>

            <x-field name="tahun_ajaran" label="Tahun Ajaran" :value="old('tahun_ajaran', $jb->tahun_ajaran)"
                     :options="$taOptions + ($jb->tahun_ajaran ? [$jb->tahun_ajaran => $jb->tahun_ajaran] : [])" required
                     hint="Jenis biaya berlaku untuk tahun ajaran ini (rujukan saat pendaftaran calon santri)." />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Tipe <span class="text-red-500">*</span></label>
                <select name="tipe" x-model="kodeTipe" required
                        class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    @foreach ($opsiTipe as $t)
                        <option value="{{ $t->kode }}">{{ $t->nama }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">
                    Daftar tipe diatur di menu Setting Awal &rarr; Tipe Biaya.
                    @error('tipe')<span class="text-red-600">{{ $message }}</span>@enderror
                </p>
            </div>

            {{-- Registrasi, Uang Pangkal, & SPP memakai nominal + jenjang. Bedanya:
                 registrasi WAJIB (tagihan terbit otomatis saat mendaftar), SPP WAJIB
                 (dipakai saat menerbitkan tagihan SPP per periode), sedangkan uang
                 pangkal hanya nilai DEFAULT yang masih boleh diubah per calon. --}}
            <div class="grid gap-4 sm:grid-cols-2" x-show="tipe !== 'lain'" x-cloak>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700"
                           x-text="{ registrasi: 'Nominal Registrasi', uang_pangkal: 'Nominal Uang Pangkal (default)', perlengkapan: 'Nominal Perlengkapan (default)', spp: 'Tarif SPP per Bulan' }[tipe] ?? 'Nominal'"></label>
                    <input type="number" step="0.01" min="0" name="nominal" value="{{ old('nominal', $jb->nominal) }}"
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    <p class="mt-1 text-xs text-gray-400" x-show="tipe === 'registrasi'" x-cloak>
                        Nominal biaya registrasi (tagihannya terbit otomatis saat calon mendaftar).
                    </p>
                    <p class="mt-1 text-xs text-gray-400" x-show="tipe === 'uang_pangkal'" x-cloak>
                        Mengisi otomatis form &ldquo;Tagihkan Uang Pangkal&rdquo; dan <b>tetap bisa diubah</b> per calon.
                        Kosongkan bila nominalnya selalu diketik manual.
                    </p>
                    <p class="mt-1 text-xs text-gray-400" x-show="tipe === 'perlengkapan'" x-cloak>
                        Mengisi otomatis isian &ldquo;Biaya Perlengkapan&rdquo; pada form Tagihkan Uang Pangkal dan <b>tetap bisa diubah</b> per calon.
                        Perlengkapan <b>tidak dipotong potongan gelombang</b>.
                    </p>
                    <p class="mt-1 text-xs text-gray-400" x-show="tipe === 'spp'" x-cloak>
                        Tarif SPP jenjang ini, dipakai saat menerbitkan tagihan SPP tiap periode.
                        Nominal khusus per santri (beasiswa/keringanan) tetap mengalahkan tarif ini.
                    </p>
                    @error('nominal')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-field name="kode_jalur" label="Jalur Pendaftaran" :value="$jb->kode_jalur"
                         :options="\App\Support\Referensi::withEmpty(\App\Support\Referensi::jalur(), '— Semua jalur (tarif dasar) —')"
                         hint="Isi HANYA untuk tarif pengecualian satu jalur (mis. uang pangkal SMP jalur OSS). Kosong = tarif dasar yang dipakai semua jalur yang tak punya baris sendiri." />
            </div>
            <p class="-mt-2 text-xs text-gray-400" x-show="tipe !== 'lain'" x-cloak>
                Tarif seorang santri dicari dari yang paling khusus: jenjang + jalur &rarr; jalur saja &rarr; jenjang saja &rarr; umum.
                Dua baris aktif dengan cakupan sama akan ditolak, karena program tak bisa tahu mana yang benar.
            </p>

            {{-- Jenjang & unit berlaku untuk SEMUA perilaku, termasuk lain-lain:
                 keduanya dimensi yang dipakai memilah laporan, bukan sekadar
                 bahan pencarian tarif. Karena itu keduanya di LUAR blok yang
                 disembunyikan saat perilaku "lain". --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_jenjang" label="Jenjang" :value="$jb->kode_jenjang"
                         :options="\App\Support\Referensi::withEmpty(\App\Support\Referensi::jenjang(), '— UMUM (semua jenjang) —')"
                         hint="Kosongkan bila berlaku untuk semua jenjang." />
                <x-field name="kode_unit" label="Unit Bisnis" :value="$jb->kode_unit" :options="$unitOptions" required
                         hint="Unit penanggung jurnal pembayaran biaya ini; menentukan laba rugi unit mana yang menerimanya." />
            </div>
            <p class="-mt-2 text-xs text-gray-400" x-show="tipe === 'lain'" x-cloak>
                Untuk tagihan lain-lain, jenjang tidak ikut menentukan tarif (nominalnya diketik saat menagih),
                tetapi tetap berguna untuk memilah laporan dan membedakan jenis yang namanya mirip antar jenjang.
            </p>

            <fieldset class="rounded-lg border border-gray-200 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-700">Akun Akuntansi</legend>
                <div class="space-y-3">
                    <x-field name="kode_coa_pendapatan" label="Akun Pendapatan" :value="$jb->kode_coa_pendapatan" :options="$coaWajib" required />
                    <x-field name="kode_coa_piutang" label="Akun Piutang" :value="$jb->kode_coa_piutang" :options="$coaOpsional"
                             hint="Kosongkan untuk CASH BASIS — pendapatan diakui saat uang diterima, tanpa piutang. Registrasi wajib kosong. Isi hanya untuk biaya yang ditagihkan lebih dulu (SPP, uang pangkal santri aktif)." />
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="berulang" value="0">
                    <input type="checkbox" name="berulang" value="1" @checked(old('berulang', $jb->berulang))
                           class="rounded border-gray-300 text-brand focus:ring-brand">
                    Berulang tiap periode (centang untuk SPP)
                </label>
                <x-field name="status" label="Status" :value="$jb->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('jenis_biaya.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
