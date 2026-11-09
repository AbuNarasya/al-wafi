@extends('layouts.mitra')

@section('title', 'Kasir')

@section('content')
{{--
  Kasir mitra: ketuk menu → Bayar dengan kartu → tempel kartu (pembaca RFID USB mengetik UID + Enter ke isian
  yang sedang fokus) → PIN bila diminta → selesai. Kunci klien dibuat per pembayaran: kiriman ulang karena
  sinyal putus tak memotong dua kali. Status "belum pasti" = JANGAN terima tunai, tekan Cek lagi.
--}}
<div x-data="kasirMitra(@js($menu), @js(['cek' => route('mitra.cek'), 'bayar' => route('mitra.bayar'), 'cekLagi' => url('mitra/bayar')]))" x-cloak>
    @if ($menu->isEmpty())
        <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm">
            Belum ada menu. Setorkan daftar menu &amp; harga ke kepala Wafi Mart untuk dimasukkan ke sistem.
        </div>
    @else
        <div class="grid grid-cols-2 gap-2">
            <template x-for="m in menu" :key="m.id">
                <button type="button" @click="tambah(m)" :disabled="tahap !== 'pilih'"
                        class="relative rounded-xl bg-white p-3 text-left shadow-sm ring-1 ring-gray-200 active:bg-brand/10">
                    <div class="text-sm font-semibold text-gray-900" x-text="m.nama"></div>
                    <div class="mt-1 text-sm tabular-nums text-brand" x-text="rp(m.harga)"></div>
                    <span x-show="jumlah(m.id) > 0" class="absolute right-2 top-2 rounded-full bg-brand px-2 text-xs font-bold text-white" x-text="jumlah(m.id)"></span>
                </button>
            </template>
        </div>
    @endif

    {{-- Keranjang --}}
    <div x-show="keranjang.length" class="mt-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200">
        <template x-for="b in keranjang" :key="b.id">
            <div class="flex items-center justify-between gap-2 py-1 text-sm">
                <div class="min-w-0 flex-1 truncate" x-text="b.nama"></div>
                <div class="flex items-center gap-2" x-show="tahap === 'pilih'">
                    <button type="button" @click="kurang(b.id)" class="h-7 w-7 rounded-full bg-gray-100 font-bold">−</button>
                    <span class="w-5 text-center tabular-nums" x-text="b.jumlah"></span>
                    <button type="button" @click="tambah(b)" class="h-7 w-7 rounded-full bg-gray-100 font-bold">+</button>
                </div>
                <span x-show="tahap !== 'pilih'" class="tabular-nums" x-text="'×' + b.jumlah"></span>
                <div class="w-20 text-right tabular-nums" x-text="rp(b.harga * b.jumlah)"></div>
            </div>
        </template>
        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 font-semibold">
            <span>Total</span><span class="text-lg tabular-nums" x-text="rp(total())"></span>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2" x-show="tahap === 'pilih'">
            <button type="button" @click="kosongkan()" class="rounded-lg bg-gray-100 py-3 text-sm font-semibold text-gray-700">Hapus</button>
            <button type="button" @click="mulaiKartu()" class="col-span-2 rounded-lg bg-brand py-3 text-sm font-semibold text-white">Bayar dengan kartu</button>
        </div>
    </div>

    {{-- Tempel kartu & PIN --}}
    <div x-show="tahap === 'kartu'" class="mt-3 space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-brand/30">
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
    <div x-show="tahap === 'hasil'" class="mt-3 space-y-3 rounded-xl p-4 shadow-sm"
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

        return {
            menu, keranjang: [], tahap: 'pilih', uid: '', pin: '', kartu: null, perluPin: false, galat: '', sibuk: false, hasil: null, kunci: kunciBaru(),
            rp(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); },
            jumlah(id) { return this.keranjang.find(b => b.id === id)?.jumlah || 0; },
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
            baru() { this.keranjang = []; this.hasil = null; this.tahap = 'pilih'; this.kunci = kunciBaru(); },
        };
    }
</script>
@endsection
