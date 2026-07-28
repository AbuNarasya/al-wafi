@extends('layouts.app')

@php $rt = $lingkup === 'ppsb' ? 'pembayaran_ppsb' : 'pembayaran_kesantrian'; @endphp

@section('title', 'Catat Pembayaran')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route($rt . '.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if ($santriData->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">
                Tidak ada tagihan yang menunggu pembayaran untuk lingkup ini.
            </div>
        @else
            <form method="POST" action="{{ route($rt . '.store') }}" enctype="multipart/form-data" x-data="bayar(@js($santriData))"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf

                {{-- Pemilih santri dengan pencarian bebas: petugas biasanya memegang
                     NOMOR PENDAFTARAN, sedangkan <select> biasa hanya bisa dicari
                     lewat nama dan mengetik cepat. Nomor & NIS ikut dicocokkan. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Santri <span class="text-red-500">*</span></label>
                    <div class="relative" @click.outside="bukaSantri = false" @keydown.escape="bukaSantri = false">
                        <input type="hidden" name="id_santri" :value="santriId">
                        <input type="text" x-model="cari" @focus="bukaSantri = true" @input="bukaSantri = true"
                               placeholder="Ketik nomor pendaftaran, NIS, atau nama…" autocomplete="off"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <div x-show="bukaSantri" x-cloak
                             class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-xl">
                            <template x-for="s in hasilCari()" :key="s.id">
                                <div @click="pilihSantri(s)"
                                     class="cursor-pointer px-3 py-2 text-sm hover:bg-brand-soft"
                                     :class="s.id === santriId ? 'bg-brand-soft font-medium text-brand' : 'text-gray-700'">
                                    <span x-text="s.no_pendaftaran || '—'" class="font-medium"></span>
                                    <span x-text="' — ' + s.nama"></span>
                                    <span class="text-xs text-gray-400"
                                          x-text="(s.jenjang ? ' · ' + s.jenjang : '') + (s.nis ? ' · NIS ' + s.nis : '')"></span>
                                </div>
                            </template>
                            <div x-show="hasilCari().length === 0" class="px-3 py-2 text-sm text-gray-400">
                                Tidak ada santri yang cocok.
                            </div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-400" x-show="!santriId" x-cloak>
                        <span x-text="list.length"></span> santri punya tagihan yang belum lunas.
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tagihan <span class="text-red-500">*</span></label>
                    <select name="id_tagihan" x-model.number="tagihanId" required class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                        <option value="">— pilih tagihan —</option>
                        <template x-for="t in tagihanList" :key="t.id"><option :value="t.id" x-text="t.label"></option></template>
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Nominal <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" min="0" name="nominal" x-model="nominal" :max="maxSisa" required class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-brand" x-show="maxSisa > 0" x-cloak>Sisa tagihan: <span x-text="fmt(maxSisa)"></span></p>
                    </div>
                </div>

                <x-field name="kode_rekening" label="Kas/Rekening Penerima" :value="old('kode_rekening')" :options="$rekeningOptions" required />
                <x-field name="metode" label="Metode" :value="old('metode')" placeholder="Tunai / Transfer BCA / …" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Bukti Transfer</label>
                    <input type="file" name="bukti" accept="image/jpeg,image/png,image/webp,application/pdf"
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-brand-soft file:px-3 file:py-1 file:text-sm file:text-brand">
                    <p class="mt-1 text-xs text-gray-400">JPG, PNG, WebP, atau PDF · maksimal 5 MB. Ini yang diperiksa tim keuangan sebelum memverifikasi — tanpa bukti, verifikasinya bisa tertahan.</p>
                    @error('bukti')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <x-field name="catatan" label="Catatan" :value="old('catatan')" />

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route($rt . '.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Catat Pembayaran</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        function bayar(list) {
            return {
                list, santriId: '', tagihanId: '', nominal: '',
                cari: '', bukaSantri: false,

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
                    this.santriId = s.id;
                    // Kotak pencarian menampilkan pilihannya, jadi jelas siapa yang terpilih.
                    this.cari = (s.no_pendaftaran ? s.no_pendaftaran + ' — ' : '') + s.nama;
                    this.bukaSantri = false;
                },

                get selectedSantri() { return this.list.find(s => s.id === this.santriId) || null; },
                get tagihanList() { return this.selectedSantri?.tagihan || []; },
                get maxSisa() { return this.tagihanList.find(t => t.id === this.tagihanId)?.sisa || 0; },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
                init() {
                    this.$watch('santriId', () => { this.tagihanId = ''; this.nominal = ''; });
                    this.$watch('tagihanId', () => { if (this.maxSisa > 0) this.nominal = this.maxSisa; });
                },
            };
        }
    </script>
@endsection
