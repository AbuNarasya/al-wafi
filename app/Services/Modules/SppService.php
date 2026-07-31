<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\MutasiDompet;
use App\Models\PrabayarSpp;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifBiaya;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * SPP — akrual: diakui saat DITERBITKAN (D Piutang / K Pendapatan), jadi
 * sudah_akrual=true sejak lahir; pembayaran mengurangi piutang. Jenis & tarif
 * mengikuti JENJANG santri (nominal khusus hanya mengganti angka).
 */
class SppService
{
    /**
     * Nominal & jenis SPP seorang santri (khusus menang atas tarif jenjang).
     *
     * `$taTagihan` = tahun ajaran yang SEDANG DITAGIH. Diisi saat menerbitkan
     * tagihan satu periode, supaya harganya diambil dari sel tarif tahun itu —
     * satu tagihan tak boleh dicap tahun A tetapi dihargai tarif tahun B.
     * Dibiarkan null untuk pertanyaan umum "berapa SPP santri ini" (kolom di
     * daftar santri, setoran prabayar), yang memang mengikuti tahun santrinya.
     */
    public function nominalSppSantri(int $idSantri, ?string $taTagihan = null): array
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if (! $santri->kode_jenjang) {
            throw new AppException(422, "Santri \"{$santri->nama}\" belum punya jenjang, jadi jenis & tarif SPP-nya tak bisa ditentukan.");
        }
        // Tarif dicari pada tahun yang SEDANG DIJALANI, bukan angkatan: santri
        // angkatan 2026 yang kini kelas 3 pada T.A 2028 harus memakai tarif 2028.
        $ta = $taTagihan ?: $santri->taBerjalan();

        // JENJANG pun mengikuti tahun itu, bukan keadaan hari ini. Penerbitan
        // SUSULAN untuk periode tahun lalu dulu memakai jenjang sekarang: santri
        // yang sudah naik ke SMP ditagih dengan tarif DAN akun SMP untuk bulan
        // ketika ia masih di SDTQ — dan akun itu menentukan pendapatan unit
        // bisnis mana yang bertambah, jadi laba rugi per unit ikut keliru.
        $jenjang = (new PenempatanSantriService)->pada($santri, $ta)['kode_jenjang'] ?: $santri->kode_jenjang;

        ['jenis' => $jenis, 'tarif' => $tarif] = (new SantriService)
            ->komponen('spp', $ta, $jenjang, $santri->jalur);

        // Nominal khusus per santri menang atas grid — dan sengaja diperiksa
        // SEBELUM status tarif: santri bertarif khusus tetap bisa ditagih walau
        // sel grid jenjangnya belum diisi.
        if ($santri->nominal_spp !== null) {
            // `asal_bagian` null: kalimatnya bukan kalimat asal tarif, jadi tak
            // ada nama jenjang/jalur yang bisa ditebalkan <x-asal-tarif>.
            return ['nominal' => Money::of($santri->nominal_spp), 'asal' => 'khusus',
                'kode_jenis' => $jenis->kode, 'asal_label' => 'Nominal khusus santri',
                'asal_bagian' => null, 'keterangan' => $santri->keterangan_spp];
        }
        if ($tarif['status'] === 'bebas') {
            throw new AppException(422, "Tarif SPP untuk jenjang \"{$santri->kode_jenjang}\" jalur \"{$santri->jalur}\" bertanda BEBAS, "
                .'jadi tak ada SPP yang bisa diterbitkan. Isi nominal khusus santri bila ia tetap harus membayar.');
        }
        if ($tarif['status'] !== 'ada') {
            throw new AppException(422, $tarif['label'].' Isi selnya di menu Setting Awal → Tarif.');
        }

        return ['nominal' => $tarif['nominal'], 'asal' => 'jenjang',
            'kode_jenis' => $jenis->kode, 'asal_label' => $tarif['asal'],
            'asal_bagian' => $tarif['bagian'] ?? null, 'keterangan' => null];
    }

    /**
     * Nominal SPP untuk BANYAK santri sekaligus — untuk kolom di daftar santri.
     *
     * `nominalSppSantri()` melakukan 3–4 kueri per santri (santri, jenis biaya,
     * tarif); dipanggil sebaris-sebaris pada daftar berpaginasi itu berarti
     * ratusan kueri per halaman. Di sini seluruh sel tarif yang mungkin terpakai
     * diambil SEKALI, lalu dijodohkan di memori.
     *
     * Aturannya sama dengan `nominalSppSantri()` supaya angka di daftar tak
     * pernah berbeda dari angka yang benar-benar ditagih: nominal khusus santri
     * menang, sisanya sel grid dengan baris berjalur mengalahkan baris Umum.
     * Yang TIDAK disertakan: pemeriksaan jenis biaya — kolom ini hanya
     * memberitahu ANGKA, bukan menerbitkan tagihan.
     *
     * @param  iterable<Santri>  $santri
     * @return array<int,array{status:string, nominal:?string, label:string, keterangan:?string}>
     */
    public function ringkasMassal(iterable $santri): array
    {
        $baris = collect($santri)->filter(fn ($s) => $s->kode_jenjang && $s->taBerjalan());
        if ($baris->isEmpty()) {
            return [];
        }

        // Satu kueri untuk semua kombinasi yang mungkin dipakai halaman ini.
        // Baris berjalur ikut diambil: grid Tarif memang menolak membuatnya untuk
        // SPP, tetapi data lama/hasil impor bisa memilikinya — dan pencarian yang
        // sebenarnya (TarifService::cari) tetap menghormatinya.
        $sel = TarifBiaya::where('perilaku', 'spp')
            ->whereNull('tingkat')
            ->whereIn('tahun_ajaran', $baris->map(fn ($s) => $s->taBerjalan())->unique()->values())
            ->whereIn('kode_jenjang', $baris->pluck('kode_jenjang')->unique()->values())
            ->get()
            ->groupBy(fn ($t) => $t->tahun_ajaran.'|'.$t->kode_jenjang);

        $hasil = [];
        foreach ($baris as $s) {
            if ($s->nominal_spp !== null) {
                $hasil[$s->id] = ['status' => 'khusus', 'nominal' => Money::of($s->nominal_spp),
                    'label' => 'Nominal khusus santri', 'keterangan' => $s->keterangan_spp];

                continue;
            }

            $grup = $sel[$s->taBerjalan().'|'.$s->kode_jenjang] ?? collect();
            // Baris berjalur menang atas baris Umum — pengecualian mengalahkan aturan.
            $pilih = $grup->firstWhere('kode_jalur', $s->jalur) ?? $grup->firstWhere('kode_jalur', null);

            $hasil[$s->id] = match (true) {
                $pilih === null => ['status' => 'kosong', 'nominal' => null,
                    'label' => 'Sel tarif SPP untuk jenjang & T.A ini belum diisi.', 'keterangan' => null],
                (bool) $pilih->bebas => ['status' => 'bebas', 'nominal' => null,
                    'label' => 'Sel tarifnya bertanda Bebas — SPP tidak diterbitkan.', 'keterangan' => null],
                default => ['status' => 'tarif', 'nominal' => Money::of($pilih->nominal),
                    'label' => 'Tarif '.($pilih->kode_jalur ? "jalur {$pilih->kode_jalur}" : 'baris Umum')." T.A {$s->taBerjalan()}",
                    'keterangan' => null],
            };
        }

        return $hasil;
    }

    /**
     * Menetapkan / mencabut nominal SPP khusus seorang santri (beasiswa,
     * keringanan, jalur tahfizh). Nominal kosong = kembali ke tarif jenjang;
     * alasannya ikut dibuang, supaya tak tertinggal alasan tanpa angka.
     * Nominal **0** berbeda dari kosong: itu beasiswa penuh, tagihannya tetap
     * terbit senilai nol.
     *
     * TAGIHAN SPP YANG SUDAH TERBIT SENGAJA TIDAK DISENTUH. Angka yang sudah
     * dijanjikan ke wali tetap seperti semula, dan tagihan yang sudah dibayar
     * sebagian tak berubah sisanya diam-diam. Yang berubah adalah penerbitan
     * periode BERIKUTNYA (lihat nominalSppSantri()). Tagihan yang terlanjur
     * terbit dikoreksi lewat menu Outstanding SPP, yang memang punya jalurnya.
     *
     * Ada di sini — bukan di controller — karena pintunya DUA: modul SPP dan
     * form penagihan PPSB di halaman calon santri. Aturannya harus satu.
     */
    public function setNominalKhusus(Santri $santri, ?string $nominal, ?string $keterangan = null): Santri
    {
        $kosong = $nominal === null || $nominal === '';
        $santri->update([
            'nominal_spp' => $kosong ? null : Money::of($nominal),
            'keterangan_spp' => $kosong ? null : ($keterangan ?: null),
        ]);

        return $santri->refresh();
    }

    /** Identitas akuntansi SPP untuk sebuah jenjang (tarifnya ada di grid Tarif). */
    public function jenisSppSantri(string $kodeJenjang): ?JenisBiaya
    {
        return JenisBiaya::untuk('spp', $kodeJenjang);
    }

    /**
     * TAHUN AJARAN sebuah periode tagihan — DITURUNKAN dari periodenya.
     *
     * Dulu tagihan dicap `$santri->taBerjalan()`, yaitu tahun ajaran SANTRINYA.
     * Akibatnya SPP periode Juli 2026 tercap 2027/2028 hanya karena santrinya
     * sudah terdaftar untuk tahun itu — padahal 2027/2028 baru mulai setahun
     * kemudian. Periode-lah yang menentukan tahun bukunya, bukan santrinya.
     */
    public function taPeriode(string $periode): TahunAjaran
    {
        $ta = (new TahunAjaranService)->yangMemuatPeriode($periode);
        if (! $ta) {
            throw new AppException(422, "Belum ada tahun ajaran yang memuat periode {$periode}. "
                .'Tambahkan dulu tahun ajarannya di menu PPSB → Tahun Ajaran, '
                .'lengkap dengan tanggal mulai & selesainya.');
        }

        return $ta;
    }

    /**
     * Apakah periode ini berada DI LUAR tahun ajaran berjalan?
     *
     * Bukan larangan, melainkan penanda: menerbitkannya tetap boleh, tetapi harus
     * disengaja — lihat generate(). Bulan yang terlewat memang terjadi, dan
     * memaksanya lewat Tagihan Lain-lain akan membuat pendapatan SPP tercatat di
     * jenis biaya yang salah.
     *
     * @return array{lintas:bool, ta:TahunAjaran, berjalan:?TahunAjaran}
     */
    public function periksaPeriode(string $periode): array
    {
        $ta = $this->taPeriode($periode);
        $berjalan = (new TahunAjaranService)->berjalan();

        return [
            'lintas' => $berjalan !== null && $ta->kode !== $berjalan->kode,
            'ta' => $ta,
            'berjalan' => $berjalan,
        ];
    }

    /**
     * Pratinjau: siapa ditagih berapa untuk periode.
     *
     * Diurutkan JENJANG dulu (mengikuti urutan master, bukan abjad kode), baru
     * NIS di dalamnya — sejajar dengan rekap per jenjang yang berdiri tepat di
     * atas daftarnya. Mengurutkan NIS lebih dulu akan menyerakkan jenjang, dan
     * angka rekap jadi tak bisa ditelusuri ke barisnya.
     */
    public function pratinjau(string $periode): array
    {
        // Dipanggil lebih dulu supaya periode tanpa tahun ajaran ditolak SEKARANG,
        // saat petugas masih di layar pratinjau — bukan nanti setelah ia menekan
        // "Terbitkan" dan mengira daftarnya sudah beres.
        $taPeriode = $this->taPeriode($periode);

        $santri = Santri::where('status', 'aktif')->orderBy('nama')
            ->get(['id', 'nama', 'nis', 'tingkat', 'kode_jenjang', 'tahun_ajaran', 'tahun_ajaran_berjalan', 'nominal_spp', 'keterangan_spp']);
        $sudahAda = TagihanSantri::where('perilaku', 'spp')->where('periode', $periode)->pluck('id_santri')->all();

        // Jenjang disebut lewat NAMA di layar — kode `J001` tak bercerita apa pun.
        $jenjang = Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();
        $urutanJenjang = array_flip(array_keys($jenjang));

        $hasil = [];
        foreach ($santri as $s) {
            // Identitas (NIS, jenjang, tingkat) dibawa oleh SETIAP baris, apa pun
            // statusnya: nama saja tak cukup membedakan santri yang namanya mirip,
            // dan rekap per jenjang di layar harus bisa menyebut berapa yang
            // TERTAHAN di tiap jenjang — bukan hanya yang siap. Tanpa itu, jenjang
            // yang separuh selnya belum diisi tampak bertotal kecil tanpa sebab.
            $identitas = [
                'id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis,
                'kode_jenjang' => $s->kode_jenjang, 'jenjang' => $jenjang[$s->kode_jenjang] ?? $s->kode_jenjang,
                'tingkat' => $s->tingkat,
            ];

            if (in_array($s->id, $sudahAda, true)) {
                $hasil[] = $identitas + ['status' => 'sudah_ada', 'nominal' => null, 'kode_jenis' => null];

                continue;
            }
            try {
                $n = $this->nominalSppSantri($s->id, $taPeriode->kode);
                $hasil[] = $identitas + ['nominal' => $n['nominal'], 'asal' => $n['asal'],
                    'asal_label' => $n['asal_label'], 'kode_jenis' => $n['kode_jenis'], 'status' => 'siap',
                    // Tahun ajaran PERIODE-nya — sama untuk seluruh baris, karena
                    // yang ditagih memang satu bulan yang sama bagi semua santri.
                    'tahun_ajaran' => $taPeriode->kode];
            } catch (AppException $e) {
                $hasil[] = $identitas + ['status' => 'tanpa_tarif', 'nominal' => null,
                    'kode_jenis' => null, 'pesan' => $e->getMessage()];
            }
        }

        // Jenjang → NIS. Yang belum ber-NIS (hasil impor lama) ditaruh di belakang
        // kelompoknya, bukan di depan: string kosong akan mengalahkan angka mana pun.
        usort($hasil, function ($a, $b) use ($urutanJenjang) {
            $ja = $urutanJenjang[$a['kode_jenjang']] ?? PHP_INT_MAX;
            $jb = $urutanJenjang[$b['kode_jenjang']] ?? PHP_INT_MAX;
            if ($ja !== $jb) {
                return $ja <=> $jb;
            }
            $na = (string) ($a['nis'] ?? '');
            $nb = (string) ($b['nis'] ?? '');
            if (($na === '') !== ($nb === '')) {
                return $na === '' ? 1 : -1;
            }

            return [$na, $a['nama']] <=> [$nb, $b['nama']];
        });

        return $hasil;
    }

    /** Terbitkan tagihan SPP satu periode untuk seluruh santri aktif (satu jurnal per jenis). */
    public function generate(array $data, int $idPengguna): array
    {
        // PENJAGA LINTAS TAHUN AJARAN. Menerbitkan periode di luar tahun berjalan
        // tetap BOLEH — bulan yang terlewat memang terjadi, dan memaksanya lewat
        // Tagihan Lain-lain akan menaruh pendapatan SPP di jenis biaya yang salah.
        // Tetapi harus DISENGAJA: alasannya wajib, dan tercatat di log aktivitas.
        $periksa = $this->periksaPeriode($data['periode']);
        $alasanLintas = trim((string) ($data['alasan_lintas_ta'] ?? ''));
        if ($periksa['lintas'] && $alasanLintas === '') {
            throw new AppException(422, "Periode {$data['periode']} termasuk tahun ajaran {$periksa['ta']->kode}, "
                ."sedangkan tahun ajaran yang sedang berjalan adalah {$periksa['berjalan']->kode}. "
                .'Penerbitan lintas tahun ajaran boleh dilakukan, tetapi alasannya wajib diisi lebih dulu.');
        }

        $rencana = array_values(array_filter($this->pratinjau($data['periode']), fn ($r) => $r['status'] === 'siap'));
        if (count($rencana) === 0) {
            throw new AppException(422, "Tidak ada tagihan SPP yang bisa diterbitkan untuk periode {$data['periode']}.");
        }

        $perJenis = [];
        foreach ($rencana as $r) {
            $perJenis[$r['kode_jenis']][] = $r;
        }
        $jenisMap = JenisBiaya::whereIn('kode', array_keys($perJenis))->get()->keyBy('kode');
        foreach ($jenisMap as $kode => $j) {
            if (! $j->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$j->nama}\" ({$kode}) belum punya akun piutang. SPP diakui akrual, jadi akun itu wajib.");
            }
        }
        $total = array_reduce($rencana, fn ($a, $r) => Money::add($a, $r['nominal']), '0');

        $hasil = DB::transaction(function () use ($data, $perJenis, $jenisMap, $total, $idPengguna) {
            $base = DocNumber::docBase('SPP', $data['tanggal']);
            $nomor = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $referensi = [];
            $now = now();

            foreach ($perJenis as $kode => $baris) {
                $jenis = $jenisMap[$kode];
                $subtotal = array_reduce($baris, fn ($a, $r) => Money::add($a, $r['nominal']), '0');

                // Nominal nol = santri dibebaskan (mis. anak karyawan lewat Nominal
                // Khusus Santri). Tak ada yang bisa diakru untuknya: D 0 / K 0 bukan
                // jurnal, dan PostingService memang menolaknya. Bila SELURUH santri
                // di jenis ini bernominal nol, jurnalnya dilewati — bukan dipaksakan,
                // dan bukan pula membatalkan penerbitan jenis biaya yang lain (dulu
                // satu santri bebas menggagalkan seluruh angkatan dalam satu transaksi).
                $nomorJurnal = null;
                if (Money::gtZero($subtotal)) {
                    $nomor = DocNumber::nextDocNumber($base, $nomor);
                    $nomorJurnal = $nomor;
                    $referensi[] = $nomor;

                    PostingService::postJournal([
                        'referensi' => $nomor, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                        'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                        'keterangan' => "{$jenis->nama} periode {$data['periode']} — ".count($baris).' santri',
                        'lines' => [
                            ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $subtotal, 'kredit' => '0'],
                            ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $subtotal],
                        ],
                    ]);
                }

                // Tagihannya TETAP terbit walau nol — itulah catatan bahwa SPP periode
                // ini sudah beres untuknya. Tanpa baris ini ia akan muncul lagi sebagai
                // "siap terbit" tiap bulan, selamanya. Statusnya langsung lunas (tak ada
                // yang perlu dibayar) dan `sudah_akrual` false karena memang tak masuk
                // jurnal mana pun.
                TagihanSantri::insert(array_map(function ($r) use ($kode, $data, $jenis, $nomorJurnal, $now) {
                    $nol = Money::isZero($r['nominal']);

                    return [
                        'id_santri' => $r['id'], 'kode_jenis' => $kode, 'periode' => $data['periode'],
                        'perilaku' => 'spp', 'kode_jenjang' => $r['kode_jenjang'], 'tahun_ajaran' => $r['tahun_ajaran'],
                        'nominal' => $r['nominal'], 'sisa' => $r['nominal'],
                        'sudah_akrual' => ! $nol, 'status' => $nol ? 'lunas' : 'belum_bayar',
                        'jatuh_tempo' => $nol ? null : ($data['jatuh_tempo'] ?? null),
                        'keterangan' => "{$jenis->nama} {$data['periode']} · ".($nol ? 'bebas (nominal nol)' : "akrual {$nomorJurnal}"),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }, $baris));
            }

            // Saldo prabayar langsung dipakai untuk tagihan yang baru terbit.
            $terpakai = '0';
            foreach ($perJenis as $kode => $baris) {
                $t = $this->pakaiPrabayar(array_column($baris, 'id'), $data, $jenisMap[$kode], $idPengguna);
                $terpakai = Money::add($terpakai, $t);
            }

            return [
                'periode' => $data['periode'], 'terbit' => count($this->flatten($perJenis)), 'total' => $total,
                'referensi' => implode(', ', $referensi), 'prabayar_terpakai' => $terpakai,
            ];
        });

        // Jejak penerbitan lintas tahun ajaran. Ditulis SETELAH transaksinya
        // berhasil supaya log tak pernah memuat penerbitan yang batal.
        if ($periksa['lintas']) {
            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'terbitkan_spp_lintas_tahun_ajaran',
                'detail' => json_encode([
                    'periode' => $data['periode'],
                    'tahun_ajaran_tagihan' => $periksa['ta']->kode,
                    'tahun_ajaran_berjalan' => $periksa['berjalan']?->kode,
                    'jumlah_tagihan' => $hasil['terbit'], 'total' => $hasil['total'],
                    'alasan' => $alasanLintas,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $autoDebet = (new AutoDebetService)->jalankan($idPengguna, $data['tanggal']);

        return array_merge($hasil, [
            'auto_debet' => $autoDebet,
            'tahun_ajaran' => $periksa['ta']->kode,
            'lintas_ta' => $periksa['lintas'],
        ]);
    }

    /** Setoran prabayar: lunasi tunggakan tertua dulu, sisanya jadi saldo prabayar. */
    public function prabayar(array $data, int $idPengguna): array
    {
        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, 'Prabayar SPP hanya untuk santri aktif.');
        }
        $kodeJenis = $this->nominalSppSantri($data['id_santri'])['kode_jenis'];
        $jenis = JenisBiaya::findOrFail($kodeJenis);
        if (! $jenis->kode_coa_diterima_dimuka) {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" belum punya akun Pendapatan Diterima Dimuka.");
        }
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal setoran harus lebih dari nol.');
        }

        $dompet = null;
        if (($data['sumber'] ?? null) === 'dompet_wali') {
            $dompet = DompetWali::where('id_wali', $santri->id_wali)->first();
            if (! $dompet) {
                throw new AppException(422, 'Keluarga ini belum punya Dompet Wali.');
            }
            if (Money::lt($dompet->saldo, $nominal)) {
                throw new AppException(422, "Saldo Dompet Wali tidak cukup (tersedia {$dompet->saldo}).");
            }
            $akunDebet = DompetPolicy::COA_TITIPAN['wali'];
        } else {
            if (empty($data['kode_rekening'])) {
                throw new AppException(400, 'Kas/rekening penerima wajib dipilih.');
            }
            if (! BankAccount::find($data['kode_rekening'])) {
                throw new AppException(400, 'Kas/rekening penerima tidak ditemukan.');
            }
            $akunDebet = $data['kode_rekening'];
        }

        $tunggakan = TagihanSantri::where('id_santri', $data['id_santri'])->where('kode_jenis', $jenis->kode)
            ->whereIn('status', ['belum_bayar', 'sebagian'])->orderBy('periode')->orderBy('id')->get();

        return DB::transaction(function () use ($data, $idPengguna, $santri, $jenis, $nominal, $dompet, $akunDebet, $tunggakan) {
            $sisaSetoran = $nominal;
            $lines = [['kode_coa' => $akunDebet, 'debet' => $nominal, 'kredit' => '0']];
            $kePiutang = '0';
            foreach ($tunggakan as $t) {
                if (Money::lte($sisaSetoran, '0')) {
                    break;
                }
                $bayar = Money::lt($t->sisa, $sisaSetoran) ? Money::of($t->sisa) : $sisaSetoran;
                $sisaBaru = Money::sub($t->sisa, $bayar);
                $t->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
                $kePiutang = Money::add($kePiutang, $bayar);
                $sisaSetoran = Money::sub($sisaSetoran, $bayar);
            }
            if (Money::gtZero($kePiutang)) {
                $lines[] = ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $kePiutang];
            }
            if (Money::gtZero($sisaSetoran)) {
                $lines[] = ['kode_coa' => $jenis->kode_coa_diterima_dimuka, 'debet' => '0', 'kredit' => $sisaSetoran];
                $pra = PrabayarSpp::firstOrCreate(['id_santri' => $data['id_santri']], ['saldo' => '0']);
                $pra->update(['saldo' => Money::add($pra->saldo, $sisaSetoran)]);
            }

            $base = DocNumber::docBase('PRA', $data['tanggal']);
            $last = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $entry = PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                'keterangan' => "Setoran SPP {$santri->nama} — tunggakan {$kePiutang}, di muka {$sisaSetoran}",
                'lines' => $lines,
            ]);

            if ($dompet) {
                $dompet->update(['saldo' => Money::sub($dompet->saldo, $nominal)]);
                $baseM = DocNumber::docBase('DMP', $data['tanggal']);
                $lastM = MutasiDompet::where('nomor', 'like', $baseM.'%')->orderByDesc('nomor')->value('nomor');
                MutasiDompet::create([
                    'nomor' => DocNumber::nextDocNumber($baseM, $lastM), 'pemilik' => 'wali', 'id_dompet' => $dompet->id,
                    'jenis' => 'bayar_tagihan', 'nominal' => Money::sub('0', $nominal), 'saldo_setelah' => $dompet->saldo,
                    'tanggal' => $data['tanggal'], 'keterangan' => "Setoran SPP — {$santri->nama}", 'dicatat_oleh' => $idPengguna, 'journal_entry_id' => $entry->id,
                ]);
            }

            return ['referensi' => $nomor, 'dipakai_tunggakan' => $kePiutang, 'jadi_prabayar' => $sisaSetoran];
        });
    }

    public function saldoPrabayar(int $idSantri): array
    {
        $row = PrabayarSpp::where('id_santri', $idSantri)->first();

        return ['id_santri' => $idSantri, 'saldo' => Money::of($row->saldo ?? 0)];
    }

    private function flatten(array $perJenis): array
    {
        return array_merge(...array_values($perJenis));
    }

    /** Pakai saldo prabayar untuk tagihan baru terbit (D Diterima Dimuka / K Piutang). */
    private function pakaiPrabayar(array $idSantri, array $data, JenisBiaya $jenis, int $idPengguna): string
    {
        if (! $jenis->kode_coa_diterima_dimuka) {
            return '0';
        }
        $saldoList = PrabayarSpp::whereIn('id_santri', $idSantri)->where('saldo', '>', 0)->get();
        if ($saldoList->isEmpty()) {
            return '0';
        }
        $total = '0';
        foreach ($saldoList as $p) {
            $tagihan = TagihanSantri::where('id_santri', $p->id_santri)->where('kode_jenis', $jenis->kode)->where('periode', $data['periode'])->first();
            if (! $tagihan) {
                continue;
            }
            $pakai = Money::lt($p->saldo, $tagihan->sisa) ? Money::of($p->saldo) : Money::of($tagihan->sisa);
            if (Money::lte($pakai, '0')) {
                continue;
            }
            $sisaBaru = Money::sub($tagihan->sisa, $pakai);
            $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
            $p->update(['saldo' => Money::sub($p->saldo, $pakai)]);
            $total = Money::add($total, $pakai);
        }
        if (Money::gtZero($total)) {
            PostingService::postJournal([
                'referensi' => "{$data['periode']}-PRA", 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                'keterangan' => "Pemakaian saldo prabayar SPP periode {$data['periode']}",
                'lines' => [
                    ['kode_coa' => $jenis->kode_coa_diterima_dimuka, 'debet' => $total, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $total],
                ],
            ]);
        }

        return $total;
    }
}
