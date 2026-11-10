@extends('layouts.mitra')

@section('title', 'Kasir')

@section('content')
{{--
  Kasir mitra (disusun ulang 10 Nov 2026 untuk menu yang banyak):
   • Halaman PAS SATU LAYAR — tak ada gulir halaman. Tingginya diukur di peramban (ukur()) karena tinggi kepala
     & navigasi bawah berbeda-beda per HP.
   • Kotak cari di atas; daftar menu (baris ringkas, terlaris 30 hari di atas) menggulir di BINGKAINYA SENDIRI.
   • Rekap pesanan selalu terlihat di bawah; daftar itemnya juga menggulir sendiri bila panjang, sedangkan
     total + tombol Hapus/Bayar selalu tampil. Bayar di lembar layar penuh tersendiri.
  Alur bayar: tempel kartu (pembaca RFID USB mengetik UID + Enter ke isian yang fokus) → PIN bila diminta → selesai.
  Kunci klien dibuat per pembayaran: kiriman ulang karena sinyal putus tak memotong dua kali.
  Status "belum pasti" = JANGAN terima tunai, tekan Cek lagi.
--}}
<div x-data="kasirMitra(@js($menu), @js(['cek' => route('mitra.cek'), 'bayar' => route('mitra.bayar'), 'cekLagi' => url('mitra/bayar')]))" x-cloak
     x-init="$nextTick(() => setTimeout(() => ukur(), 0))" @resize.window.debounce.100ms="ukur()" class="flex flex-col" :style="tinggi ? 'height:' + tinggi + 'px' : ''">
    @if ($menu->isEmpty())
        <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm">
            Belum ada menu. Setorkan daftar menu &amp; harga ke kepala Wafi Mart untuk dimasukkan ke sistem.
        </div>
    @else
        {{-- Cari --}}
        <div class="shrink-0 pb-2">
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                <input type="search" x-model="cari" placeholder="Cari menu…" autocomplete="off" enterkeyhint="search"
                       class="w-full rounded-xl border-gray-300 py-2.5 pl-9 pr-9 text-sm shadow-sm">
                <button type="button" x-show="cari" @click="cari = ''" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-lg leading-none text-gray-400" aria-label="Hapus pencarian">&times;</button>
            </div>
            <div class="mt-1 px-1 text-[11px] text-gray-500" x-text="cari ? tersaring().length + ' dari ' + menu.length + ' menu' : menu.length + ' menu · terlaris di atas'"></div>
        </div>

        {{-- Daftar menu: baris ringkas, menggulir di bingkainya sendiri. Ketuk baris = tambah satu. --}}
        <div class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto overscroll-contain rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
            <template x-for="m in tersaring()" :key="m.id">
                {{-- Tanpa padding vertikal di baris: tombol sudah ber-tinggi minimal 44px (sasaran sentuh, app.css). --}}
                <div class="flex items-center gap-2 px-3" :class="jumlah(m.id) > 0 ? 'bg-brand/5' : ''">
                    <button type="button" @click="tambah(m)" class="flex min-w-0 flex-1 items-center justify-between gap-3 py-1 text-left">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900" x-text="m.nama"></span>
                            <span x-show="m.laku > 0 && !cari" class="text-[10px] font-semibold uppercase tracking-wide text-amber-600">terlaris</span>
                        </span>
                        <span class="shrink-0 text-sm tabular-nums text-brand" x-text="rp(m.harga)"></span>
                    </button>
                    <template x-if="jumlah(m.id) > 0">
                        <div class="flex shrink-0 items-center gap-1.5">
                            <button type="button" @click="kurang(m.id)" class="h-8 w-8 rounded-full bg-gray-100 font-bold text-gray-700" aria-label="Kurangi">−</button>
                            <span class="w-5 text-center text-sm font-bold tabular-nums text-brand" x-text="jumlah(m.id)"></span>
                            <button type="button" @click="tambah(m)" class="h-8 w-8 rounded-full bg-brand font-bold text-white" aria-label="Tambah">+</button>
                        </div>
                    </template>
                </div>
            </template>
            <div x-show="tersaring().length === 0" class="px-3 py-8 text-center text-sm text-gray-400">
                Tidak ada menu yang cocok dengan "<span x-text="cari"></span>".
            </div>
        </div>

        {{-- Rekap pesanan: selalu terlihat di bawah daftar menu (permintaan user 10 Nov 2026). Daftar itemnya
             menggulir sendiri bila panjang; total & tombol selalu tampil. --}}
        <div x-show="keranjang.length" class="mt-2 shrink-0 rounded-xl bg-white p-3 shadow-sm ring-1 ring-brand/30">
            <div class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500">
                <span>Pesanan</span><span class="normal-case" x-text="totalItem() + ' item'"></span>
            </div>
            <div class="max-h-[22vh] overflow-y-auto overscroll-contain">
                <template x-for="b in keranjang" :key="b.id">
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <div class="min-w-0 flex-1 truncate" x-text="b.nama"></div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="kurang(b.id)" class="h-8 w-8 rounded-full bg-gray-100 font-bold" aria-label="Kurangi">−</button>
                            <span class="w-5 text-center tabular-nums" x-text="b.jumlah"></span>
                            <button type="button" @click="tambah(b)" class="h-8 w-8 rounded-full bg-gray-100 font-bold" aria-label="Tambah">+</button>
                        </div>
                        <div class="w-20 text-right tabular-nums" x-text="rp(b.harga * b.jumlah)"></div>
                    </div>
                </template>
            </div>
            <div class="mt-1 flex items-center justify-between border-t border-gray-100 pt-2 font-semibold">
                <span>Total</span><span class="text-lg tabular-nums" x-text="rp(total())"></span>
            </div>
            <div class="mt-2 grid grid-cols-3 gap-2">
                <button type="button" @click="kosongkan()" class="rounded-lg bg-gray-100 py-2.5 text-sm font-semibold text-gray-700">Hapus</button>
                <button type="button" @click="mulaiKartu()" class="col-span-2 rounded-lg bg-brand py-2.5 text-sm font-bold text-white">Bayar kartu</button>
            </div>
        </div>
    @endif

    {{-- Lembar bayar: layar penuh, fokus pada satu pembayaran --}}
    <div x-show="tahap !== 'pilih'" class="fixed inset-0 z-30 overflow-y-auto bg-slate-100">
        <div class="mx-auto max-w-xl space-y-3 p-3 pb-10">
            <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200">
                <template x-for="b in keranjang" :key="b.id">
                    <div class="flex items-center justify-between gap-2 py-1 text-sm">
                        <div class="min-w-0 flex-1 truncate" x-text="b.nama"></div>
                        <span class="tabular-nums text-gray-500" x-text="'×' + b.jumlah"></span>
                        <div class="w-24 text-right tabular-nums" x-text="rp(b.harga * b.jumlah)"></div>
                    </div>
                </template>
                <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 font-semibold">
                    <span>Total</span><span class="text-lg tabular-nums" x-text="rp(total())"></span>
                </div>
            </div>

            {{-- Tempel kartu & PIN --}}
            <div x-show="tahap === 'kartu'" class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-brand/30">
                <div class="text-sm font-semibold">Tempelkan kartu santri ke pembaca</div>
                <input x-ref="uid" type="text" x-model="uid" @keydown.enter.prevent="cekKartu()" autocomplete="off" inputmode="numeric"
                       placeholder="UID kartu" class="w-full rounded-lg border-gray-300 text-center font-mono tracking-widest">
                <div x-show="kartu" class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <div class="font-semibold" x-text="kartu?.nama"></div>
                    <div class="text-xs text-gray-500">Sisa batas kantin hari ini <span class="tabular-nums" x-text="rp(kartu?.sisa_batas)"></span></div>
                </div>
                <div x-show="perluPin">
                    <label class="block text-xs font-medium text-gray-700">Melewati batas harian — santri mengetik PIN belanjanya</label>
                    <input x-ref="pin" type="password" x-model="pin" inputmode="numeric" maxlength="6" autocomplete="off"
                           @keydown.enter.prevent="bayar()" class="mt-1 w-full rounded-lg border-gray-300 text-center tracking-widest">
                </div>
                <p x-show="galat" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="galat"></p>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" @click="batalKartu()" :disabled="sibuk" class="rounded-lg bg-gray-100 py-3 text-sm font-semibold text-gray-700">Kembali</button>
                    <button type="button" x-show="!kartu" @click="cekKartu()" :disabled="sibuk || !uid" class="col-span-2 rounded-lg bg-brand py-3 text-sm font-semibold text-white disabled:opacity-50">Cek kartu</button>
                    <button type="button" x-show="kartu" @click="bayar()" :disabled="sibuk" class="col-span-2 rounded-lg bg-brand py-3 text-sm font-semibold text-white disabled:opacity-50"
                            x-text="sibuk ? 'Memproses…' : 'Bayar ' + rp(total())"></button>
                </div>
            </div>

            {{-- Hasil --}}
            <div x-show="tahap === 'hasil'" class="space-y-3 rounded-xl p-4 shadow-sm"
                 :class="hasil?.status === 'berhasil' ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-amber-50 ring-1 ring-amber-300'">
                <template x-if="hasil?.status === 'berhasil'">
                    <div class="text-center">
                        <div class="text-lg font-bold text-emerald-700">Pembayaran berhasil</div>
                        <div class="mt-1 text-sm" x-text="hasil.nama"></div>
                        <div class="text-2xl font-bold tabular-nums" x-text="rp(hasil.total)"></div>
                        <div class="text-xs text-gray-500" x-show="hasil.sisa_saldo !== null">Sisa saldo <span x-text="rp(hasil.sisa_saldo)"></span></div>
                        <div class="mt-1 font-mono text-[11px] text-gray-400" x-text="hasil.nomor"></div>
                    </div>
                </template>
                <template x-if="hasil && hasil.status !== 'berhasil'">
                    <div>
                        <div class="font-bold text-amber-800">Belum pasti</div>
                        <p class="text-sm text-amber-800" x-text="hasil.pesan"></p>
                        <button type="button" @click="cekLagi()" :disabled="sibuk" class="mt-3 w-full rounded-lg bg-amber-600 py-3 text-sm font-semibold text-white">Cek lagi</button>
                    </div>
                </template>
                <button type="button" x-show="hasil?.status === 'berhasil'" @click="baru()" class="w-full rounded-lg bg-brand py-3 text-sm font-semibold text-white">Transaksi baru</button>
            </div>
        </div>
    </div>
</div>

<script>
    function kasirMitra(menu, url) {
        const csrf = document.querySelector('meta[name=csrf-token]').content;
        const kirim = async (alamat, isi) => {
            const r = await fetch(alamat, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(isi || {}) });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) { const e = new Error(j.message || 'Gagal (' + r.status + ').'); e.status = r.status; throw e; }
            return j;
        };
        const kunciBaru = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2);
        // Pencarian tanpa peduli huruf besar & tanda baca; semua kata harus ada ("nasi gor" cocok "Nasi Goreng").
        const bersih = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9 ]/g, ' ');

        return {
            tinggi: null,
            // Tinggi halaman kasir = sisa layar di bawah kepala, di atas navigasi bawah (ruang pb di bingkai layout).
            ukur() {
                const bingkai = document.getElementById('bingkai-mitra');
                const bawah = (bingkai ? parseFloat(getComputedStyle(bingkai).paddingBottom) : 80) + 12;   // + p-3 milik <main>
                const atas = this.$el.getBoundingClientRect().top + window.scrollY;
                this.tinggi = Math.max(360, Math.floor(window.innerHeight - atas - bawah));
            },
            menu, cari: '', keranjang: [], tahap: 'pilih', uid: '', pin: '', kartu: null, perluPin: false, galat: '', sibuk: false, hasil: null, kunci: kunciBaru(),
            rp(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); },
            tersaring() {
                const kata = bersih(this.cari).split(' ').filter(Boolean);
                return kata.length ? this.menu.filter(m => { const n = bersih(m.nama); return kata.every(k => n.includes(k)); }) : this.menu;
            },
            jumlah(id) { return this.keranjang.find(b => b.id === id)?.jumlah || 0; },
            totalItem() { return this.keranjang.reduce((s, b) => s + b.jumlah, 0); },
            total() { return this.keranjang.reduce((s, b) => s + b.harga * b.jumlah, 0); },
            tambah(m) { const b = this.keranjang.find(x => x.id === m.id); b ? b.jumlah++ : this.keranjang.push({ id: m.id, nama: m.nama, harga: m.harga, jumlah: 1 }); },
            kurang(id) { const b = this.keranjang.find(x => x.id === id); if (!b) return; b.jumlah--; if (b.jumlah <= 0) this.keranjang = this.keranjang.filter(x => x.id !== id); },
            kosongkan() { this.keranjang = []; },
            mulaiKartu() { this.tahap = 'kartu'; this.uid = ''; this.pin = ''; this.kartu = null; this.perluPin = false; this.galat = ''; this.$nextTick(() => this.$refs.uid.focus()); },
            batalKartu() { this.tahap = 'pilih'; this.galat = ''; },
            async cekKartu() {
                if (!this.uid || this.sibuk) return;
                this.sibuk = true; this.galat = '';
                try {
                    this.kartu = await kirim(url.cek, { uid: this.uid, total: this.total() });
                    this.perluPin = !!this.kartu.perlu_pin;
                    this.$nextTick(() => this.perluPin && this.$refs.pin.focus());
                } catch (e) { this.galat = e.message; this.kartu = null; this.uid = ''; this.$nextTick(() => this.$refs.uid.focus()); }
                this.sibuk = false;
            },
            async bayar() {
                if (this.sibuk) return;
                this.sibuk = true; this.galat = '';
                try {
                    this.hasil = await kirim(url.bayar, { uid: this.uid, pin: this.pin || null, kunci: this.kunci,
                        baris: this.keranjang.map(b => ({ id_menu: b.id, jumlah: b.jumlah })) });
                    this.tahap = 'hasil';
                } catch (e) {
                    this.galat = e.message;
                    if (e.status === 409) { this.perluPin = true; this.pin = ''; this.$nextTick(() => this.$refs.pin.focus()); }
                }
                this.sibuk = false;
            },
            async cekLagi() {
                this.sibuk = true;
                try { this.hasil = await kirim(url.cekLagi + '/' + this.hasil.id + '/cek'); }
                catch (e) { this.hasil = { ...this.hasil, pesan: e.message }; }
                if (this.hasil.status === 'gagal') { this.galat = 'Pembayaran tidak terjadi — aman diulang atau terima tunai.'; this.tahap = 'kartu'; this.kunci = kunciBaru(); this.kartu = null; this.uid = ''; }
                this.sibuk = false;
            },
            baru() { this.keranjang = []; this.cari = ''; this.hasil = null; this.tahap = 'pilih'; this.kunci = kunciBaru(); },
        };
    }
</script>
@endsection
