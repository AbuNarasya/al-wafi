<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ZONA WAKTU APLIKASI — Asia/Jakarta, bukan UTC bawaan Laravel.
 *
 * Seluruh pemakai aplikasi ini berada di satu zona waktu, dan setiap angka
 * waktu yang mereka lihat atau tentukan berarti WIB. Dengan UTC, pengaturan
 * reminder `07:00` mengirim pukul 14:00 WIB, dan pergantian hari untuk
 * hitungan jatuh tempo terjadi pukul 07:00 pagi alih-alih tengah malam.
 *
 * Dijaga test karena `config/app.php` ikut tertimpa saat kerangka Laravel
 * diperbarui, dan kembalinya ke UTC tak menimbulkan satu pun galat — hanya
 * angka yang diam-diam bergeser tujuh jam.
 *
 * Tanpa database: yang diuji setelan dan tafsir waktu, bukan penyimpanannya.
 */
class ZonaWaktuTest extends TestCase
{
    public function test_zona_waktu_aplikasi_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', date_default_timezone_get(),
            'Laravel menyetel zona waktu PHP dari config — kalau meleset, date() dan Carbon berbeda pendapat');
    }

    /** `now()` yang dipakai di seluruh service harus WIB, bukan UTC. */
    public function test_now_mengikuti_wib(): void
    {
        $this->assertSame('Asia/Jakarta', now()->timezone->getName());
        $this->assertSame(7 * 3600, now()->getOffset(), 'WIB = UTC+7, tanpa daylight saving');
    }

    /**
     * Penjaga yang paling menentukan: tengah malam menurut aplikasi harus
     * tengah malam WIB. Inilah titik yang memutuskan sebuah tagihan dihitung
     * "lewat jatuh tempo" hari ini atau besok — dipakai OutstandingLainService,
     * ReminderTagihanService, dan dashboard aging piutang.
     */
    public function test_pergantian_hari_terjadi_tengah_malam_wib(): void
    {
        $tengahMalam = Carbon::now()->startOfDay();

        $this->assertSame('00:00:00', $tengahMalam->format('H:i:s'));
        $this->assertSame('Asia/Jakarta', $tengahMalam->timezone->getName());
        // Tengah malam WIB = 17:00 UTC hari sebelumnya. Kalau aplikasi kembali
        // ke UTC, angka ini jadi 00:00 dan penjaganya gugur.
        $this->assertSame('17:00:00', $tengahMalam->copy()->utc()->format('H:i:s'));
    }

    /**
     * Jam yang diketik petugas — `jam_kirim` pada pengaturan reminder —
     * ditafsirkan sebagai WIB, dan itulah yang dipakai `Schedule::dailyAt()`.
     *
     * Angka 00:00 UTC di bawah adalah inti perbaikan ini: dulu "07:00" berarti
     * 07:00 UTC alias 14:00 WIB; sekarang ia berarti 07:00 WIB.
     */
    public function test_jam_yang_diketik_petugas_berarti_wib(): void
    {
        $jam = Carbon::createFromFormat('H:i', '07:00');

        $this->assertSame('Asia/Jakarta', $jam->timezone->getName());
        $this->assertSame('07:00', $jam->format('H:i'));
        $this->assertSame('00:00', $jam->copy()->utc()->format('H:i'));
    }
}
