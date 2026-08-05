<?php

namespace App\Support;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\Vendor;
use App\Services\Ppsb\Tahap;

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
            ->orderBy('urutan')
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

    /**
     * Santri untuk dropdown → [id => "NIS - Nama - Jenjang - Tingkat"].
     *
     * NAMA SAJA TIDAK CUKUP untuk memilih santri: di pesantren banyak nama yang
     * mirip atau persis sama, dan memilih yang salah berarti tagihan/keringanan
     * menempel pada anak orang lain. Keempat keping itu bersama-sama praktis
     * selalu membedakan — dan ketiganya (jenjang, tingkat) juga yang paling
     * diingat petugas saat menerima wali di depan meja.
     *
     * Diurutkan menurut NAMA, bukan NIS, walau labelnya dimulai dengan NIS:
     * justru nama-nama yang mirip harus BERDAMPINGAN supaya bedanya terlihat.
     *
     * $status membatasi ke satu tahap (mis. 'aktif'); null = semua santri, yang
     * memang diperlukan modul seperti Nominal SPP Khusus (calon pun boleh disetel).
     *
     * Yang sudah MENGUNDURKAN DIRI selalu disingkirkan (lihat
     * Tahap::DISEMBUNYIKAN_DARI_PEMILIH): tagihannya sudah ditutup saat ia mundur,
     * jadi menawarkannya di pemilih hanya membuka jalan salah-pilih ke anak yang
     * sudah tak ada urusannya dengan pesantren.
     */
    public static function santri(?string $status = null): array
    {
        $peta = Jenjang::pluck('nama', 'kode')->all();

        return Santri::when($status, fn ($q) => $q->where('status', $status))
            ->whereNotIn('status', Tahap::DISEMBUNYIKAN_DARI_PEMILIH)
            ->orderBy('nama')
            ->get(['id', 'nis', 'no_pendaftaran', 'nama', 'kode_jenjang', 'tingkat'])
            ->mapWithKeys(fn ($s) => [$s->id => static::labelSantri($s, $peta)])
            ->all();
    }

    /**
     * Satu label santri. $petaJenjang ([kode => nama]) sebaiknya dioper bila
     * dipanggil berulang — tanpa itu master jenjang dibaca sekali per panggilan.
     *
     * Calon santri belum ber-NIS, jadi nomor pendaftarannya yang dipakai —
     * tetap satu kolom identitas, bukan sel kosong yang membingungkan.
     */
    public static function labelSantri(Santri $santri, ?array $petaJenjang = null): string
    {
        $petaJenjang ??= Jenjang::pluck('nama', 'kode')->all();

        $nomor = trim((string) ($santri->nis ?: $santri->no_pendaftaran));
        $jenjang = $petaJenjang[$santri->kode_jenjang] ?? (string) $santri->kode_jenjang;

        return implode(' - ', [
            $nomor !== '' ? $nomor : '—',
            $santri->nama,
            $jenjang !== '' ? $jenjang : '—',
            $santri->tingkat ? "Tingkat {$santri->tingkat}" : '—',
        ]);
    }

    /** Vendor aktif → [kode_vendor => nama_vendor]. */
    public static function vendors(): array
    {
        return Vendor::where('status', 'aktif')
            ->orderBy('nama_vendor')
            ->pluck('nama_vendor', 'kode_vendor')
            ->all();
    }

    /**
     * Label "kode — nama" yang MENGKERUT jadi satu bila keduanya sama.
     *
     * Banyak master di sini diisi dengan kode = nama (jenjang SDTQ/SMP/SMA
     * misalnya), sehingga penggabungan mentah menghasilkan "SDTQ — SDTQ".
     * Dipakai di tempat yang perlu menampilkan kodenya, bukan namanya saja.
     */
    public static function label(?string $kode, ?string $nama): string
    {
        $kode = trim((string) $kode);
        $nama = trim((string) $nama);

        if ($kode === '' || strcasecmp($kode, $nama) === 0) {
            return $nama !== '' ? $nama : $kode;
        }

        return $nama === '' ? $kode : "{$kode} — {$nama}";
    }

    /** Sisipkan opsi kosong di depan (mis. "— Semua —"). */
    public static function withEmpty(array $options, string $label = '— Pilih —'): array
    {
        return ['' => $label] + $options;
    }
}
