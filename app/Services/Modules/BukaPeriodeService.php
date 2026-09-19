<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\AccountingPeriod;
use App\Models\PermohonanBukaPeriode;
use App\Models\User;
use App\Support\Akses;
use App\Support\Audit\Jejak;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * PERMOHONAN BUKA PERIODE — dua tangan untuk membatalkan tutup buku.
 *
 * Sebelum ini periode tertutup masih bisa dijurnal siapa pun selama 30 hari
 * (`PeriodService::GRACE_DAYS`), dan membukanya kembali cukup oleh seorang
 * pemegang level otorisasi tertinggi — sekali klik, tanpa alasan, tanpa jejak.
 *
 * Sekarang: **admin keuangan mengajukan** beserta alasan wajib (hak
 * `buka-periode.buat`), **direktur keuangan memutuskan** (hak
 * `buka-periode.ubah`). Pemohon tak boleh memutuskan permohonannya sendiri —
 * tanpa larangan itu, "dua tangan" hanya jadi dua klik oleh orang yang sama.
 *
 * Seluruh langkahnya meninggalkan jejak beserta alasannya.
 */
class BukaPeriodeService
{
    public const MODUL = 'buka-periode';

    /** Ajukan pembukaan satu bulan, atau pembatalan tutup buku tahunan. */
    public function ajukan(array $data, User $pemohon): PermohonanBukaPeriode
    {
        if (! Akses::boleh(self::MODUL, 'buat')) {
            throw new AppException(403, 'Anda tidak berhak mengajukan pembukaan periode.');
        }

        $alasan = trim((string) ($data['alasan'] ?? ''));
        if ($alasan === '') {
            throw new AppException(422, 'Alasan wajib diisi — inilah yang akan dibaca berbulan-bulan kemudian saat angkanya dipersoalkan.');
        }

        $lingkup = $data['lingkup'] ?? 'bulan';
        $tahun = (int) $data['tahun'];
        $bulan = $lingkup === 'bulan' ? (int) $data['bulan'] : null;

        if ($lingkup === 'bulan') {
            $periode = AccountingPeriod::where('tahun', $tahun)->where('bulan', $bulan)->first();
            if (! $periode || $periode->status !== 'closed') {
                throw new AppException(409, 'Periode itu belum ditutup, jadi tak ada yang perlu dibuka.');
            }
        }

        try {
            $permohonan = PermohonanBukaPeriode::create([
                'lingkup' => $lingkup,
                'tahun' => $tahun,
                'bulan' => $bulan,
                'alasan' => $alasan,
                'status' => 'diajukan',
                'diajukan_oleh' => $pemohon->id_pengguna,
                'diajukan_pada' => now(),
            ]);
        } catch (QueryException) {
            // Indeks unik parsial `permohonan_buka_periode_hidup`.
            throw new AppException(409, 'Sudah ada permohonan yang masih menunggu keputusan untuk periode itu.');
        }

        Jejak::catat('ajukan_buka_periode', [
            'modul' => self::MODUL,
            'ref_jenis' => 'PermohonanBukaPeriode',
            'ref_id' => $permohonan->id,
            'detail' => ['periode' => $permohonan->labelPeriode(), 'alasan' => $alasan],
            'id_pengguna' => $pemohon->id_pengguna,
        ]);

        return $permohonan;
    }

    /** Setujui → periodenya benar-benar dibuka. */
    public function setujui(int $id, User $pemutus, ?string $catatan = null): PermohonanBukaPeriode
    {
        $permohonan = $this->siapDiputus($id, $pemutus);

        return DB::transaction(function () use ($permohonan, $pemutus, $catatan) {
            $service = new PeriodCloseService;

            if ($permohonan->lingkup === 'bulan') {
                $service->bukaBulan($permohonan->tahun, $permohonan->bulan, $pemutus->id_pengguna, lewatPersetujuan: true);
            } else {
                $service->bukaTahun($permohonan->tahun, $pemutus->id_pengguna, lewatPersetujuan: true);
            }

            $permohonan->update([
                'status' => 'disetujui',
                'diputus_oleh' => $pemutus->id_pengguna,
                'diputus_pada' => now(),
                'catatan_keputusan' => $catatan,
            ]);

            Jejak::catat('setujui_buka_periode', [
                'modul' => self::MODUL,
                'ref_jenis' => 'PermohonanBukaPeriode',
                'ref_id' => $permohonan->id,
                'detail' => [
                    'periode' => $permohonan->labelPeriode(),
                    'alasan_pemohon' => $permohonan->alasan,
                    'pemohon' => $permohonan->pemohon?->nama,
                    'catatan_keputusan' => $catatan,
                ],
                'id_pengguna' => $pemutus->id_pengguna,
            ]);

            return $permohonan->refresh();
        });
    }

    public function tolak(int $id, User $pemutus, string $catatan): PermohonanBukaPeriode
    {
        $permohonan = $this->siapDiputus($id, $pemutus);

        if (trim($catatan) === '') {
            throw new AppException(422, 'Alasan penolakan wajib diisi.');
        }

        $permohonan->update([
            'status' => 'ditolak',
            'diputus_oleh' => $pemutus->id_pengguna,
            'diputus_pada' => now(),
            'catatan_keputusan' => $catatan,
        ]);

        Jejak::catat('tolak_buka_periode', [
            'modul' => self::MODUL,
            'ref_jenis' => 'PermohonanBukaPeriode',
            'ref_id' => $permohonan->id,
            'detail' => ['periode' => $permohonan->labelPeriode(), 'catatan_keputusan' => $catatan],
            'id_pengguna' => $pemutus->id_pengguna,
        ]);

        return $permohonan->refresh();
    }

    /** @return Collection<int,PermohonanBukaPeriode> */
    public function daftar(?string $status = null)
    {
        return PermohonanBukaPeriode::with(['pemohon:id_pengguna,nama', 'pemutus:id_pengguna,nama'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();
    }

    private function siapDiputus(int $id, User $pemutus): PermohonanBukaPeriode
    {
        if (! Akses::boleh(self::MODUL, 'ubah')) {
            throw new AppException(403, 'Hanya direktur keuangan yang boleh memutuskan permohonan pembukaan periode.');
        }

        $permohonan = PermohonanBukaPeriode::with('pemohon')->find($id);
        if (! $permohonan) {
            throw new AppException(404, 'Permohonan tidak ditemukan.');
        }
        if ($permohonan->status !== 'diajukan') {
            throw new AppException(409, 'Permohonan ini sudah diputuskan.');
        }

        // Inti dari "dua tangan". Tanpa larangan ini, seorang admin keuangan
        // yang kebetulan juga memegang hak `ubah` bisa mengajukan lalu
        // menyetujui sendiri, dan kontrolnya berhenti jadi formalitas.
        if ($permohonan->diajukan_oleh === $pemutus->id_pengguna) {
            throw new AppException(403, 'Permohonan tidak boleh diputuskan oleh pemohonnya sendiri.');
        }

        return $permohonan;
    }
}
