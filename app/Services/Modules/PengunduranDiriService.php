<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DokumenPengajuanKeluar;
use App\Models\HakAksesModul;
use App\Models\PengajuanKeluar;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Services\PortalWali\KabarWali;
use App\Support\Akses;
use App\Support\Audit\Jejak;
use App\Support\Money;
use App\Support\Teks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * PENGUNDURAN DIRI SANTRI AKTIF lewat portal wali (keputusan user 21 Okt 2026).
 *
 *   wali mengajukan + surat → wali kelas (lanjut / kembalikan) → kepala sekolah (Ya / Tidak)
 *   → clearance paralel: keuangan · kesantrian · admin sekolah (+ surat mutasi/SK)
 *   → siap dikeluarkan → admin sekolah mengeluarkan di akhir bulan (+ tanda Dapodik).
 *
 *  • Wali kelas = `kelas.id_wali_kelas` santri itu; kepala sekolah = `jenjang.id_kepala_sekolah`.
 *    Admin selalu boleh (cadangan bila belum ditetapkan).
 *  • SATU-SATUNYA jalan keluar santri aktif (22 Okt 2026): tombol langsung di halaman santri ditutup; staf
 *    boleh mengajukan atas nama wali (ajukanOlehStaf).
 *  • SELURUH tagihan tetap ditagih saat santri aktif keluar — termasuk sisa uang pangkal & perlengkapan
 *    (keputusan user 22 Okt 2026; dulu sisa PPSB dibatalkan). Clearance keuangan baru boleh ✓ bila tak ada
 *    tagihan bersisa & titipan nol — selain itu catatan "disepakati / dibebaskan" WAJIB.
 *  • Pengeluaran memakai SantriService::mengundurkanDiri (lepas kelas, nonaktifkan kartu, tutup jadwal).
 */
class PengunduranDiriService
{
    public const PERILAKU_PPSB = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    public const MODUL_CLEARANCE = ['keuangan' => 'clearance-keuangan', 'kesantrian' => 'clearance-kesantrian', 'admin' => 'clearance-admin'];

    // ---- Wali ----

    public function ajukan(Santri $santri, Wali $wali, string $peran, string $alasan, ?string $rencana, UploadedFile $surat): PengajuanKeluar
    {
        if ((int) $santri->id_wali !== (int) $wali->id) {
            throw new AppException(404, __('Santri tidak ditemukan.'));
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, __('Pengunduran diri lewat portal hanya untuk santri aktif.'));
        }
        if (PengajuanKeluar::where('id_santri', $santri->id)->whereIn('status', PengajuanKeluar::BERJALAN)->exists()) {
            throw new AppException(422, __('Sudah ada pengajuan pengunduran diri yang sedang berjalan untuk anak ini.'));
        }
        if (trim($alasan) === '') {
            throw new AppException(422, __('Alasan wajib diisi.'));
        }

        $p = DB::transaction(function () use ($santri, $wali, $peran, $alasan, $rencana, $surat) {
            $base = DocNumber::docBase('PKD', now());
            $p = PengajuanKeluar::create([
                'nomor' => DocNumber::nextDocNumber($base, PengajuanKeluar::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'id_wali' => $wali->id, 'diajukan_peran' => $peran,
                'alasan' => trim($alasan), 'rencana_tanggal' => $rencana ?: null, 'status' => 'menunggu_wali_kelas',
            ]);
            $this->simpanBerkas($p, 'surat_pengajuan', $surat, null, $wali->id);

            return $p;
        });
        $this->kabariStaf($p, $this->penerimaWaliKelas($p), 'Pengajuan pengunduran diri perlu diverifikasi');

        return $p;
    }

    /**
     * Diajukan STAF atas nama wali (wali datang ke sekolah membawa surat). Alurnya sama persis dengan dari
     * portal — langsung ke wali kelas — dan walinya dikabari supaya tahu ada pengajuan atas anaknya.
     */
    public function ajukanOlehStaf(Santri $santri, User $u, string $alasan, ?string $rencana, UploadedFile $surat): PengajuanKeluar
    {
        if (! Akses::bolehUntuk($u, 'pengunduran-diri', 'buat')) {
            throw new AppException(403, 'Anda tidak berhak mengajukan pengunduran diri.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, 'Alur pengunduran diri hanya untuk santri aktif.');
        }
        if (! $santri->id_wali) {
            throw new AppException(422, 'Santri ini belum punya wali — lengkapi data walinya dulu.');
        }
        if (PengajuanKeluar::where('id_santri', $santri->id)->whereIn('status', PengajuanKeluar::BERJALAN)->exists()) {
            throw new AppException(422, 'Sudah ada pengajuan pengunduran diri yang sedang berjalan untuk santri ini.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan wajib diisi.');
        }

        $p = DB::transaction(function () use ($santri, $u, $alasan, $rencana, $surat) {
            $base = DocNumber::docBase('PKD', now());
            $p = PengajuanKeluar::create([
                'nomor' => DocNumber::nextDocNumber($base, PengajuanKeluar::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'id_wali' => $santri->id_wali, 'diajukan_peran' => 'staf',
                'alasan' => trim($alasan), 'rencana_tanggal' => $rencana ?: null, 'status' => 'menunggu_wali_kelas',
            ]);
            $this->simpanBerkas($p, 'surat_pengajuan', $surat, $u->id_pengguna, null);

            return $p;
        });
        Jejak::catat('pengunduran_diri_diajukan_staf', ['modul' => 'pengunduran-diri', 'ref_jenis' => 'PengajuanKeluar', 'ref_id' => $p->id,
            'id_pengguna' => $u->id_pengguna, 'detail' => ['nomor' => $p->nomor, 'id_santri' => $santri->id]]);
        $this->kabariWali($p, Teks::k('Pengajuan pengunduran diri dicatat sekolah'),
            Teks::k('Pengajuan :nomor dicatat staf atas nama Anda dan diteruskan ke wali kelas.', ['nomor' => $p->nomor]));
        $this->kabariStaf($p, $this->penerimaWaliKelas($p), 'Pengajuan pengunduran diri perlu diverifikasi');

        return $p;
    }

    /** Pengajuan yang dikembalikan wali kelas: diperbaiki lalu dikirim lagi. */
    public function ajukanUlang(PengajuanKeluar $p, string $alasan, ?string $rencana, ?UploadedFile $surat, int $idWali): PengajuanKeluar
    {
        $this->assertStatus($p, ['dikembalikan']);
        DB::transaction(function () use ($p, $alasan, $rencana, $surat, $idWali) {
            $p->update(['alasan' => trim($alasan) ?: $p->alasan, 'rencana_tanggal' => $rencana ?: $p->rencana_tanggal, 'status' => 'menunggu_wali_kelas']);
            if ($surat) {
                $this->simpanBerkas($p, 'surat_pengajuan', $surat, null, $idWali);
            }
        });
        $this->kabariStaf($p, $this->penerimaWaliKelas($p), 'Pengajuan pengunduran diri dikirim ulang');

        return $p;
    }

    public function batalkanOlehWali(PengajuanKeluar $p): void
    {
        $this->assertStatus($p, ['menunggu_wali_kelas', 'dikembalikan', 'menunggu_kepala_sekolah'],
            __('Pengajuan yang sudah disetujui kepala sekolah tidak bisa dibatalkan dari portal — hubungi sekolah.'));
        $p->update(['status' => 'dibatalkan']);
    }

    // ---- Wali kelas & kepala sekolah ----

    public function bolehWaliKelas(User $u, PengajuanKeluar $p): bool
    {
        return $u->is_admin || ($u->id_pengguna && (int) $p->santri?->kelas?->id_wali_kelas === (int) $u->id_pengguna);
    }

    public function bolehKepalaSekolah(User $u, PengajuanKeluar $p): bool
    {
        return $u->is_admin || ($u->id_pengguna && (int) $p->santri?->jenjang?->id_kepala_sekolah === (int) $u->id_pengguna);
    }

    public function verifikasiWaliKelas(PengajuanKeluar $p, User $u, bool $lanjut, ?string $catatan): void
    {
        $this->assertStatus($p, ['menunggu_wali_kelas']);
        if (! $this->bolehWaliKelas($u, $p)) {
            throw new AppException(403, 'Hanya wali kelas santri ini yang bisa memverifikasi.');
        }
        if (! $lanjut && trim((string) $catatan) === '') {
            throw new AppException(422, 'Tulis alasan pengembalian supaya wali tahu yang perlu diperbaiki.');
        }
        $p->update(['status' => $lanjut ? 'menunggu_kepala_sekolah' : 'dikembalikan',
            'wali_kelas_oleh' => $u->id_pengguna, 'wali_kelas_pada' => now(), 'wali_kelas_catatan' => $catatan ?: null]);

        if ($lanjut) {
            $this->kabariWali($p, Teks::k('Pengajuan pengunduran diri diverifikasi wali kelas'), Teks::k('Pengajuan diteruskan ke kepala sekolah.'));
            $this->kabariStaf($p, $this->penerimaKepalaSekolah($p), 'Pengajuan pengunduran diri menunggu persetujuan');
        } else {
            $this->kabariWali($p, Teks::k('Pengajuan pengunduran diri dikembalikan'), Teks::k('Catatan wali kelas: :catatan', ['catatan' => $catatan]));
        }
    }

    public function putuskanKepalaSekolah(PengajuanKeluar $p, User $u, bool $setuju, ?string $catatan): void
    {
        $this->assertStatus($p, ['menunggu_kepala_sekolah']);
        if (! $this->bolehKepalaSekolah($u, $p)) {
            throw new AppException(403, 'Hanya kepala sekolah jenjang ini yang bisa memutuskan.');
        }
        // Pemisahan tugas (audit 22 Okt 2026): yang memverifikasi sebagai wali kelas tak boleh juga memutuskan —
        // terutama admin, yang lolos kedua pemeriksaan. Satu-satunya pengecualian: master data memang menetapkan
        // orang yang sama sebagai wali kelas DAN kepala sekolah (sekolah kecil), selain itu alurnya macet.
        $rangkap = (int) $p->santri?->kelas?->id_wali_kelas === (int) $u->id_pengguna
            && (int) $p->santri?->jenjang?->id_kepala_sekolah === (int) $u->id_pengguna;
        if ($p->wali_kelas_oleh && (int) $p->wali_kelas_oleh === (int) $u->id_pengguna && ! $rangkap) {
            throw new AppException(403, 'Anda sudah memverifikasi pengajuan ini sebagai wali kelas — keputusan kepala sekolah harus oleh orang lain.');
        }
        if (! $setuju && trim((string) $catatan) === '') {
            throw new AppException(422, 'Tulis alasan bila pengajuan tidak disetujui.');
        }
        $p->update(['status' => $setuju ? 'clearance' : 'ditolak',
            'kepala_sekolah_oleh' => $u->id_pengguna, 'kepala_sekolah_pada' => now(), 'kepala_sekolah_catatan' => $catatan ?: null]);

        if ($setuju) {
            $this->kabariWali($p, Teks::k('Pengunduran diri disetujui kepala sekolah'), Teks::k('Sedang diproses clearance (keuangan, kesantrian, administrasi sekolah).'));
            foreach (self::MODUL_CLEARANCE as $modul) {
                $this->kabariStaf($p, $this->pemegangHak($modul), 'Clearance pengunduran diri santri');
            }
        } else {
            $this->kabariWali($p, Teks::k('Pengunduran diri tidak disetujui'), Teks::k('Catatan kepala sekolah: :catatan', ['catatan' => $catatan]));
        }
    }

    // ---- Clearance ----

    /**
     * Keadaan keuangan santri untuk clearance: tagihan yang TETAP tertagih — SELURUHNYA, termasuk sisa PPSB
     * (keputusan user 22 Okt 2026) — dan titipan (dompet + tabungan) yang harus dikembalikan. `dibatalkan`
     * dipertahankan (selalu kosong) supaya layar lama tetap terbaca.
     *
     * @return array{tetap:list<array>, tetap_total:string, dibatalkan:list<array>, dibatalkan_total:string, titipan:array, bersih:bool}
     */
    public function keuangan(Santri $s): array
    {
        $tagihan = TagihanSantri::with('jenis:kode,nama')->where('id_santri', $s->id)
            ->whereNotIn('status', ['lunas', 'batal', 'dihapus'])->where('sisa', '>', 0)->orderBy('jatuh_tempo')->get();
        $baris = fn ($t) => ['nama' => \App\Http\Controllers\PortalKeluargaController::namaTagihan($t), 'sisa' => Money::of($t->sisa)];
        [$ppsb, $tetap] = [collect(), $tagihan];
        $titipan = (new BebasTanggunganService)->periksa($s->id)['titipan'];
        $tetapTotal = Money::of($tetap->sum(fn ($t) => (float) $t->sisa));

        return [
            'tetap' => $tetap->map($baris)->values()->all(), 'tetap_total' => $tetapTotal,
            'dibatalkan' => $ppsb->map($baris)->values()->all(), 'dibatalkan_total' => Money::of($ppsb->sum(fn ($t) => (float) $t->sisa)),
            'titipan' => $titipan,
            'bersih' => Money::isZero($tetapTotal) && Money::isZero($titipan['total']),
        ];
    }

    public function clearance(PengajuanKeluar $p, string $pihak, User $u, ?string $catatan): void
    {
        $this->assertStatus($p, ['clearance']);
        $modul = self::MODUL_CLEARANCE[$pihak] ?? throw new AppException(404, 'Pihak clearance tidak dikenal.');
        if (! Akses::bolehUntuk($u, $modul, 'buat')) {
            throw new AppException(403, 'Anda tidak berhak memberi clearance '.PengajuanKeluar::CLEARANCE[$pihak].'.');
        }
        if ($p->{"{$pihak}_pada"}) {
            throw new AppException(422, 'Clearance bagian ini sudah diberikan.');
        }
        if ($pihak === 'keuangan' && ! $this->keuangan($p->santri)['bersih'] && trim((string) $catatan) === '') {
            throw new AppException(422, 'Masih ada tunggakan atau titipan. Clearance hanya bisa diberikan dengan catatan "disepakati / dibebaskan" yang menjelaskan penyelesaiannya.');
        }
        if ($pihak === 'admin' && ! $p->dokumen()->whereIn('jenis', ['surat_mutasi', 'surat_keputusan'])->exists()) {
            throw new AppException(422, 'Unggah dulu surat mutasi atau surat keputusan sebelum memberi clearance administrasi.');
        }

        DB::transaction(function () use ($p, $pihak, $u, $catatan) {
            $p->update(["{$pihak}_oleh" => $u->id_pengguna, "{$pihak}_pada" => now(), "{$pihak}_catatan" => $catatan ?: null]);
            if ($p->keuangan_pada && $p->kesantrian_pada && $p->admin_pada) {
                $p->update(['status' => 'siap_dikeluarkan']);
            }
        });

        if ($p->status === 'siap_dikeluarkan') {
            $this->kabariWali($p, Teks::k('Clearance pengunduran diri selesai'), Teks::k('Santri akan dikeluarkan pada proses akhir bulan.'));
            $this->kabariStaf($p, $this->pemegangHak('clearance-admin'), 'Santri siap dikeluarkan (akhir bulan)');
        }
    }

    public function unggahDokumen(PengajuanKeluar $p, string $jenis, UploadedFile $berkas, ?string $keterangan, int $idPengguna): DokumenPengajuanKeluar
    {
        $this->assertStatus($p, ['clearance', 'siap_dikeluarkan', 'selesai']);
        if (! array_key_exists($jenis, DokumenPengajuanKeluar::JENIS) || $jenis === 'surat_pengajuan') {
            throw new AppException(422, 'Jenis dokumen tidak dikenal.');
        }

        return $this->simpanBerkas($p, $jenis, $berkas, $idPengguna, null, $keterangan);
    }

    // ---- Pengeluaran (admin sekolah, akhir bulan) ----

    public function keluarkan(PengajuanKeluar $p, User $u): void
    {
        $this->assertStatus($p, ['siap_dikeluarkan']);
        if (! Akses::bolehUntuk($u, 'clearance-admin', 'ubah')) {
            throw new AppException(403, 'Hanya admin sekolah yang bisa mengeluarkan santri.');
        }
        $titipan = $this->keuangan($p->santri)['titipan'];
        // Titipan yang masih ada hanya lolos bila clearance keuangan memberinya catatan (sudah disepakati).
        $abaikan = Money::gtZero($titipan['total']) && filled($p->keuangan_catatan);
        $alasan = "Pengunduran diri {$p->nomor}: {$p->alasan}".($abaikan ? " — titipan: {$p->keuangan_catatan}" : '');

        DB::transaction(function () use ($p, $u, $alasan, $abaikan) {
            (new SantriService)->mengundurkanDiri($p->id_santri, $alasan, $u->id_pengguna, $abaikan);
            $p->update(['status' => 'selesai', 'dikeluarkan_oleh' => $u->id_pengguna, 'dikeluarkan_pada' => now()]);
        });
        Jejak::catat('santri_keluar_pengajuan', ['modul' => 'pengunduran-diri', 'ref_jenis' => 'PengajuanKeluar', 'ref_id' => $p->id,
            'id_pengguna' => $u->id_pengguna, 'detail' => ['nomor' => $p->nomor, 'id_santri' => $p->id_santri]]);
        $this->kabariWali($p, Teks::k('Santri resmi dikeluarkan'), Teks::k('Proses pengunduran diri selesai. Surat mutasi / SK dapat diunduh di portal.'));
    }

    public function tandaiDapodik(PengajuanKeluar $p, User $u): void
    {
        $this->assertStatus($p, ['selesai']);
        $p->update(['dapodik_oleh' => $u->id_pengguna, 'dapodik_pada' => now()]);
    }

    // ---- Pembantu ----

    private function assertStatus(PengajuanKeluar $p, array $boleh, ?string $pesan = null): void
    {
        if (! in_array($p->status, $boleh, true)) {
            throw new AppException(422, $pesan ?? __('Pengajuan ini berstatus ":status" — tindakan ini tidak berlaku.', ['status' => __($p->labelStatus())]));
        }
    }

    private function simpanBerkas(PengajuanKeluar $p, string $jenis, UploadedFile $f, ?int $idPengguna, ?int $idWali, ?string $ket = null): DokumenPengajuanKeluar
    {
        $path = $f->store("pengunduran-diri/{$p->id}", 'local');

        return DokumenPengajuanKeluar::create([
            'id_pengajuan' => $p->id, 'jenis' => $jenis, 'keterangan' => $ket, 'nama_asli' => mb_substr($f->getClientOriginalName(), 0, 250),
            'path' => $path, 'mime' => (string) $f->getMimeType(), 'ukuran' => (int) $f->getSize(),
            'diunggah_oleh' => $idPengguna, 'diunggah_wali' => $idWali,
        ]);
    }

    /** @return list<int> */
    private function penerimaWaliKelas(PengajuanKeluar $p): array
    {
        $id = $p->santri?->kelas?->id_wali_kelas;

        return $id ? [(int) $id] : $this->adminAktif();
    }

    /** @return list<int> */
    private function penerimaKepalaSekolah(PengajuanKeluar $p): array
    {
        $id = $p->santri?->jenjang?->id_kepala_sekolah;

        return $id ? [(int) $id] : $this->adminAktif();
    }

    /** @return list<int> */
    private function pemegangHak(string $modul): array
    {
        $ids = HakAksesModul::where('kode_modul', $modul)->where('buat', true)->pluck('id_pengguna')->all();

        return array_values(array_unique(array_merge(User::whereIn('id_pengguna', $ids)->where('status', 'aktif')->pluck('id_pengguna')->all(), $this->adminAktif())));
    }

    /** @return list<int> */
    private function adminAktif(): array
    {
        return User::where('is_admin', true)->where('status', 'aktif')->pluck('id_pengguna')->all();
    }

    /** @param list<int> $penerima */
    private function kabariStaf(PengajuanKeluar $p, array $penerima, string $judul): void
    {
        $p->loadMissing('santri:id,nama');
        (new NotificationService)->kirim(array_map(fn ($id) => [
            'id_pengguna' => $id, 'judul' => $judul, 'pesan' => "{$p->nomor} — {$p->santri?->nama}: {$p->labelStatus()}.",
            'jenis' => 'pengunduran_diri', 'ref_jenis' => 'PengajuanKeluar', 'ref_id' => (string) $p->id,
        ], $penerima));
    }

    private function kabariWali(PengajuanKeluar $p, string|Teks $judul, string|Teks $pesan): void
    {
        $p->loadMissing(['wali', 'santri:id,nama']);
        KabarWali::keKeluarga($p->wali, ['judul' => $judul,
            'pesan' => Teks::k(':nama (:nomor). :pesan', ['nama' => $p->santri?->nama, 'nomor' => $p->nomor, 'pesan' => $pesan]),
            'jenis' => 'pengunduran_diri', 'ref_jenis' => 'PengajuanKeluar', 'ref_id' => (string) $p->id, 'id_santri' => $p->id_santri]);
    }
}
