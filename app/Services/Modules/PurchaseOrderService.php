<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Ledger\DocNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Modul Purchase Order (TIDAK menghasilkan jurnal — komitmen pembelian saja).
 * Progres qty_invoiced & status diperbarui oleh InvoiceService.
 */
class PurchaseOrderService
{
    public function create(array $input, ?int $idPengguna): PurchaseOrder
    {
        $vendor = Vendor::find($input['kode_vendor']);
        if (! $vendor) {
            throw new AppException(400, 'Vendor tidak ditemukan.');
        }
        $unit = BusinessUnit::find($input['kode_unit']);
        if (! $unit) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }

        $total = '0';
        $details = [];
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
                'qty_invoiced' => '0',
                'kode_persediaan' => $l['kode_persediaan'] ?? null,
            ];
        }

        return DB::transaction(function () use ($input, $idPengguna, $total, $details) {
            $base = DocNumber::docBase('PO', $input['tanggal_po']);
            $last = PurchaseOrder::where('nomor_po', 'like', $base.'%')
                ->orderByDesc('nomor_po')
                ->value('nomor_po');
            $nomorPo = DocNumber::nextDocNumber($base, $last);

            $po = PurchaseOrder::create([
                'nomor_po' => $nomorPo,
                'tanggal_po' => $input['tanggal_po'],
                'kode_vendor' => $input['kode_vendor'],
                'kode_unit' => $input['kode_unit'],
                'keterangan' => $input['keterangan'] ?? null,
                'total_po' => $total,
                'status' => 'open',
                'id_pengguna' => $idPengguna ?? 0,
            ]);
            foreach ($details as $d) {
                $po->details()->create($d);
            }

            return $po->load(['details', 'vendor']);
        });
    }

    /** Batalkan PO. PO yang sebagian/seluruhnya sudah di-invoice tidak bisa dibatalkan. */
    public function cancel(int $idPo): PurchaseOrder
    {
        $po = PurchaseOrder::with('details')->find($idPo);
        if (! $po) {
            throw new AppException(404, 'Purchase Order tidak ditemukan.');
        }
        if ($po->status === 'batal') {
            throw new AppException(409, 'PO sudah dibatalkan.');
        }
        if ($po->status === 'selesai') {
            throw new AppException(409, 'PO sudah selesai (fully invoiced).');
        }
        if ($po->details->contains(fn ($d) => Money::gtZero($d->qty_invoiced, 4))) {
            throw new AppException(409, 'PO sudah sebagian di-invoice; tidak dapat dibatalkan.');
        }

        $po->update(['status' => 'batal']);

        return $po;
    }
}
