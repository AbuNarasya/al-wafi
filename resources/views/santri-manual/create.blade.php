@extends('layouts.app')

@section('title', 'Input Manual Santri Aktif')

@php
    // Jenis biaya dikirim ke Alpine supaya tiap baris bisa memberi tahu sendiri
    // perlakuan mana yang BELUM bisa dipakai — akun piutang/pendapatan yang
    // kosong ditolak service, dan lebih baik ketahuan sebelum tombol ditekan.
    $jenisJson = $opsiJenisBiaya->mapWithKeys(fn ($j) => [$j->kode => [
        'nama' => $j->nama,
        'piutang' => (bool) $j->kode_coa_piutang,
        'pendapatan' => (bool) $j->kode_coa_pendapatan,
    ]])->all();
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Input Manual Santri Aktif</h2>
        <p class="mt-1 text-sm text-gray-500">
            Untuk santri yang <b>sudah bersekolah</b> sebelum aplikasi ini dipakai — versi satuan dari
            <b>Impor Data Awal → Santri Lama</b>, bagi satu-dua orang susulan yang tak sepadan dibuatkan berkas.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif
    @if ($errors->any())
        <div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Akibat yang paling penting disebut PALING ATAS, bukan disembunyikan di
         bawah tombol. --}}
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-900">
        <b>Melompati seluruh alur PPSB.</b> Santri lahir langsung berstatus <b>Aktif</b> — tanpa baris pendaftaran,
        tanpa tagihan registrasi, dan tanpa potongan gelombang. Jangan dipakai untuk pendaftar baru; mereka lewat
        <b>PPSB → Pendaftaran</b> agar registrasi dan gelombangnya terhitung benar.
    </div>

    <form method="POST" action="{{ route('santri_manual.store') }}" class="space-y-5"
          x-data="santriManual(@js($jenisJson), @js(old('tagihan', [])), @js((bool) old('id_wali')))">
        @csrf

        {{-- ── Identitas santri ─────────────────────────────────────────── --}}
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-700">Identitas Santri</h3>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="nis" label="NIS" :value="old('nis')" required hint="NIS aslinya dari sekolah/sistem lama." />
                <div class="sm:col-span-2">
                    <x-field name="nama" label="Nama Lengkap" :value="old('nama')" required />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-field name="jenis_kelamin" label="Jenis Kelamin" :value="old('jenis_kelamin')" :options="['' => '— pilih —', 'L' => 'Laki-laki', 'P' => 'Perempuan']" required />
                <x-field name="tempat_lahir" label="Tempat Lahir" :value="old('tempat_lahir')" />
                <x-field name="tanggal_lahir" label="Tanggal Lahir" type="date" :value="old('tanggal_lahir')" />
                <x-field name="nisn" label="NISN" :value="old('nisn')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-field name="kode_jenjang" label="Jenjang" :value="old('kode_jenjang')" :options="['' => '— pilih —'] + $opsiJenjang" required />
                <x-field name="tingkat" label="Tingkat" type="number" :value="old('tingkat')" required />
                <x-field name="jalur" label="Jalur" :value="old('jalur')" :options="['' => '— pilih —'] + $opsiJalur" required />
                <x-field name="tahun_ajaran" label="T.A Masuk (angkatan)" :value="old('tahun_ajaran')" :options="['' => '— pilih —'] + array_combine($opsiTahunAjaran, $opsiTahunAjaran)" required
                         hint="Tahun ia MASUK, bukan tahun berjalan." />
            </div>

            {{-- Dua tahun ajaran yang berbeda artinya, dan itu sumber kekeliruan
                 yang sudah pernah dibayar mahal: `tahun_ajaran` tak pernah maju,
                 `tahun_ajaran_berjalan` maju tiap kenaikan. --}}
            <x-field name="tahun_ajaran_berjalan" label="T.A Berjalan" :value="old('tahun_ajaran_berjalan')"
                     :options="['' => '— sama dengan T.A masuk —'] + array_combine($opsiTahunAjaran, $opsiTahunAjaran)"
                     hint="Tahun yang sedang DIJALANI. Kosongkan bila sama dengan T.A masuk. Tanpa ini pencarian tarif SPP-nya buntu." />
        </div>

        {{-- ── Wali ─────────────────────────────────────────────────────── --}}
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-700">Wali</h3>
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" x-model="waliBaru" class="rounded border-gray-300 text-brand focus:ring-brand">
                    Walinya belum terdaftar — buat sekalian di sini
                </label>
            </div>

            <div x-show="! waliBaru">
                <label class="mb-1 block text-xs font-medium text-gray-600">Pilih wali yang sudah ada</label>
                <x-search-select name="id_wali" :options="$opsiWali" placeholder="— cari nama atau telepon wali —" />
                {{-- Anak kedua dari keluarga yang sama HARUS menumpang wali yang
                     sudah ada; wali kembar tak punya modul penyatuan. --}}
                <p class="mt-1 text-xs text-gray-500">
                    Untuk adik/kakak dari keluarga yang sudah terdaftar, <b>pilih wali yang sama</b> — jangan buat baru.
                </p>
            </div>

            <div x-show="waliBaru" x-cloak class="space-y-4">
                {{-- Nama & telepon wali TIDAK diisi langsung: WaliService menyalinnya
                     dari peran yang ditunjuk Kontak Utama. Jadi peran yang dipilih
                     itulah yang nama & teleponnya wajib terisi. --}}
                <x-field name="wali_baru[kontak_utama]" label="Kontak Utama" :value="old('wali_baru.kontak_utama', 'ayah')" :options="['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali (bukan orang tua)']"
                         hint="Nama & telepon wali diambil dari peran ini — jadi baris peran inilah yang wajib diisi." />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="wali_baru[nama_ayah]" label="Nama Ayah" :value="old('wali_baru.nama_ayah')" />
                    <x-field name="wali_baru[telepon_ayah]" label="Telepon Ayah" :value="old('wali_baru.telepon_ayah')" />
                    <x-field name="wali_baru[nama_ibu]" label="Nama Ibu" :value="old('wali_baru.nama_ibu')" />
                    <x-field name="wali_baru[telepon_ibu]" label="Telepon Ibu" :value="old('wali_baru.telepon_ibu')" />
                    <x-field name="wali_baru[nama_wali]" label="Nama Wali (bukan orang tua)" :value="old('wali_baru.nama_wali')" />
                    <x-field name="wali_baru[telepon_wali]" label="Telepon Wali" :value="old('wali_baru.telepon_wali')" />
                </div>
                <x-field name="wali_baru[alamat]" label="Alamat" :value="old('wali_baru.alamat')" textarea />
                <p class="text-xs text-gray-500">
                    Nomor telepon dipakai sebagai penanda unik wali — nomor yang sudah terdaftar akan ditolak,
                    dan itu memang penjaga terhadap wali kembar.
                </p>
            </div>
        </div>

        {{-- ── Tagihan outstanding ──────────────────────────────────────── --}}
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700">Tagihan Outstanding</h3>
                    <p class="text-xs text-gray-500">Kewajiban yang santri ini bawa. Boleh dikosongkan.</p>
                </div>
                <button type="button" @click="tambah()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">+ Baris</button>
            </div>

            {{-- Tiga perlakuan posting, dijelaskan SEKALI di sini dan diulang
                 ringkas per baris. Salah pilih tak membuat aplikasi gagal — ia
                 membuat laporan laba rugi salah, dan itu baru ketahuan saat
                 rekonsiliasi. --}}
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-slate-50 p-3">
                <table class="min-w-full text-xs">
                    <thead class="text-left text-gray-500">
                        <tr><th class="pr-4 pb-1">Perlakuan</th><th class="pr-4 pb-1">Jurnal saat dicatat</th><th class="pr-4 pb-1">Saat dibayar mengkredit</th><th class="pb-1">Dipakai bila</th></tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <tr class="border-t border-gray-200">
                            <td class="py-1 pr-4 font-semibold text-sky-700">Saldo awal</td>
                            <td class="py-1 pr-4">tidak ada</td><td class="py-1 pr-4">Piutang</td>
                            <td class="py-1">kewajibannya sudah diakui di pembukuan lama; masuk neraca lewat menu Saldo Awal</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="py-1 pr-4 font-semibold text-emerald-700">Akrual sekarang</td>
                            <td class="py-1 pr-4">D Piutang / K Pendapatan</td><td class="py-1 pr-4">Piutang</td>
                            <td class="py-1">jasanya baru diberikan di bawah aplikasi ini — jadi pendapatan periode berjalan</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="py-1 pr-4 font-semibold text-gray-700">Kas basis</td>
                            <td class="py-1 pr-4">tidak ada</td><td class="py-1 pr-4">Pendapatan</td>
                            <td class="py-1">belum diakui sama sekali; pendapatannya menunggu uangnya datang</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template x-if="rows.length === 0">
                <p class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-400">
                    Belum ada tagihan. Klik <b>+ Baris</b> bila santri ini membawa kewajiban yang belum lunas.
                </p>
            </template>

            <template x-for="(row, i) in rows" :key="i">
                <div class="space-y-2 rounded-lg border border-gray-200 p-3">
                    <div class="grid grid-cols-12 items-end gap-2">
                        <div class="col-span-12 sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Jenis Biaya</label>
                            <select :name="`tagihan[${i}][kode_jenis]`" x-model="row.kode_jenis" class="w-full rounded border-gray-300 px-2 py-1.5 text-sm">
                                <option value="">— pilih —</option>
                                @foreach ($opsiJenisBiaya as $j)
                                    <option value="{{ $j->kode }}">{{ $j->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-6 sm:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Nominal</label>
                            {{-- Baris berulang: komponen x-input-rupiah TIDAK dipakai —
                                 nilainya dipegang Alpine induk. --}}
                            <input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)" @input="row.nominal = ketikRupiah($event)"
                                   placeholder="mis. 1500000"
                                   class="w-full rounded border-gray-300 px-2 py-1.5 text-right text-sm tabular-nums">
                            <input type="hidden" :name="`tagihan[${i}][nominal]`" :value="row.nominal">
                        </div>
                        <div class="col-span-6 sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Perlakuan Posting</label>
                            <select :name="`tagihan[${i}][posting]`" x-model="row.posting" class="w-full rounded border-gray-300 px-2 py-1.5 text-sm">
                                <option value="saldo_awal">Saldo awal — tanpa jurnal</option>
                                <option value="akrual">Akrual sekarang — terbitkan jurnal</option>
                                <option value="kas">Kas basis — diakui saat dibayar</option>
                            </select>
                        </div>
                        <div class="col-span-12 text-right sm:col-span-1">
                            <button type="button" @click="hapus(i)" class="text-red-500 hover:text-red-700" title="Hapus baris">&times;</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 items-end gap-2">
                        <div class="col-span-12 sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-gray-600">T.A Tagihan</label>
                            <select :name="`tagihan[${i}][tahun_ajaran]`" x-model="row.tahun_ajaran" class="w-full rounded border-gray-300 px-2 py-1.5 text-sm">
                                <option value="">— ikut T.A masuk —</option>
                                @foreach ($opsiTahunAjaran as $t)
                                    <option value="{{ $t }}">{{ $t }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-6 sm:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Jatuh Tempo</label>
                            <input type="date" :name="`tagihan[${i}][jatuh_tempo]`" x-model="row.jatuh_tempo" class="w-full rounded border-gray-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="col-span-6 sm:col-span-5">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label>
                            <input type="text" :name="`tagihan[${i}][keterangan]`" x-model="row.keterangan" maxlength="255"
                                   placeholder="mis. Tunggakan SPP Jan–Jun 2025" class="w-full rounded border-gray-300 px-2 py-1.5 text-sm">
                        </div>
                    </div>

                    {{-- Peringatan akun kosong muncul SEBELUM tombol ditekan, bukan
                         sebagai penolakan sesudahnya. --}}
                    <p x-show="masalah(row)" x-cloak x-text="masalah(row)"
                       class="rounded bg-amber-50 px-2 py-1.5 text-[11px] leading-relaxed text-amber-800"></p>
                </div>
            </template>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('santri.aktif') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Santri Aktif</button>
        </div>
    </form>

    {{-- ── Yang baru saja dimasukkan lewat pintu ini ────────────────────── --}}
    @if ($terakhir->isNotEmpty())
        <div class="mt-8">
            <h3 class="mb-2 text-sm font-semibold text-gray-700">Terakhir dimasukkan lewat pintu ini</h3>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-3">No.</th><th class="px-4 py-3">NIS</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Wali</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($terakhir as $s)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $s->no_pendaftaran }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $s->nis }}</td>
                                <td class="px-4 py-2"><a href="{{ route('santri.show', $s->id) }}" class="text-brand hover:underline">{{ $s->nama }}</a></td>
                                <td class="px-4 py-2 text-gray-600">{{ $s->wali?->nama ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ ucfirst(str_replace('_', ' ', $s->status)) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if (($halangan[$s->id] ?? []) === [])
                                        <form method="POST" action="{{ route('santri_manual.destroy', $s->id) }}" class="inline"
                                              data-confirm="Hapus {{ $s->nama }} beserta seluruh tagihan, riwayat NIS, dan riwayat tingkatnya? Belum ada yang tersentuh, jadi tak ada jurnal yang dibalik.">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50">Hapus</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400" title="{{ implode(' ', $halangan[$s->id]) }}">terkunci</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <script>
        function santriManual(jenis, lama, adaWali) {
            return {
                jenis,
                waliBaru: ! adaWali && Object.keys(lama).length === 0 ? false : ! adaWali,
                rows: Object.values(lama || {}),
                tambah() {
                    this.rows.push({ kode_jenis: '', nominal: '', posting: 'saldo_awal', tahun_ajaran: '', jatuh_tempo: '', keterangan: '' });
                },
                hapus(i) {
                    this.rows.splice(i, 1);
                },
                /* Akun yang belum lengkap ditunjukkan SEBELUM disimpan — sama
                   dengan yang ditolak service, supaya tak ada kejutan. */
                masalah(row) {
                    const j = this.jenis[row.kode_jenis];
                    if (! j) return '';
                    if (row.posting !== 'kas' && ! j.piutang) {
                        return `"${j.nama}" belum punya akun piutang. Perlakuan Saldo Awal & Akrual selalu dibayar dengan mengkredit piutang — lengkapi dulu di master Jenis Biaya, atau pilih Kas basis.`;
                    }
                    if (row.posting === 'akrual' && ! j.pendapatan) {
                        return `"${j.nama}" belum punya akun pendapatan, sehingga jurnal akrualnya tak punya alamat.`;
                    }
                    return '';
                },
            };
        }
    </script>
@endsection
