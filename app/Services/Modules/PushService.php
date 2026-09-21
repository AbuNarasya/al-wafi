<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Notification;
use App\Models\PushLangganan;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * PUSH NOTIFICATION — mendorong notifikasi yang sudah ada ke perangkat.
 *
 * Ia TIDAK membuat notifikasi baru. Seluruh isinya berasal dari tabel
 * `notifications` yang sudah dipakai lonceng; berkas ini hanya menjadikannya
 * sampai ke ponsel yang sedang di saku.
 *
 * ══ HANYA JENIS TUGAS ══
 * Keputusan user 21 Sep 2026. Mendorong SEMUA notifikasi akan berubah jadi
 * gangguan dalam seminggu, lalu izinnya dicabut orang — dan izin yang sudah
 * dicabut hanya bisa dipulihkan lewat pengaturan peramban, bukan dari dalam
 * aplikasi. Sekali hilang, sulit kembali.
 *
 * ══ LEWAT CRON, BUKAN DI TENGAH PERMINTAAN WEB ══
 * Mendorong ke sepuluh perangkat berarti sepuluh panggilan HTTPS keluar.
 * Ditaruh di tengah permintaan, layar staf menggantung beberapa detik setiap
 * kali ia menyetujui sesuatu. Harganya: tertunda paling lama satu menit.
 *
 * ══ KALAU KUNCINYA BELUM DIISI, SEMUANYA DIAM ══
 * Tanpa VAPID di `.env`, tak ada yang meledak: tombol langganan tak muncul dan
 * perilisnya tidur. Aplikasi tetap berjalan persis seperti sebelum fitur ini
 * ada, dan itu memang yang benar untuk lingkungan yang belum disiapkan.
 */
class PushService
{
    /** Fiturnya hidup hanya bila kedua kunci VAPID terisi. */
    public function aktif(): bool
    {
        return (string) config('push.vapid.public') !== ''
            && (string) config('push.vapid.private') !== '';
    }

    public function kunciPublik(): ?string
    {
        return $this->aktif() ? (string) config('push.vapid.public') : null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  LANGGANAN
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simpan (atau perbarui) langganan sebuah perangkat.
     *
     * Dikunci pada `endpoint`, bukan pada pengguna: perangkat yang sama
     * mendaftar ulang harus MEMPERBARUI barisnya. Tanpa itu, tiap kali
     * seseorang menekan "Nyalakan" lahir baris baru dan ia mulai menerima
     * notifikasi yang sama berkali-kali.
     *
     * `id_pengguna` ikut diperbarui dengan sengaja — satu ponsel bisa berpindah
     * tangan, atau dipakai dua staf bergantian di peramban yang sama.
     */
    public function simpanLangganan(int $idPengguna, array $data, ?string $userAgent = null): PushLangganan
    {
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new AppException(422, 'Data langganan dari peramban tidak lengkap.');
        }
        if (mb_strlen($endpoint) > 500) {
            throw new AppException(422, 'Alamat langganan terlalu panjang untuk disimpan.');
        }

        return PushLangganan::updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'id_pengguna' => $idPengguna,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'perangkat' => $this->ringkasPerangkat($userAgent),
                'gagal_beruntun' => 0,
            ],
        );
    }

    /** Matikan di perangkat ini. Izin di ponselnya TIDAK ikut tercabut. */
    public function hapusLangganan(int $idPengguna, string $endpoint): int
    {
        return PushLangganan::where('id_pengguna', $idPengguna)
            ->where('endpoint', $endpoint)->delete();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,PushLangganan> */
    public function langgananPengguna(int $idPengguna)
    {
        return PushLangganan::where('id_pengguna', $idPengguna)->orderByDesc('id')->get();
    }

    /**
     * Ringkasan perangkat dari User-Agent — untuk dikenali pemiliknya, dan
     * supaya sebaran ponsel staf akhirnya terjawab tanpa perlu bertanya.
     */
    public function ringkasPerangkat(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return 'Perangkat tak dikenal';
        }

        $sistem = match (true) {
            // iPad modern menyamar sebagai Macintosh, jadi iPad diperiksa lebih dulu.
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Perangkat lain',
        };

        // Urutannya penting: Edge & Opera menyebut "Chrome" di UA-nya juga.
        $peramban = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'peramban lain',
        };

        return "{$sistem} · {$peramban}";
    }

    // ══════════════════════════════════════════════════════════════════
    //  PENGIRIMAN
    // ══════════════════════════════════════════════════════════════════

    /**
     * Dorong notifikasi tugas yang belum pernah didorong.
     *
     * @return array{disapu:int, terkirim:int, gagal:int, langganan_dibuang:int}
     */
    public function kirimYangBelum(): array
    {
        $ringkas = ['disapu' => 0, 'terkirim' => 0, 'gagal' => 0, 'langganan_dibuang' => 0];

        $antre = Notification::whereNull('didorong_pada')
            ->whereIn('jenis', NotificationService::JENIS_TUGAS)
            ->orderBy('id')
            ->limit((int) config('push.batas_sapuan', 200))
            ->get();

        if ($antre->isEmpty()) {
            return $ringkas;
        }
        $ringkas['disapu'] = $antre->count();

        // Yang sudah basi dikeluarkan dari antrean TANPA dikirim. Lihat catatan
        // `umur_maksimal_menit` di config/push.php — inilah penjaga yang mencegah
        // sapuan pertama meledakkan puluhan notifikasi lama sekaligus.
        $batas = now()->subMinutes((int) config('push.umur_maksimal_menit', 120));
        [$segar, $basi] = $antre->partition(fn ($n) => $n->created_at && $n->created_at->gte($batas));

        if ($basi->isNotEmpty()) {
            Notification::whereIn('id', $basi->pluck('id'))->update(['didorong_pada' => now()]);
        }
        if ($segar->isEmpty() || ! $this->aktif()) {
            // Kunci belum diisi: tetap tandai supaya antreannya tak menumpuk
            // selamanya menunggu lingkungan yang mungkin tak pernah disiapkan.
            if ($segar->isNotEmpty()) {
                Notification::whereIn('id', $segar->pluck('id'))->update(['didorong_pada' => now()]);
            }

            return $ringkas;
        }

        $langganan = PushLangganan::whereIn('id_pengguna', $segar->pluck('id_pengguna')->unique())
            ->get()->groupBy('id_pengguna');

        // Antreannya disusun DULU, pengirimnya dirakit belakangan. Bukan sekadar
        // kerapian: konstruktor WebPush memvalidasi kunci VAPID, jadi merakitnya
        // untuk keadaan "tak seorang pun berlangganan" berarti melempar galat
        // atas kunci yang bahkan tak akan dipakai.
        $antreanKirim = [];
        $perEndpoint = [];

        foreach ($segar as $n) {
            foreach ($langganan->get($n->id_pengguna, collect()) as $l) {
                $perEndpoint[$l->endpoint] = $l;
                $antreanKirim[] = [$l, json_encode($this->muatan($n), JSON_UNESCAPED_UNICODE)];
            }
        }

        if ($antreanKirim === []) {
            Notification::whereIn('id', $segar->pluck('id'))->update(['didorong_pada' => now()]);

            return $ringkas;
        }

        $webPush = $this->webPush();
        foreach ($antreanKirim as [$l, $muatan]) {
            $webPush->queueNotification(
                new Subscription($l->endpoint, $l->p256dh, $l->auth),
                $muatan,
                ['TTL' => (int) config('push.ttl', 43200)],
            );
        }

        // Ditandai SEBELUM hasil dibaca, dan itu disengaja: kalau pengiriman
        // meledak di tengah, notifikasi yang sudah telanjur terkirim tak boleh
        // ikut terdorong ulang pada sapuan berikutnya. Notifikasi ganda jauh
        // lebih merusak kepercayaan daripada satu yang tak sampai.
        Notification::whereIn('id', $segar->pluck('id'))->update(['didorong_pada' => now()]);

        foreach ($webPush->flush() as $laporan) {
            $l = $perEndpoint[$laporan->getEndpoint()] ?? null;

            if ($laporan->isSuccess()) {
                $ringkas['terkirim']++;
                $l?->update(['gagal_beruntun' => 0, 'terakhir_berhasil' => now()]);

                continue;
            }

            $ringkas['gagal']++;

            // 404/410 dari layanan push = langganannya memang sudah mati.
            // Tak ada gunanya menunggu lima kali gagal untuk yang satu ini.
            if ($laporan->isSubscriptionExpired()) {
                $l?->delete();
                $ringkas['langganan_dibuang']++;

                continue;
            }

            if ($l) {
                $l->increment('gagal_beruntun');
                if ($l->gagal_beruntun >= PushLangganan::BATAS_GAGAL) {
                    $l->delete();
                    $ringkas['langganan_dibuang']++;
                }
            }

            Log::warning('Push gagal', [
                'endpoint' => substr($laporan->getEndpoint(), 0, 60).'…',
                'alasan' => $laporan->getReason(),
            ]);
        }

        return $ringkas;
    }

    /**
     * Kirim satu notifikasi uji ke seluruh perangkat seorang pengguna.
     *
     * Ada karena fitur ini gagalnya SENYAP: kunci salah, pekerja layanan versi
     * lama, izin dicabut diam-diam dari pengaturan ponsel — semuanya berakhir
     * sama, yaitu tak ada yang muncul. Menunggu tugas sungguhan untuk mengetahui
     * apakah pemasangannya benar berarti mengetahuinya justru saat ia dibutuhkan.
     *
     * @return array{perangkat:int, terkirim:int, gagal:int, pesan:list<string>}
     */
    public function kirimUji(int $idPengguna): array
    {
        $hasil = ['perangkat' => 0, 'terkirim' => 0, 'gagal' => 0, 'pesan' => []];

        if (! $this->aktif()) {
            $hasil['pesan'][] = 'Kunci VAPID belum diisi di lingkungan ini.';

            return $hasil;
        }

        $langganan = $this->langgananPengguna($idPengguna);
        $hasil['perangkat'] = $langganan->count();

        if ($langganan->isEmpty()) {
            $hasil['pesan'][] = 'Pengguna ini belum menyalakan notifikasi di perangkat mana pun.';

            return $hasil;
        }

        $webPush = $this->webPush();
        // Penandanya SELALU BARU tiap kali diuji, tidak tetap `uji-coba`.
        // Penanda yang sama membuat notifikasi uji berikutnya sekadar MENGGANTI
        // isi yang masih tergeletak di bilah notifikasi — dan sebagian versi
        // Android melakukan penggantian itu tanpa bunyi maupun getar. Alat uji
        // yang diam-diam tak membunyikan apa pun adalah alat uji yang menipu
        // orang yang sedang mencari sebab notifikasinya tak berbunyi.
        $muatan = json_encode([
            'judul' => 'Uji coba notifikasi',
            'pesan' => 'Kalau ini muncul, notifikasi di perangkat ini sudah berfungsi. ('.now()->format('H:i:s').')',
            'tautan' => '/profil',
            'tag' => 'uji-'.now()->timestamp,
        ], JSON_UNESCAPED_UNICODE);

        $peta = [];
        foreach ($langganan as $l) {
            $peta[$l->endpoint] = $l;
            $webPush->queueNotification(
                new Subscription($l->endpoint, $l->p256dh, $l->auth),
                $muatan,
                ['TTL' => 60],
            );
        }

        foreach ($webPush->flush() as $laporan) {
            $l = $peta[$laporan->getEndpoint()] ?? null;

            if ($laporan->isSuccess()) {
                $hasil['terkirim']++;
                $l?->update(['gagal_beruntun' => 0, 'terakhir_berhasil' => now()]);

                continue;
            }

            $hasil['gagal']++;
            $hasil['pesan'][] = ($l?->perangkat ?? 'Perangkat').': '.$laporan->getReason();

            // Uji coba pun membuang langganan yang jelas sudah mati — tak ada
            // gunanya menyimpan alamat yang layanan push-nya sendiri menolak.
            if ($laporan->isSubscriptionExpired()) {
                $l?->delete();
            }
        }

        return $hasil;
    }

    /**
     * Isi yang dibaca `sw.js`. Sengaja ramping — muatan push dibatasi ±4 KB,
     * dan yang penting cuma tiga: judulnya, kalimatnya, dan ke mana ia menuju.
     *
     * `tag` membuat notifikasi untuk dokumen yang sama saling MENGGANTI alih-
     * alih menumpuk. Dokumen yang statusnya berubah tiga kali tak perlu jadi
     * tiga baris di layar kunci.
     */
    private function muatan(Notification $n): array
    {
        return [
            'judul' => (string) $n->judul,
            'pesan' => mb_strimwidth((string) $n->pesan, 0, 180, '…'),
            'tautan' => (new NotificationService)->tautan($n) ?? '/',
            'tag' => $n->jenis.':'.($n->ref_id ?? $n->id),
        ];
    }

    private function webPush(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => (string) config('push.vapid.subject'),
            'publicKey' => (string) config('push.vapid.public'),
            'privateKey' => (string) config('push.vapid.private'),
        ]]);
    }
}
