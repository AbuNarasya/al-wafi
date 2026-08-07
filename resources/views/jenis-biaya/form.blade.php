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

            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Baris ini hanya menentukan <b>akun &amp; unit bisnis</b>-nya &mdash; diisi sekali, dipakai terus.
                <b>Besarannya tidak di sini</b>: tarif per tahun ajaran, jenjang, dan jalur diatur di
                <a href="{{ route('tarif.index') }}" class="font-semibold underline">Setting Awal &rarr; Tarif</a>.
            </div>

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

            {{-- Jenjang & unit berlaku untuk SEMUA perilaku, termasuk lain-lain:
                 keduanya dimensi yang dipakai memilah laporan. --}}
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

            {{-- Pengakuan mendahului akun: ia yang menentukan apakah akun piutang
                 wajib, bukan sebaliknya. Sebelum kolom ini ada, sifat akrual
                 ditebak dari terisinya akun piutang — sehingga mengisi akun
                 "supaya lengkap" diam-diam mengubah kapan pendapatan diakui. --}}
            <fieldset class="rounded-lg border border-gray-200 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-700">Pengakuan &amp; Akun</legend>
                <div class="space-y-3">
                    <x-field name="pengakuan" label="Pengakuan" required
                             :value="$jb->pengakuan ?? 'kas'"
                             :options="[
                                 'kas' => 'Diakui saat DIBAYAR — belum ada jurnal saat tagihan terbit',
                                 'akrual' => 'Diakui saat DITAGIHKAN — piutang langsung masuk buku besar',
                             ]" />
                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
                        <b>Saat ditagihkan:</b> tagihannya <b>tidak bisa dibatalkan</b> begitu terbit — kekeliruan
                        diperbaiki lewat Koreksi Nominal Tagihan, yang menerbitkan jurnal penyesuaian.<br>
                        <b>Saat dibayar:</b> tagihannya boleh dibatalkan selama belum ada pembayaran, karena tak ada
                        jurnal yang perlu dibalik.
                    </div>

                    <x-field name="kode_coa_pendapatan" label="Akun Pendapatan" :value="$jb->kode_coa_pendapatan" :options="$coaWajib" required />
                    <x-field name="kode_coa_piutang" label="Akun Piutang" :value="$jb->kode_coa_piutang" :options="$coaOpsional"
                             hint="Wajib diisi bila pengakuannya “saat ditagihkan” — ke sanalah piutangnya dibukukan. Untuk pengakuan “saat dibayar”, biarkan kosong." />
                </div>
            </fieldset>

            {{-- Hanya perilaku lain-lain. Registrasi, uang pangkal, SPP & daftar
                 ulang punya alur penagihannya masing-masing. --}}
            <div x-show="tipe === 'lain'" x-cloak>
                <x-field name="cara_tagih" label="Cara Menagih" :value="$jb->cara_tagih"
                         :options="[
                             '' => '— pilih —',
                             'kepesertaan' => 'Menurut kepesertaan — hanya santri yang ikut, tarif per jenjang',
                             'pemakaian' => 'Menurut pemakaian — tarif per satuan dikali kuantitas',
                         ]"
                         hint="Kepesertaan untuk ekskul, kegiatan khusus, program umroh. Pemakaian untuk layanan bersatuan seperti laundry per kilogram." />
            </div>

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
