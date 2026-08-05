<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Pendaftaran;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PENDAFTARAN LANJUTAN — kenaikan jenjang santri internal, LEWAT PROSES PPSB.
 *
 * Santri kelas akhir tidak "dipindahkan" begitu saja: ia mendaftar ke jenjang
 * berikutnya dan melewati seleksi, penerimaan, serta med check seperti pendaftar
 * lain. Bedanya hanya dua:
 *   • tahap BERKAS dilewati — santrinya sudah dikenal & dokumennya sudah ada;
 *   • biaya registrasinya OPSIONAL, ditentukan sel tarif (terisi = ditagih,
 *     ditandai Bebas = tidak). Perubahan kebijakan cukup mencentang di menu Tarif.
 *
 * YANG PALING PENTING: `santri.status` TIDAK disentuh sepanjang proses. Ia tetap
 * `aktif` — masih bersekolah, masih ditagih SPP, masih masuk laporan. Yang
 * bergerak adalah `pendaftaran.status`. Data santri baru berubah pada langkah
 * terakhir, `eksekusiKenaikan()`, dan itu satu transaksi: gagal di tengah berarti
 * tak ada yang berubah sama sekali.
 */
class PendaftaranLanjutanService
{
    public function __construct(
        private readonly TarifService $tarif = new TarifService,
        private readonly SantriService $santri = new SantriService,
    ) {}

    /**
     * Sasaran kenaikan seorang santri, atau `null` bila memang tak bisa naik.
     * Dipakai layar untuk memutuskan apakah tombolnya ditampilkan, dan dipakai
     * `buat()` sebagai satu-satunya sumber jenjang & jalur tujuan.
     *
     * `null` = jenjangnya memang tak punya lanjutan (santrinya jadi alumni).
     * `alasan` terisi = sasarannya ada tapi BELUM boleh dijalankan; layar
     * menampilkan kalimatnya alih-alih formulir, dan `buat()` menolaknya.
     *
     * @return array{kode_jenjang:string,nama_jenjang:string,kode_jalur:?string,nama_jalur:?string,alasan:?string}|null
     */
    public function sasaran(Santri $santri): ?array
    {
        $jenjang = $santri->kode_jenjang ? Jenjang::find($santri->kode_jenjang) : null;
        if (! $jenjang) {
            return null;
        }
        if (! $jenjang->kode_jenjang_lanjutan) {
            // Jenjang terakhir: santrinya menjadi alumni, bukan naik. Sengaja
            // dibaca dari master, bukan ditebak dari kolom `urutan` — urutan akan
            // menyesatkan begitu ada jenjang paralel (putra/putri).
            return null;
        }
        $tujuan = Jenjang::find($jenjang->kode_jenjang_lanjutan);
        if (! $tujuan) {
            return null;
        }

        // Jalur setelah naik mengikuti master jalur (Reguler → Lanjutan Reguler,
        // OSS → Lanjutan (OSS), Anak Karyawan → tetap dirinya sendiri).
        $jalurTujuan = $santri->jalur ? JalurPendaftaran::find($santri->jalur)?->kode_jalur_lanjutan : null;

        return [
            'kode_jenjang' => $tujuan->kode,
            'nama_jenjang' => $tujuan->nama,
            'kode_jalur' => $jalurTujuan,
            // Namanya ikut: layar menyebut NAMA jalur ("Lanjutan Reguler"), bukan "003".
            'nama_jalur' => $jalurTujuan ? (JalurPendaftaran::find($jalurTujuan)?->nama ?: $jalurTujuan) : null,
            // Tingkat diperiksa LEBIH DULU: ia penghalang yang datang lebih awal,
            // dan menyebut urusan jalur pada santri yang belum kelas akhir hanya
            // menyesatkan.
            'alasan' => $this->alasanBelumBolehNaik($santri, $jenjang)
                ?? ($jalurTujuan ? null
                    : 'Jalur "'.$santri->jalur.'" belum punya "Jalur Setelah Naik Jenjang" di master Jalur Pendaftaran.'),
        ];
    }

    /**
     * NAIK JENJANG HANYA DARI TINGKAT TERAKHIR jenjang asalnya.
     *
     * Tanpa penjaga ini santri SDTQ tingkat 1 bisa didaftarkan ke SMP dan
     * dieksekusi — melewati sisa tingkatnya tanpa gejala apa pun, dan
     * `riwayat_tingkat`-nya mencatat lompatan itu sebagai kenaikan yang sah.
     * Modul Kenaikan Tingkat massal sudah lama mengenal batas ini
     * (`KenaikanTingkatService::usulkan` menawarkan `naik` selama tingkat <
     * terakhir, dan menyerahkan tingkat terakhir ke Pendaftaran Lanjutan);
     * jalur per santri inilah yang tertinggal.
     *
     * Dua keadaan yang TIDAK bisa dipastikan sengaja ikut dihalangi & dikatakan
     * terus terang, bukan diloloskan: jumlah tingkat jenjang yang belum diisi,
     * dan santri (mis. hasil impor) yang tingkatnya masih kosong.
     */
    private function alasanBelumBolehNaik(Santri $santri, Jenjang $jenjang): ?string
    {
        // Tingkat TERAKHIR jenjang ini menurut penomoran berkelanjutan — SMP
        // berakhir di 9, bukan di 3. Memakai `jumlah_tingkat` apa adanya akan
        // menganggap santri SMP tingkat 9 "belum tingkat terakhir dari 3".
        $terakhir = $jenjang->tingkatAkhir();
        $tingkat = (int) $santri->tingkat;

        if ($terakhir < 1) {
            return "Jumlah tingkat jenjang \"{$jenjang->nama}\" belum diisi, jadi tak bisa dipastikan "
                .'santri ini sudah di tingkat terakhir. Lengkapi dulu di Setting Awal → Jenjang Pendidikan.';
        }
        if ($tingkat < 1) {
            return 'Tingkat santri ini belum terisi, jadi tak bisa dipastikan ia sudah di tingkat terakhir. '
                .'Isi tingkatnya dulu di data santri.';
        }
        if ($tingkat < $terakhir) {
            return "Naik jenjang hanya dari tingkat terakhir. Santri ini masih tingkat {$tingkat} dari {$terakhir} "
                ."di {$jenjang->nama} — naikkan tingkatnya dulu lewat Kependidikan → Kenaikan Tingkat.";
        }

        return null;
    }

    /**
     * Buka siklus pendaftaran lanjutan. Tagihan registrasi terbit di sini bila sel
     * tarifnya terisi — sama seperti pendaftar baru, yang tagihannya juga lahir
     * bersamaan dengan pendaftarannya.
     */
    public function buat(int $idSantri, array $data, int $idPengguna): Pendaftaran
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, 'Pendaftaran ke jenjang lanjutan hanya untuk santri AKTIF. Status sekarang "'
                .Tahap::labelStatus((string) $santri->status).'".');
        }

        $sasaran = $this->sasaran($santri);
        if (! $sasaran) {
            throw new AppException(422, "Jenjang \"{$santri->kode_jenjang}\" tidak punya jenjang lanjutan, "
                .'jadi santrinya menjadi alumni — bukan naik jenjang.');
        }
        if ($sasaran['alasan']) {
            throw new AppException(422, $sasaran['alasan']);
        }

        $taTujuan = (string) ($data['tahun_ajaran'] ?? '');
        if ($taTujuan === '') {
            throw new AppException(422, 'Tahun ajaran tujuan wajib dipilih.');
        }
        if ($taTujuan === $santri->taBerjalan()) {
            throw new AppException(422, "T.A tujuan tidak boleh sama dengan tahun yang sedang dijalani ({$taTujuan}).");
        }
        // Kenaikan jenjang selalu menuju DEPAN. Penjaga di atas hanya menolak
        // tahun yang sama dengan tahun santrinya; ini yang menolak tahun yang
        // sudah lewat menurut kalender.
        (new TahunAjaranService)->assertTidakMundur($taTujuan, 'Pendaftaran lanjutan (kenaikan jenjang)');

        // Satu siklus per (santri, T.A, jenjang tujuan) — indeks unik juga menjaga,
        // tetapi pesan di sini jauh lebih menuntun daripada galat basis data.
        $adaBerjalan = Pendaftaran::where('id_santri', $idSantri)->lanjutan()->terbuka()->first();
        if ($adaBerjalan) {
            throw new AppException(409, "Santri ini masih punya pendaftaran lanjutan yang berjalan ke {$adaBerjalan->kode_jenjang} "
                ."T.A {$adaBerjalan->tahun_ajaran} (tahap \"".$adaBerjalan->labelStatus().'"). Selesaikan atau batalkan dulu.');
        }

        // Registrasi: terisi = ditagih · Bebas = dilewati · kosong = BERHENTI.
        $registrasi = $this->tarif->cari('registrasi', $taTujuan, $sasaran['kode_jenjang'], $sasaran['kode_jalur']);
        if ($registrasi['status'] === 'kosong') {
            throw new AppException(422, $registrasi['label'].' Isi selnya di menu Setting Awal → Tarif, '
                .'atau tandai Bebas bila jalur lanjutan ini tidak dipungut biaya registrasi.');
        }

        return DB::transaction(function () use ($santri, $sasaran, $taTujuan, $registrasi, $idPengguna, $data) {
            $now = Carbon::now();
            $base = DocNumber::docBase('PSL', $now);
            $last = Pendaftaran::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            $pendaftaran = Pendaftaran::create([
                'id_santri' => $santri->id,
                'tanggal' => $now->toDateString(),
                'tahun_ajaran' => $taTujuan,
                'kode_jenjang' => $sasaran['kode_jenjang'],
                'kode_jalur' => $sasaran['kode_jalur'],
                'jenis' => 'lanjutan',
                // Berkas dilewati, tapi tahap registrasi tetap dilalui: bila tak
                // dipungut, statusnya langsung maju ke `terbayar` di bawah.
                'status' => 'calon',
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'dokumen_lengkap' => true,
                'verifikasi_ok' => true,
                'catatan' => $data['catatan'] ?? null,
            ]);

            if ($registrasi['status'] !== 'ada') {
                // Bebas biaya registrasi → tak ada yang perlu dibayar, tahapnya lewat.
                $pendaftaran->update(['status' => 'terbayar']);
            } else {
                // Siklus yang dibuka ULANG (setelah dibatalkan/tidak lulus) mungkin
                // masih punya tagihan registrasi dari percobaan sebelumnya. Yang
                // sudah dibayar TIDAK diterbitkan lagi — uangnya sudah diterima.
                $lama = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')
                    ->where('kode_jenjang', $sasaran['kode_jenjang'])->where('tahun_ajaran', $taTujuan)
                    ->where('status', '!=', 'batal')->first();

                if ($lama) {
                    if ($lama->status === 'lunas') {
                        $pendaftaran->update(['status' => 'terbayar']);
                    }
                } else {
                    $jenis = $this->santri->komponen('registrasi', $taTujuan, $sasaran['kode_jenjang'], $sasaran['kode_jalur'])['jenis'];
                    TagihanSantri::create([
                        'id_santri' => $santri->id, 'kode_jenis' => $jenis->kode,
                        'perilaku' => 'registrasi',
                        'kode_jenjang' => $sasaran['kode_jenjang'], 'tahun_ajaran' => $taTujuan,
                        'nominal' => $registrasi['nominal'], 'sisa' => $registrasi['nominal'],
                        'keterangan' => "Registrasi jenjang lanjutan {$sasaran['kode_jenjang']} T.A {$taTujuan}",
                    ]);
                }
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'buat_pendaftaran_lanjutan',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'nis' => $santri->nis, 'nomor' => $pendaftaran->nomor,
                    'dari_jenjang' => $santri->kode_jenjang, 'ke_jenjang' => $sasaran['kode_jenjang'],
                    'ke_jalur' => $sasaran['kode_jalur'], 'tahun_ajaran' => $taTujuan,
                    'registrasi' => $registrasi['status'], 'nominal_registrasi' => $registrasi['nominal'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $pendaftaran->refresh();
        });
    }

    /**
     * Majukan tahapan satu siklus lanjutan. Rantainya TRANSISI_LANJUTAN, jadi
     * `terbayar` langsung ke `diseleksi` — berkas dilewati.
     */
    public function majukan(int $idPendaftaran, string $ke, array $isi, int $idPengguna): Pendaftaran
    {
        $p = Pendaftaran::lanjutan()->find($idPendaftaran);
        if (! $p) {
            throw new AppException(404, 'Pendaftaran lanjutan tidak ditemukan.');
        }
        Tahap::assertTransisi((string) $p->status, $ke, 'lanjutan');

        $p->update($isi + ['status' => $ke]);
        ActivityLog::create([
            'id_pengguna' => $idPengguna,
            'aksi' => 'tahap_pendaftaran_lanjutan',
            'detail' => json_encode(['id_pendaftaran' => $p->id, 'nomor' => $p->nomor, 'ke' => $ke], JSON_UNESCAPED_UNICODE),
        ]);

        return $p->refresh();
    }

    /**
     * Registrasi lanjutan LUNAS → tahapnya maju. Dipanggil dari verifikasi
     * pembayaran; dicocokkan lewat (santri, jenjang, T.A) karena kombinasi itu
     * dijamin tunggal oleh indeks unik tagihan maupun indeks siklus pendaftaran.
     */
    public function tandaiRegistrasiLunas(int $idSantri, ?string $kodeJenjang, ?string $tahunAjaran): void
    {
        Pendaftaran::where('id_santri', $idSantri)->lanjutan()
            ->where('status', 'calon')
            ->where('kode_jenjang', $kodeJenjang)->where('tahun_ajaran', $tahunAjaran)
            ->first()?->update(['status' => 'terbayar']);
    }

    /**
     * LANGKAH TERAKHIR — kenaikan benar-benar dieksekusi. Satu transaksi:
     *  1. jenjang, tingkat, jalur, & tahun berjalan santri diperbarui;
     *  2. riwayat tingkat ditulis (di mana ia berada pada T.A tujuan);
     *  3. uang pangkal + perlengkapan ditagihkan dengan tarif jenjang & T.A TUJUAN.
     *
     * Sebelum ini tak ada apa pun pada data santri yang berubah, jadi pendaftaran
     * yang gagal atau dibatalkan tidak meninggalkan bekas.
     *
     * @return array{santri:Santri,uang_pangkal:?TagihanSantri,perlengkapan:?TagihanSantri}
     */
    public function eksekusiKenaikan(int $idPendaftaran, array $data, int $idPengguna): array
    {
        $p = Pendaftaran::lanjutan()->with('santri')->find($idPendaftaran);
        if (! $p) {
            throw new AppException(404, 'Pendaftaran lanjutan tidak ditemukan.');
        }
        Tahap::assertTransisi((string) $p->status, 'naik', 'lanjutan');

        $santri = $p->santri;
        if (! $santri || $santri->status !== 'aktif') {
            throw new AppException(422, 'Kenaikan hanya bisa dieksekusi untuk santri yang masih aktif.');
        }

        // Batas tingkat diperiksa ULANG di sini, bukan hanya saat siklusnya dibuka:
        // inilah satu-satunya langkah yang benar-benar mengubah data santri, dan
        // siklus yang dibuka SEBELUM penjaga ini ada (atau yang tingkatnya berubah
        // di tengah proses) masih akan sampai ke sini.
        $jenjangAsal = $santri->kode_jenjang ? Jenjang::find($santri->kode_jenjang) : null;
        if ($jenjangAsal && ($alasan = $this->alasanBelumBolehNaik($santri, $jenjangAsal))) {
            throw new AppException(422, $alasan);
        }

        $tingkatBaru = (int) ($data['tingkat'] ?? 1);
        $this->santri->pastikanTingkatSah((string) $p->kode_jenjang, $tingkatBaru);

        $dariJenjang = $santri->kode_jenjang;
        $dariTingkat = $santri->tingkat;

        return DB::transaction(function () use ($p, $santri, $tingkatBaru, $data, $idPengguna, $dariJenjang, $dariTingkat) {
            // PERPINDAHANNYA TIDAK DIKERJAKAN DI SINI. Dulu keempat kolom santri
            // (jenjang, tingkat, jalur, tahun berjalan) berubah seketika di baris
            // ini — sehingga siklus yang tuntas bulan Mei membuat santri ber-jenjang
            // SMP sementara kalender masih tahun lama, dan setiap pencarian tarif
            // yang bersandar pada kolom itu ikut mendahului kalender.
            //
            // Yang tetap dikerjakan sekarang: TAGIHAN & AKRUALNYA. Justru itulah
            // gunanya siklus dibuka jauh hari — keluarga perlu waktu mencicil.
            // Riwayat tingkatnya ikut ditulis oleh penerap jadwal, bersama
            // perpindahannya, supaya keduanya tak pernah terpisah.
            (new KenaikanTingkatService)->tandaiSiapDariPpsb($p, $tingkatBaru);

            // Tarif dicari pada jenjang & T.A TUJUAN — inilah gunanya penimpa
            // `tahun_ajaran` di tagihkanUangPangkal.
            $tagihan = $this->santri->tagihkanUangPangkal($santri->id, [
                'komponen' => ['uang_pangkal', 'perlengkapan'],
                'tahun_ajaran' => $p->tahun_ajaran,
                // Jenjang & jalur TUJUAN disebut eksplisit: santrinya belum tentu
                // sudah berpindah — perpindahannya menunggu tahun ajaran tujuan
                // dimulai. Tanpa ini tarifnya diambil dari jenjang yang ditinggalkan.
                'kode_jenjang' => $p->kode_jenjang,
                'jalur' => $p->kode_jalur ?: $santri->jalur,
                'nominal' => $data['nominal_uang_pangkal'] ?? null,
                'nominal_perlengkapan' => $data['nominal_perlengkapan'] ?? '0',
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null,
            ]);

            // AKRUAL — persis seperti daftar ulang. Santrinya sudah benar-benar
            // pindah jenjang di baris atas: jenjangnya berubah, riwayat tingkatnya
            // tercatat, dan SPP jenjang barunya sudah bisa ditagih. Jasanya
            // dianggap mulai diberikan, jadi piutangnya diakui sekarang — bukan
            // menunggu uangnya datang. Tanpa ini, santri yang naik jenjang tak
            // pernah memunculkan piutang sama sekali.
            $akrual = $this->santri->akrualkanTagihan(
                $santri,
                array_values(array_filter([$tagihan['uang_pangkal'] ?? null, $tagihan['perlengkapan'] ?? null])),
                $idPengguna,
                "kenaikan jenjang ke {$p->kode_jenjang}",
                $p->nomor,
            );

            $p->update(['status' => 'naik']);

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'eksekusi_kenaikan_jenjang',
                'detail' => json_encode([
                    'id_santri' => $santri->id, 'nis' => $santri->nis, 'nomor' => $p->nomor,
                    'dari' => ['jenjang' => $dariJenjang, 'tingkat' => $dariTingkat],
                    'ke' => ['jenjang' => $p->kode_jenjang, 'tingkat' => $tingkatBaru, 'jalur' => $p->kode_jalur],
                    'tahun_ajaran' => $p->tahun_ajaran,
                    'uang_pangkal' => $tagihan['uang_pangkal']?->nominal,
                    'perlengkapan' => $tagihan['perlengkapan']?->nominal,
                    'akrual' => $akrual,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return ['santri' => $santri->refresh()] + $tagihan;
        });
    }

    /** Batalkan siklus yang sedang berjalan. Data santri belum tersentuh, jadi tak ada yang perlu dibalik. */
    public function batalkan(int $idPendaftaran, string $alasan, int $idPengguna): Pendaftaran
    {
        $p = Pendaftaran::lanjutan()->find($idPendaftaran);
        if (! $p) {
            throw new AppException(404, 'Pendaftaran lanjutan tidak ditemukan.');
        }
        Tahap::assertBolehMundur((string) $p->status, 'lanjutan');

        return DB::transaction(function () use ($p, $alasan, $idPengguna) {
            $p->update([
                'status' => 'mengundurkan_diri',
                'catatan' => trim(($p->catatan ? $p->catatan.' | ' : '').'Dibatalkan: '.$alasan),
            ]);

            // Tagihan registrasinya ikut dibatalkan HANYA bila belum ada yang
            // dibayar. Yang sudah bersisa uang masuk tetap berdiri — pembatalan
            // pendaftaran bukan alasan menghapus penerimaan.
            $tagihanBatal = TagihanSantri::where('id_santri', $p->id_santri)->where('perilaku', 'registrasi')
                ->where('kode_jenjang', $p->kode_jenjang)->where('tahun_ajaran', $p->tahun_ajaran)
                ->where('status', 'belum_bayar')
                ->whereDoesntHave('pembayaran', fn ($q) => $q->where('status', '!=', 'ditolak'))
                ->get();
            foreach ($tagihanBatal as $t) {
                $t->update(['status' => 'batal', 'sisa' => '0',
                    'keterangan' => trim(($t->keterangan ? $t->keterangan.' · ' : '').'Dibatalkan bersama pendaftaran '.$p->nomor)]);
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'batal_pendaftaran_lanjutan',
                'detail' => json_encode([
                    'id_pendaftaran' => $p->id, 'nomor' => $p->nomor, 'alasan' => $alasan,
                    'tagihan_registrasi_dibatalkan' => $tagihanBatal->pluck('id')->all(),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $p->refresh();
        });
    }
}
