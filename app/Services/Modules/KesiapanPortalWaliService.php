<?php

namespace App\Services\Modules;

use App\Services\Ppsb\Tahap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KESIAPAN AKUN PORTAL WALI — siapa yang sudah bisa masuk, siapa yang perlu dibereskan.
 *
 * Wali masuk portal dengan email (OTP), dicari dari `email_ayah` / `email_ibu` /
 * `email_wali` tanpa membedakan huruf. Dua keadaan yang menghalanginya:
 *  • TANPA EMAIL sama sekali — tak ada alamat untuk mengirim kode;
 *  • EMAIL KEMBAR — satu alamat tercatat di lebih dari satu keluarga. Masuk
 *    ditolak, karena dompet melekat per keluarga dan menggabungkan dua keluarga
 *    diam-diam berarti mencampur dua dompet. Diperiksa di SELURUH wali, bukan
 *    hanya yang anaknya aktif: penolakannya pun berlaku tanpa memandang status.
 *
 * "Keluarga yang perlu bisa masuk" = punya anak yang belum berstatus final
 * (aktif, atau masih dalam proses PSB). Keluarga alumni tak dihitung kurang.
 *
 * Laporan saja — perbaikannya lewat form sunting wali yang sudah ada.
 */
class KesiapanPortalWaliService
{
    /**
     * @return array{perlu:int, siap:int, kembar:list<array{email:string, keluarga:list<array<string,mixed>>}>, tanpa_email:list<array<string,mixed>>}
     */
    public function ringkasan(): array
    {
        $final = Tahap::STATUS_FINAL;
        $tanda = implode(',', array_fill(0, count($final), '?'));

        $perlu = collect(DB::select("SELECT DISTINCT id_wali FROM santri
            WHERE id_wali IS NOT NULL AND status NOT IN ({$tanda})", $final))->pluck('id_wali');

        $email = collect(DB::select(<<<'SQL'
            SELECT id AS id_wali, peran, email FROM (
                SELECT id, 'ayah' AS peran, lower(trim(email_ayah)) AS email FROM wali
                UNION ALL SELECT id, 'ibu', lower(trim(email_ibu)) FROM wali
                UNION ALL SELECT id, 'wali', lower(trim(email_wali)) FROM wali
            ) e WHERE COALESCE(email, '') <> ''
        SQL));

        // Alamat yang dipakai lebih dari satu KELUARGA. Ayah & ibu satu keluarga
        // yang berbagi alamat bukan kembar — mereka melihat anak yang sama.
        $alamatKembar = $email->groupBy('email')
            ->filter(fn (Collection $baris) => $baris->pluck('id_wali')->unique()->count() > 1)
            ->keys();

        $punyaEmailSah = $email->reject(fn ($e) => $alamatKembar->contains($e->email))->pluck('id_wali')->unique();
        $punyaEmail = $email->pluck('id_wali')->unique();

        $siap = $perlu->intersect($punyaEmailSah);
        $tanpaEmail = $perlu->diff($punyaEmail);

        $idDitampilkan = $tanpaEmail->merge($email->whereIn('email', $alamatKembar)->pluck('id_wali'))->unique();
        $wali = $this->profil($idDitampilkan->all());

        return [
            'perlu' => $perlu->count(),
            'siap' => $siap->count(),
            'kembar' => $alamatKembar->sort()->values()->map(fn ($alamat) => [
                'email' => $alamat,
                'keluarga' => $email->where('email', $alamat)->groupBy('id_wali')
                    ->map(fn (Collection $b, $id) => ($wali[$id] ?? []) + ['peran' => $b->pluck('peran')->all()])
                    ->values()->all(),
            ])->all(),
            'tanpa_email' => $tanpaEmail->map(fn ($id) => $wali[$id] ?? ['id' => $id])
                ->sortBy('nama')->values()->all(),
        ];
    }

    /**
     * Nama, telepon, dan anak (yang belum final) per wali — satu kueri untuk semuanya.
     *
     * @param  list<int>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function profil(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $anak = DB::table('santri')->whereIn('id_wali', $ids)->whereNotIn('status', Tahap::STATUS_FINAL)
            ->orderBy('nama')->get(['id_wali', 'nama', 'status'])->groupBy('id_wali');

        return DB::table('wali')->whereIn('id', $ids)->get(['id', 'nama', 'telepon'])
            ->mapWithKeys(fn ($w) => [$w->id => [
                'id' => $w->id, 'nama' => $w->nama, 'telepon' => $w->telepon,
                'anak' => ($anak[$w->id] ?? collect())->map(fn ($s) => [
                    'nama' => $s->nama, 'status' => Tahap::labelStatus((string) $s->status),
                ])->all(),
            ]])->all();
    }
}
