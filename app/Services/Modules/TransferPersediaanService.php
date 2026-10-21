<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\TransferPersediaan;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * TRANSFER STOK ANTAR LOKASI — dua langkah, dua orang.
 *
 *   kirim   : asal → TRANSIT ("Dalam Perjalanan"), harga pokok asal ikut terbawa
 *   terima  : TRANSIT → tujuan, sejumlah yang BENAR-BENAR tiba
 *   selisih : tertahan di TRANSIT sampai pemegang hak persetujuan memutus —
 *             kembali ke asal, atau hilang (D Selisih Persediaan / K Persediaan)
 *
 * Setiap pergerakan bersumber_ref nomor transfer, dan pengambilan dari TRANSIT dibatasi
 * ke lapisan milik transfer itu sendiri — barang beberapa transfer yang sedang di jalan
 * tak saling memakan harga pokok. Pindah lokasi tak berjurnal: barang & akunnya sama.
 * POS lama mencatat transfer ganda tanpa ketahuan; di sini nomor dokumen & status
 * membuat satu kiriman hanya bisa diterima sekali.
 */
class TransferPersediaanService
{
    /**
     * @param  array{tanggal_kirim:string, kode_lokasi_asal:string, kode_lokasi_tujuan:string, keterangan?:?string,
     *     details:list<array{kode_persediaan:string, satuan?:?string, kuantiti:string|float, keterangan?:?string}>}  $input
     */
    public function kirim(array $input, ?int $idPengguna): TransferPersediaan
    {
        $asal = $this->lokasiPilihan($input['kode_lokasi_asal'], 'asal');
        $tujuan = $this->lokasiPilihan($input['kode_lokasi_tujuan'], 'tujuan');
        if ($asal->kode === $tujuan->kode) {
            throw new AppException(422, 'Lokasi asal dan tujuan tidak boleh sama.');
        }

        $baris = [];
        $perBarang = [];
        foreach ($input['details'] as $i => $l) {
            $no = $i + 1;
            $item = Inventory::find($l['kode_persediaan']);
            if (! $item) {
                throw new AppException(400, "Baris {$no}: barang tidak ditemukan.");
            }
            [$satuan, $isi] = $item->satuanKe($l['satuan'] ?? null)
                ?? throw new AppException(422, "Baris {$no}: satuan {$l['satuan']} belum terdaftar untuk {$item->nama_persediaan}.");
            $qtyInput = Money::of($l['kuantiti'], 4);
            if (! Money::gtZero($qtyInput, 4)) {
                throw new AppException(422, "Baris {$no}: jumlah harus lebih dari nol.");
            }
            $qty = Money::mul($qtyInput, $isi, 4);

            // Diperiksa per barang (bukan per baris) — dua baris barang yang sama bersama-sama
            // tak boleh melebihi stok asal.
            $perBarang[$item->kode_persediaan] = Money::add($perBarang[$item->kode_persediaan] ?? '0', $qty, 4);
            $stok = InventoryMovement::stok($item->kode_persediaan, $asal->kode);
            if (Money::gt($perBarang[$item->kode_persediaan], $stok, 4)) {
                throw new AppException(422, "Baris {$no}: stok {$item->nama_persediaan} di {$asal->nama} tidak cukup (tersedia {$this->angka($stok)} {$item->satuan}).");
            }

            $baris[] = [
                'kode_persediaan' => $item->kode_persediaan, 'satuan' => $satuan, 'isi' => $isi,
                'kuantiti_input' => $qtyInput, 'kuantiti' => $qty, 'keterangan' => $l['keterangan'] ?? null,
            ];
        }
        if (! $baris) {
            throw new AppException(422, 'Belum ada barang yang dikirim.');
        }

        return DB::transaction(function () use ($input, $asal, $tujuan, $baris, $idPengguna) {
            $base = DocNumber::docBase('TS', $input['tanggal_kirim']);
            $last = TransferPersediaan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $doc = TransferPersediaan::create([
                'nomor' => $nomor,
                'tanggal_kirim' => $input['tanggal_kirim'],
                'kode_lokasi_asal' => $asal->kode,
                'kode_lokasi_tujuan' => $tujuan->kode,
                'status' => 'dikirim',
                'keterangan' => $input['keterangan'] ?? null,
                'dikirim_oleh' => $idPengguna,
            ]);

            foreach ($baris as $b) {
                $keluar = $this->pindah($b['kode_persediaan'], $b['kuantiti'], $asal->kode, LokasiPersediaan::TRANSIT,
                    $input['tanggal_kirim'], $nomor, "Kirim ke {$tujuan->nama}", $idPengguna);
                $doc->details()->create([...$b, 'nilai' => $keluar]);
            }

            return $doc->load('details');
        });
    }

    /**
     * Terima kiriman. `$diterima` = [id baris => jumlah tiba dalam SATUAN DASAR] — dari
     * 1 dus bisa saja tiba 20 pcs, dan petugas tak perlu mengetik 0,8333 dus.
     * Yang tak disebut dianggap tiba utuh.
     *
     * @param  array<int,string|float|null>  $diterima
     */
    public function terima(int $id, string $tanggal, array $diterima, ?string $catatan, ?int $idPengguna): TransferPersediaan
    {
        $doc = TransferPersediaan::with('details.persediaan', 'tujuan')->lockForUpdate()->find($id);
        if (! $doc) {
            throw new AppException(404, 'Transfer tidak ditemukan.');
        }
        if ($doc->status !== 'dikirim') {
            throw new AppException(409, "Transfer {$doc->nomor} sudah ".mb_strtolower(TransferPersediaan::STATUS[$doc->status]).'.');
        }
        if ($tanggal < $doc->tanggal_kirim->toDateString()) {
            throw new AppException(422, 'Tanggal terima tidak boleh sebelum tanggal kirim.');
        }
        // Dua langkah, dua orang (audit 21 Okt 2026): pengirim tak mencatat penerimaan kirimannya sendiri.
        $this->bukanOrangYangSama($doc->dikirim_oleh, $idPengguna, 'Penerimaan harus dicatat orang di lokasi tujuan, bukan pengirimnya.');

        return DB::transaction(function () use ($doc, $tanggal, $diterima, $catatan, $idPengguna) {
            $this->kunciStatus($doc, 'dikirim');
            $adaSelisih = false;
            foreach ($doc->details as $d) {
                $input = $diterima[$d->id] ?? null;
                $qty = ($input === null || $input === '') ? Money::of($d->kuantiti, 4) : Money::of($input, 4);
                if (Money::isNegative($qty, 4) || Money::gt($qty, $d->kuantiti, 4)) {
                    throw new AppException(422,
                        "{$d->persediaan->nama_persediaan}: jumlah tiba harus antara 0 dan {$this->angka($d->kuantiti)} {$d->persediaan->satuan}. "
                        .'Kelebihan barang dicatat lewat Penerimaan Barang atau Opname, bukan di sini.');
                }

                if (Money::gtZero($qty, 4)) {
                    $this->pindah($d->kode_persediaan, $qty, LokasiPersediaan::TRANSIT, $doc->kode_lokasi_tujuan,
                        $tanggal, $doc->nomor, "Terima dari {$doc->asal->nama}", $idPengguna);
                }
                $d->update(['kuantiti_diterima' => $qty]);
                $adaSelisih = $adaSelisih || Money::gtZero($d->selisih(), 4);
            }

            $doc->update([
                'status' => $adaSelisih ? 'selisih' : 'selesai',
                'tanggal_terima' => $tanggal,
                'catatan_terima' => $catatan,
                'diterima_oleh' => $idPengguna,
            ]);

            return $doc->refresh();
        });
    }

    /**
     * Putuskan selisih yang tertahan di TRANSIT.
     *  • kembali — barang memang tak jadi dikirim / masih di asal: pindah balik ke asal.
     *  • hilang  — barang tak ditemukan: dibukukan ke akun Selisih Persediaan (beban),
     *              unit = unit lokasi asal, Bagian wajib.
     * Yang memutus tak boleh orang yang menerima (kecuali admin) — itulah gunanya dua tangan.
     */
    public function putus(int $id, string $putusan, ?string $kodeBagian, ?string $catatan, ?int $idPengguna): TransferPersediaan
    {
        $doc = TransferPersediaan::with('details.persediaan', 'asal')->lockForUpdate()->find($id);
        if (! $doc) {
            throw new AppException(404, 'Transfer tidak ditemukan.');
        }
        if ($doc->status !== 'selisih') {
            throw new AppException(409, "Transfer {$doc->nomor} tidak sedang menunggu putusan selisih.");
        }
        if (! in_array($putusan, ['kembali', 'hilang'], true)) {
            throw new AppException(422, 'Putusan tidak dikenal.');
        }
        $pemutus = User::find($idPengguna);
        if ($doc->diterima_oleh && $doc->diterima_oleh === $idPengguna && ! $pemutus?->is_admin) {
            throw new AppException(403, 'Selisih harus diputus orang lain, bukan yang mencatat penerimaannya.');
        }
        if ($putusan === 'hilang' && ! $kodeBagian) {
            throw new AppException(422, 'Pilih Bagian yang menanggung kehilangan.');
        }
        $this->bukanOrangYangSama($doc->dikirim_oleh, $idPengguna, 'Selisih harus diputus orang lain, bukan pengirimnya.');

        return DB::transaction(function () use ($doc, $putusan, $kodeBagian, $catatan, $idPengguna) {
            $this->kunciStatus($doc, 'selisih');
            $hariIni = now()->toDateString();
            foreach ($doc->details as $d) {
                $selisih = $d->selisih();
                if (! Money::gtZero($selisih, 4)) {
                    continue;
                }
                if ($putusan === 'kembali') {
                    $this->pindah($d->kode_persediaan, $selisih, LokasiPersediaan::TRANSIT, $doc->kode_lokasi_asal,
                        $hariIni, $doc->nomor, 'Selisih transfer dikembalikan ke asal', $idPengguna);
                } else {
                    (new PersediaanService)->sesuaikan([
                        'kode_persediaan' => $d->kode_persediaan,
                        'kode_lokasi' => LokasiPersediaan::TRANSIT,
                        'selisih' => Money::sub('0', $selisih, 4),
                        'tanggal' => $hariIni,
                        'alasan' => 'kehilangan',
                        'sumber_modul' => TransferPersediaan::SUMBER,
                        'sumber_ref' => $doc->nomor,
                        'lapisan_sumber' => [TransferPersediaan::SUMBER, $doc->nomor],
                        'keterangan' => "Kehilangan transfer {$doc->nomor}".($catatan ? " — {$catatan}" : ''),
                        'kode_unit' => $doc->asal->kode_unit,
                        'kode_bagian' => $kodeBagian,
                    ], $idPengguna);
                }
            }

            $doc->update([
                'status' => 'selesai', 'putusan' => $putusan, 'catatan_putusan' => $catatan,
                'diputus_oleh' => $idPengguna, 'diputus_pada' => now(),
            ]);

            return $doc->refresh();
        });
    }

    /** Batalkan kiriman yang BELUM diterima: seluruh pergerakannya dibalik persis. */
    public function batal(int $id, string $alasan, ?int $idPengguna): TransferPersediaan
    {
        $doc = TransferPersediaan::lockForUpdate()->find($id);
        if (! $doc) {
            throw new AppException(404, 'Transfer tidak ditemukan.');
        }
        if ($doc->status !== 'dikirim') {
            throw new AppException(409, 'Hanya kiriman yang belum diterima yang bisa dibatalkan.');
        }

        return DB::transaction(function () use ($doc, $alasan, $idPengguna) {
            $this->kunciStatus($doc, 'dikirim');
            InventoryMovement::batalkanDokumen(TransferPersediaan::SUMBER, $doc->nomor, $idPengguna);
            $doc->update(['status' => 'batal', 'catatan_putusan' => "Dibatalkan: {$alasan}"]);

            return $doc;
        });
    }

    /**
     * Pindahkan barang antar lokasi tanpa jurnal; nilai keluar (FIFO) = nilai masuk.
     * Keluar dari TRANSIT hanya boleh menggerus lapisan transfer ini sendiri.
     */
    private function pindah(string $kode, string $qty, string $dari, string $ke, string $tanggal, string $nomor, string $ket, ?int $idPengguna): string
    {
        $umum = [
            'kode_persediaan' => $kode, 'kuantiti' => $qty, 'tanggal' => $tanggal, 'alasan' => 'transfer',
            'sumber_modul' => TransferPersediaan::SUMBER, 'sumber_ref' => $nomor, 'keterangan' => $ket, 'id_pengguna' => $idPengguna,
        ];
        $keluar = InventoryMovement::keluar([
            ...$umum,
            'kode_lokasi' => $dari,
            'lapisan_sumber' => $dari === LokasiPersediaan::TRANSIT ? [TransferPersediaan::SUMBER, $nomor] : null,
        ]);
        InventoryMovement::masuk([...$umum, 'kode_lokasi' => $ke, 'nilai' => $keluar->nilai]);

        return Money::of($keluar->nilai);
    }

    private function lokasiPilihan(string $kode, string $peran): LokasiPersediaan
    {
        $l = LokasiPersediaan::find($kode);
        if (! $l || $l->status !== 'aktif' || $l->kode === LokasiPersediaan::TRANSIT) {
            throw new AppException(422, "Lokasi {$peran} tidak ditemukan atau tidak aktif.");
        }

        return $l;
    }

    /**
     * Kunci baris dokumen DI DALAM transaksi & periksa ulang statusnya (audit 21 Okt 2026): `lockForUpdate()` di
     * luar transaksi langsung lepas, jadi terima/batal/putus ganda dulu hanya tertahan oleh kebetulan.
     */
    private function kunciStatus(TransferPersediaan $doc, string $status): void
    {
        $kini = TransferPersediaan::lockForUpdate()->whereKey($doc->getKey())->value('status');
        if ($kini !== $status) {
            throw new AppException(409, "Transfer {$doc->nomor} baru saja diubah orang lain — muat ulang.");
        }
    }

    private function bukanOrangYangSama(?int $a, ?int $b, string $pesan): void
    {
        if ($a !== null && $a === $b && ! User::find($b)?->is_admin) {
            throw new AppException(403, $pesan);
        }
    }

    private function angka(string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }
}
