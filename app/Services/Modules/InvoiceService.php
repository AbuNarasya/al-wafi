<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PenerimaanBarangDetail;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Ledger\AssetDraft;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\BagianPolicy;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Modul Invoice Vendor (pengakuan hutang). Jurnal: Debit tiap akun rincian /
 * Kredit Hutang Usaha. Opsional referensi PO (memperbarui qty_invoiced & status
 * PO). Baris bisa memicu stok persediaan & aset (draft / tambah nilai).
 */
class InvoiceService
{
    public function create(array $input, ?int $idPengguna): Invoice
    {
        $vendor = Vendor::find($input['kode_vendor']);
        if (! $vendor) {
            throw new AppException(400, 'Vendor tidak ditemukan.');
        }
        $unit = BusinessUnit::find($input['kode_unit']);
        if (! $unit) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }
        $hutangCoa = CoaDetail::find($input['kode_coa_hutang']);
        if (! $hutangCoa) {
            throw new AppException(400, 'Akun hutang usaha tidak ditemukan.');
        }

        $po = null;
        if (! empty($input['id_po'])) {
            $po = $this->poUntukInvoice((int) $input['id_po'], $input['kode_vendor']);
        }

        $total = '0';
        $details = [];
        $jLines = [];
        $ditagihPerTerima = [];
        foreach ($input['details'] as $i => $l) {
            $no = $i + 1;
            $lineTotal = Money::mul($l['kuantiti'], $l['harga_satuan']);
            $ketBaris = $l['keterangan'] ?? "Invoice {$input['nomor_invoice']}";

            // ---- Baris yang MENAGIH penerimaan barang: stoknya sudah masuk saat diterima. ----
            if (! empty($l['id_penerimaan_detail'])) {
                [$baris, $jurnal, $poTerima] = $this->barisPenerimaan($l, $no, $lineTotal, $ketBaris, $input['kode_vendor'], $ditagihPerTerima);
                $total = Money::add($total, $baris['total']);   // bisa disamakan dengan nilai terima (pembulatan)
                if ($poTerima) {
                    // Invoice tanpa PO mewarisi PO penerimaannya; PO yang berbeda ditolak —
                    // satu invoice memajukan satu PO saja.
                    if ($po && $po->id_po !== $poTerima) {
                        throw new AppException(422, "Baris {$no}: penerimaannya milik PO lain, bukan {$po->nomor_po}.");
                    }
                    $po ??= $this->poUntukInvoice($poTerima, $input['kode_vendor'], bolehSelesai: true);
                }
                $details[] = $baris;
                array_push($jLines, ...$jurnal);

                continue;
            }

            $total = Money::add($total, $lineTotal);

            // `kode_persediaan` AKHIRNYA ditulis. Sebelum ini kolomnya ada di
            // tabel, terbawa dari baris PO, lalu mati di sini — dan pencocokan
            // barangnya dilakukan lewat `kode_coa` yang lazim dipakai BANYAK
            // barang sekaligus, sehingga beras yang dibeli bisa menambah stok
            // minyak tanpa seorang pun melihatnya.
            $kodePersediaan = $l['kode_persediaan'] ?? null;
            $kodeCoa = $l['kode_coa'];
            if ($kodePersediaan) {
                $item = Inventory::find($kodePersediaan);
                if (! $item) {
                    throw new AppException(400, "Item persediaan {$kodePersediaan} tidak ditemukan.");
                }
                // Barang yang menambah stok WAJIB didebet ke akun persediaannya sendiri —
                // dulu akun bebas dipilih, sehingga stok bisa bertambah sementara nilainya
                // dibebankan, dan buku pembantu persediaan menjauh dari buku besar.
                if (! $item->kode_coa) {
                    throw new AppException(422, "Baris {$no}: {$item->nama_persediaan} belum punya Akun Persediaan. Lengkapi dulu di master Persediaan.");
                }
                $kodeCoa = $item->kode_coa;
            }
            $coa = CoaDetail::find($kodeCoa);
            if (! $coa) {
                throw new AppException(400, "Akun COA {$kodeCoa} tidak ditemukan.");
            }

            $idPoDetail = $l['id_po_detail'] ?? null;
            if ($idPoDetail) {
                $poDetail = $po?->details->firstWhere('id', (int) $idPoDetail);
                if (! $poDetail) {
                    throw new AppException(422, "Baris {$no}: baris PO tidak cocok dengan PO yang dipilih.");
                }
                // Satu baris PO menempuh SATU jalur stok: lewat Penerimaan Barang, atau
                // langsung lewat invoice. Menagih langsung baris yang barangnya sudah
                // diterima akan memasukkan stoknya untuk kedua kalinya.
                if ($kodePersediaan && Money::gtZero($poDetail->qty_diterima, 4)) {
                    throw new AppException(422,
                        "Baris {$no}: barang di baris PO ini sudah diterima lewat Penerimaan Barang. "
                        .'Tagih lewat "Muat dari Penerimaan", bukan dari PO.');
                }
            } elseif ($kodePersediaan && $po?->details->contains(fn ($d) => $d->kode_persediaan === $kodePersediaan && Money::gtZero($d->qty_diterima, 4))) {
                throw new AppException(422,
                    "Baris {$no}: barang ini sudah diterima lewat Penerimaan Barang untuk PO {$po->nomor_po}. "
                    .'Tagih lewat "Muat dari Penerimaan".');
            } elseif ($kodePersediaan && ! $po && ($terima = \App\Models\PenerimaanBarangDetail::query()
                ->join('penerimaan_barang as p', 'p.id_penerimaan', '=', 'penerimaan_barang_detail.id_penerimaan')
                ->where('p.status', 'aktif')->where('p.kode_vendor', $input['kode_vendor'])
                ->where('penerimaan_barang_detail.kode_persediaan', $kodePersediaan)
                ->whereColumn('penerimaan_barang_detail.qty_ditagih', '<', 'penerimaan_barang_detail.kuantiti')
                ->value('p.nomor'))) {
                // Invoice langsung tanpa PO atas barang yang SUDAH diterima vendor ini & belum ditagih: stoknya akan
                // masuk dua kali dan GRNI tak pernah nol (audit 21 Okt 2026).
                throw new AppException(422,
                    "Baris {$no}: {$item->nama_persediaan} dari vendor ini sudah diterima lewat {$terima} dan belum ditagih. "
                    .'Tagih lewat "Muat dari Penerimaan" supaya stoknya tak masuk dua kali.');
            }

            $details[] = [
                'kode_coa' => $kodeCoa,
                'nama_coa' => $coa->nama_coa,
                'keterangan' => $l['keterangan'] ?? null,
                'kuantiti' => Money::of($l['kuantiti'], 4),
                'harga_satuan' => Money::of($l['harga_satuan']),
                'total' => $lineTotal,
                'kode_persediaan' => $kodePersediaan,
                'id_po_detail' => $idPoDetail ? (int) $idPoDetail : null,
            ];
            $jLines[] = [
                'kode_coa' => $kodeCoa,
                'nama_coa' => $coa->nama_coa,
                'kode_bagian' => $l['kode_bagian'] ?? null,
                'debet' => $lineTotal,
                'kredit' => '0',
                'keterangan' => $ketBaris,
            ];
        }
        $jLines[] = [
            'kode_coa' => $input['kode_coa_hutang'],
            'nama_coa' => $hutangCoa->nama_coa,
            'debet' => '0',
            'kredit' => $total,
            'keterangan' => "Pengakuan hutang invoice {$input['nomor_invoice']}",
        ];

        $input['id_po'] = $po?->id_po;
        $nomorPo = $po?->nomor_po;

        return DB::transaction(function () use ($input, $idPengguna, $total, $details, $jLines, $nomorPo) {
            $base = DocNumber::docBase('INV', $input['tanggal_invoice']);
            $last = Invoice::where('nomor_ref_internal', 'like', $base.'%')
                ->orderByDesc('nomor_ref_internal')
                ->value('nomor_ref_internal');
            $ref = DocNumber::nextDocNumber($base, $last);

            $inv = Invoice::create([
                'nomor_invoice' => $input['nomor_invoice'],
                'nomor_ref_internal' => $ref,
                'tanggal_invoice' => $input['tanggal_invoice'],
                'tanggal_jatuh_tempo' => $input['tanggal_jatuh_tempo'],
                'kode_vendor' => $input['kode_vendor'],
                'kode_unit' => $input['kode_unit'],
                'kode_coa_hutang' => $input['kode_coa_hutang'],
                'id_po' => $input['id_po'] ?? null,
                'nomor_po' => $nomorPo,
                'kode_lokasi' => $input['kode_lokasi'] ?? null,
                'keterangan' => $input['keterangan'] ?? null,
                'total' => $total,
                'sisa_hutang' => $total,
                'status' => 'belum_bayar',
                'id_pengguna' => $idPengguna ?? 0,
            ]);
            foreach ($details as $d) {
                $inv->details()->create($d);
            }

            $entry = PostingService::postJournal([
                'referensi' => $ref,
                'tanggal' => $input['tanggal_invoice'],
                'kode_unit' => $input['kode_unit'],
                'keterangan' => $input['keterangan'] ?? "Invoice {$input['nomor_invoice']}",
                'sumber_modul' => 'Invoice',
                'id_sumber' => (string) $inv->id_invoice,
                'id_pengguna' => $idPengguna,
                'lines' => $jLines,
            ]);

            // Stok bertambah untuk baris yang MENYEBUT itemnya. Barang yang
            // dipilih petugaslah yang bertambah — bukan barang pertama yang
            // kebetulan berbagi akun COA yang sama.
            foreach ($inv->details as $d) {
                if ($d->id_penerimaan_detail) {
                    // Stoknya sudah masuk saat diterima — yang bergerak di sini hanya
                    // catatan "sudah ditagih" pada baris penerimaannya.
                    $pd = PenerimaanBarangDetail::lockForUpdate()->find($d->id_penerimaan_detail);
                    $ditagihBaru = Money::add($pd->qty_ditagih, $d->kuantiti, 4);
                    // Diperiksa ULANG di bawah kunci (audit 21 Okt 2026): dua invoice bersamaan dulu bisa menagih
                    // penerimaan yang sama → GRNI terdebit ganda.
                    if (Money::gt($ditagihBaru, $pd->kuantiti, 4)) {
                        throw new AppException(409, 'Penerimaan barang ini baru saja ditagih invoice lain — muat ulang lalu ulangi.');
                    }
                    $pd->update([
                        'qty_ditagih' => $ditagihBaru,
                        'nilai_ditagih' => Money::add($pd->nilai_ditagih, $d->nilai_penerimaan),
                    ]);

                    continue;
                }
                if (! $d->kode_persediaan || ! Money::gtZero($d->kuantiti ?? 0, 4)) {
                    continue;
                }
                InventoryMovement::masuk([
                    'kode_persediaan' => $d->kode_persediaan,
                    'kode_lokasi' => $input['kode_lokasi'] ?? null,
                    'kuantiti' => $d->kuantiti,
                    'nilai' => $d->total,
                    'tanggal' => $input['tanggal_invoice'],
                    'alasan' => 'pembelian',
                    'sumber_modul' => 'Invoice',
                    'sumber_ref' => $ref,
                    'journal_entry_id' => $entry->id ?? null,
                    'keterangan' => $d->keterangan,
                    'id_pengguna' => $idPengguna,
                ]);
            }

            // #5: perlakuan aset per baris.
            foreach ($input['details'] as $l) {
                $nilai = Money::mul($l['kuantiti'], $l['harga_satuan']);
                if (! empty($l['kode_aset'])) {
                    AssetDraft::addToAsset($l['kode_aset'], [
                        'nominal' => $nilai,
                        'kuantiti' => Money::of($l['kuantiti'], 4),
                        'sumber_ref' => $ref,
                        'sumber_modul' => 'Invoice',
                    ]);
                } elseif (! empty($l['buat_aset'])) {
                    AssetDraft::createDraftAsset([
                        'nama_aset' => $l['keterangan'] ?? "Aset dari {$input['nomor_invoice']}",
                        'kuantiti' => Money::of($l['kuantiti'], 4),
                        'harga_perolehan' => $nilai,
                        'tanggal_perolehan' => $input['tanggal_invoice'],
                        'kode_coa' => $l['kode_coa'],
                        'sumber_ref' => $ref,
                    ]);
                }
            }

            if (! empty($input['id_po'])) {
                $this->updatePoProgress($input['id_po'], $inv->details);
            }

            return $inv->load('details');
        });
    }

    /** Ubah metadata invoice (tanggal jatuh tempo, keterangan) — tanpa menyentuh jurnal. */
    public function updateMeta(int $idInvoice, array $data): Invoice
    {
        $inv = Invoice::find($idInvoice);
        if (! $inv) {
            throw new AppException(404, 'Invoice tidak ditemukan.');
        }
        $inv->update(array_intersect_key($data, array_flip(['tanggal_jatuh_tempo', 'keterangan'])));

        return $inv;
    }

    /** Void invoice: reversal jurnal + rollback stok/aset/PO. Diblokir bila sudah dibayar. */
    public function void(int $idInvoice, string $alasan, ?int $idPengguna): Invoice
    {
        $inv = Invoice::with('details')->find($idInvoice);
        if (! $inv) {
            throw new AppException(404, 'Invoice tidak ditemukan.');
        }
        if ($inv->status === 'void') {
            throw new AppException(409, 'Invoice sudah di-void.');
        }
        if (Money::lt($inv->sisa_hutang, $inv->total)) {
            throw new AppException(409, 'Invoice sudah dibayar sebagian/lunas. Void pembayaran (Kas Keluar) terkait terlebih dahulu.');
        }

        return DB::transaction(function () use ($inv, $idInvoice, $alasan, $idPengguna) {
            Authorization::authorizeByUser($idPengguna, $inv->total);

            $entry = JournalEntry::where('sumber_modul', 'Invoice')
                ->where('id_sumber', (string) $idInvoice)
                ->where('status', 'aktif')
                ->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, [
                    'id_pengguna' => $idPengguna, 'keteranganPrefix' => "Void ({$alasan}) — ",
                ]);
            }

            // Lapisan FIFO yang lahir dari invoice ini ditarik kembali; kalau
            // barangnya sudah terlanjur dipakai, void-nya berhenti di sini
            // dengan pesan yang terang alih-alih merusak harga pokok diam-diam.
            InventoryMovement::batalkanDokumen('Invoice', $inv->nomor_ref_internal, $idPengguna);

            // Baris penerimaan yang ditagih invoice ini kembali "belum ditagih".
            foreach ($inv->details as $d) {
                if (! $d->id_penerimaan_detail) {
                    continue;
                }
                $pd = PenerimaanBarangDetail::lockForUpdate()->find($d->id_penerimaan_detail);
                $pd->update([
                    'qty_ditagih' => Money::sub($pd->qty_ditagih, $d->kuantiti, 4),
                    'nilai_ditagih' => Money::sub($pd->nilai_ditagih, $d->nilai_penerimaan ?? '0'),
                ]);
            }

            if ($inv->nomor_ref_internal) {
                AssetDraft::deleteDraftAssets($inv->nomor_ref_internal);
                AssetDraft::reverseAssetMovements($inv->nomor_ref_internal);
            }

            if ($inv->id_po) {
                $this->rollbackPoProgress($inv->id_po, $inv->details);
            }

            $inv->update([
                'status' => 'void',
                'keterangan' => trim("[VOID: {$alasan}] ".($inv->keterangan ?? '')),
            ]);

            return $inv;
        });
    }

    /**
     * Baris invoice yang menagih satu baris penerimaan barang.
     *
     *   D Barang Diterima Belum Ditagih  sebesar NILAI TERIMA bagian yang ditagih
     *   ± selisih (harga invoice − harga terima) ke akun Selisih Persediaan barangnya
     *
     * Tagihan yang menghabiskan sisa baris memakai sisa nilainya PERSIS, supaya akun
     * GRNI kembali nol tanpa sisa pembulatan rupiah.
     *
     * @param  array<int,string>  $ditagihPerTerima  qty yang sudah dipakai baris sebelumnya di invoice yang sama
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:?int}
     */
    private function barisPenerimaan(array $l, int $no, string $lineTotal, string $ket, string $kodeVendor, array &$ditagihPerTerima): array
    {
        $grni = PenerimaanBarangService::akunGrni();
        $pd = PenerimaanBarangDetail::with('penerimaan', 'persediaan')->find($l['id_penerimaan_detail']);
        if (! $pd || $pd->penerimaan->status !== 'aktif') {
            throw new AppException(422, "Baris {$no}: penerimaan barang yang dirujuk tidak ditemukan atau sudah dibatalkan.");
        }
        if ($pd->penerimaan->kode_vendor !== $kodeVendor) {
            throw new AppException(422, "Baris {$no}: penerimaan {$pd->penerimaan->nomor} milik vendor lain.");
        }

        $item = $pd->persediaan;
        $qty = Money::of($l['kuantiti'], 4);
        $sudah = $ditagihPerTerima[$pd->id] ?? '0';
        $sisaQty = Money::sub($pd->sisaQty(), $sudah, 4);
        if (Money::gt($qty, $sisaQty, 4)) {
            throw new AppException(422,
                "Baris {$no}: {$item->nama_persediaan} ditagih melebihi yang diterima di {$pd->penerimaan->nomor} "
                ."(sisa belum ditagih {$this->angka($sisaQty)} {$item->satuan}).");
        }
        $ditagihPerTerima[$pd->id] = Money::add($sudah, $qty, 4);

        $nilaiTerima = Money::eq($qty, $sisaQty, 4)
            ? Money::sub($pd->sisaNilai(), $ditagihPerTerima['nilai:'.$pd->id] ?? '0')
            : Money::div(Money::mul($qty, $pd->total), $pd->kuantiti);
        $ditagihPerTerima['nilai:'.$pd->id] = Money::add($ditagihPerTerima['nilai:'.$pd->id] ?? '0', $nilaiTerima);

        // Menagih SELURUH sisa penerimaan & bedanya hanya pembulatan harga satuan 2 desimal (≤ ½ sen per unit,
        // mis. 72 × 2.083,33 = 149.999,76 vs 150.000) → bukan selisih harga sungguhan: total baris disamakan dengan
        // nilai terima. Dulu muncul "selisih Rp 0,24" yang memaksa petugas memilih Bagian (audit 21 Okt 2026).
        $beda = Money::sub($lineTotal, $nilaiTerima);
        if (Money::eq($qty, $sisaQty, 4) && Money::lte(ltrim($beda, '-'), bcmul($qty, '0.005', 2))) {
            $lineTotal = $nilaiTerima;
        }

        $jurnal = [];
        if (Money::gtZero($nilaiTerima)) {
            $jurnal[] = ['kode_coa' => $grni, 'debet' => $nilaiTerima, 'kredit' => '0', 'keterangan' => $ket];
        }
        $selisih = Money::sub($lineTotal, $nilaiTerima);
        if (! Money::isZero($selisih)) {
            if (! $item->kode_coa_selisih) {
                throw new AppException(422, "Baris {$no}: harga invoice berbeda dari harga terima, tetapi {$item->nama_persediaan} belum punya Akun Selisih Persediaan.");
            }
            if (BagianPolicy::wajibBagian('Invoice', $item->kode_coa_selisih) && empty($l['kode_bagian'])) {
                throw new AppException(422, "Baris {$no}: harga invoice berbeda dari harga terima (selisih Rp ".number_format((float) $selisih, 0, ',', '.').'). Pilih Bagian untuk membukukan selisihnya.');
            }
            $jurnal[] = [
                'kode_coa' => $item->kode_coa_selisih,
                'kode_bagian' => $l['kode_bagian'] ?? null,
                'debet' => Money::gtZero($selisih) ? $selisih : '0',
                'kredit' => Money::gtZero($selisih) ? '0' : Money::sub('0', $selisih),
                'keterangan' => "Selisih harga invoice vs terima — {$item->nama_persediaan}",
            ];
        }

        $baris = [
            'kode_coa' => $grni,
            'nama_coa' => CoaDetail::find($grni)->nama_coa,
            'keterangan' => $l['keterangan'] ?? "{$item->nama_persediaan} ({$pd->penerimaan->nomor})",
            'kuantiti' => $qty,
            'harga_satuan' => Money::of($l['harga_satuan']),
            'total' => $lineTotal,
            'kode_persediaan' => $item->kode_persediaan,
            'id_po_detail' => $pd->id_po_detail,
            'id_penerimaan_detail' => $pd->id,
            'nilai_penerimaan' => $nilaiTerima,
        ];

        return [$baris, $jurnal, $pd->penerimaan->id_po];
    }

    /** PO yang dirujuk invoice: harus ada, vendornya sama, dan belum batal. */
    private function poUntukInvoice(int $idPo, string $kodeVendor, bool $bolehSelesai = false): PurchaseOrder
    {
        $po = PurchaseOrder::with('details')->find($idPo);
        if (! $po) {
            throw new AppException(400, 'Purchase Order tidak ditemukan.');
        }
        if ($po->status === 'batal' || ($po->status === 'selesai' && ! $bolehSelesai)) {
            throw new AppException(422, "PO {$po->nomor_po} berstatus {$po->status}; tak bisa ditagih lagi.");
        }
        if ($po->kode_vendor !== $kodeVendor) {
            throw new AppException(422, "Vendor invoice berbeda dari vendor PO {$po->nomor_po}.");
        }

        return $po;
    }

    private function angka(string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }

    /**
     * Perbarui qty_invoiced tiap baris PO + status PO (open/sebagian/selesai).
     *
     * Baris PO yang ditagih DISIMPAN di `invoice_details.id_po_detail`. Pencocokan bila
     * belum disebut: barang yang sama dulu, baru akun yang sama (cara lama) — dan hasil
     * pencocokannya ikut disimpan, sehingga void mengembalikan progres ke baris yang benar.
     */
    private function updatePoProgress(int $idPo, $invoiceDetails): void
    {
        $po = PurchaseOrder::with('details')->find($idPo);
        if (! $po) {
            return;
        }

        foreach ($invoiceDetails as $inv) {
            $poDetail = $inv->id_po_detail
                ? $po->details->firstWhere('id', $inv->id_po_detail)
                : ($po->details->first(fn ($d) => $inv->kode_persediaan && $d->kode_persediaan === $inv->kode_persediaan
                        && Money::lt($d->qty_invoiced, $d->kuantiti, 4))
                    ?? $po->details->first(fn ($d) => $d->kode_coa === $inv->kode_coa
                        && Money::lt($d->qty_invoiced, $d->kuantiti, 4)));
            if (! $poDetail) {
                continue;
            }
            $newInvoiced = Money::add($poDetail->qty_invoiced, $inv->kuantiti ?? 0, 4);
            if (Money::gt($newInvoiced, $poDetail->kuantiti, 4)) {
                $newInvoiced = Money::of($poDetail->kuantiti, 4);
            }
            $poDetail->qty_invoiced = $newInvoiced;
            $poDetail->save();
            if (! $inv->id_po_detail) {
                $inv->update(['id_po_detail' => $poDetail->id]);
            }
        }

        $this->syncPoStatus($po);
    }

    /** Kebalikan updatePoProgress (saat void invoice). */
    private function rollbackPoProgress(int $idPo, $invoiceDetails): void
    {
        $po = PurchaseOrder::with('details')->find($idPo);
        if (! $po) {
            return;
        }

        foreach ($invoiceDetails as $inv) {
            // Invoice lama (sebelum id_po_detail disimpan) masih dicocokkan lewat akun.
            $poDetail = $inv->id_po_detail
                ? $po->details->firstWhere('id', $inv->id_po_detail)
                : $po->details->first(fn ($d) => $d->kode_coa === $inv->kode_coa && Money::gtZero($d->qty_invoiced, 4));
            if (! $poDetail) {
                continue;
            }
            $newInvoiced = Money::sub($poDetail->qty_invoiced, $inv->kuantiti ?? 0, 4);
            if (Money::isNegative($newInvoiced, 4)) {
                $newInvoiced = '0';
            }
            $poDetail->qty_invoiced = $newInvoiced;
            $poDetail->save();
        }

        $this->syncPoStatus($po, keepBatal: true);
    }

    /** Hitung ulang status PO dari qty_invoiced tiap baris. */
    private function syncPoStatus(PurchaseOrder $po, bool $keepBatal = false): void
    {
        $po->load('details');
        $allDone = $po->details->every(fn ($d) => Money::gte($d->qty_invoiced, $d->kuantiti, 4));
        $anyDone = $po->details->contains(fn ($d) => Money::gtZero($d->qty_invoiced, 4));
        $status = ($keepBatal && $po->status === 'batal')
            ? 'batal'
            : ($allDone ? 'selesai' : ($anyDone ? 'sebagian' : 'open'));
        $po->update(['status' => $status]);
    }
}
