/**
 * Pekerja layanan AL Wafi — LINGKUPNYA SENGAJA SEMPIT.
 *
 * Yang disimpan HANYA aset statis hasil build (`/build/…`, nama berkasnya sudah
 * mengandung hash sehingga isinya tak pernah berubah) dan satu halaman
 * "tidak ada koneksi".
 *
 * Halaman ber-SESI TIDAK PERNAH DISIMPAN, dan itu keputusan yang disengaja:
 * halaman tersimpan bisa menampilkan data milik pengguna yang login sebelumnya,
 * atau angka keuangan basi yang tampak mutakhir. Untuk aplikasi kas & tagihan,
 * salah satu dari keduanya lebih berbahaya daripada layar "tidak ada koneksi".
 *
 * Jadi tidak ada mode luring untuk data. Servernya memang menyala terus; yang
 * diberikan pekerja layanan ini cuma dua: aplikasi terbuka layar penuh dari
 * layar utama, dan aset yang tak perlu diunduh ulang tiap kali.
 *
 * Mematikannya: setel env PWA_AKTIF=false lalu deploy. Halaman akan mencabut
 * pendaftaran pekerja layanan ini sendiri saat dimuat (lihat app.js).
 */
// Naikkan nomornya setiap kali isi `/ikon/` diganti: `activate` membuang semua
// simpanan bernama lain, dan itu satu-satunya cara memaksa perangkat yang sudah
// terlanjur menyimpan lambang lama untuk mengambilnya lagi SEKETIKA.
const VERSI = 'alwafi-v2';
const LURING = '/luring.html';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSI).then((c) => c.add(LURING)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((nama) => Promise.all(nama.filter((n) => n !== VERSI).map((n) => caches.delete(n))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;

    // Hanya GET. POST (simpan, hapus, login) tak pernah disentuh — mencegatnya
    // berisiko menggandakan transaksi keuangan.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Aset build: isi tetap selamanya (nama berkas ber-hash) → ambil dari
    // simpanan lebih dulu, unduh sekali lalu simpan.
    if (url.pathname.startsWith('/build/')) {
        e.respondWith(
            caches.match(req).then((tersimpan) => tersimpan || fetch(req).then((res) => {
                if (res.ok) {
                    const salinan = res.clone();
                    caches.open(VERSI).then((c) => c.put(req, salinan));
                }

                return res;
            })),
        );

        return;
    }

    // IKON — sengaja TIDAK disamakan dengan aset build. Namanya tetap
    // (`/ikon/ikon-192.png`) walau gambarnya diganti, jadi menyimpannya
    // selamanya berarti lambang lama menempel di dialog "tambahkan ke layar
    // utama" sampai pemakainya mencopot aplikasinya sendiri.
    //
    // Sajikan yang tersimpan supaya tetap cepat dan tetap ada saat luring, TAPI
    // selalu unduh ulang di latar untuk pemakaian berikutnya.
    if (url.pathname.startsWith('/ikon/')) {
        e.respondWith(
            caches.open(VERSI).then((c) => c.match(req).then((tersimpan) => {
                const segar = fetch(req).then((res) => {
                    if (res.ok) {
                        c.put(req, res.clone());
                    }

                    return res;
                }).catch(() => tersimpan);

                return tersimpan || segar;
            })),
        );

        return;
    }

    // Sisanya SELALU dari jaringan. Bila gagal dan yang diminta adalah sebuah
    // halaman, barulah layar "tidak ada koneksi" ditampilkan.
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match(LURING)));
    }
});
