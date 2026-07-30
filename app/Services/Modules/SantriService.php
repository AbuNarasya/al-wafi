<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\PembayaranSantri;
use App\Models\Pendaftaran;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\RiwayatTingkat;
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
        $this->pastikanJalurSah(
            (string) ($data['jalur'] ?? ''),
            (string) $data['tahun_ajaran'],
            ($data['kode_jenjang'] ?? null) ?: null,
        );
        // Kewajiban mengisi tingkat ditegakkan di FORM pendaftaran (PPSB);
        // di sini yang dijaga kesahihannya — supaya jalur lain (impor santri
        // lama yang memang belum bertingkat) tidak ikut tertolak.
        if (($data['tingkat'] ?? '') !== '' && $data['tingkat'] !== null) {
            $this->pastikanTingkatSah((string) ($data['kode_jenjang'] ?? ''), $data['tingkat']);
        }
        $this->periksaCalonKembar($data);

        return DB::transaction(function () use ($data) {
            $now = Carbon::now();
            $base = DocNumber::docBase('PSB', $now);
            $last = Santri::where('no_pendaftaran', 'like', $base.'%')->orderByDesc('no_pendaftaran')->value('no_pendaftaran');

            $santri = Santri::create(array_merge($data, [
                'no_pendaftaran' => DocNumber::nextDocNumber($base, $last),
                'status' => 'calon',
            ]));
            // Siklus pendaftaran PERTAMA santri ini. Sasaran & tahapannya dicatat
            // di sini, bukan hanya di santri, karena kenaikan jenjang nanti
            // menambah siklus berikutnya (lihat PendaftaranLanjutanService).
            Pendaftaran::create([
                'id_santri' => $santri->id, 'tanggal' => $now->toDateString(),
                'tahun_ajaran' => $santri->tahun_ajaran, 'kode_jenjang' => $santri->kode_jenjang,
                'kode_jalur' => $santri->jalur, 'jenis' => 'baru', 'status' => 'calon',
                'nomor' => $santri->no_pendaftaran,
            ]);

            // Tagihan registrasi otomatis (cash basis — belum berjurnal).
            $registrasi = $this->pilihRegistrasi($santri->kode_jenjang, $santri->tahun_ajaran, $santri->jalur);
            if ($registrasi) {
                $santri->tagihan()->create([
                    'kode_jenis' => $registrasi['jenis']->kode,
                    'perilaku' => 'registrasi',
                    'kode_jenjang' => $santri->kode_jenjang,
                    'tahun_ajaran' => $santri->tahun_ajaran,
                    'nominal' => $registrasi['nominal'],
                    'sisa' => $registrasi['nominal'],
                    'keterangan' => $registrasi['jenis']->nama,
                ]);
            }

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
        // Tingkat diperiksa terhadap jenjang yang AKAN berlaku, bukan yang lama:
        // keduanya bisa berubah dalam satu kiriman (mis. naik ke SMP tingkat 1).
        if (array_key_exists('tingkat', $data) && $data['tingkat'] !== null && $data['tingkat'] !== '') {
            $this->pastikanTingkatSah((string) ($data['kode_jenjang'] ?? $lama->kode_jenjang), $data['tingkat']);
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
     *
     * BIAYA PERLENGKAPAN terbit BERSAMAAN di sini, tetapi sebagai tagihan
     * TERSENDIRI — bukan dilebur ke nominal uang pangkal. Alasannya tiga:
     *  1. satu baris tagihan hanya punya satu jenis biaya, jadi satu pasang akun;
     *     dilebur berarti pendapatan perlengkapan masuk ke akun uang pangkal dan
     *     tak bisa dipisahkan lagi di laba rugi.
     *  2. potongan gelombang TIDAK memotong perlengkapan. Syarat "50% dibayar
     *     sebelum tenggat" dihitung dari `tagihan.nominal`; kalau nominalnya
     *     gabungan, ambangnya ikut membengkak dan aturan potongannya berubah
     *     diam-diam.
     *  3. jadwal termin melekat pada tagihan (`rencana_angsuran.id_tagihan`),
     *     sehingga dua tagihan = dua jadwal tanpa mengubah skema apa pun.
     *
     * Perlengkapan boleh dikosongkan: nominal kosong/nol → tagihannya tidak
     * terbit sama sekali.
     *
     * JALUR BEBAS UANG PANGKAL (mis. Anak Karyawan): tagihan uang pangkalnya
     * TIDAK diterbitkan sama sekali — bukan diterbitkan bernominal nol, yang
     * hanya akan jadi baris kosong di rekap dan tetap ditolak penjaga nominal.
     * Perlengkapannya tetap ditagih seperti biasa.
     *
     * @return array{uang_pangkal:?TagihanSantri,perlengkapan:?TagihanSantri}
     */
    public function tagihkanUangPangkal(int $id, array $data): array
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        // "aktif" ikut diterima: santri yang naik jenjang ditagih uang pangkal
        // lagi dengan tarif jenjang & T.A barunya. Penjaga ini ada untuk
        // menghalangi penagihan SEBELUM calon dinyatakan lulus, bukan sesudahnya.
        if (! in_array($santri->status, ['diterima', 'lolos_kesehatan', 'aktif'], true)) {
            throw new AppException(422, 'Uang pangkal hanya bisa ditagihkan setelah calon dinyatakan lulus. Status sekarang "'.Tahap::labelStatus($santri->status).'".');
        }

        // Penerbitan massal kadang hanya perlu salah satu komponen (mis. uang
        // pangkalnya sudah terbit, tinggal perlengkapannya). Bawaannya keduanya.
        $diminta = $data['komponen'] ?? ['uang_pangkal', 'perlengkapan'];
        $mintaUp = in_array('uang_pangkal', $diminta, true);

        // TAHUN AJARAN TAGIHAN, bukan angkatan. `santri.tahun_ajaran` adalah tahun
        // MASUK dan tak pernah maju, jadi santri yang naik jenjang harus bisa
        // ditagih atas nama tahun ajaran tujuan. Tanpa penimpaan ini, tagihan
        // kenaikan akan tercap tahun angkatan dan ditolak indeks unik.
        $taTagihan = ($data['tahun_ajaran'] ?? null) ?: $santri->tahun_ajaran;

        $jenis = null;
        $bebas = true; // "tak menerbitkan uang pangkal" — jalannya sama dengan bebas
        if ($mintaUp) {
            ['jenis' => $jenis, 'tarif' => $tarif] = $this->komponen('uang_pangkal', $taTagihan, $santri->kode_jenjang, $santri->jalur);
            $bebas = $tarif['status'] === 'bebas';
            if (! $bebas && $tarif['status'] !== 'ada') {
                throw new AppException(422, $tarif['label'].' Isi selnya di menu Setting Awal → Tarif, '
                    .'atau tandai Bebas bila jalur ini memang tidak ditagih uang pangkal.');
            }
            if ($bebas) {
                $jenis = null;
            }
            // Penjaga per (jenjang, T.A) — BUKAN per santri. Satu santri memang
            // ditagih uang pangkal lagi setiap kali naik jenjang; yang dilarang
            // adalah dua tagihan untuk jenjang & tahun ajaran yang sama.
            if (! $bebas && $this->tagihanBerperilaku($id, 'uang_pangkal', $santri->kode_jenjang, $taTagihan)) {
                throw new AppException(409, "Uang pangkal {$santri->kode_jenjang} T.A {$taTagihan} untuk santri ini sudah pernah ditagihkan. Sunting tagihannya, jangan terbitkan yang kedua.");
            }
        }
        $nominalNormal = $bebas ? '0' : Money::of($data['nominal']);
        if (! $bebas && Money::lte($nominalNormal, '0')) {
            throw new AppException(422, 'Nominal uang pangkal harus lebih dari nol.');
        }

        // Perlengkapan: jenis biayanya baru dicari bila nominalnya benar-benar
        // diisi, supaya pesantren yang tak memungut perlengkapan tidak dipaksa
        // membuat barisnya di master.
        $nominalPerlengkapan = Money::of($data['nominal_perlengkapan'] ?? '0');
        $jenisPerlengkapan = null;
        if (Money::gtZero($nominalPerlengkapan)) {
            if ($this->tagihanBerperilaku($id, 'perlengkapan', $santri->kode_jenjang, $taTagihan)) {
                throw new AppException(409, "Biaya perlengkapan {$santri->kode_jenjang} T.A {$taTagihan} untuk santri ini sudah pernah ditagihkan. Sunting tagihannya, jangan terbitkan yang kedua.");
            }
            $komponenPerlengkapan = $this->komponen('perlengkapan', $taTagihan, $santri->kode_jenjang, $santri->jalur);
            if ($komponenPerlengkapan['tarif']['status'] === 'bebas') {
                throw new AppException(422, 'Tarif perlengkapan untuk jalur ini bertanda BEBAS, jadi tagihannya memang tidak diterbitkan. '
                    .'Kosongkan isian nominal perlengkapan, atau ubah tandanya di menu Setting Awal → Tarif.');
            }
            $jenisPerlengkapan = $komponenPerlengkapan['jenis'];
        }

        // Jalur bebas: tak ada nominal, jadi tak ada pula potongan yang dihitung.
        $potonganRow = $bebas ? null
            : (new PotonganGelombangService)->potonganAktif($santri->gelombang, $santri->kode_jenjang, $taTagihan);
        $potongan = $potonganRow ? Money::of($potonganRow->potongan) : '0';
        if (Money::gte($potongan, $nominalNormal) && ! $bebas) {
            throw new AppException(422, "Potongan Gelombang {$santri->gelombang} ({$potongan}) tidak boleh ≥ nominal uang pangkal ({$nominalNormal}).");
        }
        $efektif = Money::sub($nominalNormal, $potongan);

        return DB::transaction(function () use ($id, $data, $jenis, $santri, $taTagihan, $nominalNormal, $potongan, $potonganRow, $efektif, $jenisPerlengkapan, $nominalPerlengkapan) {
            $tagihan = $jenis === null ? null : TagihanSantri::create([
                'id_santri' => $id, 'kode_jenis' => $jenis->kode,
                'perilaku' => 'uang_pangkal', 'kode_jenjang' => $santri->kode_jenjang, 'tahun_ajaran' => $taTagihan,
                'nominal' => $efektif, 'sisa' => $efektif,
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => $data['keterangan'] ?? $jenis->nama,
            ]);
            if ($tagihan && Money::gtZero($potongan)) {
                $masaBerlaku = $potonganRow?->masa_berlaku_hari ?? 7;
                PotonganUangPangkal::create([
                    'id_tagihan' => $tagihan->id, 'gelombang' => $santri->gelombang,
                    'nominal_normal' => $nominalNormal, 'potongan' => $potongan, 'syarat_persen' => 50,
                    'tenggat' => Carbon::now()->startOfDay()->addDays($masaBerlaku)->toDateString(), 'status' => 'berlaku',
                ]);
            }

            // Terbit UTUH — tak ada baris potongan yang dilekatkan padanya.
            $perlengkapan = $jenisPerlengkapan ? TagihanSantri::create([
                'id_santri' => $id, 'kode_jenis' => $jenisPerlengkapan->kode,
                'perilaku' => 'perlengkapan', 'kode_jenjang' => $santri->kode_jenjang, 'tahun_ajaran' => $taTagihan,
                'nominal' => $nominalPerlengkapan, 'sisa' => $nominalPerlengkapan,
                'jatuh_tempo' => $data['jatuh_tempo_perlengkapan'] ?? $data['jatuh_tempo'] ?? null,
                'keterangan' => $data['keterangan_perlengkapan'] ?? $jenisPerlengkapan->nama,
            ]) : null;

            return ['uang_pangkal' => $tagihan, 'perlengkapan' => $perlengkapan];
        });
    }

    /** Santri ini sudah punya tagihan berperilaku tertentu untuk (jenjang, T.A) itu? */
    private function tagihanBerperilaku(int $idSantri, string $perilaku, ?string $kodeJenjang = null, ?string $tahunAjaran = null): bool
    {
        return $this->tagihanPerilaku($idSantri, $perilaku, $kodeJenjang, $tahunAjaran) !== null;
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
        // Cari lewat PERILAKU yang tercatat di tagihannya, bukan dengan menebak
        // ulang baris master: master bisa berubah sehingga hasil tebakan tak lagi
        // cocok dengan tagihan yang sudah terbit. Tagihan batal ikut diambil agar
        // pesan penolakannya tetap menyebut sebab yang benar.
        $tagihan = $this->tagihanPerilaku($id, 'uang_pangkal', null, null, true);
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

    /**
     * KOREKSI nominal biaya perlengkapan (salah input). Lebih sederhana daripada
     * uang pangkal: tak ada potongan gelombang yang perlu dihitung ulang, jadi
     * nominal yang diketik = nominal tagihan.
     *
     * Pagarnya sama: hanya sebelum akrual, tak boleh ada pembayaran menggantung,
     * dan nominal baru tak boleh di bawah yang sudah dibayar. Rencana angsuran
     * perlengkapan yang aktif dinonaktifkan agar terminnya disusun ulang.
     */
    public function koreksiNominalPerlengkapan(int $id, array $data, int $idPengguna): TagihanSantri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        $tagihan = $this->tagihanPerilaku($id, 'perlengkapan', null, null, true);
        if (! $tagihan) {
            throw new AppException(404, 'Biaya perlengkapan belum ditagihkan untuk santri ini, jadi tidak ada nominal yang bisa dikoreksi.');
        }
        if ($tagihan->sudah_akrual) {
            throw new AppException(422, 'Biaya perlengkapan ini sudah diakrualkan saat daftar ulang (jurnal sudah terbit). Koreksi nominalnya harus lewat jurnal penyesuaian oleh keuangan, bukan dari sini.');
        }
        if ($tagihan->status === 'batal') {
            throw new AppException(422, 'Tagihan biaya perlengkapan ini sudah dibatalkan.');
        }
        $menunggu = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran yang menunggu verifikasi keuangan. Selesaikan dulu agar sisa tagihan tidak dihitung dari angka yang belum pasti.");
        }

        $nominalBaru = Money::of($data['nominal']);
        if (Money::lte($nominalBaru, '0')) {
            throw new AppException(422, 'Nominal biaya perlengkapan harus lebih dari nol. Untuk meniadakannya, batalkan tagihannya.');
        }

        $terbayar = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'terverifikasi')
            ->get(['nominal'])->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0');
        if (Money::lt($nominalBaru, $terbayar)) {
            throw new AppException(422, "Nominal baru ({$nominalBaru}) lebih kecil dari yang sudah dibayar ({$terbayar}). Kelebihan bayar harus diselesaikan keuangan dulu (pengembalian atau pemindahan ke tagihan lain).");
        }

        $sisaBaru = Money::sub($nominalBaru, $terbayar);
        $statusBaru = Money::lte($sisaBaru, '0') ? 'lunas' : (Money::gtZero($terbayar) ? 'sebagian' : 'belum_bayar');
        $nominalLama = Money::of($tagihan->nominal);

        return DB::transaction(function () use ($tagihan, $data, $idPengguna, $santri, $nominalBaru, $sisaBaru, $statusBaru, $nominalLama) {
            $tagihan->update([
                'nominal' => $nominalBaru,
                'sisa' => $sisaBaru,
                'status' => $statusBaru,
                'jatuh_tempo' => array_key_exists('jatuh_tempo', $data) ? ($data['jatuh_tempo'] ?: null) : $tagihan->jatuh_tempo,
            ]);

            // Σ termin wajib sama dengan nominal tagihan → jadwal lama tak lagi sah.
            $rencana = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->first();
            $rencanaDinonaktifkan = false;
            if ($rencana) {
                $rencana->update([
                    'status' => 'digantikan',
                    'alasan' => trim(($rencana->alasan ? $rencana->alasan.' | ' : '')."Nominal biaya perlengkapan dikoreksi {$nominalLama} → {$nominalBaru}; jadwal termin harus disusun ulang."),
                ]);
                $rencanaDinonaktifkan = true;
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'koreksi_nominal_perlengkapan',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'no_pendaftaran' => $santri->no_pendaftaran, 'id_tagihan' => $tagihan->id,
                    'nominal_lama' => $nominalLama, 'nominal_baru' => $nominalBaru,
                    'sisa_baru' => $sisaBaru, 'alasan' => $data['alasan'],
                    'rencana_angsuran_dinonaktifkan' => $rencanaDinonaktifkan,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $tagihan->refresh();
        });
    }

    /**
     * TAHAP 7 — daftar ulang: calon → santri aktif + akrual sisa uang pangkal
     * (beserta biaya perlengkapan, bila ada) + terbitkan NIS.
     *
     * Uang pangkal & perlengkapan diakrualkan lewat DUA JURNAL TERPISAH, bukan
     * satu jurnal berisi empat baris: unit bisnis dibawa di kepala dokumen dan
     * disalin ke setiap barisnya, jadi kalau unit kedua jenis biaya itu berbeda,
     * satu jurnal gabungan akan menempelkan unit yang salah pada separuh
     * barisnya — dan laba rugi per unit ikut keliru.
     */
    public function daftarUlang(int $id, int $idPengguna): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        Tahap::assertTransisi($santri->status, 'aktif');

        // Cari lewat TIPE tagihannya (lihat catatan di koreksiNominalUangPangkal).
        $tagihan = $this->tagihanPerilaku($id, 'uang_pangkal');
        if (! $tagihan) {
            // Santri yang tarif uang pangkalnya bertanda BEBAS memang tak punya
            // tagihan itu — menuntutnya akan mengunci mereka selamanya di
            // "lolos kesehatan". Sel yang BELUM DIISI tetap dianggap kurang
            // lengkap, bukan bebas: itu beda keadaan.
            $tarif = (new TarifService)->cari('uang_pangkal', $santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur);
            if ($tarif['status'] !== 'bebas') {
                throw new AppException(422, 'Uang pangkal belum ditagihkan. Terbitkan tagihannya lebih dulu sebelum daftar ulang.');
            }
        } elseif ($tagihan->sudah_akrual) {
            throw new AppException(422, 'Uang pangkal santri ini sudah pernah diakrualkan.');
        }

        // Perlengkapan ikut bila ada, belum diakrualkan, dan tidak dibatalkan.
        $perlengkapan = $this->tagihanPerilaku($id, 'perlengkapan');
        if ($perlengkapan && ($perlengkapan->sudah_akrual || $perlengkapan->status === 'batal')) {
            $perlengkapan = null;
        }

        $akrual = [];
        foreach (array_filter([$tagihan, $perlengkapan]) as $t) {
            $label = $t->jenis->nama;
            $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->count();
            if ($menunggu > 0) {
                throw new AppException(422, "Masih ada {$menunggu} pembayaran \"{$label}\" yang menunggu verifikasi keuangan. Selesaikan dulu agar nilai yang diakrualkan tidak keliru.");
            }
            if (! $t->jenis->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$label}\" belum punya akun piutang. Isi dulu di master Jenis Biaya.");
            }
            $akrual[] = $t;
        }

        return DB::transaction(function () use ($idPengguna, $santri, $akrual) {
            foreach ($akrual as $t) {
                $jenis = $t->jenis;
                $sisa = Money::of($t->sisa);
                if (Money::gtZero($sisa)) {
                    PostingService::postJournal([
                        'referensi' => $santri->no_pendaftaran, 'tanggal' => Carbon::now()->toDateString(),
                        'kode_unit' => $jenis->kode_unit, 'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $t->id,
                        'id_pengguna' => $idPengguna, 'keterangan' => 'Akrual '.$this->labelKomponen($t)." daftar ulang — {$santri->nama}",
                        'lines' => [
                            ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $sisa, 'kredit' => '0'],
                            ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $sisa],
                        ],
                    ]);
                }
                $t->update(['sudah_akrual' => true]);
            }
            $santri->update([
                'status' => 'aktif',
                'nis' => $this->terbitkanNis(),
                // Tahun yang MULAI dijalani. Angkatan (`tahun_ajaran`) tak disentuh.
                'tahun_ajaran_berjalan' => $santri->tahun_ajaran,
            ]);
            Pendaftaran::where('id_santri', $santri->id)->orderByDesc('id')->first()?->update(['status' => 'aktif']);

            // Baris pertama riwayat tingkatnya — supaya tahun yang sedang berjalan
            // pun tercatat, bukan hanya tahun-tahun sesudah kenaikan.
            if ($santri->kode_jenjang && $santri->tahun_ajaran) {
                RiwayatTingkat::updateOrCreate(
                    ['id_santri' => $santri->id, 'tahun_ajaran' => $santri->tahun_ajaran],
                    ['kode_jenjang' => $santri->kode_jenjang, 'tingkat' => $santri->tingkat,
                        'catatan' => 'Masuk lewat daftar ulang PPSB.'],
                );
            }

            return $santri;
        });
    }

    /**
     * Label komponen untuk KETERANGAN JURNAL. Sengaja diturunkan dari perilaku,
     * bukan dari nama baris master: nama master boleh diganti kapan saja, dan
     * keterangan jurnal yang berubah-ubah membuat penelusuran sulit.
     */
    private const LABEL_KOMPONEN = [
        'uang_pangkal' => 'uang pangkal',
        'perlengkapan' => 'biaya perlengkapan',
    ];

    private function labelKomponen(TagihanSantri $tagihan): string
    {
        $perilaku = (string) \App\Models\TipeBiaya::perilakuDari($tagihan->jenis?->tipe);

        return self::LABEL_KOMPONEN[$perilaku] ?? ($tagihan->jenis?->nama ?? 'tagihan');
    }

    /**
     * Tagihan santri berperilaku tertentu, lengkap dengan jenisnya.
     *
     * Membaca kolom `perilaku` di tagihan — bukan lagi menelusuri tipe jenis
     * biayanya. Kolom itu SNAPSHOT saat tagihan terbit: kalau perilaku sebuah
     * tipe diubah di master belakangan, tagihan yang sudah terbit tetap
     * diperlakukan menurut aturan yang berlaku ketika ia lahir.
     *
     * Tanpa (jenjang, T.A) yang dikembalikan adalah tagihan TERBARU — sejak naik
     * jenjang menagih uang pangkal lagi, satu santri bisa punya lebih dari satu.
     */
    private function tagihanPerilaku(int $idSantri, string $perilaku, ?string $kodeJenjang = null, ?string $tahunAjaran = null, bool $termasukBatal = false): ?TagihanSantri
    {
        return TagihanSantri::where('id_santri', $idSantri)
            ->where('perilaku', $perilaku)
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang))
            ->when($tahunAjaran, fn ($q) => $q->where('tahun_ajaran', $tahunAjaran))
            // Tagihan batal sengaja diabaikan: setelah dibatalkan, santrinya
            // memang boleh ditagih ulang (indeks unik anti tagih-ganda juga
            // mengecualikan status batal).
            ->when(! $termasukBatal, fn ($q) => $q->where('status', '!=', 'batal'))
            ->with('jenis')->orderByDesc('id')->first();
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
     * Pengunduran diri santri AKTIF → status "keluar". Sisa uang pangkal DAN
     * biaya perlengkapan yang belum dibayar dibatalkan, akrualnya dibalik
     * SEBESAR SISA (bukan nominal akrual asli): pembayaran yang sudah masuk
     * sejak daftar ulang telah mengurangi piutang lewat jurnalnya sendiri, jadi
     * membalik nominal asli akan membuat piutang minus dan menghapus pendapatan
     * yang benar diterima. Tagihan lain (SPP dll.) sengaja TIDAK disentuh.
     *
     * Sama seperti akrualnya, pembalikan keduanya berupa dua jurnal terpisah
     * agar masing-masing memakai unit bisnisnya sendiri.
     */
    private function keluarkanSantriAktif(Santri $santri, string $alasan, ?int $idPengguna): Santri
    {
        $tagihan = $this->tagihanPerilaku($santri->id, 'uang_pangkal');
        $perlengkapan = $this->tagihanPerilaku($santri->id, 'perlengkapan');

        /** @var list<array{tagihan:TagihanSantri,sisa:string,balik:bool}> */
        $garap = [];
        $sisaTotal = '0';
        foreach (array_filter([$tagihan, $perlengkapan]) as $t) {
            if ($t->status === 'batal') {
                continue;
            }
            $label = $t->jenis?->nama ?? 'tagihan';
            $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->count();
            if ($menunggu > 0) {
                throw new AppException(422, "Masih ada {$menunggu} pembayaran \"{$label}\" yang menunggu verifikasi keuangan. Verifikasi atau tolak dulu, agar sisa yang dihapuskan bukan angka yang masih berubah.");
            }

            $sisa = Money::of($t->sisa);
            $balik = $t->sudah_akrual && Money::gtZero($sisa);
            if ($balik && ! $t->jenis?->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$label}\" belum punya akun piutang, sehingga akrualnya tidak bisa dibalik. Lengkapi dulu di master Jenis Biaya.");
            }
            $garap[] = ['tagihan' => $t, 'sisa' => $sisa, 'balik' => $balik];
            if ($balik) {
                $sisaTotal = Money::add($sisaTotal, $sisa);
            }
        }
        $adaBalik = collect($garap)->contains('balik', true);

        return DB::transaction(function () use ($santri, $alasan, $idPengguna, $tagihan, $perlengkapan, $garap, $sisaTotal, $adaBalik) {
            foreach ($garap as $g) {
                $t = $g['tagihan'];
                if ($g['balik']) {
                    $jenis = $t->jenis;
                    PostingService::postJournal([
                        'referensi' => $santri->nis ?? $santri->no_pendaftaran,
                        'tanggal' => Carbon::now()->toDateString(),
                        'kode_unit' => $jenis->kode_unit,
                        'sumber_modul' => 'PembayaranSantri',
                        'id_sumber' => (string) $t->id,
                        'id_pengguna' => $idPengguna,
                        'keterangan' => 'Pembatalan sisa '.$this->labelKomponen($t)." — pengunduran diri {$santri->nama}",
                        // Kebalikan jurnal akrual daftar ulang, sebesar sisa yang masih menggantung.
                        'lines' => [
                            ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => $g['sisa'], 'kredit' => '0'],
                            ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $g['sisa']],
                        ],
                    ]);
                }

                $t->update(['sisa' => '0', 'status' => 'batal']);

                // Jadwal angsuran atas tagihan yang dibatalkan tak lagi berlaku.
                RencanaAngsuranUangPangkal::where('id_tagihan', $t->id)->where('status', 'aktif')
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
                    'id_tagihan_perlengkapan' => $perlengkapan?->id,
                    'sisa_dihapuskan' => $adaBalik ? $sisaTotal : '0',
                    'akrual_dibalik' => $adaBalik,
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
            // Hanya siklus TERBARU yang bergerak — siklus lama (kenaikan jenjang
            // yang sudah selesai) tak boleh ikut tersunting.
            $terbaru = Pendaftaran::where('id_santri', $id)->orderByDesc('id')->first();
            $terbaru?->update($pendaftaran + ['status' => $ke]);
            $santri->update(['status' => $ke]);

            return $santri;
        });
    }

    /**
     * Dua hal yang dulu tercampur di satu baris master, kini dicari terpisah:
     *  • AKUN & unit bisnisnya  → `jenis_biaya`, satu baris per (jenjang, perilaku)
     *  • BESARANNYA             → grid Tarif, per (T.A, jenjang, jalur)
     *
     * Tarif dikembalikan apa adanya beserta statusnya (ada / bebas / kosong);
     * pemanggilah yang memutuskan artinya. "kosong" JANGAN diperlakukan sama
     * dengan "bebas" — yang satu berarti petugas belum mengisi, yang lain berarti
     * memang tidak dipungut.
     *
     * @return array{jenis:JenisBiaya, tarif:array{status:string,nominal:?string,asal:?string,label:string}}
     */
    public function komponen(string $perilaku, ?string $tahunAjaran, ?string $kodeJenjang, ?string $kodeJalur): array
    {
        $jenis = JenisBiaya::untuk($perilaku, $kodeJenjang);
        if (! $jenis) {
            $label = TarifService::PERILAKU[$perilaku] ?? $perilaku;
            throw new AppException(422, "Belum ada jenis biaya {$label} yang aktif untuk jenjang \""
                .($kodeJenjang ?: '—')."\". Buat barisnya di Setting Awal → Jenis Biaya. "
                .'Cukup sekali: baris itu hanya memegang akun & unit bisnisnya, tarifnya diisi di menu Tarif.');
        }

        return ['jenis' => $jenis, 'tarif' => (new TarifService)->cari($perilaku, $tahunAjaran, $kodeJenjang, $kodeJalur)];
    }

    /** Identitas akuntansi uang pangkal untuk jenjang santri. */
    public function jenisUangPangkal(?string $kodeJenjang = null): JenisBiaya
    {
        return $this->komponen('uang_pangkal', null, $kodeJenjang, null)['jenis'];
    }

    /** Identitas akuntansi biaya perlengkapan untuk jenjang santri. */
    public function jenisPerlengkapan(?string $kodeJenjang = null): JenisBiaya
    {
        return $this->komponen('perlengkapan', null, $kodeJenjang, null)['jenis'];
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

    /**
     * Registrasi untuk (TA, jenjang, jalur) santri. Tarif bertanda BEBAS berarti
     * tagihannya tidak terbit sama sekali (null), bukan terbit bernominal nol.
     */
    private function pilihRegistrasi(?string $kodeJenjang, ?string $tahunAjaran, ?string $kodeJalur = null): ?array
    {
        ['jenis' => $jenis, 'tarif' => $tarif] = $this->komponen('registrasi', $tahunAjaran, $kodeJenjang, $kodeJalur);

        if ($tarif['status'] === 'bebas') {
            return null;
        }
        if ($tarif['status'] !== 'ada') {
            throw new AppException(422, $tarif['label'].' Isi selnya di menu Setting Awal → Tarif, '
                .'atau tandai Bebas bila jalur ini memang tidak dipungut biaya registrasi.');
        }

        return ['jenis' => $jenis, 'nominal' => $tarif['nominal']];
    }

    /**
     * Jalur wajib terdaftar & aktif. Jalur berlaku lintas T.A (lihat migration
     * jalur_pendaftaran_lintas_tahun_ajaran), TETAPI bisa dinonaktifkan untuk
     * (tahun ajaran, jenjang) tertentu — mis. SDTQ tak punya jalur OSS. Penjaga
     * itu ditegakkan di sini, bukan hanya disembunyikan di dropdown: kiriman
     * langsung & impor tak lewat layar.
     */
    private function pastikanJalurSah(string $kodeJalur, ?string $tahunAjaran = null, ?string $kodeJenjang = null): void
    {
        $jalur = \App\Models\JalurPendaftaran::find($kodeJalur);
        if (! $jalur || $jalur->status !== 'aktif') {
            throw new AppException(422, "Jalur pendaftaran \"{$kodeJalur}\" tidak terdaftar / nonaktif.");
        }
        if (in_array($kodeJalur, \App\Models\JalurNonaktif::kodeUntuk($tahunAjaran, $kodeJenjang), true)) {
            throw new AppException(422, "Jalur \"{$jalur->nama}\" tidak berlaku untuk jenjang {$kodeJenjang} pada T.A {$tahunAjaran}. "
                .'Pilih jalur lain, atau aktifkan kembali jalurnya di Setting Awal → Tarif.');
        }
    }

    /** Ubah tingkat seorang santri (mis. mengisi data lama, atau naik tingkat). */
    public function setTingkat(int $id, int $tingkat): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        $this->pastikanTingkatSah((string) $santri->kode_jenjang, $tingkat);
        $santri->update(['tingkat' => $tingkat]);

        return $santri;
    }

    /**
     * Tingkat harus berada dalam rentang jenjangnya (1..jumlah_tingkat).
     *
     * Diperiksa di SERVICE, bukan cuma di form: batas atasnya data master yang
     * bisa berubah, dan aturan ini harus tetap tegak bagi pemanggil lain
     * (impor data awal, pemindahan jenjang) — bukan hanya bagi form PPSB.
     */
    public function pastikanTingkatSah(string $kodeJenjang, int|string|null $tingkat): void
    {
        $jenjang = Jenjang::find($kodeJenjang);
        if (! $jenjang) {
            throw new AppException(422, "Jenjang \"{$kodeJenjang}\" tidak terdaftar.");
        }
        if (! $jenjang->jumlah_tingkat) {
            throw new AppException(422, "Jumlah tingkat jenjang \"{$jenjang->nama}\" belum diisi. Lengkapi dulu di Setting Awal → Jenjang Pendidikan.");
        }
        $tingkat = (int) $tingkat;
        if ($tingkat < 1 || $tingkat > $jenjang->jumlah_tingkat) {
            throw new AppException(422, "Tingkat {$tingkat} tidak ada di jenjang \"{$jenjang->nama}\" (hanya tingkat 1–{$jenjang->jumlah_tingkat}).");
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
