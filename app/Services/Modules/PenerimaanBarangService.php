<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\LokasiPersediaan;
use App\Models\PenerimaanBarang;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Vendor;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PENERIMAAN BARANG — stok masuk saat barang tiba di gudang/toko, bukan saat invoice.
 *
 *   D Persediaan (akun tiap barang) / K Barang Diterima Belum Ditagih (GRNI)
 *
 * Hutang belum lahir di sini: ia lahir saat Invoice Vendor merujuk baris penerimaan
 * (D GRNI / K Hutang, lihat InvoiceService). Saldo akun GRNI = nilai barang yang sudah
 * ada di rak tetapi tagihannya belum datang — angka yang dulu sama sekali tak terlihat.
 *
 * Petugas boleh menghitung dalam satuan konversi (3 DUS); yang tercatat di stok selalu
 * satuan dasar (72 pcs), dan nilainya = jumlah × harga yang ia ketik, tanpa pembulatan
 * ulang per satuan dasar.
 */
class PenerimaanBarangService
{
    /** Akun GRNI dari Pengaturan Perusahaan — wajib ada sebelum penerimaan pertama. */
    public static function akunGrni(): string
    {
        $kode = CompanySettings::find(1)?->kode_coa_grni;
        if (! $kode || ! CoaDetail::find($kode)) {
            throw new AppException(422,
                'Akun "Barang Diterima Belum Ditagih" belum ditetapkan. Pilih dulu di Pengaturan Perusahaan '
                .'(akun hutang jangka pendek).');
        }

        return $kode;
    }

    /**
     * @param  array{tanggal:string, id_po?:?int, kode_vendor:string, kode_lokasi:string, kode_unit?:?string,
     *     nomor_surat_jalan?:?string, keterangan?:?string,
     *     details:list<array{id_po_detail?:?int, kode_persediaan:string, satuan?:?string, kuantiti:string|float, harga_satuan:string|float, keterangan?:?string}>}  $input
     */
    public function create(array $input, ?int $idPengguna): PenerimaanBarang
    {
        $grni = self::akunGrni();

        if (! Vendor::find($input['kode_vendor'])) {
            throw new AppException(400, 'Vendor tidak ditemukan.');
        }
        $lokasi = LokasiPersediaan::find($input['kode_lokasi']);
        if (! $lokasi || $lokasi->status !== 'aktif') {
            throw new AppException(422, 'Lokasi penerimaan tidak ditemukan atau sudah nonaktif.');
        }

        $po = null;
        if (! empty($input['id_po'])) {
            $po = PurchaseOrder::with('details')->find($input['id_po']);
            if (! $po) {
                throw new AppException(400, 'Purchase Order tidak ditemukan.');
            }
            if (! in_array($po->status, ['open', 'sebagian'], true)) {
                throw new AppException(422, "PO {$po->nomor_po} berstatus {$po->status}; barangnya tak bisa diterima lagi.");
            }
            if ($po->kode_vendor !== $input['kode_vendor']) {
                throw new AppException(422, "Vendor penerimaan berbeda dari vendor PO {$po->nomor_po}.");
            }
        }

        // Unit: yang disebut → unit PO → unit pemilik lokasi.
        $unit = ($input['kode_unit'] ?? null) ?: ($po?->kode_unit ?: $lokasi->kode_unit);
        if (! $unit || ! BusinessUnit::find($unit)) {
            throw new AppException(422, 'Unit bisnis penerimaan belum ditentukan.');
        }

        $baris = [];
        $total = '0';
        $terimaPerPoDetail = [];
        foreach ($input['details'] as $i => $l) {
            $no = $i + 1;
            $item = Inventory::find($l['kode_persediaan']);
            if (! $item) {
                throw new AppException(400, "Baris {$no}: barang {$l['kode_persediaan']} tidak ditemukan.");
            }
            if (! $item->kode_coa) {
                throw new AppException(422, "Baris {$no}: {$item->nama_persediaan} belum punya Akun Persediaan. Lengkapi dulu di master Persediaan.");
            }

            [$satuan, $isi] = $this->satuan($item, $l['satuan'] ?? null, $no);
            $qtyInput = Money::of($l['kuantiti'], 4);
            $hargaInput = Money::of($l['harga_satuan']);
            if (! Money::gtZero($qtyInput, 4)) {
                throw new AppException(422, "Baris {$no}: jumlah diterima harus lebih dari nol.");
            }
            if (Money::isNegative($hargaInput)) {
                throw new AppException(422, "Baris {$no}: harga tidak boleh negatif.");
            }
            $qtyDasar = Money::mul($qtyInput, $isi, 4);
            // Kuantiti pada skala 4 lalu dibulatkan (dulu Money::mul memotong kuantiti ke 2 desimal: 0,125 → 0,12).
            $nilai = bcadd(bcmul($qtyInput, $hargaInput, 6), '0.005', 2);

            $idPoDetail = $l['id_po_detail'] ?? null;
            if ($idPoDetail) {
                $pd = $po?->details->firstWhere('id', (int) $idPoDetail);
                if (! $pd) {
                    throw new AppException(422, "Baris {$no}: baris PO tidak cocok dengan PO yang dipilih.");
                }
                if ($pd->kode_persediaan && $pd->kode_persediaan !== $item->kode_persediaan) {
                    throw new AppException(422, "Baris {$no}: barang berbeda dari baris PO-nya.");
                }
                // Baris PO yang sudah ditagih LANGSUNG (stoknya masuk saat invoice) tak boleh
                // diterima lagi di sini — stoknya akan tercatat dua kali.
                if (Money::gtZero($pd->qty_invoiced, 4) && ! Money::gtZero($pd->qty_diterima, 4)) {
                    throw new AppException(422, "Baris {$no}: baris PO ini sudah ditagih langsung lewat invoice (stoknya masuk saat itu).");
                }
                $terimaPerPoDetail[$pd->id] = Money::add($terimaPerPoDetail[$pd->id] ?? '0', $qtyDasar, 4);
                $sisa = Money::sub($pd->kuantiti, $pd->qty_diterima, 4);
                if (Money::gt($terimaPerPoDetail[$pd->id], $sisa, 4)) {
                    throw new AppException(422,
                        "Baris {$no}: {$item->nama_persediaan} diterima melebihi sisa PO "
                        ."(sisa {$this->angka($sisa)} {$item->satuan}). Buat PO tambahan untuk kelebihannya.");
                }
            }

            $baris[] = [
                'id_po_detail' => $idPoDetail ?: null,
                'kode_persediaan' => $item->kode_persediaan,
                'satuan' => $satuan,
                'isi' => $isi,
                'kuantiti_input' => $qtyInput,
                'harga_input' => $hargaInput,
                'kuantiti' => $qtyDasar,
                'total' => $nilai,
                'keterangan' => $l['keterangan'] ?? null,
                '_item' => $item,
            ];
            $total = Money::add($total, $nilai);
        }

        if (! $baris) {
            throw new AppException(422, 'Tidak ada barang yang diterima.');
        }

        return DB::transaction(function () use ($input, $idPengguna, $po, $unit, $baris, $total, $grni, $lokasi) {
            $base = DocNumber::docBase('TB', $input['tanggal']);
            $last = PenerimaanBarang::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $doc = PenerimaanBarang::create([
                'nomor' => $nomor,
                'tanggal' => $input['tanggal'],
                'id_po' => $po?->id_po,
                'kode_vendor' => $input['kode_vendor'],
                'kode_lokasi' => $lokasi->kode,
                'kode_unit' => $unit,
                'nomor_surat_jalan' => $input['nomor_surat_jalan'] ?? null,
                'keterangan' => $input['keterangan'] ?? null,
                'total' => $total,
                'status' => 'aktif',
                'id_pengguna' => $idPengguna,
            ]);

            // Jurnal: satu debit per AKUN persediaan (bukan per barang), satu kredit GRNI.
            $entry = null;
            if (Money::gtZero($total)) {
                $debit = [];
                foreach ($baris as $b) {
                    $debit[$b['_item']->kode_coa] = Money::add($debit[$b['_item']->kode_coa] ?? '0', $b['total']);
                }
                $ket = "Penerimaan barang {$nomor} — ".Vendor::find($input['kode_vendor'])->nama_vendor;
                $lines = [];
                foreach ($debit as $coa => $nilai) {
                    if (Money::gtZero($nilai)) {
                        $lines[] = ['kode_coa' => $coa, 'debet' => $nilai, 'kredit' => '0', 'keterangan' => $ket];
                    }
                }
                $lines[] = ['kode_coa' => $grni, 'debet' => '0', 'kredit' => $total, 'keterangan' => $ket];

                $entry = PostingService::postJournal([
                    'referensi' => $nomor,
                    'tanggal' => $input['tanggal'],
                    'kode_unit' => $unit,
                    'keterangan' => ($input['keterangan'] ?? null) ?: $ket,
                    'sumber_modul' => PenerimaanBarang::SUMBER,
                    'id_sumber' => (string) $doc->id_penerimaan,
                    'id_pengguna' => $idPengguna,
                    'lines' => $lines,
                ]);
                $doc->update(['journal_entry_id' => $entry->id]);
            }

            foreach ($baris as $b) {
                $item = $b['_item'];
                unset($b['_item']);
                $doc->details()->create($b);

                InventoryMovement::masuk([
                    'kode_persediaan' => $item->kode_persediaan,
                    'kode_lokasi' => $lokasi->kode,
                    'kuantiti' => $b['kuantiti'],
                    'nilai' => $b['total'],
                    'tanggal' => $input['tanggal'],
                    'alasan' => 'pembelian',
                    'sumber_modul' => PenerimaanBarang::SUMBER,
                    'sumber_ref' => $nomor,
                    'journal_entry_id' => $entry?->id,
                    'keterangan' => $b['keterangan'],
                    'id_pengguna' => $idPengguna,
                ]);

                if ($b['id_po_detail']) {
                    $pd = PurchaseOrderDetail::lockForUpdate()->find($b['id_po_detail']);
                    $baru = Money::add($pd->qty_diterima, $b['kuantiti'], 4);
                    // Diperiksa ULANG di bawah kunci (audit 21 Okt 2026): dua penerimaan bersamaan dulu sama-sama
                    // lolos pemeriksaan sisa di luar transaksi → PO terlampaui.
                    if (Money::gt($baru, $pd->kuantiti, 4)) {
                        throw new AppException(409, 'Baris PO ini baru saja diterima lewat penerimaan lain — sisanya tak cukup lagi. Muat ulang.');
                    }
                    $pd->update(['qty_diterima' => $baru]);
                }
            }

            return $doc->load('details');
        });
    }

    /**
     * Batalkan penerimaan: jurnal dibalik, stok ditarik, progres PO dikembalikan.
     * Ditolak bila sudah ada baris yang ditagih (void invoice-nya dulu), atau bila barangnya
     * sudah terlanjur keluar (InventoryMovement menolaknya dengan pesan yang terang).
     */
    public function batal(int $id, string $alasan, ?int $idPengguna): PenerimaanBarang
    {
        $doc = PenerimaanBarang::with('details')->find($id);
        if (! $doc) {
            throw new AppException(404, 'Penerimaan barang tidak ditemukan.');
        }
        if ($doc->status === 'batal') {
            throw new AppException(409, 'Penerimaan ini sudah dibatalkan.');
        }
        if ($doc->sudahDitagih()) {
            throw new AppException(409, 'Sebagian barang sudah ditagih lewat invoice vendor. Void invoice-nya dulu.');
        }

        return DB::transaction(function () use ($doc, $alasan, $idPengguna) {
            // Dikunci & diperiksa ULANG (audit 21 Okt 2026): batal ganda dulu mengurangi qty_diterima PO dua kali.
            $kini = PenerimaanBarang::lockForUpdate()->find($doc->id_penerimaan);
            if ($kini->status === 'batal') {
                throw new AppException(409, 'Penerimaan ini sudah dibatalkan.');
            }
            if ($kini->load('details')->sudahDitagih()) {
                throw new AppException(409, 'Sebagian barang sudah ditagih lewat invoice vendor. Void invoice-nya dulu.');
            }
            $entry = JournalEntry::where('sumber_modul', PenerimaanBarang::SUMBER)
                ->where('id_sumber', (string) $doc->id_penerimaan)->where('status', 'aktif')->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, [
                    'id_pengguna' => $idPengguna, 'keteranganPrefix' => "Batal ({$alasan}) — ",
                ]);
            }

            InventoryMovement::batalkanDokumen(PenerimaanBarang::SUMBER, $doc->nomor, $idPengguna);

            foreach ($doc->details as $d) {
                if ($d->id_po_detail) {
                    $pd = PurchaseOrderDetail::lockForUpdate()->find($d->id_po_detail);
                    $sisa = Money::sub($pd->qty_diterima, $d->kuantiti, 4);
                    $pd->update(['qty_diterima' => Money::isNegative($sisa, 4) ? '0' : $sisa]);
                }
            }

            $doc->update(['status' => 'batal', 'alasan_batal' => $alasan]);

            return $doc;
        });
    }

    /** @return array{0:string,1:string} [nama satuan, isi dalam satuan dasar] */
    private function satuan(Inventory $item, ?string $satuan, int $no): array
    {
        return $item->satuanKe($satuan)
            ?? throw new AppException(422, "Baris {$no}: satuan {$satuan} belum terdaftar untuk {$item->nama_persediaan}.");
    }

    private function angka(string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }
}
