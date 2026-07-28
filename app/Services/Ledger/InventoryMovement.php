<?php

namespace App\Services\Ledger;

use App\Models\Inventory;
use App\Support\Money;

/**
 * Pergerakan stok persediaan (#6). Model V3: stok = stok_masuk - stok_keluar,
 * harga_perolehan = rata-rata tertimbang. Qty pakai scale 4.
 */
final class InventoryMovement
{
    /** Stok masuk: stok_masuk += qty, harga rata-rata baru dari value yang masuk. */
    public static function applyStockIn(string $kodePersediaan, string|int|float $qty, string|int|float $value): void
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            return;
        }
        $oldQty = Money::sub($item->stok_masuk, $item->stok_keluar, 4);
        $oldVal = Money::mul($oldQty, $item->harga_perolehan, 2);
        $newQty = Money::add($oldQty, $qty, 4);
        $newVal = Money::add($oldVal, $value, 2);

        $item->harga_perolehan = Money::gtZero($newQty, 4)
            ? Money::div($newVal, $newQty, 2)
            : Money::of($item->harga_perolehan, 2);
        $item->stok_masuk = Money::add($item->stok_masuk, $qty, 4);
        $item->save();
    }

    /** Stok keluar: stok_keluar += qty (harga rata-rata tetap). */
    public static function applyStockOut(string $kodePersediaan, string|int|float $qty): void
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            return;
        }
        $item->stok_keluar = Money::add($item->stok_keluar, $qty, 4);
        $item->save();
    }

    /** Batalkan stok masuk: stok_masuk -= qty (min 0). */
    public static function rollbackStockIn(string $kodePersediaan, string|int|float $qty): void
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            return;
        }
        $masuk = Money::sub($item->stok_masuk, $qty, 4);
        if (Money::isNegative($masuk, 4)) {
            $masuk = '0';
        }
        $item->stok_masuk = $masuk;
        $item->save();
    }

    /** Batalkan stok keluar: stok_keluar -= qty (min 0). */
    public static function rollbackStockOut(string $kodePersediaan, string|int|float $qty): void
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            return;
        }
        $keluar = Money::sub($item->stok_keluar, $qty, 4);
        if (Money::isNegative($keluar, 4)) {
            $keluar = '0';
        }
        $item->stok_keluar = $keluar;
        $item->save();
    }
}
