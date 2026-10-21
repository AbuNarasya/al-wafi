<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Inventory;
use App\Models\LapisanPersediaan;
use App\Models\LokasiPersediaan;
use App\Models\OpnamePersediaan;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * STOK OPNAME BERDOKUMEN — hitung, ajukan, setujui (dua tangan).
 *
 *  draft     : petugas mengisi stok fisik, boleh disimpan bertahap. Saat angka sebuah
 *              barang diisi/diubah, stok tercatatnya DIPOTRET saat itu juga — penjualan
 *              sesudah barang itu dihitung tak ikut terbaca sebagai selisih.
 *  diajukan  : terkunci; menunggu pemegang hak persetujuan.
 *  disetujui : selisih tiap barang menggerakkan stok + jurnal (D/K akun Selisih Persediaan,
 *              unit = unit lokasi, Bagian = Bagian dokumen). Ditolak → kembali ke draft.
 *
 * Barang yang dikosongkan = tak dihitung, tak disesuaikan. Penyesuaian memakai pintu yang
 * sama dengan opname per barang: PersediaanService::sesuaikan().
 */
class OpnamePersediaanService
{
    /**
     * @param  array{tanggal:string, kode_lokasi:string, kode_kategori?:?string, kode_bagian?:?string,
     *     keterangan?:?string, cakupan?:string}  $input  cakupan: 'pernah' (barang yang pernah ada di lokasi) | 'semua'
     */
    public function buat(array $input, ?int $idPengguna): OpnamePersediaan
    {
        $lokasi = LokasiPersediaan::find($input['kode_lokasi']);
        if (! $lokasi || $lokasi->status !== 'aktif' || $lokasi->kode === LokasiPersediaan::TRANSIT) {
            throw new AppException(422, 'Lokasi opname tidak ditemukan atau tidak aktif.');
        }

        // Satu opname berjalan per lokasi (audit 21 Okt 2026): dua dokumen yang sama-sama memuat barang X dulu
        // bisa sama-sama disetujui — selisihnya diterapkan dua kali.
        if ($jalan = OpnamePersediaan::where('kode_lokasi', $lokasi->kode)->whereIn('status', ['draft', 'diajukan'])->value('nomor')) {
            throw new AppException(409, "Masih ada opname {$jalan} yang belum selesai di {$lokasi->nama} — selesaikan atau batalkan dulu.");
        }

        $kode = Inventory::where('status', 'aktif')
            ->when($input['kode_kategori'] ?? null, fn ($q, $k) => $q->where('kode_kategori', $k))
            ->when(($input['cakupan'] ?? 'pernah') === 'pernah', fn ($q) => $q->whereIn('kode_persediaan',
                LapisanPersediaan::where('kode_lokasi', $lokasi->kode)->select('kode_persediaan')))
            ->orderBy('nama_persediaan')->pluck('kode_persediaan');
        if ($kode->isEmpty()) {
            throw new AppException(422, "Tidak ada barang untuk dihitung di {$lokasi->nama} dengan pilihan itu. Coba cakupan \"semua barang aktif\".");
        }

        return DB::transaction(function () use ($input, $lokasi, $kode, $idPengguna) {
            $base = DocNumber::docBase('OP', $input['tanggal']);
            $last = OpnamePersediaan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            $doc = OpnamePersediaan::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'tanggal' => $input['tanggal'],
                'kode_lokasi' => $lokasi->kode,
                'kode_kategori' => ($input['kode_kategori'] ?? null) ?: null,
                'kode_bagian' => ($input['kode_bagian'] ?? null) ?: null,
                'keterangan' => $input['keterangan'] ?? null,
                'status' => 'draft',
                'dibuat_oleh' => $idPengguna,
            ]);
            foreach ($kode as $k) {
                $doc->details()->create(['kode_persediaan' => $k]);
            }

            return $doc;
        });
    }

    /**
     * Simpan hitungan. `$hitungan` = [kode_persediaan => stok fisik | '' (kosongkan)].
     * Barang yang tak disebut tak disentuh.
     *
     * @param  array<string,string|int|float|null>  $hitungan
     */
    public function simpanHitungan(int $id, array $hitungan, ?string $kodeBagian, ?int $idPengguna): OpnamePersediaan
    {
        $doc = $this->dokumen($id, 'draft');

        return DB::transaction(function () use ($doc, $hitungan, $kodeBagian) {
            if ($kodeBagian !== null) {
                $doc->update(['kode_bagian' => $kodeBagian ?: null]);
            }
            $baris = $doc->details()->whereIn('kode_persediaan', array_keys($hitungan))->get();
            foreach ($baris as $d) {
                $nilai = $hitungan[$d->kode_persediaan];
                if ($nilai === null || $nilai === '') {
                    $d->update(['stok_fisik' => null, 'stok_tercatat' => null, 'dihitung_pada' => null]);

                    continue;
                }
                if (! is_numeric($nilai) || (float) $nilai < 0) {
                    throw new AppException(422, "Stok fisik {$d->persediaan->nama_persediaan} harus angka 0 atau lebih.");
                }
                $fisik = Money::of($nilai, 4);
                // Potret ulang HANYA bila angkanya berubah: menyimpan ulang angka yang sama
                // sore hari tak boleh menggeser pembandingnya ke stok sore itu.
                if ($d->stok_fisik === null || ! Money::eq($d->stok_fisik, $fisik, 4)) {
                    $d->update([
                        'stok_fisik' => $fisik,
                        'stok_tercatat' => InventoryMovement::stok($d->kode_persediaan, $doc->kode_lokasi),
                        'dihitung_pada' => now(),
                    ]);
                }
            }

            return $doc->refresh();
        });
    }

    public function ajukan(int $id, ?int $idPengguna): OpnamePersediaan
    {
        $doc = $this->dokumen($id, 'draft');
        $dihitung = $doc->details->whereNotNull('stok_fisik');
        if ($dihitung->isEmpty()) {
            throw new AppException(422, 'Belum ada satu barang pun yang dihitung.');
        }
        $adaSelisih = $dihitung->contains(fn ($d) => ! Money::isZero($d->selisih(), 4));
        if ($adaSelisih && ! $doc->kode_bagian) {
            throw new AppException(422, 'Ada selisih — pilih dulu Bagian yang menanggung selisih persediaan.');
        }

        $this->pindah($doc, 'draft', ['status' => 'diajukan', 'diajukan_oleh' => $idPengguna, 'diajukan_pada' => now(), 'catatan_putusan' => null]);

        return $doc->refresh();
    }

    /** Kembalikan ke draft beserta catatan — hitungannya tetap, bisa diperbaiki lalu diajukan lagi. */
    public function tolak(int $id, string $catatan, ?int $idPengguna): OpnamePersediaan
    {
        $doc = $this->dokumen($id, 'diajukan');
        $this->bukanPengaju($doc, $idPengguna);
        $this->pindah($doc, 'diajukan', ['status' => 'draft', 'catatan_putusan' => "Ditolak: {$catatan}"]);

        return $doc->refresh();
    }

    public function setujui(int $id, ?int $idPengguna): OpnamePersediaan
    {
        $doc = $this->dokumen($id, 'diajukan');
        $this->bukanPengaju($doc, $idPengguna);

        return DB::transaction(function () use ($doc, $idPengguna) {
            // Dikunci & diperiksa ULANG (audit 21 Okt 2026): klik ganda / dua penyetuju dulu menerapkan selisih 2×.
            $this->kunci($doc->getKey(), 'diajukan');
            $layanan = new PersediaanService;
            foreach ($doc->details->whereNotNull('stok_fisik') as $d) {
                $selisih = $d->selisih();
                $mutasi = $layanan->sesuaikan([
                    'kode_persediaan' => $d->kode_persediaan,
                    'kode_lokasi' => $doc->kode_lokasi,
                    'selisih' => $selisih,
                    'tanggal' => $doc->tanggal->toDateString(),
                    'sumber_modul' => OpnamePersediaan::SUMBER,
                    'sumber_ref' => $doc->nomor,
                    'keterangan' => "Opname {$doc->nomor}: fisik ".$this->angka($d->stok_fisik).', tercatat '.$this->angka($d->stok_tercatat),
                    'kode_bagian' => $doc->kode_bagian,
                ], $idPengguna);

                if ($mutasi) {
                    $d->update([
                        'id_mutasi' => $mutasi->id,
                        'nilai_selisih' => $mutasi->arah === 'masuk' ? $mutasi->nilai : Money::sub('0', $mutasi->nilai),
                    ]);
                }
            }

            $doc->update(['status' => 'disetujui', 'disetujui_oleh' => $idPengguna, 'disetujui_pada' => now(), 'catatan_putusan' => null]);

            return $doc->refresh();
        });
    }

    public function batal(int $id, ?int $idPengguna): OpnamePersediaan
    {
        $doc = OpnamePersediaan::find($id) ?? throw new AppException(404, 'Dokumen opname tidak ditemukan.');
        if (! in_array($doc->status, ['draft', 'diajukan'], true)) {
            throw new AppException(409, 'Opname yang sudah disetujui tak bisa dibatalkan — buat opname baru untuk mengoreksinya.');
        }
        $this->pindah($doc, $doc->status, ['status' => 'batal']);

        return $doc->refresh();
    }

    /** Kunci baris dokumen di dalam transaksi & pastikan statusnya masih seperti yang diharapkan. */
    private function kunci(int $id, string $status): OpnamePersediaan
    {
        $doc = OpnamePersediaan::lockForUpdate()->find($id) ?? throw new AppException(404, 'Dokumen opname tidak ditemukan.');
        if ($doc->status !== $status) {
            throw new AppException(409, "Opname {$doc->nomor} baru saja diubah orang lain (kini ".mb_strtolower(OpnamePersediaan::STATUS[$doc->status]).') — muat ulang.');
        }

        return $doc;
    }

    /**
     * Ganti status hanya bila masih `$dari` — baris dikunci dulu, jadi dua klik bersamaan → yang kedua ditolak.
     * Lewat model (bukan update query) supaya jejak auditnya tetap tercatat.
     */
    private function pindah(OpnamePersediaan $doc, string $dari, array $isi): void
    {
        DB::transaction(fn () => $this->kunci($doc->getKey(), $dari)->update($isi));
    }

    private function dokumen(int $id, string $status): OpnamePersediaan
    {
        $doc = OpnamePersediaan::with('details.persediaan')->find($id) ?? throw new AppException(404, 'Dokumen opname tidak ditemukan.');
        if ($doc->status !== $status) {
            throw new AppException(409, "Opname {$doc->nomor} berstatus ".mb_strtolower(OpnamePersediaan::STATUS[$doc->status]).'.');
        }

        return $doc;
    }

    /** Dua tangan: yang menghitung/mengajukan tak menyetujui hasilnya sendiri (admin dikecualikan). */
    private function bukanPengaju(OpnamePersediaan $doc, ?int $idPengguna): void
    {
        if (in_array($idPengguna, [$doc->diajukan_oleh, $doc->dibuat_oleh], true) && ! User::find($idPengguna)?->is_admin) {
            throw new AppException(403, 'Opname harus diputus orang lain, bukan yang membuat atau mengajukannya.');
        }
    }

    private function angka($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }
}
