<?php

namespace App\Support;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Vendor;

/**
 * Opsi referensi untuk dropdown form (port `referensi.service.ts` dev).
 * Mengembalikan array [nilai => label] siap pakai di <x-field :options> / <select>.
 * Dipakai lintas modul agar sumber pilihan konsisten (jenjang, bagian, unit, COA…).
 */
class Referensi
{
    /** Jenjang = master Jenjang aktif (sumber tunggal lintas modul). */
    public static function jenjang(): array
    {
        return Jenjang::where('status', 'aktif')
            ->orderBy('urutan')
            ->orderBy('kode')
            ->pluck('nama', 'kode')
            ->all();
    }

    /** Jalur pendaftaran aktif → [kode => nama]. */
    public static function jalur(): array
    {
        return JalurPendaftaran::where('status', 'aktif')
            ->orderBy('kode')
            ->pluck('nama', 'kode')
            ->all();
    }

    /** Bagian aktif → [kode => "kode — nama"]. */
    public static function bagian(): array
    {
        return Bagian::where('status', 'aktif')
            ->orderBy('kode_bagian')
            ->get(['kode_bagian', 'nama_bagian'])
            ->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])
            ->all();
    }

    /** Unit bisnis aktif → [kode_unit => nama_unit]. */
    public static function businessUnits(): array
    {
        return BusinessUnit::where('status', 'aktif')
            ->orderBy('kode_unit')
            ->pluck('nama_unit', 'kode_unit')
            ->all();
    }

    /**
     * Akun COA aktif → [kode_coa => "kode — nama"]. $prefix (mis. '5') membatasi
     * ke satu kelompok akun (Beban, Kas, dst.).
     */
    public static function coa(?string $prefix = null): array
    {
        return CoaDetail::where('status', 'aktif')
            ->when($prefix, fn ($q) => $q->where('kode_coa', 'like', $prefix.'%'))
            ->orderBy('kode_coa')
            ->get(['kode_coa', 'nama_coa'])
            ->mapWithKeys(fn ($a) => [$a->kode_coa => "{$a->kode_coa} — {$a->nama_coa}"])
            ->all();
    }

    /** Rekening kas/bank aktif → [kode_coa => "nama_rekening (kode)"]. */
    public static function bankAccounts(): array
    {
        return BankAccount::where('status', 'aktif')
            ->orderBy('kode_coa')
            ->get(['kode_coa', 'nama_rekening'])
            ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])
            ->all();
    }

    /** Vendor aktif → [kode_vendor => nama_vendor]. */
    public static function vendors(): array
    {
        return Vendor::where('status', 'aktif')
            ->orderBy('nama_vendor')
            ->pluck('nama_vendor', 'kode_vendor')
            ->all();
    }

    /** Sisipkan opsi kosong di depan (mis. "— Semua —"). */
    public static function withEmpty(array $options, string $label = '— Pilih —'): array
    {
        return ['' => $label] + $options;
    }
}
