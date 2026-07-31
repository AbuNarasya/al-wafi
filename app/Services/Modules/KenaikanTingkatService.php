<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JadwalPerubahanSantri;
use App\Models\Jenjang;
use App\Models\Pendaftaran;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Facades\DB;

/**
 * KENAIKAN TINGKAT & KELULUSAN MASSAL — dalam satu jenjang, serentak satu angkatan.
 *
 * Sebelum ini yang ada hanya aksi `set-tingkat`: mengubah kolom `santri.tingkat`
 * satu santri per kali, tanpa memajukan `tahun_ajaran_berjalan` dan tanpa menulis
 * `riwayat_tingkat`. Akibatnya riwayat bolong di tahun-tahun dalam satu jenjang,
 * dan tarif SPP santri yang naik tingkat tetap memakai tarif tahun masuknya.
 *
 * Yang DIPISAH dari sini (sengaja):
 *  • penagihan daftar ulang → modul massalnya sendiri, dijalankan SESUDAH kenaikan.
 *    Satu kesalahan tarif tak boleh memaksa membatalkan seluruh kenaikan kelas.
 *
 * Lima keputusan per santri:
 *  • `naik`        — tingkat +1, tahun berjalan maju;
 *  • `mengulang`   — tingkat tetap, tahun berjalan TETAP MAJU (tahunnya berganti);
 *  • `melanjutkan` — MENDAFTARKAN ke jenjang berikutnya lewat PPSB. Hanya di
 *    tingkat terakhir, dan hanya bila jenjangnya punya lanjutan;
 *  • `lulus`       — status → alumni + tanggal lulus. Hanya di tingkat terakhir;
 *  • `lewati`      — tak disentuh.
 *
 * `melanjutkan` TIDAK menyentuh data santri sama sekali — ia hanya membuka siklus
 * pendaftaran lanjutan, dan itu dikerjakan `PendaftaranLanjutanService::buat()`,
 * bukan ditulis ulang di sini. Santrinya tetap `aktif` di jenjang lama sampai
 * seleksi & med check PPSB selesai; jalur tujuannya pun ditentukan master
 * (`jalur_pendaftaran.kode_jalur_lanjutan`), bukan aturan baru di modul ini.
 */
class KenaikanTingkatService
{
    public const NAIK = 'naik';

    public const MENGULANG = 'mengulang';

    public const MELANJUTKAN = 'melanjutkan';

    public const LULUS = 'lulus';

    public const LEWATI = 'lewati';

    /** Label keputusan untuk layar. */
    public const KEPUTUSAN = [
        self::NAIK => 'Naik tingkat',
        self::MENGULANG => 'Mengulang',
        self::MELANJUTKAN => 'Melanjutkan (daftar ke jenjang berikutnya)',
        self::LULUS => 'Lulus (alumni)',
        self::LEWATI => 'Lewati',
    ];

    public function __construct(
        private readonly PendaftaranLanjutanService $lanjutan = new PendaftaranLanjutanService,
        private readonly TarifService $tarif = new TarifService,
        private readonly TahunAjaranService $tahunAjaran = new TahunAjaranService,
    ) {}

    /**
     * T.A tujuan dibatasi ke tahun berjalan atau berikutnya.
     *
     * Diperiksa di pratinjau MAUPUN eksekusi. Kalau hanya di eksekusi, petugas
     * sudah selesai menyusun keputusan seluruh angkatan sebelum ditolak; kalau
     * hanya di pratinjau, kiriman langsung ke eksekusi tetap lolos.
     *
     * Dulu tak ada penjaga apa pun di sini — hanya `exists:tahun_ajaran,kode` di
     * controller. Akibatnya kenaikan bisa dijalankan ke tahun yang masih lima
     * tahun lagi, MUNDUR ke tahun yang sudah lewat (padahal PPSB menolaknya
     * lewat assertTidakMundur), bahkan ke T.A berstatus nonaktif. Yang paling
     * ganjil: dalam satu batch yang sama, keputusan "Melanjutkan" tertolak
     * karena ia lewat PendaftaranLanjutanService::buat() yang memang terjaga,
     * sedangkan "Naik", "Mengulang", dan "Lulus" lolos.
     */
    private function pastikanTaTujuanSah(string $taTujuan): void
    {
        $this->tahunAjaran->assertBerjalanAtauBerikutnya($taTujuan, 'Kenaikan tingkat & kelulusan');
    }

    /**
     * Susun daftar usulan. Tidak menulis apa pun.
     *
     * @return array{baris:list<array<string,mixed>>, ringkas:array<string,int>, tingkat_terakhir:int}
     */
    public function pratinjau(array $filter): array
    {
        $ta = (string) ($filter['tahun_ajaran'] ?? '');
        $jenjang = (string) ($filter['kode_jenjang'] ?? '');
        if ($ta === '' || $jenjang === '') {
            throw new AppException(422, 'Tahun ajaran tujuan & jenjang wajib dipilih.');
        }
        $this->pastikanTaTujuanSah($ta);

        $terakhir = TarifService::tingkatTerakhir($jenjang);
        $punyaLanjutan = (bool) Jenjang::find($jenjang)?->kode_jenjang_lanjutan;

        $santri = Santri::where('kode_jenjang', $jenjang)
            ->where('status', 'aktif')
            ->when(($filter['tingkat'] ?? '') !== '', fn ($q) => $q->where('tingkat', $filter['tingkat']))
            ->orderBy('tingkat')->orderBy('nama')
            // `jalur` WAJIB ikut: keputusan "Melanjutkan" menentukan jalur tujuan
            // dari `jalur_pendaftaran.kode_jalur_lanjutan` milik jalur santri ini.
            // Tanpa kolom itu setiap baris tampak "jalurnya belum punya lanjutan".
            ->get(['id', 'nama', 'nis', 'no_pendaftaran', 'tingkat', 'kode_jenjang', 'jalur', 'tahun_ajaran', 'tahun_ajaran_berjalan']);

        $baris = [];
        // Diturunkan dari KEPUTUSAN, bukan didaftar ulang: menambah keputusan baru
        // tanpa menyentuh baris ini pernah membuat penghitungnya "undefined key".
        $ringkas = array_fill_keys(array_keys(self::KEPUTUSAN), 0);
        foreach ($santri as $s) {
            $usul = $this->usulkan($s, $ta, $terakhir, $punyaLanjutan);
            $ringkas[$usul['usul']]++;
            $baris[] = [
                'id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis, 'no_pendaftaran' => $s->no_pendaftaran,
                'tingkat' => $s->tingkat, 'angkatan' => $s->tahun_ajaran, 'ta_berjalan' => $s->taBerjalan(),
            ] + $usul;
        }

        return ['baris' => $baris, 'ringkas' => $ringkas, 'tingkat_terakhir' => $terakhir];
    }

    /**
     * Alasan santri ini MELOMPATI tahun, atau null bila lompatannya wajar.
     *
     * Kenaikan memindahkan satu tahun ke satu tahun berikutnya. Santri yang
     * tahun berjalannya tertinggal dua tahun atau lebih — mis. berkas impor yang
     * kolom tahun ajarannya salah — akan ikut terangkat ke sasaran batch dan
     * `riwayat_tingkat`-nya bolong pada tahun yang terlewat, tanpa peringatan
     * apa pun. Bolong itu tak bisa diperbaiki dari layar mana pun sesudahnya.
     *
     * Dilewati, BUKAN membatalkan seluruh batch: prinsip modul ini, satu santri
     * bermasalah tak boleh menahan seluruh angkatan. Jalan keluarnya aksi
     * "Koreksi Tahun Berjalan" di halaman santri — disebut di pesannya, supaya
     * petugas tak perlu menebak apa yang harus dikerjakan berikutnya.
     *
     * Yang tahun berjalannya MELAMPAUI sasaran (sudah dinaikkan lebih dulu) juga
     * dilewati: menariknya mundur bukan pekerjaan modul kenaikan.
     */
    private function alasanLompatTahun(Santri $s, string $taTujuan): ?string
    {
        $urut = $this->urutanTa();
        $kini = $s->taBerjalan();
        // Tahun yang tak ada di master tak bisa dibandingkan; penjaga lain
        // (tingkat, tarif) tetap berlaku, jadi ia tidak lolos begitu saja.
        if (! isset($urut[$kini], $urut[$taTujuan])) {
            return null;
        }

        $selisih = $urut[$taTujuan] - $urut[$kini];
        if ($selisih === 1) {
            return null;
        }

        if ($selisih < 0) {
            return "Tahun berjalannya ({$kini}) sudah MELAMPAUI sasaran {$taTujuan}.";
        }

        return "Tahun berjalannya masih {$kini}, sedangkan sasarannya {$taTujuan} — "
            .'melompati '.($selisih - 1).' tahun. Perbaiki dulu lewat "Koreksi Tahun Berjalan" '
            .'di halaman santri, atau jalankan kenaikannya tahun demi tahun.';
    }

    /** @var array<string,int>|null kode T.A → urutan kalender, memo per instance */
    private ?array $memoUrutanTa = null;

    /**
     * Urutan tahun ajaran menurut KALENDER, bukan abjad kodenya. Kode "2026/2027"
     * kebetulan terurut benar secara abjad, tetapi itu kebetulan penamaan —
     * `tanggal_mulai`-lah yang menentukan urutan sebenarnya.
     *
     * @return array<string,int>
     */
    private function urutanTa(): array
    {
        return $this->memoUrutanTa ??= TahunAjaran::whereNotNull('tanggal_mulai')
            ->orderBy('tanggal_mulai')->pluck('kode')
            ->values()->flip()->all();
    }

    /**
     * Usulan + pilihan yang sah untuk seorang santri.
     *
     * @return array{usul:string, pilihan:list<string>, alasan:?string}
     */
    private function usulkan(Santri $s, string $taTujuan, int $terakhir, bool $punyaLanjutan): array
    {
        $tingkat = (int) $s->tingkat;

        if ($s->taBerjalan() === $taTujuan) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => "Sudah berada di T.A {$taTujuan} — kenaikannya sudah pernah dijalankan."];
        }
        if ($alasanLompat = $this->alasanLompatTahun($s, $taTujuan)) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI], 'alasan' => $alasanLompat];
        }
        if ($tingkat < 1) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => 'Tingkat santri ini belum terisi. Lengkapi dulu di data santri.'];
        }
        if ($terakhir < 1) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => "Jumlah tingkat jenjang \"{$s->kode_jenjang}\" belum diisi di master Jenjang."];
        }

        if ($tingkat < $terakhir) {
            return ['usul' => self::NAIK, 'pilihan' => [self::NAIK, self::MENGULANG, self::LEWATI], 'alasan' => null];
        }

        // TINGKAT TERAKHIR. Bila jenjangnya punya lanjutan, "Melanjutkan" yang
        // diusulkan — itulah yang terjadi pada kebanyakan santri, dan sebelum ini
        // harus dibuka satu per satu dari halaman detail tiap santri. "Lulus"
        // tetap ditawarkan bagi yang tidak melanjutkan.
        if ($punyaLanjutan) {
            // Penghalang diambil dari sumber yang SAMA dengan yang akan
            // mengerjakannya nanti, supaya layar tak pernah menawarkan pilihan
            // yang lalu ditolak saat dijalankan.
            $halangan = $this->halanganMelanjutkan($s, $taTujuan);

            return [
                'usul' => $halangan === null ? self::MELANJUTKAN : self::LEWATI,
                'pilihan' => $halangan === null
                    ? [self::MELANJUTKAN, self::LULUS, self::MENGULANG, self::LEWATI]
                    : [self::LULUS, self::MENGULANG, self::LEWATI],
                'alasan' => $halangan
                    ?? 'Tingkat terakhir. "Melanjutkan" mendaftarkannya ke jenjang berikutnya lewat PPSB '
                        .'(seleksi & med check); pilih "Lulus" hanya bagi yang tidak melanjutkan.',
            ];
        }

        return [
            'usul' => self::LULUS,
            'pilihan' => [self::LULUS, self::MENGULANG, self::LEWATI],
            'alasan' => 'Tingkat terakhir jenjang terakhir — kelulusan.',
        ];
    }

    /**
     * TETAPKAN keputusan petugas — TIDAK mengubah santri sekarang juga.
     *
     * `$keputusan` = [idSantri => 'naik'|'mengulang'|'melanjutkan'|'lulus'|'lewati'].
     *
     * Yang tersimpan adalah santri ini AKAN MENJADI APA pada T.A tujuan; jadwalnya
     * menyala sendiri saat tahun itu benar-benar dimulai (terapkanYangJatuhTempo).
     * Sebelum ini tombolnya mengubah data seketika, sehingga santri berjalan
     * berbulan-bulan dengan tingkat & tahun berjalan yang belum berlaku — dan
     * setiap pencarian tarif yang bersandar pada kedua kolom itu ikut mendahului
     * kalender.
     *
     * Penjaganya ditegakkan SEKARANG, bukan nanti saat menyala: petugas yang
     * menekan tombol masih di layar dan bisa memperbaiki; penerap berjalan tanpa
     * seorang pun menunggui.
     *
     * Satu transaksi untuk seluruh batch: satu angkatan ditetapkan bersama, dan
     * batch yang tersimpan separuh jauh lebih sulit dibereskan daripada diulang.
     *
     * @return array{naik:int,mengulang:int,melanjutkan:int,lulus:int,batch:string,berlaku_mulai:?string}
     */
    public function tetapkan(string $taTujuan, array $keputusan, int $idPengguna, array $opsi = []): array
    {
        $this->pastikanTaTujuanSah($taTujuan);

        $sah = array_keys(self::KEPUTUSAN);
        $garap = [];
        foreach ($keputusan as $idSantri => $pilih) {
            if (in_array($pilih, $sah, true) && $pilih !== self::LEWATI) {
                $garap[(int) $idSantri] = $pilih;
            }
        }
        if ($garap === []) {
            throw new AppException(422, 'Tidak ada santri yang dipilih untuk dinaikkan atau diluluskan.');
        }

        $tanggalLulus = $opsi['tanggal_lulus'] ?? null;
        $batch = 'KENAIKAN-'.now()->format('YmdHis');

        $hasil = DB::transaction(function () use ($garap, $taTujuan, $idPengguna, $tanggalLulus, $batch) {
            $hitung = [self::NAIK => 0, self::MENGULANG => 0, self::MELANJUTKAN => 0, self::LULUS => 0];
            $santri = Santri::whereIn('id', array_keys($garap))->get()->keyBy('id');

            foreach ($garap as $idSantri => $pilih) {
                $s = $santri[$idSantri] ?? null;
                if (! $s) {
                    throw new AppException(404, "Santri #{$idSantri} tidak ditemukan.");
                }
                $this->pastikanBolehDitetapkan($s, $taTujuan);

                $terakhir = TarifService::tingkatTerakhir($s->kode_jenjang);
                $tingkat = (int) $s->tingkat;

                $baris = match ($pilih) {
                    self::NAIK => $this->rencanaNaik($s, $tingkat, $terakhir),
                    self::MENGULANG => ['tingkat_tujuan' => $tingkat],
                    self::MELANJUTKAN => $this->rencanaMelanjutkan($s, $taTujuan, $idPengguna),
                    self::LULUS => $this->rencanaLulus($s, $tingkat, $terakhir, $tanggalLulus),
                    default => [],
                };

                JadwalPerubahanSantri::create($baris + [
                    'id_santri' => $s->id,
                    'tahun_ajaran' => $taTujuan,
                    'keputusan' => $pilih,
                    // Naik/mengulang/lulus langsung siap: keputusan staf ADALAH
                    // syaratnya. Melanjutkan menunggu PPSB — lihat rencanaMelanjutkan().
                    'status' => $baris['status'] ?? 'siap',
                    // Jenjang & jalur tetap kecuali disebut lain oleh rencananya.
                    'kode_jenjang_tujuan' => $baris['kode_jenjang_tujuan'] ?? $s->kode_jenjang,
                    'kode_jalur_tujuan' => $baris['kode_jalur_tujuan'] ?? $s->jalur,
                    'batch' => $batch,
                    'ditetapkan_oleh' => $idPengguna,
                    'ditetapkan_pada' => now(),
                ]);
                $hitung[$pilih]++;
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'tetapkan_perubahan_santri',
                'detail' => json_encode([
                    'batch' => $batch, 'tahun_ajaran_tujuan' => $taTujuan,
                    'naik' => $hitung[self::NAIK], 'mengulang' => $hitung[self::MENGULANG],
                    'melanjutkan' => $hitung[self::MELANJUTKAN], 'lulus' => $hitung[self::LULUS],
                    'keputusan' => $garap,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $hitung + ['batch' => $batch];
        });

        // Ditetapkan SETELAH tahun tujuannya mulai (mis. kenaikan yang telat
        // dikerjakan) — tak ada gunanya menunggu, jadwalnya langsung menyala.
        $this->terapkanYangJatuhTempo();

        return $hasil + ['berlaku_mulai' => TahunAjaran::where('kode', $taTujuan)
            ->value('tanggal_mulai')?->toDateString()];
    }

    /**
     * Jalan pintas ke penerapnya — yang mengerjakan JadwalPerubahanService,
     * karena yang dinyalakan bukan hanya kenaikan (ada pula aktivasi dari PPSB).
     * Dipertahankan di sini supaya modul kenaikan tetap bisa menyalakan jadwal
     * yang baru saja ditetapkannya tanpa perlu tahu siapa yang mengerjakan.
     *
     * @return array{diterapkan:int, gagal:list<array{id_santri:int,pesan:string}>}
     */
    public function terapkanYangJatuhTempo(?string $tanggal = null): array
    {
        return (new JadwalPerubahanService)->terapkanYangJatuhTempo($tanggal);
    }

    /**
     * Penjaga yang berlaku bagi SEMUA keputusan, ditegakkan saat menetapkan.
     * Sama dengan yang dipakai pratinjau, supaya layar tak pernah menawarkan
     * sesuatu yang lalu ditolak.
     */
    private function pastikanBolehDitetapkan(Santri $s, string $taTujuan): void
    {
        if ($s->status !== 'aktif') {
            throw new AppException(422, "Santri {$s->nama} tidak berstatus aktif.");
        }
        if ($s->taBerjalan() === $taTujuan) {
            throw new AppException(422, "Santri {$s->nama} sudah berada di T.A {$taTujuan} — "
                .'perubahannya sudah pernah ditetapkan & diterapkan.');
        }
        if ($alasanLompat = $this->alasanLompatTahun($s, $taTujuan)) {
            throw new AppException(422, "Santri {$s->nama}: {$alasanLompat}");
        }
        $adaJadwal = JadwalPerubahanSantri::hidup()
            ->where('id_santri', $s->id)->where('tahun_ajaran', $taTujuan)->exists();
        if ($adaJadwal) {
            throw new AppException(409, "Santri {$s->nama} sudah punya perubahan terjadwal untuk T.A {$taTujuan}. "
                .'Batalkan dulu jadwal itu bila keputusannya berubah.');
        }
    }

    /** @return array<string,mixed> */
    private function rencanaNaik(Santri $s, int $tingkat, int $terakhir): array
    {
        if ($tingkat >= $terakhir) {
            throw new AppException(422, "Santri {$s->nama} sudah di tingkat terakhir {$s->kode_jenjang} "
                .'— ia naik JENJANG (lewat Pendaftaran Lanjutan) atau lulus, bukan naik tingkat.');
        }

        return ['tingkat_tujuan' => $tingkat + 1];
    }

    /** @return array<string,mixed> */
    private function rencanaLulus(Santri $s, int $tingkat, int $terakhir, ?string $tanggalLulus): array
    {
        if ($terakhir > 0 && $tingkat < $terakhir) {
            throw new AppException(422, "Santri {$s->nama} masih di tingkat {$tingkat} dari {$terakhir} "
                ."tingkat {$s->kode_jenjang}, jadi belum bisa diluluskan.");
        }
        Tahap::assertTransisi((string) $s->status, 'alumni');

        return ['tingkat_tujuan' => $tingkat, 'tanggal_lulus' => $tanggalLulus];
    }

    /**
     * MELANJUTKAN — siklus PPSB dibuka SEKARANG, perpindahannya menyusul.
     *
     * Siklusnya sengaja dibuka seketika: keluarga perlu berbulan-bulan untuk
     * mengurus dan mencicil registrasi serta uang pangkalnya. Tetapi jadwalnya
     * berstatus `menunggu_ppsb`, bukan `siap` — perpindahan jenjang menuntut
     * uang, kelayakan (med check, dokumen), dan nominal uang pangkal yang
     * diketik petugas per santri. Tak satu pun bisa ditebak penjadwal, jadi tak
     * satu pun boleh dilewati hanya karena tanggalnya tiba.
     *
     * Tingkat tujuan dibiarkan null: ia baru ditentukan saat kenaikannya
     * dieksekusi di halaman santri.
     *
     * @return array<string,mixed>
     */
    private function rencanaMelanjutkan(Santri $s, string $taTujuan, int $idPengguna): array
    {
        $p = $this->lanjutan->buat($s->id, ['tahun_ajaran' => $taTujuan,
            'catatan' => 'Dibuka dari Kenaikan Tingkat massal.'], $idPengguna);

        return [
            'status' => 'menunggu_ppsb',
            'id_pendaftaran' => $p->id,
            'kode_jenjang_tujuan' => $p->kode_jenjang,
            'kode_jalur_tujuan' => $p->kode_jalur,
            'tingkat_tujuan' => null,
        ];
    }

    /**
     * Apa yang menghalangi santri ini didaftarkan ke jenjang lanjutan? null = tak ada.
     *
     * Bertumpu pada `PendaftaranLanjutanService` — bukan aturan tersendiri —
     * supaya layar tak menawarkan "Melanjutkan" yang lalu ditolak saat batch
     * dijalankan, dan satu penolakan tak membatalkan seluruh angkatan.
     */
    private function halanganMelanjutkan(Santri $s, string $taTujuan): ?string
    {
        $sasaran = $this->lanjutan->sasaran($s);
        if (! $sasaran) {
            return 'Jenjang ini tak punya lanjutan di master, jadi santrinya menjadi alumni — bukan melanjutkan.';
        }
        if ($sasaran['alasan']) {
            return $sasaran['alasan'];
        }
        // Sel registrasi yang BELUM DIISI menghentikan `buat()`. Diperiksa di sini
        // juga, kalau tidak layar menawarkan "Melanjutkan" lalu SELURUH batch
        // dibatalkan saat dijalankan. "Bebas" bukan penghalang: tahapnya lewat.
        $registrasi = $this->tarif->cari('registrasi', $taTujuan, $sasaran['kode_jenjang'], $sasaran['kode_jalur']);
        if ($registrasi['status'] === 'kosong') {
            return $registrasi['label'].' Isi selnya di menu Setting Awal → Tarif, atau tandai Bebas.';
        }
        // Siklus yang masih berjalan akan ditolak `buat()` dengan 409; disebut di
        // sini supaya petugas tahu prosesnya SUDAH dibuka, bukan gagal.
        $berjalan = Pendaftaran::where('id_santri', $s->id)->lanjutan()->terbuka()->orderByDesc('id')->first();
        if ($berjalan) {
            return "Pendaftaran lanjutan ke {$berjalan->kode_jenjang} T.A {$berjalan->tahun_ajaran} sudah dibuka "
                .'(tahap "'.$berjalan->labelStatus().'") — lanjutkan tahapnya di halaman santri.';
        }

        return null;
    }

    /**
     * Siklus PPSB seorang santri sudah tuntas — jadwalnya boleh menyala.
     *
     * Dipanggil `PendaftaranLanjutanService::eksekusiKenaikan()` sesudah tagihan
     * uang pangkal & perlengkapannya terbit. Perpindahannya sendiri tidak
     * dikerjakan di sini: ia menunggu tanggalnya, sama seperti naik & mengulang.
     * Bila tahun tujuannya sudah dimulai (PPSB baru tuntas setelah tahun ajaran
     * berjalan), jadwalnya langsung menyala — tak ada gunanya menunggu.
     *
     * Jadwalnya DIBUAT bila belum ada: siklus lanjutan juga bisa dibuka langsung
     * dari halaman santri, tanpa lewat Kenaikan Tingkat massal.
     */
    public function tandaiSiapDariPpsb(Pendaftaran $p, int $tingkatTujuan): void
    {
        $jadwal = JadwalPerubahanSantri::hidup()
            ->where('id_santri', $p->id_santri)->where('tahun_ajaran', $p->tahun_ajaran)->first();

        $isi = [
            'status' => 'siap',
            'id_pendaftaran' => $p->id,
            'kode_jenjang_tujuan' => $p->kode_jenjang,
            'kode_jalur_tujuan' => $p->kode_jalur,
            'tingkat_tujuan' => $tingkatTujuan,
        ];

        $jadwal
            ? $jadwal->update($isi)
            : JadwalPerubahanSantri::create($isi + [
                'id_santri' => $p->id_santri,
                'tahun_ajaran' => $p->tahun_ajaran,
                'keputusan' => self::MELANJUTKAN,
                'ditetapkan_pada' => now(),
                'catatan' => "Dari pendaftaran lanjutan {$p->nomor}.",
            ]);

        $this->terapkanYangJatuhTempo();
    }
}
