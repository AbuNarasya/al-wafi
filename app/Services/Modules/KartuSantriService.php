<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\DompetSantri;
use App\Models\KartuSantri;
use App\Models\PinSantri;
use App\Models\Santri;
use App\Services\PortalWali\PinTransaksi;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * KARTU RFID & PENGAMAN BELANJA SANTRI (D1) — keputusan user 19 Okt 2026.
 *
 *  • Satu santri satu kartu AKTIF; memasang kartu baru otomatis memblokir yang lama.
 *    UID unik selamanya — kartu yang pernah dipakai orang lain tak bisa dipindahtangankan.
 *  • Blokir: wali (portal, dengan PIN wali) & staf. Membuka blokir / mengganti: staf saja.
 *  • PIN belanja anak dipasang WALI; belanja di atas batas harian meminta PIN ini.
 *    Lima kali salah → terkunci 30 menit (sama dengan PIN wali).
 *  • Batas harian per anak diatur wali; kosong = batas bawaan Pengaturan (Rp 20.000).
 */
class KartuSantriService
{
    public const MAKS_GAGAL_PIN = 5;

    public const MENIT_KUNCI_PIN = 30;

    /**
     * Rapikan UID dari pembaca kartu: spasi & tanda pemisah dibuang, huruf dibesarkan.
     * null bila bentuknya bukan UID (pembaca keyboard-emulation kadang ikut mengetik
     * karakter liar bila fokus berpindah).
     */
    public static function bersihkanUid(?string $uid): ?string
    {
        $uid = strtoupper(preg_replace('/[\s:\-]/', '', (string) $uid));

        return preg_match('/^[0-9A-Z]{4,64}$/', $uid) ? $uid : null;
    }

    public function pasang(int $idSantri, string $uid, ?int $idPengguna, ?int $idBatch = null): KartuSantri
    {
        $uid = self::bersihkanUid($uid) ?? throw new AppException(422, 'UID kartu tidak terbaca. Tempelkan ulang kartunya.');
        $santri = Santri::find($idSantri) ?? throw new AppException(404, 'Santri tidak ditemukan.');
        if ($santri->status !== 'aktif') {
            throw new AppException(422, "{$santri->nama} bukan santri aktif.");
        }

        $ada = KartuSantri::with('santri:id,nama')->where('uid', $uid)->first();
        if ($ada && $ada->id_santri !== $santri->id) {
            throw new AppException(422, "Kartu ini sudah terdaftar atas nama {$ada->santri?->nama}.");
        }
        if ($ada && $ada->status === 'aktif') {
            return $ada;   // sudah terpasang — tak ada yang berubah
        }
        if ($ada) {
            throw new AppException(422, 'Kartu ini pernah diblokir. Buka blokirnya dari daftar kartu bila memang ditemukan kembali.');
        }

        return DB::transaction(function () use ($santri, $uid, $idPengguna, $idBatch) {
            // Kartu lama yang masih aktif = digantikan; diblokir supaya tak bisa dipakai lagi.
            KartuSantri::where('id_santri', $santri->id)->where('status', 'aktif')->get()
                ->each(fn ($k) => $k->update(['status' => 'diblokir', 'alasan_blokir' => 'Diganti kartu baru',
                    'diblokir_pada' => now(), 'diblokir_oleh' => $idPengguna]));

            return KartuSantri::create([
                'uid' => $uid, 'id_santri' => $santri->id, 'status' => 'aktif',
                'dipasang_oleh' => $idPengguna, 'id_batch' => $idBatch,
            ]);
        });
    }

    public function blokir(KartuSantri $kartu, string $alasan, ?int $idPengguna, ?int $idWali = null): KartuSantri
    {
        if ($kartu->status === 'diblokir') {
            return $kartu;
        }
        $kartu->update([
            'status' => 'diblokir', 'alasan_blokir' => trim($alasan) ?: 'Kartu hilang', 'diblokir_pada' => now(),
            'diblokir_oleh' => $idPengguna, 'diblokir_oleh_wali' => $idWali,
        ]);

        return $kartu;
    }

    /** Buka blokir — staf saja (kartu ditemukan kembali). Ditolak bila santrinya sudah memakai kartu lain. */
    public function bukaBlokir(KartuSantri $kartu): KartuSantri
    {
        if ($kartu->status === 'aktif') {
            return $kartu;
        }
        if (KartuSantri::where('id_santri', $kartu->id_santri)->where('status', 'aktif')->exists()) {
            throw new AppException(422, 'Santri ini sudah memakai kartu lain yang aktif. Blokir kartu itu dulu bila ingin kembali ke kartu ini.');
        }
        $kartu->update(['status' => 'aktif', 'alasan_blokir' => null, 'diblokir_pada' => null, 'diblokir_oleh' => null, 'diblokir_oleh_wali' => null]);

        return $kartu;
    }

    /** Kartu aktif untuk UID itu, atau null. */
    public function cariAktif(?string $uid): ?KartuSantri
    {
        $uid = self::bersihkanUid($uid);

        return $uid ? KartuSantri::with('santri')->where('uid', $uid)->where('status', 'aktif')->first() : null;
    }

    // ---- Batas harian ----

    public static function batasBawaan(): string
    {
        return Money::of(CompanySettings::find(1)?->batas_jajan_harian ?? 20000);
    }

    /** Batas yang berlaku untuk santri ini (miliknya sendiri, atau bawaan). */
    public static function batasHarian(int $idSantri): string
    {
        $milik = DompetSantri::where('id_santri', $idSantri)->value('batas_harian');

        return $milik !== null ? Money::of($milik) : self::batasBawaan();
    }

    /** Wali mengatur batas harian anaknya. null = kembali ke batas bawaan. */
    public function aturBatas(Santri $santri, string|int|float|null $nominal): DompetSantri
    {
        $nominal = ($nominal === null || $nominal === '') ? null : Money::of($nominal);
        if ($nominal !== null && Money::isNegative($nominal)) {
            throw new AppException(422, 'Batas harian tidak boleh negatif.');
        }
        $dompet = DompetSantri::firstOrCreate(['id_santri' => $santri->id], ['saldo' => 0]);
        $dompet->update(['batas_harian' => $nominal]);

        return $dompet;
    }

    // ---- PIN belanja santri ----

    public static function punyaPin(int $idSantri): bool
    {
        return PinSantri::whereKey($idSantri)->exists();
    }

    /** Dipasang/diganti WALI dari portal (PIN wali sudah diperiksa pemanggil). */
    public function pasangPin(Santri $santri, string $pin, ?string $ulang, int $idWali, string $peran): void
    {
        PinTransaksi::assertSah($pin, $ulang);
        PinSantri::updateOrCreate(['id_santri' => $santri->id], [
            'pin_hash' => Hash::make($pin), 'gagal' => 0, 'terkunci_sampai' => null,
            'dipasang_oleh_wali' => $idWali, 'dipasang_oleh_peran' => $peran,
        ]);
    }

    /**
     * Periksa PIN belanja santri. Hitungan gagal disimpan LEBIH DULU, galat dilempar
     * sesudahnya — kalau dilempar di dalam transaksi pembayaran, hitungannya ikut tergulung
     * dan PIN tak pernah terkunci.
     */
    public function periksaPin(int $idSantri, ?string $pin): void
    {
        $rec = PinSantri::find($idSantri);
        if (! $rec) {
            throw new AppException(428, 'Belanja di atas batas harian butuh PIN, tetapi wali belum memasang PIN belanja anak ini.');
        }
        if ($rec->terkunci_sampai && $rec->terkunci_sampai->isFuture()) {
            throw new AppException(423, 'PIN belanja terkunci sampai '.$rec->terkunci_sampai->format('H:i').' karena terlalu sering salah.');
        }
        if (Hash::check((string) $pin, $rec->pin_hash)) {
            if ($rec->gagal > 0) {
                $rec->update(['gagal' => 0]);
            }

            return;
        }

        $gagal = $rec->gagal + 1;
        $kunci = $gagal >= self::MAKS_GAGAL_PIN;
        $rec->update(['gagal' => $kunci ? 0 : $gagal, 'terkunci_sampai' => $kunci ? now()->addMinutes(self::MENIT_KUNCI_PIN) : null]);
        throw new AppException(422, $kunci ? 'PIN salah 5 kali — terkunci '.self::MENIT_KUNCI_PIN.' menit.' : 'PIN belanja salah.');
    }
}
