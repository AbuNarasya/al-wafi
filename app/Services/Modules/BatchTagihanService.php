<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\BatchTagihan;
use App\Models\BatchTagihanBaris;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\SetoranPemakaian;
use App\Models\TagihanSantri;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * BATCH TAGIHAN — susun, otorisasi, rilis.
 *
 * Perbedaannya dengan penerbitan langsung cuma satu: ada jeda. Tetapi jeda itu
 * melahirkan seluruh kerumitan di berkas ini, karena dunia bergerak selama jeda
 * — santri keluar, naik jenjang, atau tagihannya telanjur terbit dari jalur lain.
 *
 * ══ YANG DIKUNCI DAN YANG TIDAK ══
 * DIKUNCI  : nominal. Yang diperiksa petugas itulah yang terbit.
 * DIPERIKSA: kelayakan tiap baris, tepat sebelum rilis. Caranya dengan
 *            MENJALANKAN ULANG pratinjau modulnya lalu mengambil irisannya —
 *            bukan menyalin aturannya ke sini. Aturan "siapa boleh ditagih"
 *            hanya boleh hidup di satu tempat, yaitu modulnya masing-masing.
 *
 * ══ PENERBITANNYA TETAP MILIK MODUL ══
 * Berkas ini TIDAK pernah menulis `tagihan_santri` maupun memanggil
 * PostingService. Ia menyiapkan daftar `[id santri => nominal]` lalu
 * menyerahkannya ke SppService / TagihanMassalService / TagihanLainService.
 * Menyalin logika jurnal ke sini berarti dua tempat yang harus selalu sama,
 * dan yang satu pasti tertinggal.
 */
class BatchTagihanService
{
    /** Sumber peserta untuk modul `tagihan_lain`. */
    public const SUMBER_LAIN = ['manual', 'peserta', 'pemakaian'];

    /** Jenis notifikasi hasil rilis terjadwal. */
    public const JENIS_NOTIF = 'batch_tagihan_dirilis';

    // ══════════════════════════════════════════════════════════════════
    //  MENYUSUN DRAFT
    // ══════════════════════════════════════════════════════════════════

    /**
     * Susun draft dari saringan yang dipilih petugas. Tidak menerbitkan apa pun.
     *
     * @param  array<string,mixed>  $data  modul · judul · parameter · rilis_pada
     */
    public function susun(array $data, int $idPengguna): BatchTagihan
    {
        $modul = (string) ($data['modul'] ?? '');
        if (! isset(BatchTagihan::MODUL[$modul])) {
            throw new AppException(422, 'Modul batch tidak dikenal.');
        }
        $parameter = (array) ($data['parameter'] ?? []);

        $baris = match ($modul) {
            'spp' => $this->barisSpp($parameter),
            'daftar_ulang' => $this->barisDaftarUlang($parameter),
            'tagihan_lain' => $this->barisTagihanLain($parameter),
        };

        $terbit = array_filter($baris, fn ($b) => $b['keputusan'] === BatchTagihanBaris::TERBIT);
        if ($terbit === []) {
            throw new AppException(422, 'Tidak ada satu pun baris yang bisa diterbitkan dengan saringan itu, '
                .'jadi tak ada yang perlu disimpan sebagai draft.');
        }

        $rilisPada = $this->waktuRilis($data['rilis_pada'] ?? null);

        return DB::transaction(function () use ($modul, $data, $parameter, $baris, $terbit, $rilisPada, $idPengguna) {
            $batch = BatchTagihan::create([
                'modul' => $modul,
                'judul' => trim((string) ($data['judul'] ?? '')) ?: $this->judulBawaan($modul, $parameter),
                'parameter' => $parameter,
                'status' => 'draft',
                'jumlah_baris' => count($terbit),
                'total' => array_reduce($terbit, fn ($t, $b) => Money::add($t, $b['nominal'] ?? '0'), '0'),
                'rilis_pada' => $rilisPada,
                'disusun_oleh' => $idPengguna,
            ]);

            BatchTagihanBaris::insert(array_map(fn ($b) => [
                'id_batch' => $batch->id,
                'id_santri' => $b['id_santri'],
                'kode_jenis' => $b['kode_jenis'] ?? null,
                'periode' => $b['periode'] ?? null,
                'nominal' => $b['nominal'] ?? null,
                'keputusan' => $b['keputusan'],
                'alasan' => $b['alasan'] ?? null,
                'hasil' => 'menunggu',
                // insert() melewati cast, jadi jsonb-nya dirakit sendiri di sini.
                'snapshot' => isset($b['snapshot']) ? json_encode($b['snapshot'], JSON_UNESCAPED_UNICODE) : null,
            ], $baris));

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'susun_batch_tagihan',
                'detail' => json_encode([
                    'id_batch' => $batch->id, 'modul' => $modul, 'parameter' => $parameter,
                    'jumlah_baris' => $batch->jumlah_baris, 'total' => $batch->total,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $batch;
        });
    }

    /**
     * SPP satu periode. Pratinjaunya sudah memutuskan tiga keadaan; di sini
     * ketiganya hanya diterjemahkan ke kosakata batch.
     *
     * Nominal NOL tetap `terbit` — itu santri yang dibebaskan, dan tagihannya
     * memang harus tetap lahir supaya ia tak muncul lagi tiap bulan sebagai
     * "siap terbit". Perilaku itu milik SppService, bukan keputusan di sini.
     */
    private function barisSpp(array $p): array
    {
        $periode = (string) ($p['periode'] ?? '');
        if ($periode === '') {
            throw new AppException(422, 'Periode SPP wajib dipilih.');
        }

        return array_map(fn ($r) => match ($r['status']) {
            'siap' => [
                'id_santri' => $r['id'], 'kode_jenis' => $r['kode_jenis'], 'periode' => $periode,
                'nominal' => $r['nominal'], 'keputusan' => BatchTagihanBaris::TERBIT, 'alasan' => null,
                'snapshot' => ['kode_jenjang' => $r['kode_jenjang'], 'tahun_ajaran' => $r['tahun_ajaran'], 'asal' => $r['asal_label'] ?? null],
            ],
            'sudah_ada' => [
                'id_santri' => $r['id'], 'periode' => $periode, 'keputusan' => BatchTagihanBaris::DILEWATI,
                'alasan' => "Sudah punya tagihan SPP periode {$periode}.",
            ],
            default => [
                'id_santri' => $r['id'], 'periode' => $periode, 'keputusan' => BatchTagihanBaris::TERHALANG,
                'alasan' => $r['pesan'] ?? 'Tarif SPP-nya belum bisa ditentukan.',
            ],
        }, (new SppService)->pratinjau($periode));
    }

    /** Daftar ulang. Kosakata pratinjaunya memang sudah sama dengan batch. */
    private function barisDaftarUlang(array $p): array
    {
        $ta = (string) ($p['tahun_ajaran'] ?? '');
        $massal = new TagihanMassalService;

        return array_map(function ($r) use ($ta) {
            $d = $r['daftar_ulang'];
            // Jenisnya dijepret untuk DITAMPILKAN di layar draft. Saat rilis,
            // TagihanMassalService mencarinya sendiri dari jenjang yang berlaku
            // — jadi kolom ini keterangan, bukan perintah.
            $jenis = $d['keputusan'] === TagihanMassalService::TERBIT
                ? JenisBiaya::untuk('daftar_ulang', $r['kode_jenjang'])
                : null;

            return [
                'id_santri' => $r['id'],
                'kode_jenis' => $jenis?->kode,
                'nominal' => $d['nominal'],
                'keputusan' => $d['keputusan'],
                'alasan' => $d['alasan'],
                'snapshot' => [
                    'tahun_ajaran' => $ta, 'tingkat' => $r['tingkat'],
                    'kode_jenjang' => $r['kode_jenjang'], 'asal' => $d['asal'] ?? null,
                ],
            ];
        }, $massal->pratinjau($p)['baris']);
    }

    /**
     * Tagihan lain-lain. Tiga sumber peserta yang berbeda watak:
     *  • manual    — santri dipilih satu per satu, satu nominal untuk semua;
     *  • peserta   — keluarga B (ekskul), nominal dari matriks tarif jenjang;
     *  • pemakaian — keluarga A (laundry), nominal dari timbangan yang sudah masuk.
     *
     * Untuk `pemakaian`, id setorannya ikut dijepret. Itu WAJIB: kalau saat
     * rilis kita menyapu ulang "semua setoran yang belum bertanda", timbangan
     * yang baru masuk setelah otorisasi akan ikut ditandai lunas padahal
     * nominalnya tak menghitungnya — kilogramnya hilang tanpa jejak.
     */
    private function barisTagihanLain(array $p): array
    {
        $kode = (string) ($p['kode_jenis'] ?? '');
        $sumber = (string) ($p['sumber'] ?? 'manual');
        if (! in_array($sumber, self::SUMBER_LAIN, true)) {
            throw new AppException(422, 'Sumber peserta tagihan lain tidak dikenal.');
        }
        $periode = $p['periode'] ?? null;

        if ($sumber === 'manual') {
            $nominal = Money::of($p['nominal'] ?? '0');
            if (Money::lte($nominal, '0')) {
                throw new AppException(422, 'Nominal tagihan harus lebih dari nol.');
            }
            $ids = array_map('intval', (array) ($p['id_santri'] ?? []));
            if ($ids === []) {
                throw new AppException(422, 'Belum ada santri yang dipilih.');
            }

            return array_map(fn ($id) => [
                'id_santri' => $id, 'kode_jenis' => $kode, 'periode' => $periode,
                'nominal' => $nominal, 'keputusan' => BatchTagihanBaris::TERBIT, 'alasan' => null,
            ], $ids);
        }

        if ($sumber === 'peserta') {
            ['nominal' => $peta, 'gugur' => $gugur] = (new KepesertaanLainService)->nominalPeserta($kode);
            if ($peta === []) {
                throw new AppException(422, 'Tidak ada peserta yang bisa ditagih.'
                    .($gugur === [] ? ' Daftar pesertanya masih kosong.' : ' '.implode('; ', $gugur).'.'));
            }

            return array_map(fn ($id) => [
                'id_santri' => (int) $id, 'kode_jenis' => $kode, 'periode' => $periode,
                'nominal' => $peta[$id], 'keputusan' => BatchTagihanBaris::TERBIT, 'alasan' => null,
            ], array_keys($peta));
        }

        // pemakaian
        if (! $periode) {
            throw new AppException(422, 'Periode wajib diisi untuk tagihan berbasis pemakaian.');
        }
        $pemakaian = new PemakaianLainService;
        $jenis = $pemakaian->jenis($kode);
        $sampai = Carbon::parse($periode.'-01')->endOfMonth()->toDateString();

        $baris = [];
        foreach ($pemakaian->rekap($jenis->kode, $sampai) as $r) {
            $s = $r['santri'];
            if (! $s) {
                continue;
            }
            // Di bawah kuota ⇒ TIDAK ada tagihan sama sekali, bukan tagihan nol.
            // Setorannya sengaja dibiarkan tak bertanda supaya ikut periode depan.
            if (! Money::gtZero($r['nominal'])) {
                $baris[] = [
                    'id_santri' => $s->id, 'kode_jenis' => $kode, 'periode' => $periode,
                    'keputusan' => BatchTagihanBaris::BEBAS,
                    'alasan' => "Pemakaian {$r['kuantitas']} masih di dalam kuota gratis — tak ada yang ditagih.",
                ];

                continue;
            }
            $baris[] = [
                'id_santri' => $s->id, 'kode_jenis' => $kode, 'periode' => $periode,
                'nominal' => $r['nominal'], 'keputusan' => BatchTagihanBaris::TERBIT, 'alasan' => null,
                'snapshot' => [
                    'kuantitas' => $r['kuantitas'], 'kena_tagih' => $r['kena_tagih'],
                    'setoran' => SetoranPemakaian::belumTertagih()
                        ->where('kode_jenis', $jenis->kode)->where('id_santri', $s->id)
                        ->whereDate('tanggal', '<=', $sampai)->pluck('id')->all(),
                ],
            ];
        }
        if ($baris === []) {
            throw new AppException(422, 'Belum ada setoran yang bisa ditagih untuk periode itu.');
        }

        return $baris;
    }

    // ══════════════════════════════════════════════════════════════════
    //  OTORISASI & PEMBATALAN
    // ══════════════════════════════════════════════════════════════════

    /**
     * Satu tangan: penyusun boleh mengotorisasi draftnya sendiri (keputusan
     * user 21 Sep 2026). Yang tetap dijaga adalah JEJAKNYA — siapa dan kapan.
     */
    public function otorisasi(int $id, array $data, int $idPengguna): BatchTagihan
    {
        $batch = $this->cari($id);
        if ($batch->status !== 'draft') {
            throw new AppException(422, 'Hanya batch berstatus Draft yang bisa diotorisasi.');
        }
        if ($batch->jumlah_baris < 1) {
            throw new AppException(422, 'Batch ini tak punya satu pun baris yang akan terbit.');
        }

        // Waktu rilis boleh diubah saat otorisasi — di layar inilah petugas
        // benar-benar memutuskan kapan ia berlaku.
        $rilis = array_key_exists('rilis_pada', $data)
            ? $this->waktuRilis($data['rilis_pada'])
            : $batch->rilis_pada;

        $batch->update([
            'status' => 'diotorisasi',
            'rilis_pada' => $rilis,
            'diotorisasi_oleh' => $idPengguna,
            'diotorisasi_pada' => now(),
        ]);

        ActivityLog::create([
            'id_pengguna' => $idPengguna,
            'aksi' => 'otorisasi_batch_tagihan',
            'detail' => json_encode([
                'id_batch' => $batch->id, 'modul' => $batch->modul,
                'jumlah_baris' => $batch->jumlah_baris, 'total' => $batch->total,
                'rilis_pada' => $rilis?->toDateTimeString(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $batch->refresh();
    }

    /** Batal selama belum dirilis. Tak ada yang perlu dibalik — belum ada tagihan. */
    public function batalkan(int $id, int $idPengguna, ?string $alasan = null): BatchTagihan
    {
        $batch = $this->cari($id);
        if (in_array($batch->status, BatchTagihan::SELESAI, true)) {
            throw new AppException(422, 'Batch ini sudah selesai, jadi tak bisa dibatalkan lagi.');
        }

        $batch->update(['status' => 'dibatalkan', 'catatan' => $alasan]);

        ActivityLog::create([
            'id_pengguna' => $idPengguna,
            'aksi' => 'batalkan_batch_tagihan',
            'detail' => json_encode(['id_batch' => $batch->id, 'alasan' => $alasan], JSON_UNESCAPED_UNICODE),
        ]);

        return $batch->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  RILIS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Terbitkan isi batch.
     *
     * `$idPengguna` NULL berarti yang merilis adalah penjadwal, bukan orang.
     *
     * Tanggal jurnalnya = tanggal `rilis_pada` bila batch terjadwal, bukan
     * tanggal perilis benar-benar berjalan. Cron bisa telat sehari, dan periode
     * buku besar tak boleh ikut bergeser karenanya. Bila periode itu sudah
     * ditutup, PostingService menolak — batch ditandai `gagal` berikut sebabnya,
     * dan tak ada satu pun tagihan yang lahir.
     *
     * @return array{terbit:int,dilewati:int,total:string,status:string,pesan:string}
     */
    public function rilis(int $id, ?int $idPengguna = null): array
    {
        $batch = $this->cari($id);
        if ($batch->status !== 'diotorisasi') {
            throw new AppException(422, 'Hanya batch yang sudah diotorisasi yang bisa dirilis.');
        }

        // Pelaku jurnal harus tetap seorang manusia walau pemicunya penjadwal:
        // `journal_entries.id_pengguna` tak menerima NULL, dan yang paling jujur
        // disebut di sana adalah yang mengotorisasinya.
        $pelaku = $idPengguna ?? $batch->diotorisasi_oleh;
        $tanggal = ($batch->rilis_pada ?? now())->toDateString();

        $terkunci = $batch->barisTerbit()->get()->keyBy('id_santri');
        $layak = $this->masihLayak($batch, $terkunci->keys()->all());

        $gugur = $terkunci->reject(fn ($b, $idSantri) => isset($layak[$idSantri]));
        $target = $terkunci->filter(fn ($b, $idSantri) => isset($layak[$idSantri]));

        // Tak ada yang tersisa BUKAN kegagalan: lazimnya karena seluruhnya sudah
        // telanjur ditagih lewat layar biasa. `gagal` hanya untuk penolakan yang
        // sebenarnya (periode tertutup, akun piutang kosong) — memakainya di sini
        // akan membunyikan alarm untuk keadaan yang justru sudah beres.
        if ($target->isEmpty()) {
            return $this->tutup($batch, 'sebagian', $gugur, collect(), $idPengguna,
                'Tak satu pun baris masih layak diterbitkan saat rilis.');
        }

        $nominal = $target->mapWithKeys(fn ($b) => [$b->id_santri => (string) $b->nominal])->all();

        try {
            $hasil = $this->terbitkan($batch, $nominal, $pelaku, $tanggal);
        } catch (AppException $e) {
            // Penolakan yang disengaja (periode tertutup, tahun ajaran mundur,
            // akun piutang kosong). Batch gagal utuh — tak ada yang separuh jadi,
            // karena penerbitannya sendiri berjalan di dalam satu transaksi.
            return $this->tutup($batch, 'gagal', $terkunci, collect(), $idPengguna, $e->getMessage());
        }

        // Tagihan yang benar-benar lahir, dipetakan balik ke barisnya. Modul
        // penerbitnya sendiri bisa melewati baris (mis. santri yang ternyata
        // sudah punya tagihan itu), jadi yang dipercaya adalah hasil di DB.
        $peta = $this->petaTagihan($batch, $target->keys()->all());
        $terbit = $target->filter(fn ($b) => isset($peta[$b->id_santri]));
        $gugurTambahan = $target->reject(fn ($b) => isset($peta[$b->id_santri]));

        if ($batch->modul === 'tagihan_lain' && ($batch->parameter['sumber'] ?? null) === 'pemakaian') {
            $this->tandaiSetoran($terbit, $peta);
        }

        foreach ($terbit as $b) {
            $b->update(['hasil' => 'terbit', 'id_tagihan' => $peta[$b->id_santri]]);
        }

        $status = ($gugur->isEmpty() && $gugurTambahan->isEmpty()) ? 'dirilis' : 'sebagian';

        return $this->tutup($batch, $status, $gugur->merge($gugurTambahan), $terbit, $idPengguna,
            $hasil['pesan'] ?? null);
    }

    /**
     * Pemicu cadangan dari dalam aplikasi.
     *
     * Ada karena cron di produksi bisa berhenti TANPA PESAN: `schedule:run`
     * bersandar pada `proc_open`, dan izin fungsi itu tersimpan di berkas yang
     * ditulis ulang oleh panel hosting. Tanpa cadangan ini, matinya cron baru
     * ketahuan saat ada yang bertanya kenapa tagihan bulan ini tak terbit.
     *
     * Dibuat semurah mungkin karena dipanggil dari halaman yang sering dibuka:
     * satu kueri berindeks untuk memastikan ada pekerjaan, lalu kunci supaya dua
     * permintaan yang datang bersamaan tak menerbitkan batch yang sama dua kali.
     */
    public function pemicuCadangan(): void
    {
        if (! BatchTagihan::jatuhTempo()->exists()) {
            return;
        }

        $kunci = Cache::lock('batch-tagihan-rilis', 300);
        if (! $kunci->get()) {
            return; // ada yang sedang mengerjakannya — cron, atau permintaan lain.
        }

        try {
            $this->rilisYangJatuhTempo();
        } finally {
            $kunci->release();
        }
    }

    /**
     * Rilis seluruh batch yang waktunya sudah lewat.
     *
     * Idempoten dan aman dipanggil dari mana saja — dipakai perintah terjadwal
     * DAN pemicu cadangan dari dalam aplikasi. Cadangan itu bukan kemewahan:
     * cron di shared hosting bisa berhenti diam-diam, dan tagihan yang tak
     * pernah terbit jauh lebih mahal daripada pemeriksaan sekali per halaman.
     *
     * @return array{diproses:int,terbit:int,gagal:int,batch:list<array<string,mixed>>}
     */
    public function rilisYangJatuhTempo(?int $idPengguna = null): array
    {
        $antre = BatchTagihan::jatuhTempo()->orderBy('rilis_pada')->pluck('id');
        $ringkas = ['diproses' => 0, 'terbit' => 0, 'gagal' => 0, 'batch' => []];

        foreach ($antre as $id) {
            try {
                $h = $this->rilis($id, $idPengguna);
            } catch (\Throwable $e) {
                // Satu batch yang meledak tak boleh menghentikan antreannya.
                $ringkas['gagal']++;
                $ringkas['batch'][] = ['id' => $id, 'status' => 'galat', 'pesan' => $e->getMessage()];

                continue;
            }
            $ringkas['diproses']++;
            $ringkas['terbit'] += $h['terbit'];
            if ($h['status'] === 'gagal') {
                $ringkas['gagal']++;
            }
            $ringkas['batch'][] = ['id' => $id] + $h;
        }

        return $ringkas;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PENOPANG
    // ══════════════════════════════════════════════════════════════════

    /**
     * Santri yang MASIH layak ditagih saat ini juga.
     *
     * Caranya dengan menjalankan ulang pratinjau modulnya, bukan menyalin
     * aturannya. Yang diambil hanya DAFTAR NAMANYA — angkanya tetap yang
     * terkunci di batch.
     *
     * @param  list<int>  $ids
     * @return array<int,true>
     */
    private function masihLayak(BatchTagihan $batch, array $ids): array
    {
        $p = $batch->parameter;

        $lolos = match ($batch->modul) {
            'spp' => array_column(array_filter(
                (new SppService)->pratinjau((string) $p['periode']),
                fn ($r) => $r['status'] === 'siap',
            ), 'id'),

            'daftar_ulang' => array_column(array_filter(
                (new TagihanMassalService)->pratinjau($p)['baris'],
                fn ($r) => $r['daftar_ulang']['keputusan'] === TagihanMassalService::TERBIT,
            ), 'id'),

            // Tagihan lain tak punya pratinjau tersendiri, jadi kedua penjaganya
            // ditiru di sini: santri masih aktif, dan belum punya tagihan jenis
            // yang sama pada periode yang sama.
            //
            // TagihanLainService memang menyaring hal yang sama saat menerbitkan,
            // tetapi ia melakukannya dengan MELEMPAR bila ternyata tak ada yang
            // tersisa — dan lemparan itu akan menandai seluruh batch gagal,
            // padahal yang terjadi cuma "semuanya sudah tertagih".
            'tagihan_lain' => array_diff(
                Santri::whereIn('id', $ids)->where('status', 'aktif')->pluck('id')->all(),
                TagihanSantri::whereIn('id_santri', $ids)
                    ->where('kode_jenis', $p['kode_jenis'] ?? '')
                    ->where('periode', $p['periode'] ?? null)
                    ->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU)
                    ->pluck('id_santri')->all(),
            ),
        };

        return array_fill_keys(array_intersect($ids, $lolos), true);
    }

    /**
     * Serahkan penerbitan ke modulnya. Berkas ini tak pernah menulis tagihan
     * maupun jurnal sendiri.
     *
     * @param  array<int,string>  $nominal
     */
    private function terbitkan(BatchTagihan $batch, array $nominal, int $pelaku, string $tanggal): array
    {
        $p = $batch->parameter;
        $opsi = ['tanggal' => $tanggal, 'jatuh_tempo' => $p['jatuh_tempo'] ?? null];

        return match ($batch->modul) {
            'spp' => (new SppService)->generate(
                $opsi + ['periode' => $p['periode'], 'alasan_lintas_ta' => $p['alasan_lintas_ta'] ?? ''],
                $pelaku,
                // Rencana yang DIKUNCI: nominalnya dari batch, bukan dihitung ulang.
                $this->rencanaSpp($batch, $nominal),
            ),

            'daftar_ulang' => (new TagihanMassalService)->terbitkan(
                (string) $p['tahun_ajaran'], $nominal, $pelaku, $opsi,
            ),

            'tagihan_lain' => (new TagihanLainService)->terbitkanSnapshot(
                $this->jenisBatch($p['kode_jenis'] ?? ''),
                $nominal,
                $opsi + ['periode' => $p['periode'] ?? null, 'keterangan' => $p['keterangan'] ?? null],
                $pelaku,
            ),
        };
    }

    /**
     * Rencana SPP berbentuk seperti keluaran SppService::pratinjau(), tetapi
     * nominalnya diambil dari batch. Jenjang & tahun ajarannya ikut dari
     * jepretan — keduanya menentukan jenis biaya, dan santri bisa saja sudah
     * pindah jenjang sejak draft disusun.
     *
     * @param  array<int,string>  $nominal
     */
    private function rencanaSpp(BatchTagihan $batch, array $nominal): array
    {
        return $batch->barisTerbit()->whereIn('id_santri', array_keys($nominal))->get()
            ->map(fn ($b) => [
                'id' => $b->id_santri,
                'kode_jenis' => $b->kode_jenis,
                'nominal' => (string) $b->nominal,
                'kode_jenjang' => $b->snapshot['kode_jenjang'] ?? null,
                'tahun_ajaran' => $b->snapshot['tahun_ajaran'] ?? null,
            ])->values()->all();
    }

    /**
     * Tagihan yang benar-benar ada sesudah penerbitan, per santri.
     *
     * @param  list<int>  $ids
     * @return array<int,int>
     */
    private function petaTagihan(BatchTagihan $batch, array $ids): array
    {
        $p = $batch->parameter;

        $q = TagihanSantri::whereIn('id_santri', $ids)->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU);

        return match ($batch->modul) {
            'spp' => $q->where('perilaku', 'spp')->where('periode', $p['periode'])
                ->pluck('id', 'id_santri')->all(),

            'daftar_ulang' => $q->where('perilaku', 'daftar_ulang')
                ->where('tahun_ajaran', $p['tahun_ajaran'])
                ->pluck('id', 'id_santri')->all(),

            // Perilaku `lain` boleh berulang, jadi yang dicari adalah yang
            // PALING BARU — bukan sembarang baris berjenis sama.
            'tagihan_lain' => $q->where('kode_jenis', $p['kode_jenis'])
                ->where('periode', $p['periode'] ?? null)
                ->orderBy('id')->pluck('id', 'id_santri')->all(),
        };
    }

    /**
     * Tandai setoran yang IKUT DIJEPRET saja — bukan menyapu ulang semua yang
     * belum bertanda. Timbangan yang masuk setelah otorisasi harus tetap
     * telanjang supaya terbawa ke periode berikutnya.
     *
     * @param  array<int,int>  $peta
     */
    private function tandaiSetoran($baris, array $peta): void
    {
        foreach ($baris as $b) {
            $ids = $b->snapshot['setoran'] ?? [];
            if ($ids === []) {
                continue;
            }
            SetoranPemakaian::whereIn('id', $ids)->whereNull('id_tagihan')
                ->update(['id_tagihan' => $peta[$b->id_santri]]);
        }
    }

    /** Tutup batch: tandai baris yang gugur, simpan ringkasannya, kembalikan hasil. */
    private function tutup(BatchTagihan $batch, string $status, $gugur, $terbit, ?int $idPengguna, ?string $pesan): array
    {
        foreach ($gugur as $b) {
            $b->update([
                'hasil' => $status === 'gagal' ? 'gagal' : 'dilewati',
                'hasil_alasan' => $pesan ?: 'Tidak lagi layak ditagih saat rilis.',
            ]);
        }

        $total = $terbit->reduce(fn ($t, $b) => Money::add($t, (string) $b->nominal), '0');
        $ringkas = "{$terbit->count()} tagihan terbit"
            .($gugur->count() > 0 ? ", {$gugur->count()} dilewati" : '')
            .'.'.($pesan ? ' '.$pesan : '');

        $batch->update([
            'status' => $status,
            'dirilis_oleh' => $idPengguna,
            'dirilis_pada' => now(),
            'catatan' => $ringkas,
        ]);

        ActivityLog::create([
            'id_pengguna' => $idPengguna ?? $batch->diotorisasi_oleh,
            'aksi' => 'rilis_batch_tagihan',
            'detail' => json_encode([
                'id_batch' => $batch->id, 'modul' => $batch->modul, 'status' => $status,
                'terbit' => $terbit->count(), 'dilewati' => $gugur->count(), 'total' => $total,
                'oleh_penjadwal' => $idPengguna === null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Rilis tak bertuan HARUS mengabarkan dirinya. Inti modul ini adalah
        // "sesuatu terjadi tanpa ada yang menekan tombol" — dan kalau hasilnya
        // tak pernah sampai ke siapa pun, kegagalannya jadi senyap, yang justru
        // lebih buruk daripada tak punya jadwal sama sekali.
        if ($idPengguna === null) {
            $this->kabarkan($batch, $status, $ringkas);
        }

        return [
            'terbit' => $terbit->count(),
            'dilewati' => $gugur->count(),
            'total' => $total,
            'status' => $status,
            'pesan' => $ringkas,
        ];
    }

    /**
     * Kabar hasil rilis terjadwal ke penyusun & pengotorisasinya.
     *
     * Sengaja KABAR, bukan tugas: tugas hanya reda kalau dokumennya berubah
     * keadaan, sedangkan batch yang gagal berstatus akhir selamanya — jejaknya
     * tetap terbaca di daftar batch. Yang gagal harus disusun ulang, dan itu
     * dokumen baru.
     */
    private function kabarkan(BatchTagihan $batch, string $status, string $ringkas): void
    {
        $penerima = array_values(array_unique(array_filter([
            $batch->disusun_oleh, $batch->diotorisasi_oleh,
        ])));
        if ($penerima === []) {
            return;
        }

        $judul = match ($status) {
            'gagal' => "Rilis terjadwal GAGAL — {$batch->judul}",
            'sebagian' => "Dirilis sebagian — {$batch->judul}",
            default => "Dirilis otomatis — {$batch->judul}",
        };

        (new NotificationService)->kirim(array_map(fn ($id) => [
            'id_pengguna' => $id,
            'judul' => $judul,
            'pesan' => $ringkas,
            'jenis' => self::JENIS_NOTIF,
            'ref_jenis' => 'BatchTagihan',
            'ref_id' => (string) $batch->id,
        ], $penerima));
    }

    /** Waktu rilis harus di masa depan — menjadwalkan ke masa lalu tak punya arti. */
    private function waktuRilis($nilai): ?Carbon
    {
        $nilai = trim((string) $nilai);
        if ($nilai === '') {
            return null;
        }
        $waktu = Carbon::parse($nilai);
        if ($waktu->isPast()) {
            throw new AppException(422, 'Waktu rilis sudah lewat. Pilih waktu di masa depan, '
                .'atau kosongkan supaya batch ini hanya bisa dirilis dengan tombol.');
        }

        return $waktu;
    }

    private function judulBawaan(string $modul, array $p): string
    {
        return match ($modul) {
            'spp' => 'SPP '.($p['periode'] ?? ''),
            'daftar_ulang' => 'Daftar Ulang T.A '.($p['tahun_ajaran'] ?? ''),
            default => 'Tagihan '.($p['kode_jenis'] ?? '').' '.($p['periode'] ?? ''),
        };
    }

    /** Jenis biaya yang dirujuk parameter batch — hilang berarti masternya dihapus. */
    private function jenisBatch(string $kode): JenisBiaya
    {
        $jenis = JenisBiaya::find($kode);
        if (! $jenis) {
            throw new AppException(422, "Jenis biaya \"{$kode}\" sudah tidak ada, jadi batch ini tak bisa dirilis.");
        }

        return $jenis;
    }

    public function cari(int $id): BatchTagihan
    {
        $batch = BatchTagihan::find($id);
        if (! $batch) {
            throw new AppException(404, 'Batch tagihan tidak ditemukan.');
        }

        return $batch;
    }
}
