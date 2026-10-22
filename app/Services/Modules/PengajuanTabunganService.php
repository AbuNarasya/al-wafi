<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PengajuanTarikTabungan;
use App\Models\TabunganSantri;
use App\Models\User;
use App\Services\PortalWali\KabarWali;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Persetujuan pengajuan tarik tabungan dari Portal Wali — oleh KEUANGAN.
 *
 * Disetujui → saldo pindah Tabungan → Dompet Wali lewat DompetService::pindah
 * (jurnal reklasifikasi D Titipan Tabungan / K Titipan Wali, otomatis).
 * Kas tak bergerak; pencairan tunai, bila diminta, tetap lewat Tarik Titipan.
 */
class PengajuanTabunganService
{
    public function setujui(int $id, int $idPengguna): PengajuanTarikTabungan
    {
        $this->assertTimKeuangan($idPengguna);

        $p = DB::transaction(function () use ($id, $idPengguna) {
            $p = PengajuanTarikTabungan::with('santri')->lockForUpdate()->find($id);
            $this->assertMenunggu($p);
            TabunganSantri::where('id_santri', $p->id_santri)->lockForUpdate()->first();
            $saldo = TabunganSantri::where('id_santri', $p->id_santri)->value('saldo') ?? '0';
            if (Money::gt($p->nominal, $saldo)) {
                throw new AppException(422, 'Saldo tabungan kini tak cukup (Rp '.number_format((float) $saldo, 0, ',', '.')
                    .'). Tolak pengajuan ini dengan alasannya.');
            }

            $mutasi = (new DompetService)->pindah([
                'dari' => 'tabungan', 'ke' => 'wali', 'id_wali' => $p->id_wali, 'id_santri' => $p->id_santri,
                'nominal' => (string) $p->nominal, 'tanggal' => now()->toDateString(),
                'keterangan' => "Penarikan tabungan {$p->santri->nama} — {$p->nomor}",
            ], $idPengguna, null, disetujui: true);

            $p->update(['status' => 'disetujui', 'diputus_oleh' => $idPengguna, 'diputus_pada' => now(),
                'id_mutasi' => $mutasi['keluar']->id]);

            return $p;
        });

        $this->kabari($p, \App\Support\Teks::k('Penarikan tabungan disetujui'),
            \App\Support\Teks::k(':nomor: :nominal dari tabungan :nama masuk ke Dompet Wali.', [
                'nomor' => $p->nomor, 'nominal' => 'Rp '.number_format((float) $p->nominal, 0, ',', '.'), 'nama' => $p->santri->nama,
            ]));

        return $p;
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PengajuanTarikTabungan
    {
        $this->assertTimKeuangan($idPengguna);
        $p = PengajuanTarikTabungan::with('santri')->find($id);
        $this->assertMenunggu($p);
        $p->update(['status' => 'ditolak', 'alasan_tolak' => $alasan, 'diputus_oleh' => $idPengguna, 'diputus_pada' => now()]);

        $this->kabari($p, \App\Support\Teks::k('Penarikan tabungan ditolak'), \App\Support\Teks::k(':nomor ditolak: :alasan', ['nomor' => $p->nomor, 'alasan' => $alasan]));

        return $p;
    }

    private function kabari(PengajuanTarikTabungan $p, string|\App\Support\Teks $judul, string|\App\Support\Teks $pesan): void
    {
        KabarWali::kePencatat((object) ['dicatat_oleh' => null, 'dicatat_oleh_wali' => $p->id_wali, 'dicatat_oleh_peran' => $p->peran], [
            'judul' => $judul, 'pesan' => $pesan, 'jenis' => 'tarik_tabungan', 'id_santri' => $p->id_santri,
            'ref_jenis' => 'PengajuanTarikTabungan', 'ref_id' => (string) $p->id,
        ]);
    }

    private function assertMenunggu(?PengajuanTarikTabungan $p): void
    {
        if (! $p) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($p->status !== 'menunggu') {
            throw new AppException(422, "Pengajuan ini sudah berstatus \"{$p->status}\".");
        }
    }

    private function assertTimKeuangan(int $idPengguna): void
    {
        $u = User::find($idPengguna);
        if (! $u || (! $u->tim_keuangan && ! $u->is_admin)) {
            throw new AppException(403, 'Hanya tim keuangan yang bisa memutus pengajuan tarik tabungan.');
        }
    }
}
