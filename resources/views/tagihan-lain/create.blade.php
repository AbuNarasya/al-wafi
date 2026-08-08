@extends('layouts.app')

@section('title', 'Terbitkan Tagihan Lain')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('tagihan_lain.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        {{-- Pemilih jenis berdiri SENDIRI di form GET: daftar santri di bawahnya
             disusun server mengikuti jenis yang dipilih, jadi halamannya memang
             perlu dimuat ulang. Pola yang sama dipakai Peserta Kegiatan &
             Potongan Gelombang. --}}
        <form method="GET" class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <label class="mb-1 block text-sm font-medium text-gray-700">
                Jenis Biaya (tipe Lain-lain) <span class="text-red-500">*</span>
            </label>
            <select name="jenis" onchange="this.form.submit()"
                    class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                <option value="">— pilih jenis —</option>
                @foreach ($jenisOptions as $kode => $label)
                    <option value="{{ $kode }}" @selected($kode === $kodeJenis)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($sumberDaftar)
                <p class="mt-1 text-xs text-gray-400">{{ $sumberDaftar }}</p>
            @endif
            <noscript><button class="mt-2 rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
        </form>

        @if (! $jenis)
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">
                Pilih jenis biayanya dulu — daftar santri menyesuaikan pilihan itu.
            </div>
        @elseif (empty($santriAktif))
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
                @if ($jenis->cara_tagih === 'kepesertaan')
                    Belum ada peserta terdaftar untuk <b>{{ $jenis->nama }}</b>.
                    <a href="{{ route('tagihan_lain.peserta', ['jenis' => $jenis->kode]) }}" class="font-medium underline">Daftarkan pesertanya dulu</a>.
                @else
                    Tidak ada santri aktif{{ $jenis->kode_jenjang ? ' di jenjang jenis biaya ini' : '' }}.
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('tagihan_lain.store') }}"
                  x-data="{
                      cari: '',
                      semua: false,
                      cocok(el) { return this.cari === '' || el.dataset.cari.includes(this.cari.toLowerCase()); },
                      pilihSemua() {
                          this.$refs.daftar.querySelectorAll('[data-cari]').forEach((el) => {
                              if (this.cocok(el)) { el.querySelector('input').checked = this.semua; }
                          });
                      },
                  }"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf
                <input type="hidden" name="kode_jenis" value="{{ $jenis->kode }}">

                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                    <b>{{ $jenis->kode }} — {{ $jenis->nama }}</b>
                    @if ($jenis->cara_tagih === 'pemakaian')
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">layanan bersatuan</span>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="nominal" label="Nominal per Santri" type="number" :value="old('nominal')" required />
                    <x-field name="periode" label="Periode (opsional)" :value="old('periode')" placeholder="2026-07" />
                    <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                    <x-field name="jatuh_tempo" label="Jatuh Tempo (opsional)" type="date" :value="old('jatuh_tempo')"
                             hint="Dipakai Reminder Tagihan. Dikosongkan berarti tagihan ini tak pernah masuk daftar pengingat." />
                </div>

                {{-- `&` ditulis apa adanya: isi `hint` dicetak lewat {{ }} yang
                     sudah meng-escape sendiri, jadi menulis `&amp;` di sini
                     membuatnya ter-escape dua kali dan tercetak "&amp;". --}}
                <x-field name="keterangan" label="Keterangan (opsional)" :value="old('keterangan')"
                         placeholder="Seragam olahraga Agustus 2026"
                         hint="Tampil di tagihan & kuitansi wali. Dikosongkan berarti memakai nama jenis biayanya." />

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <label class="text-sm font-medium text-gray-700">Santri ({{ count($santriAktif) }})</label>
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            <input type="checkbox" x-model="semua" @change="pilihSemua()" class="rounded border-gray-300">
                            Pilih semua yang tampil
                        </label>
                    </div>

                    <input type="search" x-model="cari" placeholder="Cari nama / NIS…"
                           class="mb-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">

                    <div x-ref="daftar" class="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-3">
                        @foreach ($santriAktif as $idSantri => $label)
                            {{-- Teks pencarian disiapkan di server dalam huruf kecil:
                                 menormalkannya di peramban tiap ketikan berarti
                                 ratusan pemanggilan toLowerCase per huruf. --}}
                            <label data-cari="{{ mb_strtolower($label) }}" x-show="cocok($el)"
                                   class="flex items-center gap-2 text-sm">
                                <input type="checkbox" class="santri-cb rounded border-gray-300 text-brand"
                                       name="id_santri[]" value="{{ $idSantri }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('id_santri')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('tagihan_lain.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan</button>
                </div>
            </form>
        @endif
    </div>
@endsection
