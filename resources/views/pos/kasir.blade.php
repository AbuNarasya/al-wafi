@extends('layouts.pos')

@section('title', 'Kasir')
@section('kepala', 'Kasir — '.$shift->lokasi->nama)
@section('subkepala', 'Shift '.$shift->nomor.' · dibuka '.$shift->dibuka_pada->format('d/m H:i'))

@section('aksi')
    <a href="{{ route('pos.riwayat') }}" class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Riwayat Struk</a>
    <a href="{{ route('pos.retur') }}" class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Retur</a>
    <a href="{{ route('pos.tutup') }}" class="rounded bg-amber-400 px-2 py-1 text-xs font-semibold text-amber-950 hover:bg-amber-300">Tutup Shift</a>
@endsection

@section('content')
    <div x-data="kasir(@js(['cari' => route('pos.cari'), 'jual' => route('pos.jual'), 'kartu' => route('pos.kartu'), 'pastikan' => route('pos.kartu_pastikan', ['permintaan' => '__ID__'])]), @js($ringkas))"
         @keydown.window.f2.prevent="$refs.cari.focus()"
         @keydown.window.f9.prevent="simpan()"
         @keydown.window.f8.prevent="bukaKartu()"
         class="grid gap-4 lg:grid-cols-3">

        {{-- Kiri: scan & keranjang --}}
        <section class="space-y-3 lg:col-span-2">
            <div class="relative">
                <input x-ref="cari" x-model="q" @input.debounce.250ms="cariNama()" @keydown.enter.prevent="enter()" @keydown.escape="q = ''; hasil = []"
                       autofocus autocomplete="off" placeholder="Scan barcode atau ketik nama barang…  (F2)"
                       class="w-full rounded-xl border-2 border-brand/40 bg-white px-4 py-3 text-lg shadow-sm focus:border-brand focus:ring-0">
                <div x-show="hasil.length" x-cloak @click.outside="hasil = []"
                     class="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                    <template x-for="(h, i) in hasil" :key="h.kode">
                        <button type="button" @click="tambah(h)" class="flex w-full items-center justify-between gap-3 px-4 py-2 text-left hover:bg-brand-soft">
                            <span><span class="font-medium" x-text="h.nama"></span> <span class="text-xs text-gray-400" x-text="h.kode"></span></span>
                            <span class="text-right text-sm">
                                <span class="font-semibold tabular-nums" x-text="rp(h.opsi[0].harga)"></span>
                                <span class="block text-xs" :class="h.stok > 0 ? 'text-gray-500' : 'text-red-600'" x-text="'stok ' + h.stok + ' ' + h.satuan_dasar"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </div>

            <div x-show="pesan" x-cloak class="rounded-lg px-4 py-2 text-sm" :class="pesanJenis === 'galat' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'" x-text="pesan"></div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-3 py-2">Barang</th><th class="px-3 py-2">Satuan</th><th class="px-3 py-2 text-center">Jumlah</th><th class="px-3 py-2 text-right">Harga</th><th class="px-3 py-2 text-right">Subtotal</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(r, i) in keranjang" :key="r.kode + '|' + r.satuan">
                            {{-- data-label ditulis di templat: tabelTumpuk (app.js) hanya melabeli baris yang
                                 sudah ada saat halaman dimuat, sedangkan baris keranjang lahir belakangan. --}}
                            <tr :class="kurang(r) ? 'bg-red-50' : ''">
                                <td class="px-3 py-2" data-label="Barang" data-tumpuk-judul>
                                    <div class="font-medium" x-text="r.nama"></div>
                                    <div class="text-xs" :class="kurang(r) ? 'text-red-600' : 'text-gray-400'" x-text="'stok ' + r.stok + ' ' + r.satuan_dasar"></div>
                                </td>
                                <td class="px-3 py-2" data-label="Satuan">
                                    <select x-model="r.satuan" @change="gantiSatuan(r)" class="rounded border-gray-300 py-1 text-sm" x-show="r.opsi.length > 1">
                                        <template x-for="o in r.opsi" :key="o.satuan"><option :value="o.satuan" x-text="o.satuan" :selected="o.satuan === r.satuan"></option></template>
                                    </select>
                                    <span x-show="r.opsi.length === 1" x-text="r.satuan"></span>
                                </td>
                                <td class="px-3 py-2" data-label="Jumlah">
                                    <div class="flex items-center gap-1 sm:justify-center">
                                        <button type="button" @click="ubahQty(r, -1)" class="h-8 w-8 rounded bg-gray-100 text-lg leading-none hover:bg-gray-200">−</button>
                                        <input type="number" min="1" step="1" x-model.number="r.qty" class="w-14 rounded border-gray-300 py-1 text-center text-sm">
                                        <button type="button" @click="ubahQty(r, 1)" class="h-8 w-8 rounded bg-gray-100 text-lg leading-none hover:bg-gray-200">+</button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums" data-label="Harga">
                                    <span x-text="rp(r.harga)"></span>
                                    <template x-if="r.normal > r.harga">
                                        <div class="text-[11px]"><span class="text-gray-400 line-through" x-text="rp(r.normal)"></span> <span class="rounded bg-rose-100 px-1 font-medium text-rose-700">promo</span></div>
                                    </template>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" data-label="Subtotal">
                                    <span x-text="rp(r.harga * r.qty - (r.diskon || 0))"></span>
                                    {{-- Diskon manual per barang — butuh PIN pengawas saat bayar. --}}
                                    <div class="text-[11px] font-normal">
                                        <button type="button" x-show="!r.bukaDiskon && !r.diskon" @click="r.bukaDiskon = true" class="text-gray-400 hover:text-brand">+ diskon</button>
                                        <span x-show="r.diskon > 0" class="text-rose-600" x-text="'− ' + rp(r.diskon)"></span>
                                        <input x-show="r.bukaDiskon" type="text" inputmode="numeric" :value="fmtRupiah(r.diskon)" @input="r.diskon = parseInt(ketikRupiah($event) || 0, 10)"
                                               @keydown.enter.prevent="r.bukaDiskon = false" @blur="r.bukaDiskon = false" placeholder="nominal"
                                               class="mt-0.5 w-24 rounded border-gray-300 py-0.5 text-right text-xs">
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-center" data-tumpuk-aksi><button type="button" @click="keranjang.splice(i, 1); fokus()" class="text-red-500 hover:text-red-700" title="Hapus">&times;</button></td>
                            </tr>
                        </template>
                        <tr x-show="!keranjang.length"><td colspan="6" class="px-4 py-12 text-center text-gray-400">Keranjang kosong — scan barcode untuk mulai.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Kanan: pembayaran --}}
        <aside class="space-y-3">
            <div class="rounded-xl bg-brand-dark p-5 text-white shadow">
                <div class="text-sm text-white/70">Total belanja</div>
                <div class="text-4xl font-bold tabular-nums" x-text="rp(total)"></div>
                <div class="mt-1 text-xs text-white/60" x-text="jumlahBarang + ' barang'"></div>
            </div>

            <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div x-show="keranjang.length" class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-gray-600">Diskon struk</span>
                    <input type="text" inputmode="numeric" :value="fmtRupiah(diskonStruk)" @input="diskonStruk = parseInt(ketikRupiah($event) || 0, 10)"
                           placeholder="0" class="w-32 rounded border-gray-300 py-1 text-right text-sm tabular-nums">
                </div>
                <div x-show="adaDiskon" x-cloak class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    Ada diskon <b x-text="rp(totalDiskon)"></b> — butuh persetujuan pengawas saat bayar.
                </div>
                <label class="block text-sm font-medium text-gray-700">Uang diterima</label>
                <input x-ref="bayar" type="text" inputmode="numeric" :value="fmtRupiah(bayar)" @input="bayar = ketikRupiah($event)" @keydown.enter.prevent="simpan()"
                       placeholder="0" class="w-full rounded-lg border-gray-300 text-right text-2xl tabular-nums">
                <div class="grid grid-cols-3 gap-2 text-sm">
                    <button type="button" @click="bayar = String(total)" class="rounded-lg bg-gray-100 py-2 hover:bg-gray-200">Uang pas</button>
                    <template x-for="n in [20000, 50000, 100000]" :key="n">
                        <button type="button" @click="bayar = String(n)" class="rounded-lg bg-gray-100 py-2 tabular-nums hover:bg-gray-200" x-text="(n / 1000) + 'rb'"></button>
                    </template>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                    <span class="text-sm text-gray-600">Kembalian</span>
                    <span class="text-2xl font-bold tabular-nums" :class="kembalian < 0 ? 'text-red-600' : 'text-emerald-700'" x-text="kembalian < 0 ? 'kurang ' + rp(-kembalian) : rp(kembalian)"></span>
                </div>
                <button type="button" @click="simpan()" :disabled="sibuk || !keranjang.length || kembalian < 0"
                        class="w-full rounded-xl bg-emerald-600 py-4 text-lg font-bold text-white shadow hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">
                    <span x-text="sibuk ? 'Menyimpan…' : 'BAYAR  (F9)'"></span>
                </button>
                <button type="button" @click="bukaKartu()" :disabled="sibuk || !keranjang.length"
                        class="w-full rounded-xl bg-sky-600 py-3 text-base font-bold text-white shadow hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-40">
                    BAYAR KARTU SANTRI  (F8)
                </button>
                <button type="button" x-show="keranjang.length" @click="if (confirm('Kosongkan keranjang?')) { keranjang = []; bayar = ''; fokus(); }"
                        class="w-full text-xs text-gray-500 hover:text-red-600">Kosongkan keranjang</button>
            </div>

            <div x-show="terakhir" x-cloak class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
                <div class="font-semibold text-emerald-900">Tersimpan <span class="font-mono" x-text="terakhir?.nomor"></span></div>
                <div class="mt-1 text-emerald-800">Kembalian <b class="text-lg tabular-nums" x-text="rp(terakhir?.kembalian || 0)"></b></div>
                <button type="button" @click="cetak(terakhir.struk)" class="mt-2 text-xs font-medium text-emerald-700 underline">Cetak ulang struk</button>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm">
                Shift ini: <b x-text="ringkas.struk"></b> struk · <b class="tabular-nums" x-text="rp(ringkas.omzet)"></b>
            </div>
        </aside>

        {{-- Bayar KARTU: santri menempelkan kartu (pembaca RFID USB mengetik UID + Enter). Di atas
             batas harian santri mengetik PIN belanjanya. Saldo sengaja tak ditampilkan ke kasir. --}}
        <div x-show="kartuBuka" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" @keydown.escape.window="if (!tertunda) kartuBuka = false">
            <form @submit.prevent="info ? bayarKartu() : cekKartu()" class="w-full max-w-sm space-y-3 rounded-xl bg-white p-5 shadow-xl">
                <div class="font-semibold text-gray-900">Bayar dengan kartu santri</div>
                <div class="text-sm text-gray-600">Total <b class="tabular-nums" x-text="rp(total)"></b></div>
                <input x-ref="uid" type="password" x-model="uid" :readonly="!!info" autocomplete="off" placeholder="Tempelkan kartu ke pembaca…"
                       class="w-full rounded-lg border-gray-300 text-center font-mono">
                <template x-if="info">
                    <div class="space-y-2 rounded-lg bg-sky-50 p-3 text-sm">
                        <div class="text-base font-semibold text-sky-900" x-text="info.nama"></div>
                        <div class="text-xs text-sky-800" x-text="'NIS ' + (info.nis || '-') + ' · sisa batas hari ini ' + rp(info.sisa_batas)"></div>
                        <template x-if="info.perlu_pin">
                            <div class="space-y-1">
                                <div class="text-xs font-medium text-amber-800" x-text="info.punya_pin ? 'Melewati batas harian — santri mengetik PIN belanjanya.' : 'Melewati batas harian, dan wali belum memasang PIN belanja — kurangi belanja atau bayar tunai.'"></div>
                                <input x-show="info.punya_pin" x-ref="pinSantri" type="password" inputmode="numeric" x-model="pinSantri" maxlength="6" autocomplete="off" placeholder="PIN santri"
                                       class="w-full rounded-lg border-gray-300 text-center text-lg tracking-widest">
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="info && adaDiskon">
                    <div class="space-y-2 rounded-lg bg-rose-50 p-3 text-sm">
                        <div class="text-xs text-rose-800">Ada diskon <b x-text="rp(totalDiskon)"></b> — persetujuan pengawas:</div>
                        <select x-model="idPengawas" class="w-full rounded-lg border-gray-300 text-sm">
                            <option value="">— pilih pengawas —</option>
                            @foreach ($pengawas as $p)<option value="{{ $p['id'] }}">{{ $p['nama'] }}</option>@endforeach
                        </select>
                        <input type="text" x-model="alasanDiskon" placeholder="Alasan diskon" class="w-full rounded-lg border-gray-300 text-sm">
                        <input type="password" inputmode="numeric" x-model="pin" maxlength="6" placeholder="PIN pengawas" autocomplete="off" class="w-full rounded-lg border-gray-300 text-center tracking-widest">
                    </div>
                </template>
                {{-- Pembayaran kartu yang nasibnya belum selesai (Fase 3c-1): keranjang ditahan, tunai DILARANG
                     sampai "Cek lagi" memastikan terpotong (struk disimpan) atau tidak (aman). --}}
                <template x-if="tertunda">
                    <div class="space-y-2 rounded-lg border-2 border-amber-400 bg-amber-50 p-3 text-sm text-amber-900">
                        <div class="font-semibold">Jangan terima tunai dulu</div>
                        <div class="text-xs" x-text="kartuGalat"></div>
                        <button type="button" @click="cekTertunda()" :disabled="sibuk"
                                class="w-full rounded-lg bg-amber-500 py-2 text-sm font-semibold text-white hover:bg-amber-600">Cek lagi</button>
                        <button type="button" @click="lepaskan()" :disabled="sibuk"
                                class="w-full text-center text-xs text-amber-800 underline">Lepaskan &amp; layani tunai (potongan kartu, bila ada, kembali ke santri malam ini)</button>
                    </div>
                </template>
                <p x-show="kartuGalat && !tertunda" class="text-sm text-red-600" x-text="kartuGalat"></p>
                <div class="flex gap-2" x-show="!tertunda">
                    <button type="button" @click="kartuBuka = false" class="flex-1 rounded-lg border border-gray-300 py-2 text-sm">Batal</button>
                    <button class="flex-1 rounded-lg bg-sky-600 py-2 text-sm font-semibold text-white hover:bg-sky-700" :disabled="sibuk"
                            x-text="info ? 'Bayar ' + rp(total) : 'Baca kartu'"></button>
                </div>
            </form>
        </div>

        {{-- Persetujuan pengawas (diskon manual) — PIN diketik pengawas di perangkat ini. --}}
        <div x-show="minta" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" @keydown.escape.window="minta = false">
            <form @submit.prevent="setujui()" class="w-full max-w-sm space-y-3 rounded-xl bg-white p-5 shadow-xl">
                <div class="font-semibold text-gray-900">Persetujuan pengawas</div>
                <p class="text-xs text-gray-500">Diskon <b x-text="rp(totalDiskon)"></b>. Pengawas memilih namanya dan mengetik PIN.</p>
                @if (! $pengawas)
                    <div class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Belum ada pengawas yang memasang PIN (menu Profil). Diskon tak bisa disetujui.</div>
                @endif
                <select x-model="idPengawas" required class="w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— pilih pengawas —</option>
                    @foreach ($pengawas as $p)<option value="{{ $p['id'] }}">{{ $p['nama'] }}</option>@endforeach
                </select>
                <input type="text" x-model="alasanDiskon" required maxlength="255" placeholder="Alasan diskon (mis. kemasan penyok)" class="w-full rounded-lg border-gray-300 text-sm">
                <input type="password" inputmode="numeric" x-ref="pin" x-model="pin" required maxlength="6" placeholder="PIN pengawas" autocomplete="off"
                       class="w-full rounded-lg border-gray-300 text-center text-lg tracking-widest">
                <div class="flex gap-2">
                    <button type="button" @click="minta = false" class="flex-1 rounded-lg border border-gray-300 py-2 text-sm">Batal</button>
                    <button class="flex-1 rounded-lg bg-emerald-600 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Setujui &amp; Bayar</button>
                </div>
            </form>
        </div>

        {{-- Struk dicetak lewat iframe tersembunyi: jendela baru sesudah fetch diblokir peramban. --}}
        <iframe x-ref="cetakan" class="hidden" title="Struk"></iframe>
        @if (session('cetak_retur'))
            <iframe class="hidden" title="Struk retur" src="{{ session('cetak_retur') }}"></iframe>
        @endif
    </div>

    <script>
        function kasir(url, ringkas) {
            return {
                q: '', hasil: [], keranjang: [], bayar: '', sibuk: false, pesan: '', pesanJenis: '', terakhir: null, ringkas,
                diskonStruk: 0, minta: false, idPengawas: '', pin: '', alasanDiskon: '',
                kartuBuka: false, uid: '', info: null, pinSantri: '', kartuGalat: '', tertunda: null,

                get totalDiskon() { return this.keranjang.reduce((s, r) => s + (r.diskon || 0), 0) + (this.diskonStruk || 0); },
                get adaDiskon() { return this.totalDiskon > 0; },
                get total() { return Math.max(0, this.keranjang.reduce((s, r) => s + r.harga * (r.qty || 0) - (r.diskon || 0), 0) - (this.diskonStruk || 0)); },
                get kembalian() { return (parseInt(this.bayar || 0, 10)) - this.total; },
                get jumlahBarang() { return this.keranjang.reduce((s, r) => s + (r.qty || 0), 0); },
                rp(n) { return 'Rp ' + Math.round(n || 0).toLocaleString('id-ID'); },
                fokus() { this.$nextTick(() => this.$refs.cari.focus()); },
                beri(teks, jenis = 'galat') { this.pesan = teks; this.pesanJenis = jenis; if (teks) setTimeout(() => { if (this.pesan === teks) this.pesan = ''; }, 5000); },

                async ambil(params) {
                    const r = await fetch(url.cari + '?' + new URLSearchParams(params), { headers: { Accept: 'application/json' } });
                    if (!r.ok) throw new Error('Gagal mencari barang (' + r.status + ').');
                    return (await r.json()).items;
                },
                async cariNama() {
                    const q = this.q.trim();
                    if (q.length < 2 || /^\d{6,}$/.test(q)) { this.hasil = []; return; }
                    try { this.hasil = await this.ambil({ q }); } catch (e) { this.beri(e.message); }
                },
                // Enter = hasil pemindai (barcode + Enter) atau memilih satu-satunya hasil ketikan.
                async enter() {
                    const q = this.q.trim();
                    if (!q) { this.$refs.bayar.focus(); return; }
                    try {
                        const pindai = await this.ambil({ barcode: q });
                        if (pindai.length) { this.tambah(pindai[0]); return; }
                        if (this.hasil.length === 1) { this.tambah(this.hasil[0]); return; }
                        if (!this.hasil.length) this.hasil = await this.ambil({ q });
                        if (this.hasil.length === 1) { this.tambah(this.hasil[0]); return; }
                        if (!this.hasil.length) this.beri('Barang "' + q + '" tidak ditemukan atau belum dijual di toko.');
                    } catch (e) { this.beri(e.message); }
                },
                tambah(h) {
                    const satuan = h.satuan_dipilih || h.opsi[0].satuan;
                    const o = h.opsi.find(x => x.satuan === satuan) || h.opsi[0];
                    const ada = this.keranjang.find(r => r.kode === h.kode && r.satuan === o.satuan);
                    if (ada) { ada.qty += 1; ada.stok = h.stok; }
                    else this.keranjang.push({ kode: h.kode, nama: h.nama, satuan: o.satuan, isi: o.isi, harga: o.harga, normal: o.normal ?? o.harga, qty: 1,
                        stok: h.stok, satuan_dasar: h.satuan_dasar, opsi: h.opsi, barcode: h.barcode, diskon: 0, bukaDiskon: false });
                    const r = this.keranjang.find(x => x.kode === h.kode && x.satuan === o.satuan);
                    if (this.kurang(r)) this.beri('Stok ' + h.nama + ' di toko tidak cukup.', 'peringatan');
                    this.q = ''; this.hasil = []; this.fokus();
                },
                gantiSatuan(r) { const o = r.opsi.find(x => x.satuan === r.satuan); if (o) { r.isi = o.isi; r.harga = o.harga; r.normal = o.normal ?? o.harga; } },
                ubahQty(r, d) { r.qty = Math.max(1, (parseInt(r.qty, 10) || 1) + d); },
                // Stok dicek per BARANG (dus + pcs barang yang sama dijumlahkan dalam satuan dasar).
                kurang(r) {
                    const pakai = this.keranjang.filter(x => x.kode === r.kode).reduce((s, x) => s + (x.qty || 0) * x.isi, 0);
                    return pakai > r.stok;
                },
                cetak(alamat) { this.$refs.cetakan.src = alamat; },

                // Ada diskon → minta PIN pengawas dulu; tanpa diskon langsung simpan.
                simpan() {
                    if (this.sibuk || !this.keranjang.length) return;
                    if (this.tertunda) { this.kartuBuka = true; return; }   // pembayaran kartu tertahan → selesaikan dulu
                    if (this.kembalian < 0) { this.beri('Uang diterima kurang dari total.'); this.$refs.bayar.focus(); return; }
                    if (this.adaDiskon) { this.pin = ''; this.minta = true; this.$nextTick(() => this.$refs.pin.focus()); return; }
                    this.kirim();
                },
                setujui() { this.minta = false; this.kirim(); },

                // ---- Bayar kartu ----
                bukaKartu() {
                    if (this.sibuk || !this.keranjang.length) return;
                    this.kartuBuka = true;
                    if (this.tertunda) return;   // tahanan tetap: kartu & pesan dipertahankan untuk "Cek lagi"
                    this.uid = ''; this.info = null; this.pinSantri = ''; this.kartuGalat = '';
                    this.$nextTick(() => this.$refs.uid.focus());
                },
                async cekKartu() {
                    this.kartuGalat = '';
                    const r = await fetch(url.kartu + '?' + new URLSearchParams({ uid: this.uid, total: this.total }), { headers: { Accept: 'application/json' } });
                    const data = await r.json();
                    if (!r.ok) { this.kartuGalat = data.message || 'Kartu tak terbaca.'; this.uid = ''; this.$refs.uid.focus(); return; }
                    this.info = data;
                    this.$nextTick(() => this.$refs.pinSantri?.focus());
                },
                async bayarKartu() {
                    const ok = await this.kirim({ kartu: true });
                    if (ok) this.kartuBuka = false;
                },
                // Jalan keluar bila Al Wafi lama tak terjangkau: pencocokan malam mengembalikan potongan tanpa struk.
                lepaskan() {
                    if (!confirm('Lepaskan pembayaran kartu ini? Bila kartu ternyata sudah terpotong, uangnya dikembalikan otomatis ke santri malam ini.')) return;
                    this.tertunda = null; this.kartuGalat = ''; this.kartuBuka = false; this.uid = ''; this.info = null;
                },
                // "Cek lagi": terpotong → simpan struk tanpa memotong lagi; tidak terjadi → aman, tahanan dilepas.
                async cekTertunda() {
                    this.sibuk = true;
                    let data = null;
                    try {
                        const r = await fetch(url.pastikan.replace('__ID__', this.tertunda), { method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                        data = await r.json();
                        if (!r.ok) throw new Error(data.message);
                    } catch (e) {
                        this.kartuGalat = 'Server toko tak terjangkau — status masih BELUM PASTI. Coba "Cek lagi" sebentar lagi.';
                        return;
                    } finally {
                        this.sibuk = false;
                    }
                    if (data.sudah_struk) { this.tertunda = null; this.kartuBuka = false; this.beri('Pembayaran ini sudah tersimpan sebagai struk — lihat Riwayat Struk.'); return; }
                    if (data.status === 'berhasil') {
                        if (await this.kirim({ kartu: true })) this.kartuBuka = false;
                    } else if (data.status === 'gagal') {
                        this.tertunda = null;
                        this.kartuGalat = 'Dipastikan TIDAK terpotong — aman: ulangi bayar kartu, atau terima tunai.';
                    } else {
                        this.kartuGalat = 'Status masih BELUM PASTI (ERP Al Wafi tak terjangkau). Jangan terima tunai — coba lagi sebentar lagi.';
                    }
                },

                async kirim(opsi = {}) {
                    this.sibuk = true;
                    try {
                        const r = await fetch(url.jual, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({
                                bayar: opsi.kartu ? this.total : parseInt(this.bayar || 0, 10),
                                kartu_uid: opsi.kartu ? this.uid : null,
                                pin_santri: opsi.kartu ? this.pinSantri : null,
                                permintaan_kartu: opsi.kartu ? this.tertunda : null,
                                baris: this.keranjang.map(x => ({ kode_persediaan: x.kode, satuan: x.satuan, kuantiti: x.qty, barcode: x.barcode, diskon: x.diskon || 0 })),
                                diskon_struk: this.diskonStruk || 0,
                                alasan_diskon: this.adaDiskon ? this.alasanDiskon : null,
                                id_pengawas: this.adaDiskon ? (parseInt(this.idPengawas, 10) || null) : null,
                                pin: this.adaDiskon ? this.pin : null,
                            }),
                        });
                        const data = await r.json();
                        this.pin = ''; this.pinSantri = '';   // PIN tak disimpan di layar, berhasil atau gagal
                        if (!r.ok) {
                            if (data.tertunda) this.tertunda = data.tertunda;
                            if (opsi.kartu) this.kartuGalat = data.message || 'Gagal menyimpan.';
                            else this.beri(data.message || 'Gagal menyimpan.');
                            return false;
                        }
                        this.terakhir = data; this.ringkas = data.ringkas;
                        this.keranjang = []; this.bayar = ''; this.diskonStruk = 0; this.alasanDiskon = ''; this.idPengawas = '';
                        this.uid = ''; this.info = null; this.tertunda = null; this.kartuGalat = '';
                        this.cetak(data.struk);
                        this.fokus();
                        return true;
                    } catch (e) {
                        const pesan = 'Koneksi ke server terputus — struk BELUM tersimpan. Coba lagi.';
                        if (opsi.kartu) this.kartuGalat = pesan; else this.beri(pesan);
                        return false;
                    } finally {
                        this.sibuk = false;
                    }
                },
            };
        }
    </script>
@endsection
