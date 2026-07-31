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
