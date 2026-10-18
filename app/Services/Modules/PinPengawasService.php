<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PinPengawas;
use App\Models\User;
use App\Support\Akses;
use Illuminate\Support\Facades\Hash;

/**
 * PIN PENGAWAS KASIR — persetujuan dua tangan DI perangkat kasir (diskon manual, retur).
 *
 * Pengawas = pemegang hak `shift-kasir` / `ubah`. Ia memasang PIN-nya sendiri di Profil;
 * di layar kasir ia memilih namanya lalu mengetik PIN. Hak diperiksa SAAT verifikasi,
 * bukan saat PIN dipasang — pengawas yang dicabut haknya otomatis tak bisa lagi menyetujui.
 *
 * Lima kali salah berturut-turut → PIN terkunci 15 menit (menebak 6 angka di depan kasir
 * tak boleh semudah mencoba terus).
 */
class PinPengawasService
{
    public const MAKS_GAGAL = 5;

    public const KUNCI_MENIT = 15;

    public function setel(User $user, string $pin): void
    {
        if (! Akses::bolehUntuk($user, 'shift-kasir', 'ubah')) {
            throw new AppException(403, 'PIN pengawas hanya untuk pemegang hak Shift Kasir & Setoran (ubah).');
        }
        if (! preg_match('/^\d{6}$/', $pin)) {
            throw new AppException(422, 'PIN pengawas harus 6 angka.');
        }
        if (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321'], true)) {
            throw new AppException(422, 'PIN terlalu mudah ditebak — hindari angka kembar atau berurutan.');
        }

        PinPengawas::updateOrCreate(['id_pengguna' => $user->id_pengguna], [
            'pin_hash' => Hash::make($pin), 'gagal' => 0, 'terkunci_sampai' => null,
        ]);
    }

    public static function punya(User $user): bool
    {
        return PinPengawas::whereKey($user->id_pengguna)->exists();
    }

    /**
     * Periksa persetujuan. Mengembalikan pengawasnya bila sah.
     * `$idKasir` tak boleh menyetujui dirinya sendiri (admin dikecualikan).
     */
    public function verifikasi(?int $idPengawas, ?string $pin, int $idKasir): User
    {
        $pengawas = $idPengawas ? User::find($idPengawas) : null;
        if (! $pengawas || ! Akses::bolehUntuk($pengawas, 'shift-kasir', 'ubah')) {
            throw new AppException(403, 'Pilih pengawas yang berhak menyetujui.');
        }
        if ($pengawas->id_pengguna === $idKasir && ! $pengawas->is_admin) {
            throw new AppException(403, 'Persetujuan harus dari pengawas lain, bukan kasirnya sendiri.');
        }

        $rec = PinPengawas::find($pengawas->id_pengguna);
        if (! $rec) {
            throw new AppException(422, "{$pengawas->nama} belum memasang PIN pengawas (menu Profil).");
        }
        if ($rec->terkunci_sampai && $rec->terkunci_sampai->isFuture()) {
            throw new AppException(429, "PIN {$pengawas->nama} terkunci sampai {$rec->terkunci_sampai->format('H:i')} karena terlalu sering salah.");
        }

        if (! Hash::check((string) $pin, $rec->pin_hash)) {
            $gagal = $rec->gagal + 1;
            $rec->update([
                'gagal' => $gagal >= self::MAKS_GAGAL ? 0 : $gagal,
                'terkunci_sampai' => $gagal >= self::MAKS_GAGAL ? now()->addMinutes(self::KUNCI_MENIT) : null,
            ]);
            throw new AppException(422, $gagal >= self::MAKS_GAGAL
                ? 'PIN salah 5 kali — terkunci '.self::KUNCI_MENIT.' menit.'
                : 'PIN pengawas salah.');
        }

        if ($rec->gagal > 0) {
            $rec->update(['gagal' => 0]);
        }

        return $pengawas;
    }
}
