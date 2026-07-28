<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\AssetMovement;
use App\Support\Money;

/**
 * #5: Aset tetap otomatis dari transaksi pembelian (Invoice/Kas Keluar).
 * Draft dibuat saat baris menyentuh akun aset & ditandai "buat aset";
 * penambahan nilai ke aset eksisting dicatat sebagai AssetMovement (bisa dibalik).
 */
final class AssetDraft
{
    /** Buat DRAFT aset (status 'draft', umur_manfaat 0 — dilengkapi user nanti). */
    public static function createDraftAsset(array $input): Asset
    {
        $last = Asset::where('kode_aset', 'like', 'AST%')
            ->orderByDesc('kode_aset')
            ->value('kode_aset');
        $n = 1;
        if ($last) {
            $tail = substr($last, 3);
            if (is_numeric($tail)) {
                $n = ((int) $tail) + 1;
            }
        }
        $kodeAset = 'AST'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);

        return Asset::create([
            'kode_aset' => $kodeAset,
            'nama_aset' => $input['nama_aset'],
            'kategori_aset' => $input['kategori_aset'] ?? null,
            'kuantiti' => Money::of($input['kuantiti'] ?? 1, 4),
            'harga_perolehan' => Money::of($input['harga_perolehan']),
            'tanggal_perolehan' => $input['tanggal_perolehan'],
            'umur_manfaat' => 0,
            'metode_depresiasi' => 'garis_lurus',
            'nilai_residu' => '0',
            'akumulasi_depresiasi' => '0',
            'kode_coa' => $input['kode_coa'] ?? null,
            'status' => 'draft',
            'sumber_ref' => $input['sumber_ref'],
        ]);
    }

    /** Hapus draft aset dari sebuah transaksi (hanya yang masih 'draft'). */
    public static function deleteDraftAssets(string $sumberRef): void
    {
        Asset::where('sumber_ref', $sumberRef)->where('status', 'draft')->delete();
    }

    /** Tambah nilai perolehan ke aset EKSISTING + catat AssetMovement. */
    public static function addToAsset(string $kodeAset, array $input): void
    {
        $aset = Asset::find($kodeAset);
        if (! $aset) {
            throw new AppException(400, "Aset {$kodeAset} tidak ditemukan.");
        }
        $aset->update([
            'harga_perolehan' => Money::add($aset->harga_perolehan, $input['nominal']),
            'kuantiti' => ! empty($input['kuantiti'])
                ? Money::add($aset->kuantiti, $input['kuantiti'], 4)
                : $aset->kuantiti,
        ]);
        AssetMovement::create([
            'kode_aset' => $kodeAset,
            'sumber_ref' => $input['sumber_ref'],
            'sumber_modul' => $input['sumber_modul'],
            'nominal' => Money::of($input['nominal']),
            'kuantiti' => ! empty($input['kuantiti']) ? Money::of($input['kuantiti'], 4) : null,
        ]);
    }

    /** Balik seluruh penambahan nilai aset dari sebuah transaksi (saat void). */
    public static function reverseAssetMovements(string $sumberRef): void
    {
        $moves = AssetMovement::where('sumber_ref', $sumberRef)->get();
        foreach ($moves as $m) {
            $aset = Asset::find($m->kode_aset);
            if ($aset) {
                $harga = Money::sub($aset->harga_perolehan, $m->nominal);
                if (Money::isNegative($harga)) {
                    $harga = '0';
                }
                $qty = Money::of($aset->kuantiti, 4);
                if ($m->kuantiti) {
                    $qty = Money::sub($qty, $m->kuantiti, 4);
                    if (Money::isNegative($qty, 4)) {
                        $qty = '0';
                    }
                }
                $aset->update(['harga_perolehan' => $harga, 'kuantiti' => $qty]);
            }
        }
        AssetMovement::where('sumber_ref', $sumberRef)->delete();
    }
}
