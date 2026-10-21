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
 *    UID unik di antara kartu yang masih terdaftar; staf bisa MENGHAPUS PENDAFTARAN sebuah kartu
 *    (21 Okt 2026) supaya kartunya bisa dipasang ke santri lain — lihat hapusPendaftaran().
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

        // Kartu yang pendaftarannya sudah dihapus (`dilepas`) tak lagi mengikat UID-nya.
        $ada = KartuSantri::with('santri:id,nama')->where('uid', $uid)->where('status', '!=', 'dilepas')->first();
        if ($ada && $ada->id_santri !== $santri->id) {
            throw new AppException(422, "Kartu ini sudah terdaftar atas nama {$ada->santri?->nama}. Bila kartunya memang dipindahkan, hapus dulu pendaftarannya di baris santri itu (atau di tab Kartu diblokir).");
        }
        if ($ada && $ada->status === 'aktif') {
            return $ada;   // sudah terpasang — tak ada yang berubah
        }
        return DB::transaction(function () use ($santri, $uid, $idPengguna, $idBatch, $ada) {
            // Kartu lama yang masih aktif = digantikan; diblokir supaya tak bisa dipakai lagi.
            KartuSantri::where('id_santri', $santri->id)->where('status', 'aktif')->get()
                ->each(fn ($k) => $k->update(['status' => 'diblokir', 'alasan_blokir' => 'Diganti kartu baru',
                    'diblokir_pada' => now(), 'diblokir_oleh' => $idPengguna]));

            // Kartu milik santri ini sendiri yang dulu diblokir, ditempel ulang oleh staf = ditemukan /
            // dipakai lagi → langsung aktif kembali (21 Okt 2026), tanpa harus lewat tab Kartu diblokir.
            if ($ada) {
                $ada->update(['status' => 'aktif', 'alasan_blokir' => null, 'diblokir_pada' => null,
                    'diblokir_oleh' => null, 'diblokir_oleh_wali' => null]);

                return $ada;
            }

            return KartuSantri::create([
                'uid' => $uid, 'id_santri' => $santri->id, 'status' => 'aktif',
                'dipasang_oleh' => $idPengguna, 'id_batch' => $idBatch,
            ]);
        });
    }

    /** Jumlah transaksi yang tercatat atas UID ini (belanja, retur, permintaan kartu, transaksi penghubung toko). */
    public static function riwayat(string $uid): int
    {
        return \App\Models\Penjualan::where('uid_kartu', $uid)->count()
            + \App\Models\TransaksiDompetToko::where('uid_kartu', $uid)->count()
            + \App\Models\PermintaanKartu::where('uid_kartu', $uid)->count();
    }

    /**
     * HAPUS PENDAFTARAN (keputusan user 21 Okt 2026) — staf, hak hapus. Kartu tanpa riwayat dihapus
     * bersih; yang sudah punya riwayat DILEPAS (baris tetap ada untuk jejak, riwayat tetap milik santri
     * lama lewat id_santri tiap transaksi). Keduanya membebaskan UID untuk didaftarkan ke santri lain.
     *
     * @return 'dihapus'|'dilepas'
     */
    public function hapusPendaftaran(KartuSantri $kartu, ?int $idPengguna): string
    {
        if ($kartu->status === 'dilepas') {
            return 'dilepas';
        }
        $kartu->loadMissing('santri:id,nama');
        $riwayat = self::riwayat($kartu->uid);
        $sebelum = $kartu->status;
        $hasil = DB::transaction(function () use ($kartu, $riwayat, $idPengguna) {
            if ($riwayat === 0) {
                $kartu->delete();

                return 'dihapus';
            }
            $kartu->update(['status' => 'dilepas', 'dilepas_pada' => now(), 'dilepas_oleh' => $idPengguna]);

            return 'dilepas';
        });
        \App\Support\Audit\Jejak::catat('hapus_pendaftaran_kartu', [
            'modul' => 'kartu-santri', 'ref_jenis' => 'KartuSantri', 'ref_id' => $kartu->id, 'id_pengguna' => $idPengguna,
            'detail' => ['uid' => $kartu->uid, 'santri' => $kartu->santri?->nama, 'id_santri' => $kartu->id_santri,
                'status_sebelum' => $sebelum, 'hasil' => $hasil, 'riwayat' => $riwayat],
        ]);

        return $hasil;
    }

    public function blokir(KartuSantri $kartu, string $alasan, ?int $idPengguna, ?int $idWali = null): KartuSantri
    {
        if ($kartu->status === 'diblokir' || $kartu->status === 'dilepas') {
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
        if ($kartu->status === 'dilepas') {
            throw new AppException(422, 'Pendaftaran kartu ini sudah dihapus. Pasang ulang kartunya bila ingin dipakai lagi.');
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
        // Baris PIN DIKUNCI selama diperiksa (audit 21 Okt 2026): tebakan serentak dulu sama-sama membaca gagal=0
        // sehingga batas 5 kali bisa dilampaui. Galat dilempar SESUDAH transaksi pendek ini tersimpan.
        [$kode, $info] = DB::transaction(function () use ($idSantri, $pin) {
            $rec = PinSantri::lockForUpdate()->find($idSantri);
            if (! $rec) {
                return [428, null];
            }
            if ($rec->terkunci_sampai && $rec->terkunci_sampai->isFuture()) {
                return [423, $rec->terkunci_sampai->format('H:i')];
            }
            if (Hash::check((string) $pin, $rec->pin_hash)) {
                if ($rec->gagal > 0) {
                    $rec->update(['gagal' => 0]);
                }

                return [0, null];
            }
            $gagal = $rec->gagal + 1;
            $kunci = $gagal >= self::MAKS_GAGAL_PIN;
            $rec->update(['gagal' => $kunci ? 0 : $gagal, 'terkunci_sampai' => $kunci ? now()->addMinutes(self::MENIT_KUNCI_PIN) : null]);

            return [422, $kunci];
        });

        match ($kode) {
            0 => null,
            428 => throw new AppException(428, 'Belanja di atas batas harian butuh PIN, tetapi wali belum memasang PIN belanja anak ini.'),
            423 => throw new AppException(423, "PIN belanja terkunci sampai {$info} karena terlalu sering salah."),
            default => throw new AppException(422, $info ? 'PIN salah 5 kali — terkunci '.self::MENIT_KUNCI_PIN.' menit.' : 'PIN belanja salah.'),
        };
    }
}
