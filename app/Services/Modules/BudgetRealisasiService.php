<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\User;
use App\Services\Ledger\PeringkatPengajuan;

/**
 * REVIEW REALISASI ANGGARAN — gerbang khusus (port budget-realisasi.service.ts),
 * bebas matriks modul. Bertingkat menurut posisi:
 *  - ADMIN & YAYASAN (Bagian.level = 1) → SEMUA bagian tanpa batas.
 *  - DIREKTORAT (Bagian.level = 2) → bagiannya + seluruh bawahannya (subtree).
 *  - MUDIR BAGIAN (peringkat 3) & STAFF (peringkat 4) → HANYA bagiannya sendiri.
 * Selain itu → 403. Dua sumbu (level bagian & peringkat) memang hierarki terpisah.
 */
class BudgetRealisasiService
{
    private const LEVEL_YAYASAN = 1;

    private const LEVEL_DIREKTORAT = 2;

    public function __construct(private BudgetService $budget = new BudgetService) {}

    /** Self + seluruh keturunan sebuah bagian (subtree), via kode_induk. */
    private function subtreeBagian(string $kode): array
    {
        $all = Bagian::all(['kode_bagian', 'nama_bagian', 'kode_induk']);
        $anak = [];
        foreach ($all as $b) {
            if (! $b->kode_induk) {
                continue;
            }
            $anak[$b->kode_induk][] = $b->kode_bagian;
        }
        $nama = $all->pluck('nama_bagian', 'kode_bagian');

        $out = [];
        $seen = [];
        $stack = [$kode];
        while ($stack) {
            $k = array_pop($stack);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            if (isset($nama[$k])) {
                $out[] = ['kode_bagian' => $k, 'nama_bagian' => $nama[$k]];
            }
            foreach ($anak[$k] ?? [] as $c) {
                $stack[] = $c;
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['kode_bagian'], $b['kode_bagian']));

        return $out;
    }

    public function review(int $idPengguna, int $tahun, ?string $kodeBagian = null, ?string $kodeUnit = null): array
    {
        $user = User::find($idPengguna);
        if (! $user || $user->status !== 'aktif') {
            throw new AppException(401, 'Pengguna tidak ditemukan.');
        }
        $kodeBagian = ($kodeBagian !== null && $kodeBagian !== '') ? $kodeBagian : null;

        $level = $user->kode_bagian
            ? Bagian::whereKey($user->kode_bagian)->value('level')
            : null;

        // Admin & Yayasan (level 1): akses PENUH — semua bagian.
        if ($user->is_admin || $level === self::LEVEL_YAYASAN) {
            $semua = Bagian::where('status', 'aktif')
                ->orderBy('kode_bagian')
                ->get(['kode_bagian', 'nama_bagian'])
                ->map(fn ($b) => ['kode_bagian' => $b->kode_bagian, 'nama_bagian' => $b->nama_bagian])
                ->all();
            $data = $this->budget->realisasi($tahun, $kodeBagian, $kodeUnit);

            return array_merge($data, ['boleh_semua' => true, 'bagian_opsi' => $semua]);
        }

        // Direktorat (level 2): bagiannya + seluruh bawahannya (subtree).
        if ($level === self::LEVEL_DIREKTORAT && $user->kode_bagian) {
            $subtree = $this->subtreeBagian($user->kode_bagian);
            $kodeSet = array_column($subtree, 'kode_bagian');
            if ($kodeBagian) {
                if (! in_array($kodeBagian, $kodeSet, true)) {
                    throw new AppException(403, 'Bagian itu di luar wewenang direktorat Anda.');
                }
                $scope = $kodeBagian;
            } else {
                $scope = $kodeSet; // seluruh subtree
            }
            $data = $this->budget->realisasi($tahun, $scope, $kodeUnit);

            return array_merge($data, ['boleh_semua' => false, 'bagian_opsi' => $subtree]);
        }

        // Mudir Bagian (3) & Staff (4): HANYA bagiannya sendiri.
        if ($user->kode_bagian
            && ((int) $user->peringkat_pengajuan === PeringkatPengajuan::MUDIR_BAGIAN
                || (int) $user->peringkat_pengajuan === PeringkatPengajuan::STAFF)) {
            if ($kodeBagian && $kodeBagian !== $user->kode_bagian) {
                throw new AppException(403, 'Anda hanya dapat melihat realisasi bagian Anda sendiri.');
            }
            $own = Bagian::whereKey($user->kode_bagian)->first(['kode_bagian', 'nama_bagian']);
            $data = $this->budget->realisasi($tahun, $user->kode_bagian, $kodeUnit);

            return array_merge($data, [
                'boleh_semua' => false,
                'bagian_opsi' => $own ? [['kode_bagian' => $own->kode_bagian, 'nama_bagian' => $own->nama_bagian]] : [],
            ]);
        }

        throw new AppException(
            403,
            'Realisasi Anggaran hanya untuk admin, Yayasan, Direktorat, dan Mudir Bagian/Staff (bagiannya sendiri).',
        );
    }
}
