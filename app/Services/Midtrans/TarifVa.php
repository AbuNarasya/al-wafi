<?php

namespace App\Services\Midtrans;

use App\Exceptions\AppException;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TARIF BIAYA VIRTUAL ACCOUNT (24 Okt 2026) — biaya layanan VA yang dipungut dari wali, harus SAMA dengan yang
 * dipotong Midtrans; kalau tidak, Rekening Midtrans di buku besar perlahan tak cocok dengan saldo Midtrans.
 *
 * Diatur dari Pengaturan Perusahaan dengan tanggal mulai berlaku, supaya perubahan tarif Midtrans yang diumumkan
 * lebih dulu bisa dipasang sebelum harinya. Yang dipakai = baris terakhir yang SUDAH berlaku; belum ada baris =
 * angka `.env` (MIDTRANS_BIAYA_VA). VA yang sudah terbit menyimpan biayanya sendiri — tarif baru tak mengubahnya.
 */
final class TarifVa
{
    public static function berlaku(?Carbon $pada = null): string
    {
        $biaya = DB::table('tarif_biaya_va')->where('berlaku_mulai', '<=', $pada ?? now())
            ->orderByDesc('berlaku_mulai')->orderByDesc('id')->value('biaya');

        return Money::of($biaya ?? (int) config('services.midtrans.biaya_va'));
    }

    /** Tarif terjadwal yang belum berlaku (paling dekat dulu). */
    public static function terjadwal(): Collection
    {
        return DB::table('tarif_biaya_va')->where('berlaku_mulai', '>', now())->orderBy('berlaku_mulai')->get();
    }

    /** @return Collection<int,object> riwayat terbaru dulu, beserta nama pencatat */
    public static function riwayat(int $batas = 20): Collection
    {
        return DB::table('tarif_biaya_va')->leftJoin('users', 'users.id_pengguna', '=', 'tarif_biaya_va.dicatat_oleh')
            ->orderByDesc('berlaku_mulai')->orderByDesc('tarif_biaya_va.id')->limit($batas)
            ->get(['tarif_biaya_va.*', 'users.nama as nama_pencatat']);
    }

    /**
     * Berlaku mulai HARI INI = seketika; tanggal yang akan datang = mulai pukul 00:00 hari itu. Tanggal lampau
     * ditolak — VA yang sudah terbit tetap memakai biaya lamanya, jadi "berlaku surut" hanya menyesatkan.
     */
    public static function simpan(string|int|float $biaya, string $berlakuMulai, ?string $catatan, int $idPengguna): void
    {
        $biaya = Money::of($biaya);
        if (Money::isNegative($biaya) || Money::gt($biaya, '100000')) {
            throw new AppException(422, 'Biaya VA harus antara Rp 0 dan Rp 100.000.');
        }
        $tanggal = Carbon::parse($berlakuMulai)->startOfDay();
        if ($tanggal->lt(today())) {
            throw new AppException(422, 'Tanggal mulai berlaku tidak boleh lampau.');
        }
        $mulai = $tanggal->isToday() ? now() : $tanggal;

        $id = DB::table('tarif_biaya_va')->insertGetId([
            'biaya' => $biaya, 'berlaku_mulai' => $mulai, 'catatan' => trim((string) $catatan) ?: null,
            'dicatat_oleh' => $idPengguna, 'created_at' => now(),
        ]);
        Jejak::catat('ubah_tarif_biaya_va', ['modul' => 'company-settings', 'ref_jenis' => 'TarifBiayaVa', 'ref_id' => $id,
            'id_pengguna' => $idPengguna, 'detail' => ['biaya' => $biaya, 'berlaku_mulai' => $mulai->toDateTimeString()]]);
    }

    /** Tarif terjadwal yang belum berlaku boleh dibatalkan; yang sudah berlaku tidak (jadi riwayat). */
    public static function batalkan(int $id, int $idPengguna): void
    {
        $hapus = DB::table('tarif_biaya_va')->where('id', $id)->where('berlaku_mulai', '>', now())->delete();
        if (! $hapus) {
            throw new AppException(422, 'Hanya tarif yang belum berlaku yang bisa dibatalkan.');
        }
        Jejak::catat('batal_tarif_biaya_va', ['modul' => 'company-settings', 'ref_jenis' => 'TarifBiayaVa', 'ref_id' => $id, 'id_pengguna' => $idPengguna]);
    }
}
