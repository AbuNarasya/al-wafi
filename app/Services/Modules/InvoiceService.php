<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Ledger\AssetDraft;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
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

        $total = '0';
        $details = [];
        $jLines = [];
        foreach ($input['details'] as $l) {
            $coa = CoaDetail::find($l['kode_coa']);
            if (! $coa) {
                throw new AppException(400, "Akun COA {$l['kode_coa']} tidak ditemukan.");
            }
            $lineTotal = Money::mul($l['kuantiti'], $l['harga_satuan']);
            $total = Money::add($total, $lineTotal);
            $details[] = [
                'kode_coa' => $l['kode_coa'],
                'nama_coa' => $coa->nama_coa,
                'keterangan' => $l['keterangan'] ?? null,
                'kuantiti' => Money::of($l['kuantiti'], 4),
                'harga_satuan' => Money::of($l['harga_satuan']),
                'total' => $lineTotal,
            ];
            $jLines[] = [
                'kode_coa' => $l['kode_coa'],
                'nama_coa' => $coa->nama_coa,
                'kode_bagian' => $l['kode_bagian'] ?? null,
                'debet' => $lineTotal,
                'kredit' => '0',
                'keterangan' => $l['keterangan'] ?? "Invoice {$input['nomor_invoice']}",
            ];
        }
        $jLines[] = [
            'kode_coa' => $input['kode_coa_hutang'],
            'nama_coa' => $hutangCoa->nama_coa,
            'debet' => '0',
            'kredit' => $total,
            'keterangan' => "Pengakuan hutang invoice {$input['nomor_invoice']}",
        ];

        $nomorPo = null;
        if (! empty($input['id_po'])) {
            $po = PurchaseOrder::find($input['id_po']);
            if (! $po) {
                throw new AppException(400, 'Purchase Order tidak ditemukan.');
            }
            $nomorPo = $po->nomor_po;
        }

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
                'keterangan' => $input['keterangan'] ?? null,
                'total' => $total,
                'sisa_hutang' => $total,
                'status' => 'belum_bayar',
                'id_pengguna' => $idPengguna ?? 0,
            ]);
            foreach ($details as $d) {
                $inv->details()->create($d);
            }

            PostingService::postJournal([
                'referensi' => $ref,
                'tanggal' => $input['tanggal_invoice'],
                'kode_unit' => $input['kode_unit'],
                'keterangan' => $input['keterangan'] ?? "Invoice {$input['nomor_invoice']}",
                'sumber_modul' => 'Invoice',
                'id_sumber' => (string) $inv->id_invoice,
                'id_pengguna' => $idPengguna,
                'lines' => $jLines,
            ]);

            // Auto-update stok persediaan (weighted-average) untuk item yang akunnya
            // terhubung ke master Persediaan.
            foreach ($inv->details as $d) {
                $item = Inventory::where('kode_coa', $d->kode_coa)->first();
                if (! $item) {
                    continue;
                }
                $oldQty = Money::sub($item->stok_masuk, $item->stok_keluar, 4);
                $oldVal = Money::mul($oldQty, $item->harga_perolehan);
                $addQty = Money::of($d->kuantiti ?? 0, 4);
                $newQty = Money::add($oldQty, $addQty, 4);
                $newVal = Money::add($oldVal, $d->total);
                $item->update([
                    'harga_perolehan' => Money::gtZero($newQty, 4) ? Money::div($newVal, $newQty) : Money::of($item->harga_perolehan),
                    'stok_masuk' => Money::add($item->stok_masuk, $addQty, 4),
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

            foreach ($inv->details as $d) {
                if (! $d->kuantiti) {
                    continue;
                }
                $item = Inventory::where('kode_coa', $d->kode_coa)->first();
                if (! $item) {
                    continue;
                }
                $masuk = Money::sub($item->stok_masuk, $d->kuantiti, 4);
                if (Money::isNegative($masuk, 4)) {
                    $masuk = '0';
                }
                $item->update(['stok_masuk' => $masuk]);
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

    /** Perbarui qty_invoiced tiap baris PO + status PO (open/sebagian/selesai). */
    private function updatePoProgress(int $idPo, $invoiceDetails): void
    {
        $po = PurchaseOrder::with('details')->find($idPo);
        if (! $po) {
            return;
        }

        foreach ($invoiceDetails as $inv) {
            $poDetail = $po->details->first(
                fn ($d) => $d->kode_coa === $inv->kode_coa && Money::lt($d->qty_invoiced, $d->kuantiti, 4)
            );
            if (! $poDetail) {
                continue;
            }
            $newInvoiced = Money::add($poDetail->qty_invoiced, $inv->kuantiti ?? 0, 4);
            if (Money::gt($newInvoiced, $poDetail->kuantiti, 4)) {
                $newInvoiced = Money::of($poDetail->kuantiti, 4);
            }
            $poDetail->qty_invoiced = $newInvoiced;
            $poDetail->save();
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
            $poDetail = $po->details->first(
                fn ($d) => $d->kode_coa === $inv->kode_coa && Money::gtZero($d->qty_invoiced, 4)
            );
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
