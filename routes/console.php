<?php

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
