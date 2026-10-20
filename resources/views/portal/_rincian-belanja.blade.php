{{--
  Lembar rincian belanja kartu (daftar barang di balik satu mutasi `jajan`). Diletakkan sekali per halaman;
  baris mutasi memicunya lewat  @click="$dispatch('rincian-belanja', '<alamat rincian>')".
  Isinya dari PortalKeluargaController::rincianMutasi (bentuk RincianBelanja).
--}}
<div x-data="{
        terbuka: false, memuat: false, galat: '', d: null,
        rp(n) { return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID') },
        angka(n) { return Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 }) },
        jam(w) { return w ? new Date(w).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '' },
        judul() {
            const j = this.d?.rincian?.jenis;
            return j === 'retur' ? 'Barang yang diretur' : (j === 'batal' ? 'Struk dibatalkan' : 'Rincian belanja');
        },
        async buka(url) {
            this.terbuka = true; this.memuat = true; this.galat = ''; this.d = null;
            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' } });
                if (! r.ok) throw new Error();
                this.d = await r.json();
            } catch (e) { this.galat = 'Rincian tidak bisa dimuat. Coba lagi sebentar.'; }
            this.memuat = false;
        },
     }"
     @rincian-belanja.window="buka($event.detail)" @keydown.escape.window="terbuka = false">
    <div x-show="terbuka" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/50">
        <div class="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-t-3xl bg-white p-5 shadow-xl" @click.outside="terbuka = false"
             style="padding-bottom: calc(2rem + env(safe-area-inset-bottom))">
            <div class="flex items-center justify-between">
                <p class="text-lg font-bold text-slate-900" x-text="judul()"></p>
                <button type="button" @click="terbuka = false" class="px-2 text-2xl leading-none text-slate-400" aria-label="Tutup">&times;</button>
            </div>

            <p x-show="memuat" class="py-8 text-center text-sm text-slate-500">Memuat…</p>
            <p x-show="galat" x-text="galat" class="py-8 text-center text-sm text-red-700"></p>

            <template x-if="d && ! d.rincian">
                <div class="py-6 text-center text-sm text-slate-500">
                    <p class="text-xl font-bold tabular-nums" :class="d.keluar ? 'text-red-700' : 'text-emerald-700'" x-text="(d.keluar ? '−' : '+') + rp(d.nominal)"></p>
                    <p class="mt-1" x-text="d.tanggal + (d.keterangan ? ' · ' + d.keterangan : '')"></p>
                    <p class="mt-3">Rincian barang tidak tersedia untuk transaksi ini.</p>
                </div>
            </template>

            <template x-if="d && d.rincian">
                <div class="mt-2">
                    <p class="text-sm font-semibold text-slate-800" x-text="d.rincian.toko || 'Toko'"></p>
                    <p class="text-xs text-slate-500">
                        <span x-text="d.rincian.struk"></span><span x-show="d.rincian.waktu" x-text="' · ' + jam(d.rincian.waktu)"></span>
                    </p>
                    <p x-show="d.rincian.struk_asal" class="text-xs text-slate-500" x-text="'Dari struk ' + d.rincian.struk_asal"></p>
                    <p x-show="d.rincian.jenis === 'batal'" class="mt-2 rounded-2xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                        Seluruh struk ini dibatalkan — uangnya sudah kembali ke uang saku.
                    </p>

                    <div class="mt-3 divide-y divide-slate-100 border-y border-slate-100">
                        <template x-for="(b, i) in d.rincian.barang" :key="i">
                            <div class="flex items-start justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900" x-text="b.nama"></p>
                                    <p class="text-xs text-slate-500" x-text="angka(b.jumlah) + ' ' + b.satuan + ' × ' + rp(b.harga)"></p>
                                    <p x-show="Number(b.diskon) > 0" class="text-xs text-emerald-700" x-text="'Diskon ' + rp(b.diskon)"></p>
                                </div>
                                <p class="shrink-0 text-sm font-semibold tabular-nums text-slate-900" x-text="rp(b.subtotal)"></p>
                            </div>
                        </template>
                    </div>

                    <div x-show="Number(d.rincian.diskon_struk) > 0" class="mt-2 flex justify-between text-sm text-emerald-700">
                        <span>Diskon struk</span><span class="tabular-nums" x-text="'−' + rp(d.rincian.diskon_struk)"></span>
                    </div>
                    <div class="mt-2 flex justify-between text-base font-bold">
                        <span class="text-slate-900" x-text="d.keluar ? 'Total dibayar' : 'Total kembali ke uang saku'"></span>
                        <span class="tabular-nums" :class="d.keluar ? 'text-red-700' : 'text-emerald-700'" x-text="(d.keluar ? '−' : '+') + rp(d.nominal)"></span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
