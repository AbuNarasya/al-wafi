{{--
  Ajakan "Pasang di HP" (meniru SANAD Wali). Muncul HANYA bila memang bisa dipasang:
   • Android/Chrome — setelah peramban mengirim `beforeinstallprompt` (app.js menyimpannya
     di window.tawaranPasang); tombol Pasang membuka dialog pasang bawaan HP.
   • iPhone/iPad — tak punya dialog pasang; tombolnya membuka petunjuk Bagikan → Tambahkan.
  Tidak muncul bila sudah dibuka sebagai aplikasi, atau baru ditutup (30 hari, per perangkat).
--}}
<div x-data="{
        tampil: false, ios: false, petunjuk: false,
        KUNCI: 'alwafi_wali_pasang_tutup',
        init() {
            const terpasang = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            let ditutup = 0;
            try { ditutup = Number(localStorage.getItem(this.KUNCI) || 0); } catch (e) {}
            if (terpasang || Date.now() - ditutup < 30 * 864e5) return;

            this.ios = /iPad|iPhone|iPod/.test(navigator.userAgent) && ! window.MSStream;
            this.tampil = this.ios || !! window.tawaranPasang;
            window.addEventListener('pwa-bisa-dipasang', () => { this.tampil = true; });
            window.addEventListener('pwa-terpasang', () => { this.tampil = false; });
        },
        async pasang() {
            if (this.ios) { this.petunjuk = ! this.petunjuk; return; }
            if (await window.pasangAplikasi?.()) this.tampil = false;
        },
        tutup() {
            this.tampil = false;
            try { localStorage.setItem(this.KUNCI, String(Date.now())); } catch (e) {}
        },
     }"
     x-show="tampil" x-cloak class="mb-5 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
    <div class="flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-slate-900">Pasang Al Wafi Wali di HP</p>
            <p class="text-sm text-slate-500">Buka seperti aplikasi, langsung dari layar utama.</p>
        </div>
        <button type="button" @click="pasang()" class="shrink-0 rounded-2xl bg-brand px-4 py-2 text-sm font-bold text-white">Pasang</button>
        <button type="button" @click="tutup()" aria-label="Tutup" class="tanpa-sentuh shrink-0 p-1 text-slate-400">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <div x-show="petunjuk" x-cloak class="mt-3 rounded-2xl bg-slate-50 p-3 text-sm text-slate-700">
        Di Safari: ketuk tombol <b>Bagikan</b>
        <svg class="inline h-4 w-4 align-text-bottom" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0-12-4 4m4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>
        lalu pilih <b>Tambahkan ke Layar Utama</b>.
    </div>
</div>
