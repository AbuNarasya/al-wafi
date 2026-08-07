<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JadwalPerubahanSantri;
use App\Models\JalurNonaktif;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\PembayaranSantri;
use App\Models\Pendaftaran;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
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
        // PPSB boleh mendaftarkan untuk tahun berjalan maupun tahun yang AKAN
        // DATANG (pendaftaran 2027/2028 dibuka pada 2026) — tetapi tidak mundur
        // ke tahun yang sudah lewat.
        $ta = (new TahunAjaranService)->assertTidakMundur($data['tahun_ajaran'], 'Pendaftaran calon santri');
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
            $pendaftaran = Pendaftaran::create([
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
            } else {
                // BEBAS biaya registrasi (mis. jalur Anak Karyawan): tak ada tagihan,
                // jadi tahap registrasinya LANGSUNG DILEWATI.
                //
                // Tanpa ini calonnya tertahan selamanya di "Calon": satu-satunya
                // yang menulis status `terbayar` adalah verifikasi pembayaran
                // registrasi, dan halaman detailnya pun tak punya tombol apa pun
                // untuk status `calon`. Pendaftaran lanjutan sudah lama
                // memperlakukannya begini (PendaftaranLanjutanService::buka) —
                // pendaftaran baru tertinggal, dan itulah bedanya.
                $santri->update(['status' => 'terbayar']);
                $pendaftaran->update(['status' => 'terbayar']);
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
        if (! in_array($santri->status, ['diterima', 'lolos_kesehatan', 'siap_aktivasi', 'aktif'], true)) {
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
        // Uang pangkal & perlengkapan boleh ditagih untuk tahun berjalan atau
        // tahun berikutnya (calon 2027/2028 ditagih pada 2026) — tak boleh mundur.
        (new TahunAjaranService)->assertTidakMundur((string) $taTagihan, 'Penagihan uang pangkal & perlengkapan');

        // JENJANG & JALUR TAGIHAN — bisa ditimpa dengan alasan yang sama seperti
        // tahun ajaran di atas. Kenaikan jenjang kini MENAGIH LEBIH DULU dan baru
        // memindahkan santrinya saat tahun ajaran tujuan dimulai; tanpa penimpaan
        // ini, tagihannya akan memakai tarif jenjang LAMA yang sedang ditinggalkan.
        $jenjangTagihan = ($data['kode_jenjang'] ?? null) ?: $santri->kode_jenjang;
        $jalurTagihan = ($data['jalur'] ?? null) ?: $santri->jalur;

        $jenis = null;
        $bebas = true; // "tak menerbitkan uang pangkal" — jalannya sama dengan bebas
        if ($mintaUp) {
            ['jenis' => $jenis, 'tarif' => $tarif] = $this->komponen('uang_pangkal', $taTagihan, $jenjangTagihan, $jalurTagihan);
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
            if (! $bebas && $this->tagihanBerperilaku($id, 'uang_pangkal', $jenjangTagihan, $taTagihan)) {
                throw new AppException(409, "Uang pangkal {$jenjangTagihan} T.A {$taTagihan} untuk santri ini sudah pernah ditagihkan. Sunting tagihannya, jangan terbitkan yang kedua.");
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
            if ($this->tagihanBerperilaku($id, 'perlengkapan', $jenjangTagihan, $taTagihan)) {
                throw new AppException(409, "Biaya perlengkapan {$jenjangTagihan} T.A {$taTagihan} untuk santri ini sudah pernah ditagihkan. Sunting tagihannya, jangan terbitkan yang kedua.");
            }
            $komponenPerlengkapan = $this->komponen('perlengkapan', $taTagihan, $jenjangTagihan, $jalurTagihan);
            if ($komponenPerlengkapan['tarif']['status'] === 'bebas') {
                throw new AppException(422, 'Tarif perlengkapan untuk jalur ini bertanda BEBAS, jadi tagihannya memang tidak diterbitkan. '
                    .'Kosongkan isian nominal perlengkapan, atau ubah tandanya di menu Setting Awal → Tarif.');
            }
            $jenisPerlengkapan = $komponenPerlengkapan['jenis'];
        }

        // Potongan gelombang DIPEROLEH dengan membayar registrasi, bukan sekadar
        // melekat pada gelombangnya. Periodenya pun diukur pada TANGGAL
        // PELUNASAN REGISTRASI, bukan hari tagihan uang pangkal dibuat: calon
        // yang membayar tepat waktu tak boleh kehilangan potongannya hanya
        // karena pengumuman kelulusan atau penagihannya tertunda.
        //
        // Jalur bebas: tak ada nominal, jadi tak ada pula potongan yang dihitung.
        $lunasRegistrasi = $this->tanggalLunasRegistrasi($id);
        $potonganRow = ($bebas || $lunasRegistrasi === null) ? null
            : (new PotonganGelombangService)->potonganAktif($santri->gelombang, $jenjangTagihan, $taTagihan, $lunasRegistrasi);
        $potongan = $potonganRow ? Money::of($potonganRow->potongan) : '0';
        if (Money::gte($potongan, $nominalNormal) && ! $bebas) {
            throw new AppException(422, "Potongan Gelombang {$santri->gelombang} ({$potongan}) tidak boleh ≥ nominal uang pangkal ({$nominalNormal}).");
        }
        $efektif = Money::sub($nominalNormal, $potongan);

        return DB::transaction(function () use ($id, $data, $jenis, $santri, $taTagihan, $jenjangTagihan, $nominalNormal, $potongan, $potonganRow, $efektif, $jenisPerlengkapan, $nominalPerlengkapan) {
            $tagihan = $jenis === null ? null : TagihanSantri::create([
                'id_santri' => $id, 'kode_jenis' => $jenis->kode,
                'perilaku' => 'uang_pangkal', 'kode_jenjang' => $jenjangTagihan, 'tahun_ajaran' => $taTagihan,
                'nominal' => $efektif, 'sisa' => $efektif,
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => $data['keterangan'] ?? $jenis->nama,
            ]);
            if ($tagihan && Money::gtZero($potongan)) {
                // Masa berlaku milik GELOMBANGNYA, bukan sel potongannya —
                // satu tenggat untuk seluruh jenjang di gelombang yang sama.
                $masaBerlaku = (new PotonganGelombangService)->masaBerlakuHari((string) $santri->gelombang, $taTagihan);
                PotonganUangPangkal::create([
                    'id_tagihan' => $tagihan->id, 'gelombang' => $santri->gelombang,
                    'nominal_normal' => $nominalNormal, 'potongan' => $potongan, 'syarat_persen' => 50,
                    'tenggat' => Carbon::now()->startOfDay()->addDays($masaBerlaku)->toDateString(), 'status' => 'berlaku',
                ]);
            }

            // Terbit UTUH — tak ada baris potongan yang dilekatkan padanya.
            $perlengkapan = $jenisPerlengkapan ? TagihanSantri::create([
                'id_santri' => $id, 'kode_jenis' => $jenisPerlengkapan->kode,
                'perilaku' => 'perlengkapan', 'kode_jenjang' => $jenjangTagihan, 'tahun_ajaran' => $taTagihan,
                'nominal' => $nominalPerlengkapan, 'sisa' => $nominalPerlengkapan,
                'jatuh_tempo' => $data['jatuh_tempo_perlengkapan'] ?? $data['jatuh_tempo'] ?? null,
                'keterangan' => $data['keterangan_perlengkapan'] ?? $jenisPerlengkapan->nama,
            ]) : null;

            return ['uang_pangkal' => $tagihan, 'perlengkapan' => $perlengkapan];
        });
    }

    /**
     * Tanggal saat tagihan REGISTRASI calon ini menjadi LUNAS — penentu apakah
     * ia berhak atas potongan gelombang, sekaligus tanggal yang dibandingkan
     * dengan periode gelombangnya.
     *
     * NULL bila registrasinya belum lunas ATAU belum pernah ditagihkan sama
     * sekali. Keduanya sengaja diperlakukan sama: potongan adalah imbalan atas
     * pembayaran registrasi, jadi tanpa pembayaran itu tak ada yang diimbali.
     */
    private function tanggalLunasRegistrasi(int $idSantri): ?string
    {
        $tagihan = TagihanSantri::where('id_santri', $idSantri)
            ->where('perilaku', 'registrasi')
            ->orderByDesc('id')->first();
        if (! $tagihan || ! Money::isZero($tagihan->sisa)) {
            return null;
        }

        // Tanggal setoran TERAKHIR — itulah saat kewajibannya benar-benar tuntas.
        $pelunas = PembayaranSantri::where('id_tagihan', $tagihan->id)
            ->orderByDesc('tanggal')->orderByDesc('id')->first();

        return $pelunas?->tanggal?->toDateString();
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
    public function siapkanAktivasi(int $id, int $idPengguna): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        Tahap::assertTransisi($santri->status, 'siap_aktivasi');

        // Syaratnya diperiksa SEKARANG, bukan nanti saat jadwalnya menyala:
        // petugas masih di layar dan bisa memperbaiki; penerap jadwal berjalan
        // tanpa seorang pun menunggui.
        $this->pastikanSiapDiaktifkan($santri);

        $ta = (string) $santri->tahun_ajaran;
        (new TahunAjaranService)->pastikanAktif($ta);

        DB::transaction(function () use ($santri, $ta, $idPengguna) {
            $santri->update(['status' => 'siap_aktivasi']);
            Pendaftaran::where('id_santri', $santri->id)->orderByDesc('id')->first()
                ?->update(['status' => 'siap_aktivasi']);

            // Jadwalnya memakai tabel yang sama dengan kenaikan tingkat: satu
            // pintu, satu penerap, satu perilaku. Berlaku saat T.A MASUK-nya
            // dimulai — untuk calon yang mendaftar di tengah tahun berjalan,
            // tanggal itu sudah lewat sehingga jadwalnya langsung menyala.
            JadwalPerubahanSantri::updateOrCreate(
                ['id_santri' => $santri->id, 'tahun_ajaran' => $ta, 'status' => 'siap'],
                [
                    'keputusan' => 'aktivasi',
                    'kode_jenjang_tujuan' => $santri->kode_jenjang,
                    'tingkat_tujuan' => $santri->tingkat,
                    'kode_jalur_tujuan' => $santri->jalur,
                    'ditetapkan_oleh' => $idPengguna,
                    'ditetapkan_pada' => now(),
                    'catatan' => 'Ditandai siap diaktifkan dari PPSB.',
                ],
            );

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'santri_siap_aktivasi',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'no_pendaftaran' => $santri->no_pendaftaran,
                    'nama' => $santri->nama, 'tahun_ajaran' => $ta,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });

        // Calon yang mendaftar di TENGAH tahun ajaran berjalan: tahun masuknya
        // sudah dimulai, jadi tak ada gunanya menunggu — jadwalnya langsung
        // menyala di sini, sama seperti penetapan kenaikan tingkat yang telat.
        (new JadwalPerubahanService)->terapkanYangJatuhTempo();

        return $santri->refresh();
    }

    /**
     * Syarat menjadi santri: uang pangkalnya sudah ditagihkan (atau tarifnya
     * memang bertanda Bebas) dan belum pernah diakrualkan.
     *
     * Dipakai DUA kali: saat menandai siap (supaya petugas tahu sedini mungkin)
     * dan saat aktivasinya benar-benar menyala (karena keadaan bisa berubah di
     * antara keduanya — tagihannya bisa dibatalkan).
     */
    private function pastikanSiapDiaktifkan(Santri $santri): void
    {
        // Cari lewat TIPE tagihannya (lihat catatan di koreksiNominalUangPangkal).
        $tagihan = $this->tagihanPerilaku($santri->id, 'uang_pangkal');
        if (! $tagihan) {
            // Santri yang tarif uang pangkalnya bertanda BEBAS memang tak punya
            // tagihan itu — menuntutnya akan mengunci mereka selamanya di
            // "lolos kesehatan". Sel yang BELUM DIISI tetap dianggap kurang
            // lengkap, bukan bebas: itu beda keadaan.
            $tarif = (new TarifService)->cari('uang_pangkal', $santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur);
            if ($tarif['status'] !== 'bebas') {
                throw new AppException(422, 'Uang pangkal belum ditagihkan. Terbitkan tagihannya lebih dulu.');
            }
        } elseif ($tagihan->sudah_akrual) {
            throw new AppException(422, 'Uang pangkal santri ini sudah pernah diakrualkan.');
        }
    }

    /**
     * AKTIVASI — calon benar-benar menjadi santri. Dipanggil penerap jadwal,
     * bukan langsung dari layar.
     *
     * INILAH saat jurnal akrualnya terbit. Dulu jurnal itu terbit bersamaan
     * dengan penekanan tombol, yang bisa berbulan-bulan sebelum tahun ajarannya
     * mulai — sehingga pendapatan tahun depan diakui di tahun ini. Menundanya
     * ke sini juga membuat pengunduran diri tetap sederhana: calon yang mundur
     * pada masa `siap_aktivasi` tak meninggalkan jurnal yang perlu dibalik,
     * karena memang belum ada.
     */
    public function aktifkan(Santri $santri, ?int $idPengguna = null): Santri
    {
        Tahap::assertTransisi($santri->status, 'aktif');
        $this->pastikanSiapDiaktifkan($santri);

        $tagihan = $this->tagihanPerilaku($santri->id, 'uang_pangkal');
        // Perlengkapan ikut bila ada, belum diakrualkan, dan tidak dibatalkan.
        $perlengkapan = $this->tagihanPerilaku($santri->id, 'perlengkapan');
        if ($perlengkapan && ($perlengkapan->sudah_akrual || $perlengkapan->status === 'batal')) {
            $perlengkapan = null;
        }
        $akrual = array_values(array_filter([$tagihan, $perlengkapan]));

        return DB::transaction(function () use ($idPengguna, $santri, $akrual) {
            $this->akrualkanTagihan($santri, $akrual, $idPengguna, 'aktivasi santri baru');
            $santri->update([
                'status' => 'aktif',
                // NIS TIDAK diterbitkan di sini. Nomornya berurut menurut ABJAD
                // dalam satu angkatan jenjang, dan abjad baru bisa ditentukan
                // setelah seluruh angkatan diterima — menerbitkannya per santri
                // di sini hanya akan menghasilkan urutan kedatangan.
                // Lihat Kependidikan → Kontrol → Generate NIS (NisService).
                'tahun_ajaran_berjalan' => $santri->tahun_ajaran,
            ]);
            Pendaftaran::where('id_santri', $santri->id)->orderByDesc('id')->first()?->update(['status' => 'aktif']);

            // Baris pertama riwayat tingkatnya — supaya tahun yang sedang berjalan
            // pun tercatat, bukan hanya tahun-tahun sesudah kenaikan.
            if ($santri->kode_jenjang && $santri->tahun_ajaran) {
                (new JadwalPerubahanService)->catatRiwayatMasuk($santri->refresh(), (string) $santri->tahun_ajaran);
            }

            return $santri->refresh();
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

    /**
     * AKRUALKAN sisa uang pangkal / perlengkapan — D Piutang / K Pendapatan.
     *
     * Dipakai DUA jalur yang membawa santri ke jenjang barunya, dan keduanya
     * WAJIB berperilaku sama:
     *  • daftar ulang — calon baru menjadi santri aktif;
     *  • kenaikan jenjang — santri aktif pindah jenjang (SDTQ→SMP, SMP→SMA).
     *
     * Dulu hanya jalur pertama yang mengakru. Akibatnya dua santri dengan
     * kewajiban yang sama persis tercatat berbeda di buku besar semata karena
     * yang satu masuk lewat pendaftaran baru dan yang lain lewat kenaikan
     * jenjang: piutangnya tak pernah muncul, dan pendapatannya baru diakui saat
     * uang datang — padahal jasanya sudah diberikan.
     *
     * Yang sudah diakrualkan atau dibatalkan DILEWATI, jadi aman dipanggil ulang.
     *
     * @param  list<TagihanSantri>  $tagihan
     * @return list<array{id:int, komponen:string, sisa:string}> yang benar-benar dijurnal
     */
    public function akrualkanTagihan(Santri $santri, array $tagihan, int $idPengguna, string $konteks, ?string $referensi = null): array
    {
        $garap = [];
        foreach ($tagihan as $t) {
            if (! $t || $t->sudah_akrual || $t->status === 'batal') {
                continue;
            }
            $label = $t->jenis?->nama ?? 'tagihan';
            $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->count();
            if ($menunggu > 0) {
                throw new AppException(422, "Masih ada {$menunggu} pembayaran \"{$label}\" yang menunggu verifikasi keuangan. "
                    .'Selesaikan dulu agar nilai yang diakrualkan tidak keliru.');
            }
            if (! $t->jenis?->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$label}\" belum punya akun piutang. Isi dulu di master Jenis Biaya.");
            }
            $garap[] = $t;
        }

        $dijurnal = [];
        foreach ($garap as $t) {
            $jenis = $t->jenis;
            $sisa = Money::of($t->sisa);
            // Sisa nol tetap ditandai akrual (tanpa jurnal): tagihannya sudah
            // lunas lebih dulu, jadi tak ada piutang yang perlu diakui — tetapi
            // menandainya mencegah pemanggilan berikutnya menjurnalnya lagi.
            if (Money::gtZero($sisa)) {
                PostingService::postJournal([
                    'referensi' => $referensi ?: ($santri->no_pendaftaran ?: $santri->nis),
                    'tanggal' => Carbon::now()->toDateString(),
                    'kode_unit' => $jenis->kode_unit, 'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $t->id,
                    'id_pengguna' => $idPengguna,
                    'keterangan' => 'Akrual '.$this->labelKomponen($t)." {$konteks} — {$santri->nama}",
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $sisa, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $sisa],
                    ],
                ]);
                $dijurnal[] = ['id' => $t->id, 'komponen' => $this->labelKomponen($t), 'sisa' => $sisa];
            }
            $t->update(['sudah_akrual' => true]);
        }

        return $dijurnal;
    }

    private function labelKomponen(TagihanSantri $tagihan): string
    {
        $perilaku = (string) TipeBiaya::perilakuDari($tagihan->jenis?->tipe);

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
            ->when(! $termasukBatal, fn ($q) => $q->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU))
            ->with('jenis')->orderByDesc('id')->first();
    }

    /**
     * Perilaku tagihan yang IKUT DITUTUP saat seorang CALON mengundurkan diri.
     *
     * Ketiganya melekat pada penerimaan yang batal terjadi: tak ada jasa yang
     * diberikan, jadi tak ada yang boleh terus ditagih. SPP tidak ada di sini —
     * calon belum pernah bersekolah, jadi ia memang tak punya.
     */
    private const PERILAKU_DITUTUP_SAAT_MUNDUR = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    /** Pengunduran diri — boleh kapan saja sebelum proses berakhir. */
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

        $tutup = $this->siapkanPenutupanTagihan($santri);

        return DB::transaction(function () use ($santri, $id, $alasan, $idPengguna, $tutup) {
            $ditutup = [];
            foreach ($tutup as $t) {
                $ditutup[] = $this->tutupTagihanMundur($t, $santri, $alasan, $idPengguna);
            }

            $santri->update(['status' => 'mengundurkan_diri']);
            Pendaftaran::where('id_santri', $id)->update(['catatan' => "Mengundurkan diri: {$alasan}"]);

            if ($ditutup !== []) {
                ActivityLog::create([
                    'id_pengguna' => $idPengguna,
                    'aksi' => 'calon_mengundurkan_diri_tagihan_ditutup',
                    'detail' => json_encode([
                        'id_santri' => $santri->id, 'no_pendaftaran' => $santri->no_pendaftaran,
                        'nama' => $santri->nama, 'alasan' => $alasan, 'tagihan' => $ditutup,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            return $santri->refresh();
        });
    }

    /**
     * Kumpulkan tagihan yang harus ditutup, sekaligus tolak lebih dulu keadaan
     * yang membuat angkanya masih bisa berubah.
     *
     * Yang DILEWATI: tagihan `batal` (sudah tak menagih apa pun) dan `lunas`
     * (sudah dibayar penuh — barisnya adalah jejak kuitansinya, bukan tagihan
     * yang menggantung; membatalkannya sama saja menghapus bukti penerimaan).
     *
     * @return list<TagihanSantri>
     */
    private function siapkanPenutupanTagihan(Santri $santri): array
    {
        $tagihan = TagihanSantri::where('id_santri', $santri->id)
            ->whereIn('perilaku', self::PERILAKU_DITUTUP_SAAT_MUNDUR)
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->with('jenis')->orderBy('id')->get();

        foreach ($tagihan as $t) {
            $label = $t->jenis?->nama ?? 'tagihan';
            $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->count();
            if ($menunggu > 0) {
                throw new AppException(422, "Masih ada {$menunggu} pembayaran \"{$label}\" yang menunggu verifikasi keuangan. "
                    .'Verifikasi atau tolak dulu, agar sisa yang dihapuskan bukan angka yang masih berubah.');
            }
            // Calon belum pernah daftar ulang, jadi tagihannya semestinya masih
            // cash basis. Bila ternyata sudah diakrualkan, piutangnya WAJIB
            // dibalik — dan itu butuh akun piutang yang lengkap.
            if ($t->sudah_akrual && Money::gtZero($t->sisa) && ! $t->jenis?->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$label}\" belum punya akun piutang, sehingga akrualnya tidak bisa dibalik. "
                    .'Lengkapi dulu di master Jenis Biaya.');
            }
        }

        return $tagihan->all();
    }

    /**
     * Tutup satu tagihan calon yang mundur.
     *
     * Dua nasib yang sengaja DIBEDAKAN di keterangannya, karena uangnya berbeda:
     *  • belum ada pembayaran → tagihannya HILANG, tak pernah ada uang yang masuk;
     *  • sudah ada pembayaran → sisanya dihapus dan yang telanjur dibayar HANGUS
     *    (tetap menjadi penerimaan pesantren, tidak dikembalikan). Registrasi &
     *    uang pangkal calon diakui saat uang diterima (cash basis), jadi jurnalnya
     *    sudah benar sejak awal dan tak ada yang perlu dibalik.
     *
     * @return array{id:int, komponen:string, sisa_dihapus:string, sudah_dibayar:string, akrual_dibalik:bool}
     */
    private function tutupTagihanMundur(TagihanSantri $t, Santri $santri, string $alasan, ?int $idPengguna): array
    {
        $sisa = Money::of($t->sisa);
        $dibayar = Money::sub($t->nominal, $sisa);
        $adaPembayaran = Money::gtZero($dibayar);
        $balik = (bool) $t->sudah_akrual && Money::gtZero($sisa);

        if ($balik) {
            $jenis = $t->jenis;
            PostingService::postJournal([
                'referensi' => $santri->nis ?? $santri->no_pendaftaran,
                'tanggal' => Carbon::now()->toDateString(),
                'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'PembayaranSantri',
                'id_sumber' => (string) $t->id,
                'id_pengguna' => $idPengguna,
                'keterangan' => 'Pembatalan sisa '.$this->labelKomponen($t)." — pengunduran diri {$santri->nama}",
                'lines' => [
                    ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => $sisa, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $sisa],
                ],
            ]);
        }

        $jejak = $adaPembayaran
            ? 'Hangus — mengundurkan diri: '.$alasan.' (sudah dibayar '.$dibayar.', tidak dikembalikan)'
            : 'Dibatalkan — mengundurkan diri: '.$alasan;

        $t->update([
            'sisa' => '0',
            'status' => 'batal',
            'keterangan' => trim(($t->keterangan ? $t->keterangan.' · ' : '').$jejak),
        ]);

        RencanaAngsuranUangPangkal::where('id_tagihan', $t->id)->where('status', 'aktif')
            ->update(['status' => 'digantikan', 'alasan' => "Santri mengundurkan diri: {$alasan}"]);

        return ['id' => $t->id, 'komponen' => $this->labelKomponen($t), 'sisa_dihapus' => $sisa,
            'sudah_dibayar' => $dibayar, 'akrual_dibalik' => $balik];
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
                .($kodeJenjang ?: '—').'". Buat barisnya di Setting Awal → Jenis Biaya. '
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

    /**
     * Registrasi untuk (TA, jenjang, jalur) santri. Tarif bertanda BEBAS berarti
     * tagihannya tidak terbit sama sekali (null), bukan terbit bernominal nol.
     *
     * NULL karena itu berarti PERSIS SATU hal: bebas. Sel yang belum diisi
     * melempar galat di sini, jadi pemanggil boleh memperlakukan null sebagai
     * "tahap registrasi tak perlu dilalui" tanpa memeriksa apa pun lagi.
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
        $jalur = JalurPendaftaran::find($kodeJalur);
        if (! $jalur || $jalur->status !== 'aktif') {
            throw new AppException(422, "Jalur pendaftaran \"{$kodeJalur}\" tidak terdaftar / nonaktif.");
        }
        if (in_array($kodeJalur, JalurNonaktif::kodeUntuk($tahunAjaran, $kodeJenjang), true)) {
            throw new AppException(422, "Jalur \"{$jalur->nama}\" tidak berlaku untuk jenjang {$kodeJenjang} pada T.A {$tahunAjaran}. "
                .'Pilih jalur lain, atau aktifkan kembali jalurnya di Setting Awal → Tarif.');
        }
    }

    /**
     * KOREKSI `tahun_ajaran_berjalan` seorang santri.
     *
     * Kolom ini biasanya bergerak sendiri: daftar ulang mengisinya, kenaikan
     * tingkat & pendaftaran lanjutan memajukannya. Yang tak punya jalan adalah
     * santri hasil IMPOR yang kolom tahun ajaran di berkasnya salah — nilainya
     * tak pernah bisa dibetulkan dari layar mana pun, dan begitu selisihnya
     * lebih dari satu tahun, `KenaikanTingkatService` melewatinya terus-menerus
     * supaya riwayat tingkatnya tidak bolong. Inilah pintu keluarnya.
     *
     * Yang SENGAJA tidak ikut berubah: `tahun_ajaran` (angkatan/tahun masuk —
     * ia memang tak pernah maju), tingkat, dan jenjang. Ini koreksi satu kolom,
     * bukan kenaikan tingkat lewat pintu belakang; tingkatnya dikoreksi
     * tersendiri lewat setTingkat().
     *
     * Tagihan yang SUDAH terbit tidak disentuh — tagihan membawa tahun ajarannya
     * sendiri sebagai snapshot, dan mengubahnya dari sini akan membuat angka
     * yang sudah dijanjikan ke wali berubah tanpa jejak.
     */
    public function setTahunBerjalan(int $id, string $tahunAjaran): Santri
    {
        $santri = Santri::find($id);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        (new TahunAjaranService)->pastikanAktif($tahunAjaran);

        $santri->update(['tahun_ajaran_berjalan' => $tahunAjaran]);

        return $santri->refresh();
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
     * Tingkat harus berada dalam rentang jenjangnya (tingkat_mulai..tingkat_akhir).
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
        if ($tingkat < $jenjang->tingkatMulai() || $tingkat > $jenjang->tingkatAkhir()) {
            throw new AppException(422, "Tingkat {$tingkat} tidak ada di jenjang \"{$jenjang->nama}\" "
                ."(hanya tingkat {$jenjang->tingkatMulai()}–{$jenjang->tingkatAkhir()}).");
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
