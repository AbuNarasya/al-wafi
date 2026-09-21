<?php

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
    $jamKirim = \App\Models\ReminderSetting::query()->value('jam_kirim') ?: '07:00';
} catch (\Throwable) {
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
