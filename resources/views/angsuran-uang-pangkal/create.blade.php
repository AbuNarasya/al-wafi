@extends('layouts.app')

@section('title', 'Rencana Angsuran Baru')

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('angsuran_uang_pangkal.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if ($santriData->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">
                Tidak ada tagihan uang pangkal atau biaya perlengkapan yang belum punya rencana angsuran aktif.
            </div>
        @else
            <form method="POST" action="{{ route('angsuran_uang_pangkal.store') }}" x-data="angsuran(@js($santriData))"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf

                {{-- SATU BARIS PER SANTRI: uang pangkal & perlengkapan dijadwalkan
                     sekaligus di form ini, masing-masing dengan tabel terminnya.
                     Pemilihnya bercari (nomor pendaftaran / NIS / nama) mengikuti
                     pola form catat pembayaran — petugas biasanya memegang NOMOR,
                     sedangkan <select> polos hanya bisa dicari lewat nama. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Santri <span class="text-red-500">*</span></label>
                    <div class="relative" @click.outside="buka = false" @keydown.escape="buka = false">
                        <input type="hidden" name="id_santri" :value="idSantri">
                        <input type="text" x-model="cari" @focus="buka = true" @input="buka = true"
                               placeholder="Ketik nomor pendaftaran, NIS, atau nama…" autocomplete="off"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <div x-show="buka" x-cloak
                             class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-xl">
                            <template x-for="s in hasilCari()" :key="s.id_santri">
                                <div @click="pilihSantri(s)"
                                     class="cursor-pointer px-3 py-2 text-sm hover:bg-brand-soft"
                                     :class="s.id_santri === idSantri ? 'bg-brand-soft font-medium text-brand' : 'text-gray-700'">
                                    <div>
                                        <span x-text="s.no_pendaftaran || '—'" class="font-medium"></span>
                                        <span x-text="' — ' + s.nama"></span>
                                        <span class="text-xs text-gray-400" x-text="(s.jenjang ? ' · ' + s.jenjang : '') + (s.nis ? ' · NIS ' + s.nis : '')"></span>
                                    </div>
                                    {{-- Outstanding-nya ikut terlihat sebelum dipilih. --}}
                                    <div class="text-xs text-gray-500">
                                        Belum terjadwal: <span class="font-mono" x-text="fmt(totalBelumTerjadwal(s))"></span>
                                        <span x-text="rincianKomponen(s)"></span>
                                    </div>
                                </div>
                            </template>
                            <div x-show="hasilCari().length === 0" class="px-3 py-2 text-sm text-gray-400">
                                Tidak ada santri yang cocok.
                            </div>
                        </div>
                    </div>

                    <p class="mt-1 text-xs text-gray-400" x-show="!idSantri" x-cloak>
                        <span x-text="list.length"></span> santri masih punya komponen yang belum dijadwalkan.
                        Uang pangkal dan biaya perlengkapan dijadwalkan terpisah, tetapi dibuat dalam satu kali simpan.
                    </p>
                    <p class="mt-1 text-xs text-brand" x-show="selected" x-cloak>
                        Total yang dijadwalkan: <b class="font-mono" x-text="fmt(totalBelumTerjadwal(selected))"></b>
                        <span x-text="rincianKomponen(selected)"></span>
                        <span x-show="sisaBelumDibayar() !== totalBelumTerjadwal(selected)" x-cloak>
                            · sisa belum dibayar <b class="font-mono" x-text="fmt(sisaBelumDibayar())"></b>
                        </span>
                    </p>
                </div>

                <x-field name="disepakati_pada" label="Disepakati Pada" type="date" :value="old('disepakati_pada', now()->toDateString())" required />

                <template x-if="selected && tenggat" >
                    <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-2.5 text-xs text-amber-800">
                        Potongan gelombang masih <b>berlaku</b>: wali harus menyetor
                        <b x-text="`≥ ${selected?.syarat_persen ?? 50}%`"></b> (<b x-text="fmt(ambang)"></b>)
                        paling lambat <b x-text="tglIndo(tenggat)"></b>.
                        Termin <b>pertama uang pangkal</b> sudah diisikan mengikuti kebijakan itu — nominal maupun tanggalnya
                        <b>masih boleh diubah</b>, tetapi setoran yang mencapai ambang setelah tanggal tersebut membuat potongannya hangus.
                    </div>
                </template>

                {{-- Dua blok terpisah. Blok yang komponennya tak ada atau sudah punya
                     rencana aktif tidak dirender sama sekali, jadi tak ada termin
                     yang ikut terkirim untuknya. --}}
                @foreach ($komponen as $kunci => $label)
                    <template x-if="aktif('{{ $kunci }}')">
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-800">Termin {{ $label }}</h3>
                                <span class="text-xs text-gray-500">Total <span class="font-mono" x-text="fmt(total('{{ $kunci }}'))"></span></span>
                            </div>

                            <div class="overflow-x-auto rounded-lg border border-gray-200">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-2 py-2">Termin</th><th class="px-2 py-2">Jatuh Tempo</th><th class="px-2 py-2">Keterangan</th><th class="px-2 py-2 text-right">Nominal</th><th></th></tr></thead>
                                    <tbody>
                                        <template x-for="(row, i) in rows['{{ $kunci }}']" :key="i">
                                            <tr class="border-t border-gray-100">
                                                <td class="px-2 py-1.5" x-text="i + 1"></td>
                                                <td class="px-2 py-1.5"><input type="date" :name="`termin_{{ $kunci }}[${i}][jatuh_tempo]`" x-model="row.jatuh_tempo" required class="rounded border-gray-300 text-sm"></td>
                                                <td class="px-2 py-1.5"><input type="text" :name="`termin_{{ $kunci }}[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border-gray-300 text-sm"></td>
                                                <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" :name="`termin_{{ $kunci }}[${i}][nominal]`" x-model="row.nominal" required class="w-32 rounded border-gray-300 text-right text-sm"></td>
                                                <td class="px-2 py-1.5 text-center"><button type="button" @click="hapus('{{ $kunci }}', i)" x-show="rows['{{ $kunci }}'].length > 1" class="text-red-500">&times;</button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                    <tfoot class="bg-gray-50">
                                        <tr class="border-t border-gray-200 font-medium">
                                            <td class="px-2 py-2" colspan="3"><button type="button" @click="tambah('{{ $kunci }}')" class="text-sm text-brand hover:underline">+ Tambah termin</button></td>
                                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(jumlah('{{ $kunci }}'))"></td><td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="mt-2 text-sm">
                                <span x-show="cocok('{{ $kunci }}')" class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">✓ Jumlah termin cocok dengan total</span>
                                <span x-show="!cocok('{{ $kunci }}')" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700"
                                      x-text="'Selisih: ' + fmt(jumlah('{{ $kunci }}') - total('{{ $kunci }}'))"></span>
                            </div>
                        </div>
                    </template>
                @endforeach

                {{-- Uang pangkal harus selesai lebih dulu; servicenya menolak bila
                     dilanggar, tetapi lebih baik ketahuan sebelum tombol ditekan. --}}
                <template x-if="urutanSalah()">
                    <p class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs text-rose-700">
                        Termin uang pangkal harus <b>selesai lebih dulu</b> daripada termin biaya perlengkapan.
                        Termin uang pangkal terakhir <b x-text="tglIndo(akhirUp())"></b>, sedangkan perlengkapan dimulai <b x-text="tglIndo(awalPerlengkapan())"></b>.
                    </p>
                </template>

                <template x-if="selected && sudahTerjadwal().length">
                    <p class="text-xs text-gray-500">
                        Sudah punya rencana aktif: <b x-text="sudahTerjadwal().join(', ')"></b>.
                        Ubah jadwalnya lewat Re-negosiasi di halaman detail santri ini.
                    </p>
                </template>

                <x-field name="catatan" label="Catatan" :value="old('catatan')" textarea />

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('angsuran_uang_pangkal.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button type="submit" :disabled="!bolehSimpan()"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Simpan Rencana</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        function angsuran(list) {
            return {
                list,
                idSantri: '',
                cari: '', buka: false,
                rows: { uang_pangkal: [], perlengkapan: [] },

                /** Pencarian bebas: nomor pendaftaran, NIS, atau nama — tak peduli urutan kata. */
                hasilCari() {
                    const q = this.cari.trim().toLowerCase();
                    if (q === '') return this.list;
                    return this.list.filter((s) => {
                        const teks = [s.no_pendaftaran, s.nis, s.nama, s.jenjang].filter(Boolean).join(' ').toLowerCase();
                        return q.split(/\s+/).every((kata) => teks.includes(kata));
                    });
                },
                pilihSantri(s) {
                    this.idSantri = s.id_santri;
                    this.cari = (s.no_pendaftaran ? s.no_pendaftaran + ' — ' : '') + s.nama;
                    this.buka = false;
                },

                /** Komponen yang masih perlu dijadwalkan — itulah yang akan diisi terminnya. */
                belumTerjadwal(s) {
                    return Object.entries(s?.komponen || {}).filter(([, c]) => !c.punya_rencana);
                },
                totalBelumTerjadwal(s) {
                    return this.belumTerjadwal(s).reduce((t, [, c]) => t + (c.total || 0), 0);
                },
                sisaBelumDibayar() {
                    return this.belumTerjadwal(this.selected).reduce((t, [, c]) => t + (c.sisa || 0), 0);
                },
                rincianKomponen(s) {
                    const bagian = this.belumTerjadwal(s).map(([, c]) => `${c.label.toLowerCase()} ${this.fmt(c.total)}`);
                    return bagian.length > 1 ? ` (${bagian.join(' + ')})` : '';
                },

                get selected() { return this.list.find(s => s.id_santri === this.idSantri) || null; },
                get tenggat() { return this.selected?.tenggat_potongan || ''; },
                get ambang() { return this.selected?.ambang_potongan || 0; },

                /** Komponen yang benar-benar bisa dijadwalkan sekarang. */
                aktif(k) {
                    const c = this.selected?.komponen?.[k];
                    return !!c && !c.punya_rencana;
                },
                total(k) { return this.selected?.komponen?.[k]?.total || 0; },
                jumlah(k) { return this.rows[k].reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                cocok(k) { return Math.abs(this.jumlah(k) - this.total(k)) < 0.005; },

                sudahTerjadwal() {
                    return Object.values(this.selected?.komponen || {}).filter(c => c.punya_rencana).map(c => c.label);
                },

                baris(k) {
                    // Termin PERTAMA uang pangkal ditawarkan mengikuti kebijakan potongan
                    // gelombang: jatuh tempo = tenggatnya, nominal = ambang syarat 50%.
                    // Keduanya tetap bisa diubah petugas — ini tawaran, bukan kunci.
                    const pertamaUp = k === 'uang_pangkal' && this.rows[k].length === 0 && !!this.tenggat;
                    return {
                        jatuh_tempo: pertamaUp ? this.tenggat : '',
                        keterangan: pertamaUp ? `Setoran ${this.selected?.syarat_persen ?? 50}% penjaga potongan gelombang` : '',
                        nominal: pertamaUp && this.ambang ? String(this.ambang) : '',
                    };
                },
                tambah(k) { this.rows[k].push(this.baris(k)); },
                hapus(k, i) { if (this.rows[k].length > 1) this.rows[k].splice(i, 1); },

                tanggal(k) { return this.rows[k].map(r => r.jatuh_tempo).filter(Boolean).sort(); },
                akhirUp() { const t = this.tanggal('uang_pangkal'); return t[t.length - 1] || ''; },
                awalPerlengkapan() { return this.tanggal('perlengkapan')[0] || ''; },
                urutanSalah() {
                    if (!this.aktif('uang_pangkal') || !this.aktif('perlengkapan')) return false;
                    const a = this.akhirUp(), b = this.awalPerlengkapan();
                    return !!a && !!b && a >= b;
                },

                bolehSimpan() {
                    const dipakai = ['uang_pangkal', 'perlengkapan'].filter(k => this.aktif(k));
                    return dipakai.length > 0 && dipakai.every(k => this.cocok(k)) && !this.urutanSalah();
                },

                fmt(n) { return 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID'); },
                tglIndo(v) { return v ? new Date(v + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; },

                init() {
                    // Ganti santri → tabel disusun ulang sesuai komponen yang tersedia.
                    this.$watch('idSantri', () => {
                        for (const k of ['uang_pangkal', 'perlengkapan']) {
                            this.rows[k] = [];
                            if (this.aktif(k)) this.rows[k].push(this.baris(k));
                        }
                    });
                },
            };
        }
    </script>
@endsection
