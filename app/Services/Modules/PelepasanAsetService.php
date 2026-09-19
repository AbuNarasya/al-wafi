<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Models\PelepasanAset;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * PELEPASAN ASET TETAP — dijual, dihibahkan, atau dihapuskan.
 *
 * Dulu aset hanya bisa dihapus dari daftar: barisnya lenyap, nilai perolehan
 * dan akumulasi penyusutannya tetap di buku besar, dan register aset bercerai
 * dari pembukuan tanpa gejala.
 *
 * SATU RUMUS untuk ketiga perlakuan:
 *
 *   D  Kas/Bank            harga jual (0 bila bukan penjualan)
 *   D  Akumulasi Penyusutan
 *   K  Aset Tetap          nilai perolehan
 *   D/K Laba-Rugi Pelepasan  selisihnya
 *
 * Hibah dan penghapusan hanyalah penjualan berharga nol — selisihnya persis
 * sebesar nilai buku dan jatuh ke debet sebagai beban. Tiga cabang kode hanya
 * akan melahirkan tiga peluang salah untuk perhitungan yang sama.
 */
class PelepasanAsetService
{
    public const SUMBER = 'PelepasanAset';

    /**
     * @param  array{
     *     kode_aset:string, tanggal:string, perlakuan:string, alasan:string,
     *     harga_jual?:string|int|float|null, kode_rekening?:?string,
     *     kode_coa_akumulasi:string, kode_coa_labarugi:string,
     *     kode_unit?:?string, kode_bagian?:?string
     * }  $input
     */
    public function lepas(array $input, ?int $idPengguna): PelepasanAset
    {
        $aset = Asset::find($input['kode_aset']);
        if (! $aset) {
            throw new AppException(404, 'Aset tidak ditemukan.');
        }
        if ($aset->status !== 'aktif') {
            throw new AppException(409, $aset->status === 'dilepas'
                ? "Aset {$aset->nama_aset} sudah dilepas."
                : "Aset {$aset->nama_aset} masih berstatus draft — lengkapi dulu datanya sebelum dilepas.");
        }

        $perlakuan = $input['perlakuan'];
        if (! isset(PelepasanAset::PERLAKUAN[$perlakuan])) {
            throw new AppException(422, 'Perlakuan pelepasan tidak dikenal.');
        }

        $alasan = trim((string) ($input['alasan'] ?? ''));
        if ($alasan === '') {
            throw new AppException(422, 'Alasan pelepasan wajib diisi — aset adalah harta yayasan, dan alasannya yang akan dibaca saat pelepasannya dipertanyakan.');
        }

        // Penjualan wajib menyebut rekening penerimanya; hibah & penghapusan
        // tak boleh menyebutnya sama sekali (tak ada uang yang masuk).
        $hargaJual = $perlakuan === 'dijual' ? Money::of($input['harga_jual'] ?? 0) : Money::of('0');
        $rekening = null;
        if ($perlakuan === 'dijual') {
            if (! Money::gtZero($hargaJual)) {
                throw new AppException(422, 'Harga jual harus lebih besar dari nol. Bila asetnya diserahkan tanpa bayaran, pilih perlakuan Dihibahkan.');
            }
            $rekening = BankAccount::find($input['kode_rekening'] ?? '');
            if (! $rekening) {
                throw new AppException(422, 'Pilih rekening penerima hasil penjualan.');
            }
        }

        $akumulasiCoa = $this->akun($input['kode_coa_akumulasi'], 'Akumulasi Penyusutan');
        $labaRugiCoa = $this->akun($input['kode_coa_labarugi'], 'Laba/Rugi Pelepasan');
        $asetCoa = $aset->kode_coa ? CoaDetail::find($aset->kode_coa) : null;
        if (! $asetCoa) {
            throw new AppException(422, "Aset \"{$aset->nama_aset}\" belum punya Akun COA. Lengkapi dulu di master Aset Tetap.");
        }

        $perolehan = Money::of($aset->harga_perolehan);
        $akumulasi = Money::of($aset->akumulasi_depresiasi);
        $nilaiBuku = Money::sub($perolehan, $akumulasi);
        $labaRugi = Money::sub($hargaJual, $nilaiBuku);

        // Otorisasi diukur dari NILAI PEROLEHAN, bukan nilai buku: aset yang
        // sudah habis disusutkan nilai bukunya nol, dan mengukur dari situ
        // membuat pelepasan gedung senilai miliaran lolos tanpa batas apa pun.
        Authorization::authorizeByUser($idPengguna, $perolehan);

        return DB::transaction(function () use (
            $aset, $input, $perlakuan, $alasan, $hargaJual, $rekening,
            $akumulasiCoa, $labaRugiCoa, $asetCoa,
            $perolehan, $akumulasi, $nilaiBuku, $labaRugi, $idPengguna
        ) {
            $nomor = $this->nomorBaru($input['tanggal']);
            $bagian = $input['kode_bagian'] ?? null;

            $lines = [];
            if (Money::gtZero($hargaJual)) {
                $lines[] = ['kode_coa' => $rekening->kode_coa, 'debet' => $hargaJual, 'kredit' => '0',
                    'keterangan' => "Hasil penjualan {$aset->nama_aset}"];
            }
            if (Money::gtZero($akumulasi)) {
                $lines[] = ['kode_coa' => $akumulasiCoa->kode_coa, 'debet' => $akumulasi, 'kredit' => '0',
                    'keterangan' => "Penghapusan akumulasi penyusutan {$aset->nama_aset}"];
            }
            $lines[] = ['kode_coa' => $asetCoa->kode_coa, 'debet' => '0', 'kredit' => $perolehan,
                'keterangan' => "Pelepasan {$aset->nama_aset} ({$aset->kode_aset})"];

            if (! Money::isZero($labaRugi)) {
                $untung = Money::gtZero($labaRugi);
                $nilai = $untung ? $labaRugi : Money::sub('0', $labaRugi);
                $lines[] = [
                    'kode_coa' => $labaRugiCoa->kode_coa,
                    'debet' => $untung ? '0' : $nilai,
                    'kredit' => $untung ? $nilai : '0',
                    'keterangan' => ($untung ? 'Laba' : 'Rugi')." pelepasan {$aset->nama_aset}",
                    'kode_bagian' => $bagian,
                ];
            }

            $entry = PostingService::postJournal([
                'referensi' => $nomor,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => PelepasanAset::PERLAKUAN[$perlakuan].": {$aset->nama_aset} — {$alasan}",
                'sumber_modul' => self::SUMBER,
                'id_sumber' => $nomor,
                'id_pengguna' => $idPengguna,
                'lines' => $lines,
            ]);

            try {
                $dok = PelepasanAset::create([
                    'nomor_ref' => $nomor,
                    'kode_aset' => $aset->kode_aset,
                    'tanggal' => $input['tanggal'],
                    'perlakuan' => $perlakuan,
                    'harga_jual' => $hargaJual,
                    'kode_rekening' => $rekening?->kode_coa,
                    'kode_coa_akumulasi' => $akumulasiCoa->kode_coa,
                    'kode_coa_labarugi' => $labaRugiCoa->kode_coa,
                    'kode_unit' => $input['kode_unit'] ?? null,
                    'kode_bagian' => $bagian,
                    'nilai_perolehan' => $perolehan,
                    'akumulasi' => $akumulasi,
                    'nilai_buku' => $nilaiBuku,
                    'laba_rugi' => $labaRugi,
                    'alasan' => $alasan,
                    'status' => 'aktif',
                    'journal_entry_id' => $entry->id,
                    'id_pengguna' => $idPengguna,
                ]);
            } catch (QueryException) {
                throw new AppException(409, 'Aset ini sudah punya dokumen pelepasan yang aktif.');
            }

            // Penyusutannya berhenti sendiri: runDepreciation() hanya menyapu
            // aset berstatus `aktif`.
            $aset->update(['status' => 'dilepas']);

            Jejak::catat('lepas_aset', [
                'modul' => 'assets',
                'ref_jenis' => 'PelepasanAset',
                'ref_id' => $dok->id,
                'detail' => [
                    'aset' => "{$aset->kode_aset} — {$aset->nama_aset}",
                    'perlakuan' => $perlakuan,
                    'nilai_perolehan' => $perolehan,
                    'nilai_buku' => $nilaiBuku,
                    'laba_rugi' => $labaRugi,
                    'alasan' => $alasan,
                ],
                'id_pengguna' => $idPengguna,
            ]);

            return $dok->load('aset');
        });
    }

    /** Batalkan pelepasan: jurnalnya dibalik, asetnya hidup kembali. */
    public function void(int $id, string $alasan, ?int $idPengguna, ?string $nama): PelepasanAset
    {
        $dok = PelepasanAset::with('aset')->find($id);
        if (! $dok) {
            throw new AppException(404, 'Dokumen pelepasan tidak ditemukan.');
        }
        if ($dok->status === 'void') {
            throw new AppException(409, 'Dokumen ini sudah di-void.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($dok, $alasan, $idPengguna, $nama) {
            Authorization::authorizeByUser($idPengguna, $dok->nilai_perolehan);

            $entry = JournalEntry::where('sumber_modul', self::SUMBER)
                ->where('id_sumber', $dok->nomor_ref)
                ->where('status', 'aktif')->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, [
                    'id_pengguna' => $idPengguna,
                    'keteranganPrefix' => "Void ({$alasan}) — ",
                ]);
            }

            // Asetnya kembali aktif — berikut akumulasi penyusutannya, yang tak
            // pernah disentuh dokumen ini (jurnalnya yang menghapusnya, dan
            // jurnal itu baru saja dibalik).
            $dok->aset?->update(['status' => 'aktif']);

            $dok->update([
                'status' => 'void',
                'void_reason' => $alasan,
                'void_by' => $nama,
                'void_at' => now(),
            ]);

            Jejak::catat('void_pelepasan_aset', [
                'modul' => 'assets',
                'ref_jenis' => 'PelepasanAset',
                'ref_id' => $dok->id,
                'detail' => ['aset' => $dok->kode_aset, 'alasan' => $alasan],
                'id_pengguna' => $idPengguna,
            ]);

            return $dok->refresh();
        });
    }

    private function nomorBaru(string $tanggal): string
    {
        $base = DocNumber::docBase('PLA', $tanggal);
        $last = PelepasanAset::where('nomor_ref', 'like', $base.'%')
            ->orderByDesc('nomor_ref')->value('nomor_ref');

        return DocNumber::nextDocNumber($base, $last);
    }

    private function akun(string $kode, string $label): CoaDetail
    {
        $coa = CoaDetail::find($kode);
        if (! $coa) {
            throw new AppException(422, "Akun {$label} tidak ditemukan.");
        }
        if ($coa->status !== 'aktif') {
            throw new AppException(422, "Akun {$label} ({$coa->nama_coa}) berstatus nonaktif.");
        }

        return $coa;
    }
}
