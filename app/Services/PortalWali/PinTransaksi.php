<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\PinWali;
use App\Models\Wali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PIN TRANSAKSI DOMPET — milik SATU orang (keluarga + peran).
 *
 * Diperiksa DI SERVER pada permintaan yang sama dengan transaksinya — tak ada
 * "jendela terbuka 5 menit". Kiriman formulir yang disusun sendiri tanpa PIN
 * pun tertolak, karena pemeriksaannya ada di sini, bukan di layar.
 *
 * Dipakai: bayar tagihan dari dompet, pindah saldo, pengajuan tarik tabungan,
 * dan MENGAKTIFKAN auto-debet (izin memotong untuk seterusnya).
 */
class PinTransaksi
{
    public const PANJANG = 6;

    public const MAKS_GAGAL = 5;

    public const MENIT_KUNCI = 30;

    /** Ditolak karena terlalu mudah ditebak. */
    private const LEMAH = ['000000', '111111', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999',
        '123456', '654321', '012345', '543210', '123123', '112233', '121212', '696969'];

    public function __construct(private readonly Wali $wali, private readonly string $peran) {}

    private function baris(): ?PinWali
    {
        return PinWali::where('id_wali', $this->wali->id)->where('peran', $this->peran)->first();
    }

    public function punya(): bool
    {
        return $this->baris() !== null;
    }

    /** Menit tersisa bila sedang terkunci, null bila tidak. */
    public function terkunci(): ?int
    {
        $b = $this->baris();

        return $b?->terkunci_sampai?->isFuture() ? (int) ceil(now()->diffInMinutes($b->terkunci_sampai, true)) : null;
    }

    /** @throws AppException bila PIN tak memenuhi syarat */
    public static function assertSah(string $pin, ?string $ulang = null): void
    {
        if (! preg_match('/^\d{'.self::PANJANG.'}$/', $pin)) {
            throw new AppException(422, 'PIN harus '.self::PANJANG.' angka.');
        }
        if (in_array($pin, self::LEMAH, true)) {
            throw new AppException(422, 'PIN terlalu mudah ditebak. Pilih kombinasi lain.');
        }
        if ($ulang !== null && $pin !== $ulang) {
            throw new AppException(422, 'Ulangan PIN tidak sama.');
        }
    }

    /**
     * Simpan PIN baru — dipanggil HANYA sesudah kode OTP email benar (buat pertama /
     * lupa PIN) atau sesudah PIN lama benar (ganti). Hitungan gagal & kunci dihapus.
     */
    public function simpan(string $pin): void
    {
        self::assertSah($pin);
        PinWali::updateOrCreate(
            ['id_wali' => $this->wali->id, 'peran' => $this->peran],
            ['pin_hash' => Hash::make($pin), 'gagal' => 0, 'terkunci_sampai' => null, 'diubah_pada' => now()],
        );
    }

    /**
     * Periksa PIN untuk sebuah transaksi.
     *
     * @throws AppException 428 bila belum punya PIN, 423 bila terkunci, 422 bila salah
     */
    public function periksa(?string $pin): void
    {
        // Dikunci baris PIN-nya: dua permintaan bersamaan tak boleh sama-sama
        // lolos menghitung "gagal ke-4" lalu tak pernah mengunci.
        //
        // Galat dilempar SESUDAH transaksi selesai: melemparnya di dalam akan
        // MEMBATALKAN penambahan hitungan gagal, dan PIN tak pernah terkunci.
        $hasil = DB::transaction(function () use ($pin) {
            $b = PinWali::where('id_wali', $this->wali->id)->where('peran', $this->peran)->lockForUpdate()->first();
            if (! $b) {
                return [428, 'Buat PIN transaksi terlebih dahulu.'];
            }
            if ($b->terkunci_sampai?->isFuture()) {
                $menit = (int) ceil(now()->diffInMinutes($b->terkunci_sampai, true));

                return [423, "PIN terkunci karena salah berulang kali. Coba lagi dalam {$menit} menit, atau setel ulang lewat Lupa PIN."];
            }
            if ($pin !== null && Hash::check($pin, $b->pin_hash)) {
                if ($b->gagal > 0 || $b->terkunci_sampai) {
                    $b->update(['gagal' => 0, 'terkunci_sampai' => null]);
                }

                return null;
            }

            // Kunci yang sudah lewat masanya: hitungan dimulai dari nol lagi.
            $gagal = ($b->terkunci_sampai ? 0 : $b->gagal) + 1;
            $kunci = $gagal >= self::MAKS_GAGAL;
            $b->update(['gagal' => $gagal, 'terkunci_sampai' => $kunci ? now()->addMinutes(self::MENIT_KUNCI) : null]);
            if ($kunci) {
                KabarWali::kePencatat((object) ['dicatat_oleh' => null, 'dicatat_oleh_wali' => $this->wali->id, 'dicatat_oleh_peran' => $this->peran], [
                    'judul' => 'PIN transaksi terkunci',
                    'pesan' => 'PIN dompet Anda salah '.self::MAKS_GAGAL.' kali dan dikunci '.self::MENIT_KUNCI.' menit. '
                        .'Bila bukan Anda yang mencoba, segera ubah PIN dan hubungi panitia.',
                    'jenis' => 'pin_terkunci',
                ]);

                return [423, 'PIN salah '.self::MAKS_GAGAL.' kali. PIN dikunci '.self::MENIT_KUNCI.' menit.'];
            }

            return [422, 'PIN salah. Sisa percobaan: '.(self::MAKS_GAGAL - $gagal).'.'];
        });

        if ($hasil !== null) {
            throw new AppException($hasil[0], $hasil[1]);
        }
    }
}
