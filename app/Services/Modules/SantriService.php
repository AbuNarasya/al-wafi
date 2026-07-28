<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JenisBiaya;
use App\Models\PembayaranSantri;
use App\Models\Pendaftaran;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\Tahap;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Santri/PPSB. Registrasi: buat calon + baris Pendaftaran + tagihan
 * Registrasi otomatis (cash basis — belum berjurnal, diakui saat pembayaran
 * diverifikasi keuangan). Lifecycle penerimaan lewat mesin Tahap.
 *
 * CATATAN: tagihkanUangPangkal & daftarUlang (M5, potongan gelombang) belum
 * dikonversi — ditandai lanjutan.
 */
class SantriService
{
    /** Status yang mengakhiri proses (untuk dedup NISN). */
    private const STATUS_PENGAKHIR = ['tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri', 'keluar'];

    public function get(int $id): Santri
    {
        $row = Santri::with('wali')->find($id);
        if (! $row) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): Santri
    {
        $wali = Wali::find($data['id_wali']);
        if (! $wali) {
            throw new AppException(400, 'Wali tidak ditemukan.');
        }
        if ($wali->status !== 'aktif') {
            throw new AppException(422, "Wali \"{$wali->nama}\" berstatus nonaktif.");
        }
        if (empty($data['tahun_ajaran'])) {
            throw new AppException(422, 'Tahun ajaran wajib dipilih saat mendaftarkan calon santri.');
        }
        $ta = (new TahunAjaranService)->pastikanAktif($data['tahun_ajaran']);
        $this->pastikanJalurMilikTa((string) ($data['jalur'] ?? ''), $ta->kode);
        $this->periksaCalonKembar($data);

        return DB::transaction(function () use ($data) {
            $now = Carbon::now();
            $base = DocNumber::docBase('PSB', $now);
            $last = Santri::where('no_pendaftaran', 'like', $base.'%')->orderByDesc('no_pendaftaran')->value('no_pendaftaran');

            $santri = Santri::create(array_merge($data, [
                'no_pendaftaran' => DocNumber::nextDocNumber($base, $last),
                'status' => 'calon',
            ]));
            Pendaftaran::create(['id_santri' => $santri->id, 'tanggal' => $now->toDateString()]);

            // Tagihan registrasi otomatis dari master (cash basis — belum berjurnal).
            $registrasi = $this->pilihRegistrasi($santri->kode_jenjang, $santri->tahun_ajaran, $santri->jalur);
            $santri->tagihan()->create([
                'kode_jenis' => $registrasi->kode,
                'nominal' => $registrasi->nominal,
                'sisa' => $registrasi->nominal,
                'keterangan' => $registrasi->nama,
            ]);

            return $santri;
        });
    }

    public function update(int $id, array $data): Santri
    {
        $lama = Santri::find($id);
        if (! $lama) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if (! empty($data['id_wali']) && $data['id_wali'] !== $lama->id_wali && ! Wali::find($data['id_wali'])) {
            throw new AppException(400, 'Wali tidak ditemukan.');
        }
        $lama->update($data);

        return $lama;
    }

    /** Tahap 3 — berkas lengkap & sah. */
    public function verifikasiBerkas(int $id): Santri
    {
        return $this->pindahTahap($id, 'terverifikasi', ['verifikasi_ok' => true]);
    }

    /** Tahap 4 — tes santri + wawancara wali. */
    public function seleksi(int $id, array $data): Santri
    {
        return $this->pindahTahap($id, 'diseleksi', [
            'nilai_baca' => $data['nilai_baca'] ?? null,
            'nilai_akademik' => $data['nilai_akademik'] ?? null,
            'wawancara_wali' => $data['wawancara_wali'] ?? null,
            'wawancara_santri' => $data['wawancara_santri'] ?? null,
            'catatan' => $data['catatan'] ?? null,
        ]);
    }

    /** Tahap 5 — pengumuman. */
    public function pengumuman(int $id, array $data): Santri
    {
        return $this->pindahTahap($id, ! empty($data['lulus']) ? 'diterima' : 'tidak_lulus', [
            'catatan' => $data['catatan'] ?? null,
        ]);
    }

    /** Tahap 6 — med check. Tidak lolos MEMBATALKAN penerimaan. */
    public function medcheck(int $id, array $data): Santri
    {
        $lolos = ! empty($data['lolos']);

        return $this->pindahTahap($id, $lolos ? 'lolos_kesehatan' : 'gagal_medcheck', [
            'medcheck_ok' => $lolos,
            'dokumen_lengkap' => $data['dokumen_lengkap'] ?? false,
            'catatan' => $data['catatan'] ?? null,
        ]);
    }

    /**
     * Tagihkan uang pangkal (setelah lulus). PPSB masukkan nominal NORMAL; bila
     * gelombang punya potongan aktif, terbit sebesar SETELAH POTONGAN (bersyarat).
     */
    public function tagihkanUangPangkal(int $id, array $data): TagihanSantri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if (! in_array($santri->status, ['diterima', 'lolos_kesehatan'], true)) {
            throw new AppException(422, 'Uang pangkal hanya bisa ditagihkan setelah calon dinyatakan lulus. Status sekarang "'.Tahap::labelStatus($santri->status).'".');
        }
        $jenis = $this->jenisUangPangkal($santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur);
        if (TagihanSantri::where('id_santri', $id)->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))->exists()) {
            throw new AppException(409, 'Uang pangkal untuk santri ini sudah pernah ditagihkan. Sunting tagihannya, jangan terbitkan yang kedua.');
        }
        $nominalNormal = Money::of($data['nominal']);
        if (Money::lte($nominalNormal, '0')) {
            throw new AppException(422, 'Nominal uang pangkal harus lebih dari nol.');
        }

        $potonganRow = (new PotonganGelombangService)->potonganAktif($santri->gelombang, $santri->kode_jenjang, $santri->tahun_ajaran);
        $potongan = $potonganRow ? Money::of($potonganRow->potongan) : '0';
        if (Money::gte($potongan, $nominalNormal)) {
            throw new AppException(422, "Potongan Gelombang {$santri->gelombang} ({$potongan}) tidak boleh ≥ nominal uang pangkal ({$nominalNormal}).");
        }
        $efektif = Money::sub($nominalNormal, $potongan);

        return DB::transaction(function () use ($id, $data, $jenis, $santri, $nominalNormal, $potongan, $potonganRow, $efektif) {
            $tagihan = TagihanSantri::create([
                'id_santri' => $id, 'kode_jenis' => $jenis->kode, 'nominal' => $efektif, 'sisa' => $efektif,
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => $data['keterangan'] ?? $jenis->nama,
            ]);
            if (Money::gtZero($potongan)) {
                $masaBerlaku = $potonganRow?->masa_berlaku_hari ?? 7;
                PotonganUangPangkal::create([
                    'id_tagihan' => $tagihan->id, 'gelombang' => $santri->gelombang,
                    'nominal_normal' => $nominalNormal, 'potongan' => $potongan, 'syarat_persen' => 50,
                    'tenggat' => Carbon::now()->startOfDay()->addDays($masaBerlaku)->toDateString(), 'status' => 'berlaku',
                ]);
            }

            return $tagihan;
        });
    }

    /**
     * KOREKSI nominal uang pangkal (salah input). Yang dikoreksi adalah nominal
     * NORMAL — sama seperti saat menagihkan; nominal tagihan dihitung ulang
     * setelah potongan, dan sisa disesuaikan dengan yang sudah dibayar.
     *
     * Pagar: hanya sebelum akrual (setelah daftar ulang jurnal sudah terbit →
     * koreksi harus lewat jurnal penyesuaian keuangan), tak boleh ada pembayaran
     * menggantung, dan nominal baru tak boleh di bawah yang sudah dibayar.
     * Rencana angsuran aktif dinonaktifkan agar terminnya disusun ulang.
     */
    public function koreksiNominalUangPangkal(int $id, array $data, int $idPengguna): TagihanSantri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        // Cari lewat TIPE tagihannya, bukan dengan menebak ulang baris master:
        // master bisa berubah (mis. kini ada baris per jenjang) sehingga hasil
        // tebakan tak lagi cocok dengan tagihan yang sudah terbit.
        $tagihan = TagihanSantri::where('id_santri', $id)
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))->first();
        if (! $tagihan) {
            throw new AppException(404, 'Uang pangkal belum ditagihkan untuk santri ini, jadi tidak ada nominal yang bisa dikoreksi.');
        }
        if ($tagihan->sudah_akrual) {
            throw new AppException(422, 'Uang pangkal ini sudah diakrualkan saat daftar ulang (jurnal sudah terbit). Koreksi nominalnya harus lewat jurnal penyesuaian oleh keuangan, bukan dari sini.');
        }
        if ($tagihan->status === 'batal') {
            throw new AppException(422, 'Tagihan uang pangkal ini sudah dibatalkan.');
        }
        $menunggu = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran yang menunggu verifikasi keuangan. Selesaikan dulu agar sisa tagihan tidak dihitung dari angka yang belum pasti.");
        }

        $nominalNormal = Money::of($data['nominal']);
        if (Money::lte($nominalNormal, '0')) {
            throw new AppException(422, 'Nominal uang pangkal harus lebih dari nol.');
        }

        // Potongan gelombang yang melekat pada tagihan ini (bukan master, karena
        // master bisa berubah setelah tagihan terbit). "hangus" = potongan sudah
        // dikembalikan ke tagihan, jadi tak dipotong lagi.
        $potonganRow = PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first();
        $potongan = ($potonganRow && $potonganRow->status !== 'hangus') ? Money::of($potonganRow->potongan) : '0';
        if (Money::gte($potongan, $nominalNormal)) {
            throw new AppException(422, "Potongan gelombang ({$potongan}) tidak boleh ≥ nominal uang pangkal ({$nominalNormal}).");
        }
        $efektif = Money::sub($nominalNormal, $potongan);

        $terbayar = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'terverifikasi')
            ->get(['nominal'])->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0');
        if (Money::lt($efektif, $terbayar)) {
            throw new AppException(422, "Nominal setelah potongan ({$efektif}) lebih kecil dari yang sudah dibayar ({$terbayar}). Kelebihan bayar harus diselesaikan keuangan dulu (pengembalian atau pemindahan ke tagihan lain).");
        }

        $sisaBaru = Money::sub($efektif, $terbayar);
        $statusBaru = Money::lte($sisaBaru, '0') ? 'lunas' : (Money::gtZero($terbayar) ? 'sebagian' : 'belum_bayar');
        $nominalLama = Money::of($tagihan->nominal);

        return DB::transaction(function () use ($tagihan, $data, $idPengguna, $santri, $potonganRow, $nominalNormal, $efektif, $sisaBaru, $statusBaru, $nominalLama) {
            $tagihan->update([
                'nominal' => $efektif,
                'sisa' => $sisaBaru,
                'status' => $statusBaru,
                'jatuh_tempo' => array_key_exists('jatuh_tempo', $data) ? ($data['jatuh_tempo'] ?: null) : $tagihan->jatuh_tempo,
            ]);
            $potonganRow?->update(['nominal_normal' => $nominalNormal]);

            // Σ termin wajib sama dengan nominal tagihan → jadwal lama tak lagi sah.
            $rencana = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->first();
            $rencanaDinonaktifkan = false;
            if ($rencana) {
                $rencana->update([
                    'status' => 'digantikan',
                    'alasan' => trim(($rencana->alasan ? $rencana->alasan.' | ' : '')."Nominal uang pangkal dikoreksi {$nominalLama} → {$efektif}; jadwal termin harus disusun ulang."),
                ]);
                $rencanaDinonaktifkan = true;
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'koreksi_nominal_uang_pangkal',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'no_pendaftaran' => $santri->no_pendaftaran, 'id_tagihan' => $tagihan->id,
                    'nominal_lama' => $nominalLama, 'nominal_baru' => $efektif, 'nominal_normal_baru' => $nominalNormal,
                    'sisa_baru' => $sisaBaru, 'alasan' => $data['alasan'],
                    'rencana_angsuran_dinonaktifkan' => $rencanaDinonaktifkan,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $tagihan->refresh();
        });
    }

    /** TAHAP 7 — daftar ulang: calon → santri aktif + akrual sisa uang pangkal + terbitkan NIS. */
    public function daftarUlang(int $id, int $idPengguna): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        Tahap::assertTransisi($santri->status, 'aktif');

        // Cari lewat TIPE tagihannya (lihat catatan di koreksiNominalUangPangkal).
        $tagihan = TagihanSantri::where('id_santri', $id)
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))->with('jenis')->first();
        if (! $tagihan) {
            throw new AppException(422, 'Uang pangkal belum ditagihkan. Terbitkan tagihannya lebih dulu sebelum daftar ulang.');
        }
        $jenis = $tagihan->jenis;
        if ($tagihan->sudah_akrual) {
            throw new AppException(422, 'Uang pangkal santri ini sudah pernah diakrualkan.');
        }
        $menunggu = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran uang pangkal yang menunggu verifikasi keuangan. Selesaikan dulu agar nilai yang diakrualkan tidak keliru.");
        }
        if (! $jenis->kode_coa_piutang) {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" belum punya akun piutang. Isi dulu di master Jenis Biaya.");
        }
        $sisa = Money::of($tagihan->sisa);

        return DB::transaction(function () use ($id, $idPengguna, $santri, $jenis, $tagihan, $sisa) {
            if (Money::gtZero($sisa)) {
                PostingService::postJournal([
                    'referensi' => $santri->no_pendaftaran, 'tanggal' => Carbon::now()->toDateString(),
                    'kode_unit' => $jenis->kode_unit, 'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $tagihan->id,
                    'id_pengguna' => $idPengguna, 'keterangan' => "Akrual uang pangkal daftar ulang — {$santri->nama}",
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $sisa, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $sisa],
                    ],
                ]);
            }
            $tagihan->update(['sudah_akrual' => true]);
            $santri->update(['status' => 'aktif', 'nis' => $this->terbitkanNis()]);

            return $santri;
        });
    }

    /** Pengunduran diri — boleh kapan saja sebelum proses berakhir. Tidak membalik jurnal. */
    public function mengundurkanDiri(int $id, string $alasan, ?int $idPengguna = null): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        Tahap::assertBolehMundur($santri->status);

        // Santri AKTIF: sisa uang pangkal dihapuskan & akrualnya dibalik.
        if ($santri->status === 'aktif') {
            return $this->keluarkanSantriAktif($santri, $alasan, $idPengguna);
        }

        $santri->update(['status' => 'mengundurkan_diri']);
        Pendaftaran::where('id_santri', $id)->update(['catatan' => "Mengundurkan diri: {$alasan}"]);

        return $santri;
    }

    /**
     * Pengunduran diri santri AKTIF → status "keluar". Sisa uang pangkal yang
     * belum dibayar dibatalkan dan akrualnya dibalik SEBESAR SISA (bukan nominal
     * akrual asli): pembayaran yang sudah masuk sejak daftar ulang telah
     * mengurangi piutang lewat jurnalnya sendiri, jadi membalik nominal asli
     * akan membuat piutang minus dan menghapus pendapatan yang benar diterima.
     * Tagihan lain (SPP dll.) sengaja TIDAK disentuh.
     */
    private function keluarkanSantriAktif(Santri $santri, string $alasan, ?int $idPengguna): Santri
    {
        $tagihan = TagihanSantri::where('id_santri', $santri->id)
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))
            ->with('jenis')->first();

        if ($tagihan && $tagihan->status !== 'batal') {
            $menunggu = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'menunggu_verifikasi')->count();
            if ($menunggu > 0) {
                throw new AppException(422, "Masih ada {$menunggu} pembayaran uang pangkal yang menunggu verifikasi keuangan. Verifikasi atau tolak dulu, agar sisa yang dihapuskan bukan angka yang masih berubah.");
            }
        }

        $sisa = $tagihan ? Money::of($tagihan->sisa) : '0';
        $perluBalik = $tagihan && $tagihan->status !== 'batal' && $tagihan->sudah_akrual && Money::gtZero($sisa);
        if ($perluBalik && ! $tagihan->jenis?->kode_coa_piutang) {
            throw new AppException(422, "Jenis biaya \"{$tagihan->jenis?->nama}\" belum punya akun piutang, sehingga akrualnya tidak bisa dibalik. Lengkapi dulu di master Jenis Biaya.");
        }

        return DB::transaction(function () use ($santri, $alasan, $idPengguna, $tagihan, $sisa, $perluBalik) {
            if ($perluBalik) {
                $jenis = $tagihan->jenis;
                PostingService::postJournal([
                    'referensi' => $santri->nis ?? $santri->no_pendaftaran,
                    'tanggal' => Carbon::now()->toDateString(),
                    'kode_unit' => $jenis->kode_unit,
                    'sumber_modul' => 'PembayaranSantri',
                    'id_sumber' => (string) $tagihan->id,
                    'id_pengguna' => $idPengguna,
                    'keterangan' => "Pembatalan sisa uang pangkal — pengunduran diri {$santri->nama}",
                    // Kebalikan jurnal akrual daftar ulang, sebesar sisa yang masih menggantung.
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => $sisa, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $sisa],
                    ],
                ]);
            }

            if ($tagihan && $tagihan->status !== 'batal') {
                $tagihan->update(['sisa' => '0', 'status' => 'batal']);

                // Jadwal angsuran atas tagihan yang dibatalkan tak lagi berlaku.
                RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')
                    ->update(['status' => 'digantikan', 'alasan' => "Santri mengundurkan diri: {$alasan}"]);
            }

            $santri->update(['status' => 'keluar']);
            Pendaftaran::where('id_santri', $santri->id)->update(['catatan' => "Mengundurkan diri (santri aktif): {$alasan}"]);

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'santri_aktif_mengundurkan_diri',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'nis' => $santri->nis, 'no_pendaftaran' => $santri->no_pendaftaran,
                    'nama' => $santri->nama, 'alasan' => $alasan,
                    'id_tagihan_uang_pangkal' => $tagihan?->id,
                    'sisa_dihapuskan' => $perluBalik ? $sisa : '0',
                    'akrual_dibalik' => $perluBalik,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $santri->refresh();
        });
    }

    public function remove(int $id): void
    {
        $row = Santri::find($id);
        if (! $row) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($row->status !== 'calon') {
            throw new AppException(422, "Santri berstatus \"{$row->status}\" tidak boleh dihapus. Untuk mengakhiri prosesnya, ubah status menjadi \"mengundurkan diri\" agar riwayatnya tetap tercatat.");
        }
        Santri::destroy($id);
    }

    // ---- Helper ----

    private function pindahTahap(int $id, string $ke, array $pendaftaran): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        Tahap::assertTransisi($santri->status, $ke);

        return DB::transaction(function () use ($id, $ke, $pendaftaran, $santri) {
            Pendaftaran::where('id_santri', $id)->update($pendaftaran);
            $santri->update(['status' => $ke]);

            return $santri;
        });
    }

    /**
     * Master uang pangkal aktif untuk (TA, jenjang, jalur) santri — urutan
     * khusus→umum ditentukan JenisBiaya::berlaku(). `nominal`-nya bersifat
     * DEFAULT (mengisi form penagihan, masih bisa diubah petugas).
     */
    public function jenisUangPangkal(?string $tahunAjaran = null, ?string $kodeJenjang = null, ?string $kodeJalur = null): JenisBiaya
    {
        $jenis = JenisBiaya::berlaku('uang_pangkal', $tahunAjaran, $kodeJenjang, $kodeJalur);

        if (! $jenis) {
            throw new AppException(422, 'Belum ada jenis biaya Uang Pangkal yang aktif untuk '
                .$this->labelBerlaku($tahunAjaran, $kodeJenjang, $kodeJalur)
                .'. Buat barisnya lewat menu PPSB → Jenis Biaya (kosongkan Jalur bila tarifnya berlaku untuk semua jalur).');
        }

        return $jenis;
    }

    /** Label "jenjang X jalur Y pada T.A Z" untuk pesan error yang menuntun. */
    private function labelBerlaku(?string $tahunAjaran, ?string $kodeJenjang, ?string $kodeJalur): string
    {
        $bagian = [];
        $bagian[] = $kodeJenjang ? "jenjang \"{$kodeJenjang}\"" : 'tanpa jenjang';
        if ($kodeJalur) {
            $bagian[] = "jalur \"{$kodeJalur}\"";
        }
        $label = implode(' ', $bagian);

        return $tahunAjaran ? "{$label} pada T.A {$tahunAjaran}" : $label;
    }

    /** NIS = <2 digit tahun><4 digit urut>, mis. 260001 (diterbitkan saat daftar ulang). */
    private function terbitkanNis(): string
    {
        $prefix = substr((string) Carbon::now()->year, 2);
        $last = Santri::where('nis', 'like', $prefix.'%')->orderByDesc('nis')->value('nis');
        $urut = 1;
        if ($last) {
            $tail = substr($last, strlen($prefix));
            if (is_numeric($tail)) {
                $urut = ((int) $tail) + 1;
            }
        }

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /** Registrasi yang berlaku untuk (TA, jenjang, jalur) santri — lihat JenisBiaya::berlaku(). */
    private function pilihRegistrasi(?string $kodeJenjang, ?string $tahunAjaran, ?string $kodeJalur = null): JenisBiaya
    {
        $registrasi = JenisBiaya::berlaku('registrasi', $tahunAjaran, $kodeJenjang, $kodeJalur);

        if (! $registrasi || $registrasi->nominal === null) {
            throw new AppException(422, 'Belum ada jenis biaya Registrasi aktif & bernominal untuk '
                .$this->labelBerlaku($tahunAjaran, $kodeJenjang, $kodeJalur)
                .'. Isi dulu di menu PPSB → Jenis Biaya (kosongkan Jalur bila tarifnya berlaku untuk semua jalur).');
        }

        return $registrasi;
    }

    /** Jalur wajib terdaftar, aktif, dan milik TA yang dipilih. */
    private function pastikanJalurMilikTa(string $kodeJalur, string $tahunAjaran): void
    {
        $jalur = \App\Models\JalurPendaftaran::find($kodeJalur);
        if (! $jalur || $jalur->status !== 'aktif') {
            throw new AppException(422, "Jalur pendaftaran \"{$kodeJalur}\" tidak terdaftar / nonaktif.");
        }
        if ($jalur->tahun_ajaran !== $tahunAjaran) {
            throw new AppException(422, "Jalur \"{$jalur->nama}\" berlaku untuk T.A {$jalur->tahun_ajaran}, bukan {$tahunAjaran}. Pilih jalur milik tahun ajaran yang sama.");
        }
    }

    /** Dedup lunak atas NISN (santri yang belum berakhir prosesnya). */
    private function periksaCalonKembar(array $data): void
    {
        if (empty($data['nisn'])) {
            return;
        }
        $kembar = Santri::where('nisn', $data['nisn'])
            ->whereNotIn('status', self::STATUS_PENGAKHIR)->first(['no_pendaftaran', 'nama']);
        if ($kembar) {
            throw new AppException(409, "NISN {$data['nisn']} sudah terdaftar atas nama \"{$kembar->nama}\" ({$kembar->no_pendaftaran}). Bila ini orang yang sama, lanjutkan dari data itu; bila berbeda, kosongkan NISN lalu perbaiki setelah nomornya dipastikan.");
        }
    }
}
