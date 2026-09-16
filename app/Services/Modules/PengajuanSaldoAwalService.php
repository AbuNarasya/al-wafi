<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\PengajuanPembayaran;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PENGAJUAN BELUM DIBAYAR — pintu manual saldo awal, di samping pintu impor.
 *
 * Hutang yang sudah disetujui di pembukuan lama tetapi belum dicairkan saat
 * pindah sistem. Dokumennya lahir langsung berstatus `diposting` — satu-satunya
 * status yang diterima Kas Keluar — TANPA melewati pemohon, verifikasi, maupun
 * rantai persetujuan, dan TANPA jurnal.
 *
 * ⚠️ PINTU PALING SENSITIF DI SELURUH MODUL SALDO AWAL. Yang dilahirkannya bisa
 * langsung dicairkan jadi uang. Di jalur impor hal itu masih wajar: berkasnya
 * disusun sekali saat pindahan, dengan pratinjau, oleh orang yang memang sedang
 * memindahkan sistem. Sebagai tombol yang menetap di layar, ia adalah cara sah
 * mengeluarkan uang tanpa persetujuan siapa pun — karena itu wewenangnya
 * menumpang `impor-data-awal` (bukan `pengajuan-pembayaran`), dan layarnya
 * menyebutkan akibat itu terang-terangan alih-alih menyembunyikannya.
 *
 * Nilainya masuk buku besar lewat baris turunan di menu Saldo Awal
 * (lihat SaldoAwalTurunan), bukan lewat jurnal dokumen ini.
 */
class PengajuanSaldoAwalService
{
    /**
     * @param  array{nomor:string,tanggal:string,kode_bagian:string,kode_coa_hutang:string,kode_coa_beban:string,kode_unit:string,nominal:string|int|float,keterangan:string}  $data
     */
    public function tambah(array $data, ?int $idPengguna): PengajuanPembayaran
    {
        $nomor = trim($data['nomor']);
        if (PengajuanPembayaran::where('nomor', $nomor)->exists()) {
            throw new AppException(422, "Nomor \"{$nomor}\" sudah dipakai dokumen pengajuan lain.");
        }

        $hutang = CoaDetail::find($data['kode_coa_hutang']);
        $beban = CoaDetail::find($data['kode_coa_beban']);
        if (! $hutang || ! $beban) {
            throw new AppException(422, 'Akun hutang atau akun beban tidak ditemukan.');
        }

        $nominal = Money::of($data['nominal']);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal harus lebih besar dari nol.');
        }

        return DB::transaction(function () use ($data, $nomor, $hutang, $beban, $nominal, $idPengguna) {
            $rec = PengajuanPembayaran::create([
                'nomor' => $nomor,
                'tanggal' => $data['tanggal'],
                'jenis' => 'pembayaran',
                'kode_bagian' => $data['kode_bagian'],
                'kode_coa_hutang' => $hutang->kode_coa,
                'nominal' => $nominal,
                'sisa_hutang' => $nominal,
                'keterangan' => $data['keterangan'],
                // Satu-satunya status yang diterima Kas Keluar. Tanpa ini
                // dokumennya ada tetapi tak bisa dilunasi dari mana pun.
                'status' => 'diposting',
                'saldo_awal' => true,
                'id_pengguna' => $idPengguna,
            ]);

            $rec->details()->create([
                'kode_coa' => $beban->kode_coa,
                'nama_coa' => $beban->nama_coa,
                'nominal' => $nominal,
                'kode_unit' => $data['kode_unit'],
                'keterangan' => $data['keterangan'],
            ]);

            return $rec;
        });
    }

    /**
     * Buang dokumen saldo awal yang keliru — selama belum tersentuh apa pun.
     *
     * Tak ada jurnal yang perlu dibalik; yang perlu dijaga justru uangnya.
     * Begitu sebagian sudah dicairkan lewat Kas Keluar, pembatalannya bukan lagi
     * urusan pintu ini melainkan void pengajuan biasa, yang membalik jurnal
     * pembayarannya.
     */
    public function hapus(int $id): void
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(404, 'Dokumen tidak ditemukan.');
        }

        $halangan = self::halangan($rec);
        if ($halangan !== []) {
            throw new AppException(422, implode(' ', $halangan));
        }

        DB::transaction(function () use ($rec) {
            $rec->details()->delete();
            $rec->delete();
        });
    }

    /**
     * Alasan sebuah dokumen tak lagi boleh dihapus lewat pintu ini.
     *
     * @return list<string>
     */
    public static function halangan(PengajuanPembayaran $rec): array
    {
        if (! $rec->saldo_awal) {
            return ['Dokumen ini bukan saldo awal — ia lahir dari pengajuan biasa dan sudah berjurnal. '
                .'Pembatalannya lewat Void Pengajuan, supaya jurnalnya ikut dibalik.'];
        }
        if ($rec->status === 'void') {
            return ['Dokumen ini sudah di-void.'];
        }
        // Netto yang sudah dibayar = nominal − sisa. Dipakai alih-alih menghitung
        // baris pembayaran, karena pelunasan lewat Kas Keluar tidak meninggalkan
        // baris di dokumen ini.
        if (! Money::eq($rec->nominal, $rec->sisa_hutang)) {
            return ['Sebagian dokumen ini sudah dicairkan lewat Kas Keluar, jadi buku besar sudah ikut bergerak. '
                .'Pakai Void Pengajuan.'];
        }

        return [];
    }
}
