@php
    // Portal Wali — bingkai bergaya SANAD Wali (bilah atas + bilah bawah, isi berkartu),
    // dengan palet ERP: biru brand #164a9e + aksen emas #f8c400.
    $masuk = \App\Services\PortalWali\SesiWali::wali() !== null;
    // Mode verifikasi (masuk lewat link dari staf): hanya layar verifikasi & data yang terbuka.
    $verifikasi = $masuk && \App\Services\PortalWali\SesiWali::modeVerifikasi(request()) !== null;
    // Wajib membuat password dulu: menu disembunyikan — semua tujuannya dialihkan kembali ke sini.
    $wajibSandi = $masuk && (bool) request()->session()->get(\App\Services\PortalWali\SesiWali::WAJIB_SANDI);
    $diBeranda = request()->routeIs('portal.beranda', 'portal.masuk', 'portal.kode', 'portal.verifikasi.ringkasan', 'portal.sandi.buat');
    // Mode terbatas masa uji coba (12 Nov 2026): tampilan & menu TETAP LENGKAP; hanya tindakannya yang dikunci
    // (PortalTerbatas). Di sini cuma menentukan keterangan pada pita demo.
    $terbatas = $masuk && ! $verifikasi && ! \App\Services\PortalWali\PortalTerbatas::bebas(\App\Services\PortalWali\SesiWali::wali());
    $menu = $wajibSandi ? [] : ($verifikasi ? [
        ['portal.verifikasi.ringkasan', 'portal.verifikasi.*', __('Verifikasi'), 'absensi'],
        ['portal.data', 'portal.data*', __('Data Wali & Santri'), 'data'],
    ] : ($masuk ? array_values(array_filter([
        ['portal.beranda', 'portal.beranda', __('Beranda'), 'beranda'],
        ['portal.tagihan', 'portal.tagihan', __('Tagihan'), 'tagihan'],
        \App\Http\Controllers\PortalKeluargaController::punyaDompet() ? ['portal.dompet', 'portal.dompet*', __('Dompet'), 'dompet'] : null,
        ['portal.data', 'portal.data*', __('Data Wali & Santri'), 'data'],
        ['portal.akun', 'portal.akun', __('Akun'), 'profil'],
    ])) : []));
    // Masa demo (12 Nov 2026): pengumuman sampai tanggal resmi. Tak tampil di mode verifikasi link WA (proses
    // sungguhan) & saat wajib membuat password. Jendelanya sekali per sesi; pitanya di setiap halaman.
    $resmiMulai = filled(config('portal.demo_sampai')) ? \Illuminate\Support\Carbon::parse(config('portal.demo_sampai'))->startOfDay() : null;
    $masaDemo = $masuk && ! $verifikasi && ! $wajibSandi && $resmiMulai && now()->lt($resmiMulai);
    $jendelaDemo = $masaDemo && ! request()->session()->has('portal.demo_dilihat');
    if ($jendelaDemo) {
        request()->session()->put('portal.demo_dilihat', true);
    }
    $belumDibaca = $masuk && ! $verifikasi && ! $wajibSandi ?\App\Http\Controllers\PortalKeluargaController::kabarBelumDibaca() : 0;
    $namaMasuk = $masuk ? (\App\Services\PortalWali\SesiWali::wali()->{'nama_'.\App\Services\PortalWali\SesiWali::peran(request())} ?: \App\Services\PortalWali\SesiWali::wali()->nama) : '';
@endphp
<!DOCTYPE html>
{{-- Bahasa & arah tulisan ikut pilihan wali (App\Support\BahasaPortal): Arab = kanan-ke-kiri. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\BahasaPortal::arah() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Portal Wali')) · SANAD Wali</title>
    {{-- Huruf Arab dari perangkat sendiri (tanpa unduhan). Nominal rupiah, nomor VA & PIN tetap ditulis
         kiri-ke-kanan supaya tak terbaca terbalik saat ditransfer. --}}
    <style>
        [dir=rtl] body { font-family: "Noto Naskh Arabic", "Noto Sans Arabic", "Geeza Pro", "Segoe UI", Tahoma, sans-serif; }
        [dir=rtl] .rp, [dir=rtl] .font-mono, [dir=rtl] input[inputmode=numeric], [dir=rtl] input[type=tel], [dir=rtl] input[type=email] { direction: ltr; unicode-bidi: isolate; }
    </style>

    {{-- WAJIB ada: tanpa meta `pwa`, app.js membacanya sebagai "mati" dan MENCABUT
         pekerja layanan di perangkat yang sama (lihat komentar di komponennya).
         Manifest sendiri → terpasang sebagai aplikasi "SANAD Wali". --}}
    {{-- Portal Wali = aplikasi "SANAD Wali" (10 Nov 2026): ikon & nama sendiri, terpisah dari ERP staf. --}}
    <x-kepala-pwa manifest="/manifest-wali.json" judul="SANAD Wali" ikon-apple="/ikon/wali-apple-touch-icon.png?v=1" />
    {{-- 12 Nov 2026: pekerja layanan SENDIRI berlingkup /wali/ (= scope manifest). Chrome Android hanya menyerahkan
         notifikasi ke aplikasi terpasang bila lingkup pekerja layanannya di dalam scope aplikasi; dengan pekerja
         berlingkup "/" notifikasi wali tampil atas nama Chrome & ikon "SANAD Wali" tak pernah berangka. --}}
    <meta name="pwa-lingkup" content="/wali/">
    @if ($masuk && ! $verifikasi)
        {{-- Langganan lama (pekerja berlingkup "/") dilepas dari server sekali per perangkat — lihat app.js. --}}
        <meta name="push-pindah" content="{{ route('portal.push.langganan') }}">
    @endif
    <link rel="icon" type="image/png" href="/ikon/wali-any-192.png?v=2">

    {{-- Mode gelap per perangkat (pilihan di Akun; kosong = ikut setelan HP). Dipasang
         SEBELUM halaman tampil supaya tidak berkedip putih lalu gelap. --}}
    <script>
        window.modeGelap = {
            pilihan() { try { return localStorage.getItem('alwafi_wali_gelap'); } catch (e) { return null; } },
            aktif() { const p = this.pilihan(); return p === null ? window.matchMedia('(prefers-color-scheme: dark)').matches : p === '1'; },
            atur(nyala) { try { localStorage.setItem('alwafi_wali_gelap', nyala ? '1' : '0'); } catch (e) {} this.terapkan(); },
            ikutHp() { try { localStorage.removeItem('alwafi_wali_gelap'); } catch (e) {} this.terapkan(); },
            terapkan() { document.documentElement.classList.toggle('wali-gelap', this.aktif()); },
        };
        window.modeGelap.terapkan();

        // Lapis kedua di samping `no-store`: halaman yang tetap dipulihkan dari bfcache
        // (mis. browser yang mengabaikan header) dimuat ulang dari server.
        window.addEventListener('pageshow', (e) => { if (e.persisted) location.reload(); });

        // Papan PIN transaksi dompet (lihat partial portal._pin). Formulir pemakai:
        // x-data="pinPad(punyaPin, urlBuatPin)" @submit.prevent="buka()". PIN dikirim
        // bersama transaksinya dan diperiksa di server — layar ini hanya pengetiknya.
        window.pinPad = (punya, urlBuat) => ({
            pin: '', terbuka: false, memproses: false,
            // Belum punya PIN → ke pembuatan PIN, membawa alamat halaman ini supaya sesudahnya wali kembali ke sini.
            buka() { if (! this.$root.reportValidity()) return; if (! punya) { location.href = urlBuat + (urlBuat.includes('?') ? '&' : '?') + 'kembali=' + encodeURIComponent(location.pathname + location.search); return; } this.pin = ''; this.terbuka = true; },
            tutup() { if (this.memproses) return; this.terbuka = false; this.pin = ''; },
            // PIN lengkap → papan berganti animasi "Memproses…" sampai halaman berikutnya termuat (21 Okt 2026),
            // supaya jeda server tak terlihat macet dan papan tak bisa diketik/ditutup dua kali.
            ketik(d) {
                if (this.pin.length >= 6 || this.memproses) return;
                this.pin += d;
                if (this.pin.length === 6) { this.memproses = true; this.$nextTick(() => this.$root.submit()); }
            },
            hapus() { if (! this.memproses) this.pin = this.pin.slice(0, -1); },
        });

        // Pemilih berkas (bukti transfer, berkas PSB): FOTO DIKECILKAN DI HP sebelum dikirim.
        // Batas unggahan aplikasi 500 KB (App\Support\Unggahan), sedangkan foto kamera HP
        // lazimnya 2–4 MB — tanpa ini hampir setiap wali ditolak. PDF tak bisa dikecilkan,
        // jadi yang terlalu besar diberi tahu sebelum terkirim. `kirim` = langsung kirim
        // formulirnya begitu berkas siap (dipakai di halaman Berkas).
        window.unggahBukti = (maks, kirim = false) => ({
            nama: '', keterangan: @json(__('Foto dikecilkan otomatis sebelum dikirim.')), pratinjau: null, galat: '', sibuk: false, seret: false,
        // Seret & lepas dari komputer (21 Okt 2026): berkas yang dilepas di kotak unggah diperlakukan
        // sama dengan yang dipilih — masuk ke <input type=file> di dalam kotak itu, lalu lewat pilih().
        jatuhkan(e) {
            this.seret = false;
            const berkas = e.dataTransfer?.files?.[0], input = e.currentTarget.querySelector('input[type=file]');
            if (! berkas || ! input || this.sibuk) return;
            if (! (berkas.type.startsWith('image/') || berkas.type === 'application/pdf')) {
                this.galat = @json(__('Hanya foto atau PDF.')); return;
            }
            const dt = new DataTransfer(); dt.items.add(berkas); input.files = dt.files;
            this.pilih({ target: input });
        },
            async pilih(e) {
                const input = e.target, berkas = input.files[0];
                this.galat = ''; this.pratinjau = null;
                if (! berkas) return;
                this.nama = berkas.name;
                if (! berkas.type.startsWith('image/')) {
                    this.keterangan = Math.round(berkas.size / 1024) + ' KB';
                    if (berkas.size > maks) { this.galat = @json(__('PDF terlalu besar (maks :ukuran KB). Kirim fotonya saja.')).replace(':ukuran', Math.round(maks / 1024)); input.value = ''; this.nama = ''; return; }
                } else {
                    try {
                        const hasil = berkas.size <= maks * 0.95 ? berkas : await this.kecilkan(berkas, maks);
                        const dt = new DataTransfer(); dt.items.add(hasil); input.files = dt.files;
                        this.pratinjau = URL.createObjectURL(hasil);
                        this.keterangan = Math.round(hasil.size / 1024) + ' KB';
                    } catch (_) {
                        this.galat = @json(__('Foto tidak bisa diproses. Coba foto lain.')); input.value = ''; this.nama = ''; return;
                    }
                }
                if (kirim && input.form.reportValidity()) { this.sibuk = true; input.form.submit(); }
            },
            async kecilkan(berkas, maks) {
                const gambar = await createImageBitmap(berkas);
                const namaJpg = (berkas.name.replace(/\.[^.]+$/, '') || 'berkas') + '.jpg';
                for (const [sisi, mutu] of [[1600, .75], [1400, .6], [1200, .5], [1000, .45], [800, .4]]) {
                    const skala = Math.min(1, sisi / Math.max(gambar.width, gambar.height));
                    const kanvas = document.createElement('canvas');
                    kanvas.width = Math.round(gambar.width * skala); kanvas.height = Math.round(gambar.height * skala);
                    kanvas.getContext('2d').drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
                    const blob = await new Promise(r => kanvas.toBlob(r, 'image/jpeg', mutu));
                    if (blob && blob.size <= maks * 0.95) return new File([blob], namaJpg, { type: 'image/jpeg' });
                }
                throw new Error('terlalu besar');
            },
        });
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased">
    {{-- LAYAR PEMBUKA SANAD Wali (10 Nov 2026). Layar pembuka bawaan Android hanya memuat warna, ikon & nama —
         keterangan kepanjangan SANAD ditampilkan di sini, tepat sesudahnya, dengan latar biru yang sama.
         Hanya saat dibuka sebagai APLIKASI dari layar utama HP (standalone), sekali per pembukaan; di peramban biasa
         langsung dibuang oleh skrip di bawahnya SEBELUM tergambar. iPhone (tanpa layar pembuka bawaan) ikut terbantu. --}}
    <div id="pembuka-sanad" aria-hidden="true"
         style="position:fixed;inset:0;z-index:100;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;background:#164a9e;color:#fff;text-align:center;padding:24px;transition:opacity .4s ease">
        <img src="/ikon/wali-any-512.png?v=2" alt="" style="width:112px;height:112px">
        <div style="font-size:24px;font-weight:700;margin-top:6px">SANAD Wali</div>
        <div style="font-size:13px;opacity:.85;max-width:280px;line-height:1.4;text-wrap:balance">Sistem Administrasi, Neraca, dan Akademik Digital</div>
        <div style="position:absolute;bottom:calc(28px + env(safe-area-inset-bottom));font-size:12px;opacity:.7">AL Wafi Islamic Boarding School</div>
    </div>
    <script>
        (function () {
            var el = document.getElementById('pembuka-sanad');
            var aplikasi = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            var sudah = false;
            try { sudah = sessionStorage.getItem('pembuka-sanad') === '1'; sessionStorage.setItem('pembuka-sanad', '1'); } catch (e) {}
            if (! aplikasi || sudah) { el.remove(); return; }
            var mulai = Date.now();
            var tutup = function () { if (! el.isConnected) return; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 450); };
            window.addEventListener('load', function () { setTimeout(tutup, Math.max(0, 1200 - (Date.now() - mulai))); });
            setTimeout(tutup, 4000);   // pengaman: halaman yang lambat dimuat tak tertahan di layar pembuka
        })();
    </script>
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur" style="padding-top: env(safe-area-inset-top)">
        <div class="mx-auto flex h-14 max-w-lg items-center gap-2 px-3">
            @if ($masuk && ! $diBeranda)
                {{-- Ke halaman INDUK yang tetap (section `kembali` di tiap halaman; bawaan: beranda),
                     BUKAN history.back(): riwayat browser mengulang halaman yang sudah dilewati —
                     sesudah membayar, ia membuka lagi halaman Bayar beserta papan PIN-nya. --}}
                <a href="@yield('kembali', $verifikasi ? route('portal.verifikasi.ringkasan') : route('portal.beranda'))"
                   class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100" aria-label="{{ __('Kembali') }}">
                    <x-portal.ikon nama="kembali" />
                </a>
                <p class="min-w-0 flex-1 truncate text-center font-semibold text-slate-900">@yield('title', __('Portal Wali'))</p>
            @else
                <a href="{{ $verifikasi ? route('portal.verifikasi.ringkasan') : ($masuk ? route('portal.beranda') : route('portal.depan')) }}" class="flex min-w-0 flex-1 items-center gap-2">
                    <x-portal.logo class="h-9 w-9 shrink-0 rounded-xl" />
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold text-slate-900">SANAD Wali</span>
                        <span class="block truncate text-xs text-slate-500">AL Wafi Islamic Boarding School</span>
                    </span>
                </a>
            @endif
            {{-- Pemilih bahasa di setiap halaman, juga sebelum masuk (gaya pemilih bahasa Windows). --}}
            <x-portal.pilih-bahasa class="shrink-0" />
            @if ($verifikasi || $wajibSandi)
                <span class="w-10 shrink-0"></span>
            @elseif ($masuk)
                <a href="{{ route('portal.kabar') }}" class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100"
                   aria-label="{{ $belumDibaca ? __('Kabar, :jumlah belum dibaca', ['jumlah' => $belumDibaca]) : __('Kabar') }}">
                    <x-portal.ikon nama="lonceng" />
                    @if ($belumDibaca > 0)
                        <span class="absolute end-0.5 top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ $belumDibaca > 99 ? '99+' : $belumDibaca }}</span>
                    @endif
                </a>
                <a href="{{ route('portal.akun') }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-bold text-white" aria-label="{{ __('Akun') }}">
                    {{ mb_strtoupper(mb_substr($namaMasuk, 0, 1)) }}
                </a>
            @else
                <span class="w-10 shrink-0"></span>
            @endif
        </div>
    </header>

    <main @class(['mx-auto max-w-lg px-4 pt-4', 'pb-28' => $masuk, 'pb-10' => ! $masuk])>
        @if ($masaDemo)
            {{-- Warna DIPAKU lewat style (12 Nov 2026): kelas amber diubah mode gelap (.wali-gelap .text-amber-900 → kuning
                 muda) sehingga tulisan kuning di atas latar kuning tak terbaca. --}}
            <div class="mb-4 rounded-2xl px-4 py-2.5 text-[13px] font-medium leading-snug" style="background-color:#fbbf24;color:#1c1917" data-pita-demo>
                <b>{{ __('Versi demo.') }}</b>
                {{ __('Aplikasi ini masih dalam tahap uji coba dan belum resmi digunakan.') }}
                @if ($terbatas)
                    {{ __('Semua menu bisa dilihat, tetapi yang bisa digunakan saat ini hanya pembaruan data keluarga dan unggah berkas santri.') }}
                @endif
            </div>
        @endif
        @if ($jendelaDemo)
            <div x-data="{ buka: true }" x-show="buka" x-cloak data-jendela-demo
                 class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4" role="dialog" aria-modal="true">
                <div class="w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-xl">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-2xl">📢</div>
                    <h2 class="text-lg font-bold text-slate-900">{{ __('Aplikasi masih versi demo') }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ __('SANAD Wali masih dalam tahap uji coba (demo) dan belum resmi digunakan.') }}
                    </p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        {{ __('Data yang tampil bisa belum lengkap atau masih berubah. Semua urusan dengan pesantren tetap melalui jalur yang biasa.') }}
                    </p>
                    <button type="button" @click="buka = false"
                            class="mt-5 w-full rounded-2xl bg-brand px-4 py-3 text-sm font-semibold text-white hover:bg-brand-dark">{{ __('Saya mengerti') }}</button>
                </div>
            </div>
        @endif
        @if (session('status'))
            <div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">{{ session('error') }}</div>
        @endif

        @yield('content')

        <p class="mt-8 text-center text-xs text-slate-400">SANAD Wali · AL Wafi Islamic Boarding School</p>
    </main>

    @if ($masuk)
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white" style="padding-bottom: env(safe-area-inset-bottom)">
            <div class="mx-auto flex max-w-lg">
                @foreach ($menu as [$rute, $pola, $label, $ikon])
                    @php $aktif = request()->routeIs($pola); @endphp
                    <a href="{{ route($rute) }}" @if ($aktif) aria-current="page" @endif
                       @class(['flex min-w-0 flex-1 flex-col items-center gap-0.5 px-0.5 py-2 text-center text-[11px] leading-tight', 'font-semibold text-brand' => $aktif, 'text-slate-500' => ! $aktif])>
                        <x-portal.ikon :nama="$ikon" />
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </nav>

        {{-- Angka di ikon aplikasi = kabar belum dibaca (12 Nov 2026); disegarkan tiap halaman. Mode verifikasi tak menyentuhnya. --}}
        @unless ($verifikasi)
            <script>if ('setAppBadge' in navigator) { ({{ (int) $belumDibaca }} > 0 ? navigator.setAppBadge({{ (int) $belumDibaca }}) : navigator.clearAppBadge()).catch(() => {}); }</script>
        @endunless

        {{-- Tombol Bantuan melayang (11 Nov 2026): ikon Bantuan tak ada di 7 ikon Beranda bawaan, wali tak menemukannya
             di Menu Lengkap. Tampil hanya bila asisten wali menyala & bukan mode verifikasi link WA. --}}
        @if (\App\Services\PortalWali\AsistenWali::aktif() && ! \App\Services\PortalWali\SesiWali::modeVerifikasi(request()) && ! request()->routeIs('portal.bantuan'))
            <a href="{{ route('portal.bantuan') }}" aria-label="{{ __('Bantuan') }}" title="{{ __('Bantuan') }}"
               class="fixed right-4 z-30 flex h-12 w-12 items-center justify-center rounded-full bg-brand text-white shadow-lg ring-4 ring-white"
               style="bottom: calc(4.75rem + env(safe-area-inset-bottom))">
                <x-portal.ikon nama="bantuan" />
            </a>
        @endif
    @endif
</body>
</html>
