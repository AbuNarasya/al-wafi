<?php

namespace App\Services\Penghubung;

use App\Exceptions\AppException;
use App\Http\Middleware\TandaTanganToko;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * KLIEN PENGHUBUNG DOMPET — dipakai kasir di instans Wafi Mart (Fase 3b) untuk memanggil API dompet
 * santri di ERP Al Wafi ([[App\Http\Controllers\Api\DompetTokoController]]). Tanda tangannya memakai rumus
 * yang SAMA dengan penerima (`TandaTanganToko::tanda`), kunci & alamat dari config/penghubung.php.
 *
 * Galat dari ERP Al Wafi diteruskan apa adanya (pesan & kodenya: 404 kartu, 409 perlu PIN, 422 saldo,
 * 423 PIN terkunci). ERP Al Wafi tak menjawab → 503 "silakan bayar tunai" — kasir TIDAK pernah
 * menganggap belanja kartu berhasil tanpa jawaban penghubung.
 */
class KlienDompetToko
{
    public const TIDAK_TERSEDIA = 'Kartu belum bisa diproses (penghubung dompet tak menjawab) — silakan bayar tunai.';

    public function cekKartu(string $uid, string $total): array
    {
        return $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => $uid, 'total' => $total]);
    }

    /** `$rincian` = daftar barang untuk portal wali ([[App\Services\Modules\RincianBelanja]]). */
    public function potong(string $nomor, string $uid, string $nominal, ?string $pin, ?string $kodeToko, ?string $namaToko, ?array $rincian = null): array
    {
        return $this->kirim('POST', '/api/dompet-toko/v1/potong', [
            'nomor' => $nomor, 'uid' => $uid, 'nominal' => $nominal, 'pin' => $pin,
            'kode_toko' => $kodeToko, 'nama_toko' => $namaToko, 'rincian' => $rincian,
        ]);
    }

    public function kembalikan(string $nomor, string $nomorAsal, string $nominal, ?string $keterangan = null, ?array $rincian = null): array
    {
        return $this->kirim('POST', '/api/dompet-toko/v1/kembalikan', [
            'nomor' => $nomor, 'nomor_asal' => $nomorAsal, 'nominal' => $nominal, 'keterangan' => $keterangan,
            'rincian' => $rincian,
        ]);
    }

    /**
     * Pastikan nasib satu nomor (Fase 3c-1): {status: berhasil, ...hasil} atau {status: dibatalkan}. Jawabannya
     * final — sesudah "dibatalkan", permintaan terlambat dengan nomor itu ditolak ERP Al Wafi.
     */
    public function pastikan(string $nomor, string $jenis, string $nominal): array
    {
        return $this->kirim('POST', '/api/dompet-toko/v1/pastikan', ['nomor' => $nomor, 'jenis' => $jenis, 'nominal' => $nominal]);
    }

    /** @return list<array<string,mixed>> */
    public function daftar(string $tanggal): array
    {
        return $this->kirim('GET', '/api/dompet-toko/v1/transaksi', ['tanggal' => $tanggal])['transaksi'] ?? [];
    }

    private function kirim(string $method, string $path, array $isi): array
    {
        $url = rtrim((string) config('penghubung.url'), '/');
        $rahasia = (string) config('penghubung.rahasia');
        if ($url === '' || $rahasia === '') {
            throw new AppException(503, 'Penghubung dompet belum diatur (PENGHUBUNG_TOKO_URL / _RAHASIA) — terima pembayaran tunai.');
        }
        $waktu = (string) time();
        $badan = $method === 'GET' ? '' : json_encode($isi);
        $tanda = TandaTanganToko::tanda($rahasia, $method, $path, $waktu, $badan);
        $req = Http::timeout(8)->connectTimeout(4)->acceptJson()
            ->withHeaders(['X-Penghubung-Waktu' => $waktu, 'X-Penghubung-Tanda' => $tanda]);

        try {
            $res = $method === 'GET'
                ? $req->get($url.$path, $isi)
                : $req->withBody($badan, 'application/json')->post($url.$path);
        } catch (ConnectionException) {
            // Waktu habis / putus: permintaannya bisa saja sudah diproses di sana → TAK PASTI, bukan gagal.
            throw new PenghubungTakPasti;
        }

        if ($res->successful()) {
            return (array) $res->json();
        }
        $status = $res->status();
        $pesan = (string) ($res->json('message') ?? '');
        if ($status >= 500) {
            // ERP Al Wafi galat — bisa sesudah menyimpan; tak pasti.
            report(new \RuntimeException("Penghubung dompet {$path} → {$status}: ".mb_substr($res->body(), 0, 300)));
            throw new PenghubungTakPasti;
        }
        if ($pesan === '' || in_array($status, [401, 403], true)) {
            // 401/403 = kunci/alamat tak cocok: ditolak SEBELUM diproses — pasti tak memotong. Kasir cukup tahu harus tunai.
            report(new \RuntimeException("Penghubung dompet {$path} → {$status}: ".mb_substr($res->body(), 0, 300)));
            throw new AppException(503, self::TIDAK_TERSEDIA);
        }

        throw new AppException($status, $pesan);
    }
}
