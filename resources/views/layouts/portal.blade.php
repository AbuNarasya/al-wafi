@php
    // Portal Wali — bingkai bergaya SANAD Wali (bilah atas + bilah bawah, isi berkartu),
    // dengan palet ERP: biru brand #164a9e + aksen emas #f8c400.
    $masuk = \App\Services\PortalWali\SesiWali::wali() !== null;
    // Mode verifikasi (masuk lewat link dari staf): hanya layar verifikasi & data yang terbuka.
    $verifikasi = $masuk && \App\Services\PortalWali\SesiWali::modeVerifikasi(request()) !== null;
    // Wajib membuat password dulu: menu disembunyikan — semua tujuannya dialihkan kembali ke sini.
    $wajibSandi = $masuk && (bool) request()->session()->get(\App\Services\PortalWali\SesiWali::WAJIB_SANDI);
    $diBeranda = request()->routeIs('portal.beranda', 'portal.masuk', 'portal.kode', 'portal.verifikasi.ringkasan', 'portal.sandi.buat');
    $menu = $wajibSandi ? [] : ($verifikasi ? [
        ['portal.verifikasi.ringkasan', 'portal.verifikasi.*', 'Verifikasi', 'absensi'],
        ['portal.data', 'portal.data*', 'Data Wali & Santri', 'data'],
    ] : ($masuk ? array_values(array_filter([
        ['portal.beranda', 'portal.beranda', 'Beranda', 'beranda'],
        ['portal.tagihan', 'portal.tagihan', 'Tagihan', 'tagihan'],
        \App\Http\Controllers\PortalKeluargaController::punyaDompet() ? ['portal.dompet', 'portal.dompet*', 'Dompet', 'dompet'] : null,
        ['portal.data', 'portal.data*', 'Data Wali & Santri', 'data'],
        ['portal.akun', 'portal.akun', 'Akun', 'profil'],
    ])) : []));
    $belumDibaca = $masuk && ! $verifikasi && ! $wajibSandi ? \App\Http\Controllers\PortalKeluargaController::kabarBelumDibaca() : 0;
    $namaMasuk = $masuk ? (\App\Services\PortalWali\SesiWali::wali()->{'nama_'.\App\Services\PortalWali\SesiWali::peran(request())} ?: \App\Services\PortalWali\SesiWali::wali()->nama) : '';
@endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal Wali') · Portal Wali Al Wafi</title>

    {{-- WAJIB ada: tanpa meta `pwa`, app.js membacanya sebagai "mati" dan MENCABUT
         pekerja layanan di perangkat yang sama (lihat komentar di komponennya).
         Manifest sendiri → terpasang sebagai aplikasi "Al Wafi Wali". --}}
    <x-kepala-pwa manifest="/manifest-wali.json" judul="Al Wafi Wali" />

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
            pin: '', terbuka: false,
            buka() { if (! this.$root.reportValidity()) return; if (! punya) { location.href = urlBuat; return; } this.pin = ''; this.terbuka = true; },
            tutup() { this.terbuka = false; this.pin = ''; },
            ketik(d) {
                if (this.pin.length >= 6) return;
                this.pin += d;
                if (this.pin.length === 6) this.$nextTick(() => this.$root.submit());
            },
            hapus() { this.pin = this.pin.slice(0, -1); },
        });

        // Pemilih berkas (bukti transfer, berkas PSB): FOTO DIKECILKAN DI HP sebelum dikirim.
        // Batas unggahan aplikasi 500 KB (App\Support\Unggahan), sedangkan foto kamera HP
        // lazimnya 2–4 MB — tanpa ini hampir setiap wali ditolak. PDF tak bisa dikecilkan,
        // jadi yang terlalu besar diberi tahu sebelum terkirim. `kirim` = langsung kirim
        // formulirnya begitu berkas siap (dipakai di halaman Berkas).
        window.unggahBukti = (maks, kirim = false) => ({
            nama: '', keterangan: 'Foto dikecilkan otomatis sebelum dikirim.', pratinjau: null, galat: '', sibuk: false,
            async pilih(e) {
                const input = e.target, berkas = input.files[0];
                this.galat = ''; this.pratinjau = null;
                if (! berkas) return;
                this.nama = berkas.name;
                if (! berkas.type.startsWith('image/')) {
                    this.keterangan = Math.round(berkas.size / 1024) + ' KB';
                    if (berkas.size > maks) { this.galat = 'PDF terlalu besar (maks ' + Math.round(maks / 1024) + ' KB). Kirim fotonya saja.'; input.value = ''; this.nama = ''; return; }
                } else {
                    try {
                        const hasil = berkas.size <= maks * 0.95 ? berkas : await this.kecilkan(berkas, maks);
                        const dt = new DataTransfer(); dt.items.add(hasil); input.files = dt.files;
                        this.pratinjau = URL.createObjectURL(hasil);
                        this.keterangan = Math.round(hasil.size / 1024) + ' KB';
                    } catch (_) {
                        this.galat = 'Foto tidak bisa diproses. Coba foto lain.'; input.value = ''; this.nama = ''; return;
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
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur" style="padding-top: env(safe-area-inset-top)">
        <div class="mx-auto flex h-14 max-w-lg items-center gap-2 px-3">
            @if ($masuk && ! $diBeranda)
                {{-- Ke halaman INDUK yang tetap (section `kembali` di tiap halaman; bawaan: beranda),
                     BUKAN history.back(): riwayat browser mengulang halaman yang sudah dilewati —
                     sesudah membayar, ia membuka lagi halaman Bayar beserta papan PIN-nya. --}}
                <a href="@yield('kembali', $verifikasi ? route('portal.verifikasi.ringkasan') : route('portal.beranda'))"
                   class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100" aria-label="Kembali">
                    <x-portal.ikon nama="kembali" />
                </a>
                <p class="min-w-0 flex-1 truncate text-center font-semibold text-slate-900">@yield('title', 'Portal Wali')</p>
            @else
                <a href="{{ $verifikasi ? route('portal.verifikasi.ringkasan') : ($masuk ? route('portal.beranda') : route('portal.depan')) }}" class="flex min-w-0 flex-1 items-center gap-2">
                    <x-logo class="h-9 w-9 shrink-0 rounded-xl text-sm" />
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold text-slate-900">AL Wafi Islamic Boarding School</span>
                        <span class="block text-xs text-slate-500">Portal Wali</span>
                    </span>
                </a>
            @endif
            @if ($verifikasi || $wajibSandi)
                <span class="w-10 shrink-0"></span>
            @elseif ($masuk)
                <a href="{{ route('portal.kabar') }}" class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100"
                   aria-label="Kabar{{ $belumDibaca ? ', '.$belumDibaca.' belum dibaca' : '' }}">
                    <x-portal.ikon nama="lonceng" />
                    @if ($belumDibaca > 0)
                        <span class="absolute right-0.5 top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ $belumDibaca > 99 ? '99+' : $belumDibaca }}</span>
                    @endif
                </a>
                <a href="{{ route('portal.akun') }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-bold text-white" aria-label="Akun">
                    {{ mb_strtoupper(mb_substr($namaMasuk, 0, 1)) }}
                </a>
            @else
                <span class="w-10 shrink-0"></span>
            @endif
        </div>
    </header>

    <main @class(['mx-auto max-w-lg px-4 pt-4', 'pb-28' => $masuk, 'pb-10' => ! $masuk])>
        @if (session('status'))
            <div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">{{ session('error') }}</div>
        @endif

        @yield('content')

        <p class="mt-8 text-center text-xs text-slate-400">Portal Wali · AL Wafi Islamic Boarding School</p>
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
    @endif
</body>
</html>
