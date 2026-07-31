<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JenisBiaya;
use App\Models\JournalEntry;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TERBITKAN TAGIHAN MASSAL — **daftar ulang tahunan** untuk banyak santri aktif.
 *
 * Cakupannya sengaja satu komponen saja:
 *  • uang pangkal & perlengkapan TIDAK massal — nominalnya dirundingkan per calon,
 *    dan penerbitannya sudah melekat pada alur PPSB di halaman detail santri;
 *  • SPP punya modulnya sendiri (`/kesantrian/spp`, lengkap dengan prabayar &
 *    auto-debet). Menerbitkannya dari dua tempat adalah cara termudah membuat
 *    tagihan SPP dobel, jadi di sini ia tidak disentuh.
 *
 * Karena hanya melayani santri yang SUDAH aktif, tak ada saringan jalur maupun
 * gelombang: keduanya hanya bermakna saat santri masuk.
 *
 * ══ DUA TAHUN AJARAN YANG BERBEDA ══
 * `tahun_ajaran` yang dipilih di sini adalah **tahun ajaran TAGIHAN**: dipakai
 * mencari tarif dan dicap ke tagihannya. Itu BUKAN `santri.tahun_ajaran`, yang
 * merupakan tahun MASUK (angkatan) dan tak pernah maju. Kalau keduanya disamakan,
 * daftar ulang tahun kedua akan tercap tahun angkatan lalu ditolak indeks unik.
 * Angkatan tetap bisa dipakai MENYARING, lewat isian terpisah.
 *
 * Daftar ulang diakui AKRUAL saat terbit (D Piutang / K Pendapatan), satu jurnal
 * per jenis biaya untuk seluruh batch — pola yang sama dengan SppService.
 */
class TagihanMassalService
{
    /** Komponen yang diterbitkan massal. Satu-satunya, dan memang sengaja. */
    public const KOMPONEN = ['daftar_ulang' => 'Daftar Ulang'];

    /** Hanya santri aktif: daftar ulang urusan yang sudah bersekolah. */
    public const STATUS = ['aktif'];

    /** Keputusan tiap baris pada pratinjau. */
    public const TERBIT = 'terbit';

    public const BEBAS = 'bebas';

    public const DILEWATI = 'dilewati';

    public const TERHALANG = 'terhalang';

    public function __construct(private readonly TarifService $tarif = new TarifService) {}

    /**
     * Susun daftar usulan. Tidak menulis apa pun.
     *
     * @return array{baris:list<array<string,mixed>>, ringkas:array<string,int>}
     */
    public function pratinjau(array $filter): array
    {
        $ta = (string) ($filter['tahun_ajaran'] ?? '');
        $jenjang = (string) ($filter['kode_jenjang'] ?? '');
        if ($ta === '' || $jenjang === '') {
            throw new AppException(422, 'Tahun ajaran tagihan & jenjang wajib dipilih.');
        }

        $santri = Santri::where('kode_jenjang', $jenjang)
            ->whereIn('status', self::STATUS)
            // Angkatan = saringan opsional, BUKAN tahun ajaran tagihan.
            ->when(($filter['angkatan'] ?? '') !== '', fn ($q) => $q->where('tahun_ajaran', $filter['angkatan']))
            ->when(($filter['tingkat'] ?? '') !== '', fn ($q) => $q->where('tingkat', $filter['tingkat']))
            ->orderBy('tingkat')->orderBy('nama')
            ->get(['id', 'nama', 'nis', 'no_pendaftaran', 'tingkat', 'status', 'kode_jenjang', 'tahun_ajaran']);

        // Sekali kueri untuk seluruh daftar — memeriksa "sudah punya tagihan?"
        // per santri di dalam perulangan akan jadi ratusan kueri.
        $sudahAda = TagihanSantri::whereIn('id_santri', $santri->pluck('id'))
            ->where('perilaku', 'daftar_ulang')
            ->where('kode_jenjang', $jenjang)->where('tahun_ajaran', $ta)
            ->where('status', '!=', 'batal')
            ->pluck('id_santri')->flip();

        // Tingkat & jenjang yang BERLAKU pada T.A yang ditagih — bukan keadaan
        // sekarang. Daftar ulang ditagih Juni, saat kenaikannya sudah ditetapkan
        // tetapi baru berlaku 1 Juli; tanpa ini seluruh angkatan tertolak "masih
        // di tingkat 1" oleh alasanTakDitagih(), padahal kenaikannya sudah ada.
        $penempatan = (new PenempatanSantriService)->massal($santri, $ta);

        $baris = [];
        $ringkas = [self::TERBIT => 0, self::BEBAS => 0, self::DILEWATI => 0, self::TERHALANG => 0];
        foreach ($santri as $s) {
            $di = $penempatan[$s->id];
            $keputusan = $this->putuskan($s, $ta, isset($sudahAda[$s->id]), $di);
            $ringkas[$keputusan['keputusan']]++;
            $baris[] = [
                'id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis, 'no_pendaftaran' => $s->no_pendaftaran,
                // Tingkat yang DITAMPILKAN adalah tingkat pada T.A tagihan —
                // itulah yang menentukan tarifnya, jadi itu pula yang harus
                // dilihat petugas saat memeriksa pratinjau.
                'tingkat' => $di['tingkat'], 'angkatan' => $s->tahun_ajaran,
                'tingkat_sekarang' => $s->tingkat,
                'daftar_ulang' => $keputusan,
                'ada_yang_terbit' => $keputusan['keputusan'] === self::TERBIT,
            ];
        }

        return ['baris' => $baris, 'ringkas' => $ringkas];
    }

    /**
     * Keputusan + angka usulan untuk seorang santri.
     *
     * @param  array{kode_jenjang:?string,tingkat:?int,asal:string}  $di  penempatannya pada T.A yang ditagih
     */
    private function putuskan(Santri $s, string $tahunAjaran, bool $sudahPunya, array $di): array
    {
        if ($sudahPunya) {
            return ['keputusan' => self::DILEWATI, 'nominal' => null, 'asal' => null,
                'alasan' => "Sudah punya tagihan daftar ulang {$s->kode_jenjang} T.A {$tahunAjaran}."];
        }
        if ($alasan = $this->alasanTakDitagih($s, $tahunAjaran, $di)) {
            return ['keputusan' => self::DILEWATI, 'nominal' => null, 'asal' => null, 'alasan' => $alasan];
        }

        // Tarifnya per KENAIKAN, disimpan pada tingkat TUJUAN — yaitu tingkat yang
        // BERLAKU pada T.A yang ditagih, bukan tingkat santri hari ini.
        $tarif = $this->tarif->cari('daftar_ulang', $tahunAjaran, $di['kode_jenjang'], null, $di['tingkat']);

        // `asal_bagian` ikut dibawa supaya pratinjau bisa menebalkan nama jenjang,
        // jalur, dan tahun ajarannya lewat <x-asal-tarif>.
        return match ($tarif['status']) {
            'bebas' => ['keputusan' => self::BEBAS, 'nominal' => null, 'asal' => $tarif['asal'], 'asal_bagian' => $tarif['bagian'] ?? null,
                'alasan' => 'Sel tarifnya bertanda Bebas — tagihan tidak diterbitkan.'],
            'ada' => ['keputusan' => self::TERBIT, 'nominal' => $tarif['nominal'], 'asal' => $tarif['asal'],
                'asal_bagian' => $tarif['bagian'] ?? null, 'alasan' => null],
            default => ['keputusan' => self::TERHALANG, 'nominal' => null, 'asal' => null, 'asal_bagian' => null, 'alasan' => $tarif['label']],
        };
    }

    /**
     * Alasan seorang santri TIDAK ditagih daftar ulang untuk satu T.A, atau `null`
     * bila ia memang harus ditagih.
     *
     * Yang dipakai adalah tingkat yang BERLAKU pada T.A tagihan — hasil kenaikan
     * yang sudah ditetapkan, walau belum menyala. Dua golongan dikecualikan:
     *  • santri yang pada tahun itu masih di TINGKAT 1 — ia belum pernah naik;
     *    yang dibayarnya biaya masuk;
     *  • santri pada TAHUN MASUKNYA — termasuk pindahan dari luar yang langsung
     *    masuk ke tingkat 2 atau lebih. Pada tahun itu ia membayar registrasi,
     *    uang pangkal, & perlengkapan, bukan daftar ulang.
     *
     * @param  array{kode_jenjang:?string,tingkat:?int,asal:string}  $di
     */
    private function alasanTakDitagih(Santri $s, string $tahunAjaran, array $di): ?string
    {
        if ($s->tahun_ajaran === $tahunAjaran) {
            return "T.A {$tahunAjaran} adalah tahun MASUK santri ini — yang dibayar registrasi, "
                .'uang pangkal, & perlengkapan, bukan daftar ulang.';
        }

        if ((int) $di['tingkat'] === 1) {
            return "Masih di tingkat 1 pada T.A {$tahunAjaran} — belum pernah naik tingkat, jadi belum "
                .'ada daftar ulang. Tetapkan kenaikannya lebih dulu di Kenaikan Tingkat & Kelulusan.';
        }

        return null;
    }

    /**
     * Terbitkan baris yang dicentang petugas.
     *
     * `$kiriman` = [idSantri => nominal]; kosong berarti baris itu tak diterbitkan.
     *
     * Satu transaksi untuk seluruh batch: kalau ada satu baris yang gagal karena
     * keadaan berubah sejak pratinjau disusun, seluruh batch dibatalkan. Batch
     * separuh jadi jauh lebih sulit dibereskan daripada batch yang diulang.
     *
     * @return array{terbit:int, santri:int, total:string, batch:string}
     */
    public function terbitkan(string $tahunAjaran, array $kiriman, int $idPengguna, array $opsi = []): array
    {
        $bersih = [];
        foreach ($kiriman as $idSantri => $nominal) {
            $nominal = trim((string) $nominal);
            if ($nominal !== '') {
                $bersih[(int) $idSantri] = $nominal;
            }
        }
        if ($bersih === []) {
            throw new AppException(422, 'Tidak ada baris yang dipilih untuk diterbitkan.');
        }
        // Daftar ulang boleh diterbitkan untuk tahun berjalan maupun tahun
        // berikutnya (daftar ulang 2027/2028 lazim dibuka menjelang akhir
        // 2026/2027) — yang tak boleh adalah menerbitkannya untuk tahun yang lewat.
        (new TahunAjaranService)->assertTidakMundur($tahunAjaran, 'Penerbitan daftar ulang massal');

        $batch = 'MASSAL-'.now()->format('YmdHis');

        return DB::transaction(function () use ($bersih, $idPengguna, $batch, $tahunAjaran, $opsi) {
            $hasil = $this->terbitkanDaftarUlang($bersih, $tahunAjaran, $idPengguna, $opsi);

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'terbitkan_tagihan_massal',
                'detail' => json_encode([
                    'batch' => $batch, 'komponen' => 'daftar_ulang', 'tahun_ajaran_tagihan' => $tahunAjaran,
                    'jumlah_santri' => count($bersih), 'tagihan_terbit' => $hasil['terbit'], 'total' => $hasil['total'],
                    'id_santri' => array_keys($bersih),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return ['terbit' => $hasil['terbit'], 'santri' => count($bersih), 'total' => $hasil['total'], 'batch' => $batch];
        });
    }

    /**
     * Jurnalnya SATU per jenis biaya (bukan per santri), mengikuti pola SppService:
     * satu batch bisa ratusan santri, dan ratusan jurnal kembar hanya memenuhi
     * buku besar tanpa menambah keterangan apa pun.
     *
     * @param  array<int,string>  $nominalPerSantri
     * @return array{terbit:int,total:string}
     */
    private function terbitkanDaftarUlang(array $nominalPerSantri, string $tahunAjaran, int $idPengguna, array $opsi): array
    {
        $santri = Santri::whereIn('id', array_keys($nominalPerSantri))
            ->get(['id', 'nama', 'kode_jenjang', 'tingkat', 'status', 'tahun_ajaran'])->keyBy('id');

        // Sumber jenjang & tingkat yang SAMA dengan pratinjau — kalau berbeda,
        // yang diperiksa petugas di layar bukan yang benar-benar terbit.
        $penempatan = (new PenempatanSantriService)->massal($santri, $tahunAjaran);

        // Dikelompokkan per jenis biaya (satu per jenjang) — satu batch boleh
        // memuat lebih dari satu jenjang bila petugas menjalankannya berulang.
        $perJenis = [];
        foreach ($nominalPerSantri as $idSantri => $nominal) {
            $s = $santri[$idSantri] ?? null;
            if (! $s) {
                throw new AppException(404, "Santri #{$idSantri} tidak ditemukan.");
            }
            if (! in_array($s->status, self::STATUS, true)) {
                throw new AppException(422, "Santri {$s->nama} tidak berstatus aktif, jadi daftar ulangnya tak bisa diterbitkan.");
            }
            $di = $penempatan[$s->id];
            // Aturan yang sama ditegakkan lagi di sini, bukan hanya di pratinjau:
            // kiriman form bisa disusun sendiri tanpa melewati pratinjau.
            if ($alasan = $this->alasanTakDitagih($s, $tahunAjaran, $di)) {
                throw new AppException(422, "{$s->nama} tidak ditagih daftar ulang. {$alasan}");
            }
            $jenis = JenisBiaya::untuk('daftar_ulang', $di['kode_jenjang']);
            if (! $jenis) {
                throw new AppException(422, "Belum ada jenis biaya Daftar Ulang yang aktif untuk jenjang \"{$di['kode_jenjang']}\". "
                    .'Buat barisnya di Setting Awal → Jenis Biaya.');
            }
            if (! $jenis->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" belum punya akun piutang. "
                    .'Daftar ulang diakui akrual saat terbit, jadi akun itu wajib.');
            }
            $nominal = Money::of($nominal);
            if (Money::lte($nominal, '0')) {
                throw new AppException(422, "Nominal daftar ulang untuk {$s->nama} harus lebih dari nol.");
            }
            $perJenis[$jenis->kode]['jenis'] = $jenis;
            $perJenis[$jenis->kode]['baris'][] = ['santri' => $s, 'nominal' => $nominal, 'di' => $di];
        }

        $tanggal = $opsi['tanggal'] ?? Carbon::now()->toDateString();
        $now = now();
        $terbit = 0;
        $totalSemua = '0';

        foreach ($perJenis as $kode => $kelompok) {
            $jenis = $kelompok['jenis'];
            $subtotal = array_reduce($kelompok['baris'], fn ($a, $b) => Money::add($a, $b['nominal']), '0');

            $base = DocNumber::docBase('DUL', $tanggal);
            $last = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $nomor = DocNumber::nextDocNumber($base, $last);

            PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => $tanggal, 'kode_unit' => $jenis->kode_unit,
                // Modul sumbernya sengaja disamakan dengan tagihan santri lain
                // supaya pemetaan unit default & penjaga bagian tetap berlaku.
                'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                'keterangan' => "{$jenis->nama} T.A {$tahunAjaran} — ".count($kelompok['baris']).' santri',
                'lines' => [
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $subtotal, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $subtotal],
                ],
            ]);

            TagihanSantri::insert(array_map(fn ($b) => [
                'id_santri' => $b['santri']->id, 'kode_jenis' => $kode,
                'perilaku' => 'daftar_ulang', 'kode_jenjang' => $b['di']['kode_jenjang'], 'tahun_ajaran' => $tahunAjaran,
                'nominal' => $b['nominal'], 'sisa' => $b['nominal'],
                'sudah_akrual' => true, 'status' => 'belum_bayar',
                'jatuh_tempo' => $opsi['jatuh_tempo'] ?? null,
                'keterangan' => "{$jenis->nama} T.A {$tahunAjaran} tingkat {$b['di']['tingkat']} · akrual {$nomor}",
                'created_at' => $now, 'updated_at' => $now,
            ], $kelompok['baris']));

            $terbit += count($kelompok['baris']);
            $totalSemua = Money::add($totalSemua, $subtotal);
        }

        return ['terbit' => $terbit, 'total' => $totalSemua];
    }
}
