<?php

use App\Models\ReminderSetting;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\KenaikanTingkatService;
use App\Services\Modules\ReminderTagihanService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reminder:tagihan', function (ReminderTagihanService $service) {
    $hasil = $service->kirim();
    $this->info("Reminder tagihan: {$hasil['terkirim']} notifikasi baru ({$hasil['kandidat']} tagihan dalam jendela pengingat).");
})->purpose('Kirim notifikasi reminder tagihan yang mendekati jatuh tempo');

// Jadwal harian pada jam dari pengaturan. try/catch: file ini juga dimuat saat
// DB belum ada (mis. migrate pertama) — jangan sampai artisan gagal boot.
try {
    $jamKirim = ReminderSetting::query()->value('jam_kirim') ?: '07:00';
} catch (Throwable) {
    $jamKirim = '07:00';
}
Schedule::command('reminder:tagihan')->dailyAt($jamKirim);

Artisan::command('santri:terapkan-jadwal', function (KenaikanTingkatService $service) {
    $hasil = $service->terapkanYangJatuhTempo();
    $this->info("Perubahan santri diterapkan: {$hasil['diterapkan']}.");
    foreach ($hasil['gagal'] as $g) {
        $this->warn("Santri #{$g['id_santri']} gagal: {$g['pesan']}");
    }
})->purpose('Nyalakan perubahan santri (naik/mengulang/melanjutkan/lulus) yang tahun ajarannya sudah dimulai');

// Dini hari: pergantian tahun ajaran jatuh pada 1 Juli, dan perubahannya
// sebaiknya sudah menyala sebelum ada yang membuka aplikasi hari itu.
//
// TIDAK BOLEH jadi satu-satunya pemicu. Produksi berjalan di paket gratis yang
// tidur, sehingga cron bisa tak pernah menyala sama sekali — karena itu halaman
// Kenaikan Tingkat & daftar santri ikut memanggilnya (lihat controllernya).
// Penerapnya idempoten, jadi dipanggil dari kedua arah pun aman.
Schedule::command('santri:terapkan-jadwal')->dailyAt('00:30');

// Belanja kartu santri di Wafi Mart (penghubung, Fase 3): jurnal ringkas per tanggal untuk transaksi
// sebelum hari ini — D Titipan Uang Saku / K Hutang ke Wafi Mart. Aman diulang; kosong = tak berbuat apa-apa.
Artisan::command('dompet:jurnal-belanja-toko', function (App\Services\Modules\JurnalDompetToko $j) {
    foreach ($j->jalankan() as $h) {
        if (isset($h['galat'])) {
            $this->error("{$h['tanggal']}: {$h['galat']}");

            continue;
        }
        $this->info("{$h['tanggal']}: {$h['transaksi']} transaksi, bersih Rp {$h['bersih']}".($h['jurnal'] ? " → jurnal #{$h['jurnal']}" : ' (nol, tanpa jurnal)'));
    }
})->purpose('Jurnal ringkas belanja kartu santri di Wafi Mart (penghubung)');
Schedule::command('dompet:jurnal-belanja-toko')->dailyAt('00:15')->withoutOverlapping();

// Uji pengiriman email (keputusan user 21 Okt 2026: SMTP Email Hostinger, noreply@al-wafi.sch.id). Email gagal
// SENYAP — kode masuk wali tak sampai tanpa galat di layar — jadi alat ini menampilkan pengaturan & galat SMTP apa adanya.
Artisan::command('email:uji {alamat : Alamat tujuan email uji}', function (string $alamat) {
    $m = config('mail.default');
    $c = config("mail.mailers.{$m}", []);
    $this->line("Mailer: {$m}".($m === 'smtp' ? " · {$c['host']}:{$c['port']} ".($c['scheme'] ?? '').' · user '.($c['username'] ?: '-') : ''));
    $this->line('Pengirim: '.config('mail.from.name').' <'.config('mail.from.address').'>');
    if ($m === 'log') {
        $this->warn('MAIL_MAILER=log — email hanya ditulis ke storage/logs, TIDAK dikirim. Isi pengaturan SMTP di .env lalu `php artisan config:cache`.');
    }
    try {
        Illuminate\Support\Facades\Mail::raw(
            'Ini email uji dari '.config('app.name').' ('.now()->format('d/m/Y H:i:s').'). Bila sampai, pengiriman email sudah berfungsi.',
            fn ($pesan) => $pesan->to($alamat)->subject('Uji email '.config('app.name'))
        );
    } catch (Throwable $e) {
        $this->error('GAGAL: '.class_basename($e).' — '.$e->getMessage());

        return 1;
    }
    $this->info($m === 'log' ? 'Ditulis ke log (tidak terkirim).' : "Terkirim ke {$alamat} — periksa kotak masuk & folder spam.");

    return 0;
})->purpose('Kirim email uji & tampilkan galat SMTP');

// Kabar "tagihan baru" ke keluarga (portal & push wali, kategori tagihan) — satu kabar per anak per sapuan.
// Tagihan dari impor tak dikabarkan. Aman diulang: yang sudah dikabarkan bertanda `dikabarkan_pada`.
Artisan::command('kabar:tagihan-baru', function () {
    $n = App\Services\PortalWali\KabarKeluarga::sapuTagihanBaru();
    if ($n > 0) {
        $this->info("{$n} tagihan baru dikabarkan ke keluarga.");
    }
})->purpose('Kabarkan tagihan baru terbit ke wali (portal & push)');
Schedule::command('kabar:tagihan-baru')->everyFiveMinutes()->withoutOverlapping();

// Sisi Wafi Mart (Fase 3c-2): cocokkan transaksi penghubung kemarin (dan tanggal tertunda ≤ 7 hari) dengan struk toko;
// potongan tanpa struk dikembalikan otomatis ke santri. Di instans Al Wafi tak berbuat apa-apa.
Artisan::command('toko:rekonsiliasi-dompet {--tanggal= : Satu tanggal tertentu (YYYY-MM-DD)}', function (App\Services\Modules\RekonsiliasiDompetToko $r) {
    if (! App\Services\Modules\KasirService::lewatPenghubung()) {
        $this->info('Bukan instans Wafi Mart — dilewati.');

        return;
    }
    $hasil = $this->option('tanggal') ? [$r->jalankan($this->option('tanggal'))] : $r->jalankanTertunda();
    foreach ($hasil as $h) {
        $this->info("{$h->tanggal->toDateString()}: {$h->status} — {$h->jumlah_transaksi} transaksi, {$h->jumlah_yatim} yatim dikembalikan, {$h->perlu_diputus} perlu diputus".($h->pesan ? " ({$h->pesan})" : ''));
    }
})->purpose('Rekonsiliasi malam belanja kartu santri (Wafi Mart ↔ ERP Al Wafi)');
Schedule::command('toko:rekonsiliasi-dompet')->dailyAt('00:40')->withoutOverlapping();

// Potongan uang pangkal per gelombang: setelah tenggatnya lewat, putuskan
// "terkunci" (syarat bayar terpenuhi) atau "hangus" (ditambahkan kembali ke
// tagihan). Dulu hanya lewat tombol Evaluasi atau saat halaman detail dibuka,
// sehingga potongan yang tenggatnya lewat bisa tetap "berlaku" berminggu-minggu.
// Pemicu lain: verifikasi pembayaran uang pangkal & sebelum akrual aktivasi.
// Idempoten — yang sudah diputuskan dilewati.
Artisan::command('potongan:evaluasi', function (AngsuranUangPangkalService $service) {
    $h = $service->evaluasiPotonganSemua();
    $this->info("Potongan gelombang: diperiksa {$h['dievaluasi']}, terkunci {$h['earned']}, hangus {$h['hangus']}, dibatalkan {$h['batal']}.");
})->purpose('Putuskan potongan uang pangkal yang tenggatnya lewat: terkunci, hangus, atau batal');
Schedule::command('potongan:evaluasi')->dailyAt('00:45')->withoutOverlapping();

// Batch tagihan yang waktu rilisnya sudah lewat. Tiap 5 menit — bukan harian:
// petugas menetapkan waktu rilis sampai ke menitnya, dan menjanjikan "terbit
// pukul 07:00" lalu baru menerbitkannya tengah malam adalah janji yang ingkar.
//
// withoutOverlapping: satu batch bisa berisi ratusan santri dan menulis jurnal.
// Dua proses yang mengerjakannya berbarengan akan saling menimpa.
//
// TIDAK BOLEH jadi satu-satunya pemicu — alasannya sama seperti kenaikan tingkat
// di atas, dan di sini taruhannya lebih besar. BatchTagihanService::pemicuCadangan()
// dipanggil juga dari dalam aplikasi. Penerapnya idempoten.
Schedule::command('tagihan:rilis-terjadwal')->everyFiveMinutes()->withoutOverlapping();

// Pengingat menyusun draft tagihan. Harian, dan SENGAJA tanpa jam yang bisa
// disetel: notifikasi di aplikasi ini hanya terlihat dari dalam aplikasi, jadi
// jam kirim tak membuatnya sampai lebih cepat — yang menentukan tetap kapan
// petugasnya masuk. 05:00 supaya sudah menunggu sebelum jam kerja.
Schedule::command('pengingat:terbit-tagihan')->dailyAt('05:00');

// Dorong notifikasi TUGAS ke perangkat staf. Tiap menit — inilah yang membuat
// jedanya paling lama semenit, dan itu batas yang masih terasa seketika bagi
// orang yang menunggu persetujuan.
//
// SENGAJA tidak dipanggil dari tengah permintaan web: mendorong ke sepuluh
// perangkat berarti sepuluh panggilan HTTPS keluar, dan layar staf akan
// menggantung beberapa detik tiap kali ia menyetujui sesuatu.
//
// Diam sendiri bila kunci VAPID belum diisi di lingkungan ini.
Schedule::command('push:kirim')->everyMinute()->withoutOverlapping();
