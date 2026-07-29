<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PembayaranSantri;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\TagihanSantri;
use App\Models\TerminUangPangkal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Angsuran uang pangkal — kesepakatan jadwal (ber-versi, TIDAK berjurnal; yang
 * berjurnal PembayaranSantri). Σ termin = tagihan.nominal. Re-negosiasi: versi
 * lama digantikan. evaluasiPotongan: ≥50% dibayar sebelum tenggat → earned,
 * lewat tenggat → hangus (potongan ditambahkan kembali ke tagihan + termin baru).
 *
 * DUA KOMPONEN (lihat self::KOMPONEN): uang pangkal & biaya perlengkapan
 * dijadwalkan TERPISAH. Rencana melekat pada tagihan, jadi satu santri bisa
 * punya dua rencana aktif sekaligus tanpa keduanya saling mengganggu — dan
 * potongan gelombang tetap hanya menyentuh tagihan uang pangkal.
 */
class AngsuranUangPangkalService
{
    /**
     * Dua komponen yang dijadwalkan terpisah. Uang pangkal mengenal potongan
     * gelombang; perlengkapan TIDAK — karena itu jadwalnya pun berdiri sendiri,
     * bukan satu jadwal bercampur. Keduanya memakai tabel yang sama karena
     * rencana angsuran memang melekat pada TAGIHAN, bukan pada santri.
     */
    public const KOMPONEN = [
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Biaya Perlengkapan',
    ];

    /**
     * Daftar santri yang masih punya komponen belum terjadwal — bahan dropdown
     * form rencana baru. SATU BARIS PER SANTRI, memuat keadaan kedua
     * komponennya sekaligus; petugas memilih nama, bukan tagihan.
     *
     * `tenggat_potongan` & `ambang_potongan` diisi hanya bila potongan
     * gelombangnya MASIH BERLAKU: dari situ form mengisi otomatis jatuh tempo
     * DAN nominal termin pertama uang pangkal, supaya jadwal yang ditawarkan
     * memang jadwal yang mempertahankan potongan itu.
     *
     * Ambangnya dihitung dengan rumus yang SAMA PERSIS dengan evaluasiPotongan()
     * (syarat_persen × nominal tagihan). Kalau dihitung dengan cara lain, form
     * akan menawarkan angka yang ternyata kurang sedikit, lalu potongannya
     * hangus padahal wali membayar tepat seperti yang dijadwalkan.
     *
     * @return list<array<string,mixed>>
     */
    public function daftarPenjadwalan(): array
    {
        $tagihan = TagihanSantri::query()
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku(...array_keys(self::KOMPONEN))))
            ->where('status', '!=', 'batal')
            ->with(['santri:id,nama,no_pendaftaran,nis,kode_jenjang', 'jenis:kode,tipe'])
            ->get();

        $idRencanaAktif = RencanaAngsuranUangPangkal::where('status', 'aktif')->pluck('id_tagihan')->all();
        $potongan = PotonganUangPangkal::where('status', 'berlaku')->get()->keyBy('id_tagihan');

        $perSantri = [];
        foreach ($tagihan as $t) {
            $komponen = $this->komponenTagihan($t);
            $id = $t->id_santri;
            $perSantri[$id] ??= [
                'id_santri' => $id,
                'nama' => $t->santri?->nama,
                'no_pendaftaran' => $t->santri?->no_pendaftaran,
                'nis' => $t->santri?->nis,
                'jenjang' => $t->santri?->kode_jenjang,
                'tenggat_potongan' => null,
                'ambang_potongan' => null,
                'syarat_persen' => null,
                'komponen' => [],
            ];
            $perSantri[$id]['komponen'][$komponen] = [
                'label' => self::KOMPONEN[$komponen],
                // `total` = nominal tagihan, karena Σ termin dibandingkan dengan
                // itu. `sisa` hanya ditampilkan sebagai kabar outstanding.
                'total' => (float) $t->nominal,
                'sisa' => (float) $t->sisa,
                'punya_rencana' => in_array($t->id, $idRencanaAktif, true),
            ];
            if ($komponen === 'uang_pangkal' && isset($potongan[$t->id])) {
                $p = $potongan[$t->id];
                $perSantri[$id]['tenggat_potongan'] = Carbon::parse($p->tenggat)->toDateString();
                $perSantri[$id]['syarat_persen'] = (int) $p->syarat_persen;
                $perSantri[$id]['ambang_potongan'] = (float) Money::div(Money::mul($t->nominal, (string) $p->syarat_persen), '100');
            }
        }

        // Yang seluruh komponennya sudah terjadwal tak perlu muncul di daftar.
        $sisa = array_filter($perSantri, fn ($s) => collect($s['komponen'])->contains('punya_rencana', false));
        usort($sisa, fn ($a, $b) => strcasecmp((string) $a['nama'], (string) $b['nama']));

        return array_values($sisa);
    }

    /**
     * Menjadwalkan KEDUA komponen dalam satu kiriman form.
     *
     * Uang pangkal sengaja dibuat LEBIH DULU: penjaga urutan di buatRencana()
     * membandingkan jadwal sebuah komponen dengan rencana aktif komponen
     * lawannya, jadi begitu uang pangkal tersimpan, jadwal perlengkapan yang
     * menyusul otomatis diperiksa terhadapnya — tanpa perlu jalur pemeriksaan
     * khusus. Keduanya dalam satu transaksi: bila perlengkapan ditolak, jadwal
     * uang pangkal ikut batal, tidak tersimpan separuh.
     *
     * @param  array{disepakati_pada:string,catatan?:?string,uang_pangkal?:?array,perlengkapan?:?array}  $data
     * @return array<string,RencanaAngsuranUangPangkal>
     */
    public function buatRencanaGabungan(int $idSantri, array $data, int $idPengguna): array
    {
        $adaIsi = fn (string $k) => ! empty($data[$k]);
        if (! $adaIsi('uang_pangkal') && ! $adaIsi('perlengkapan')) {
            throw new AppException(422, 'Tidak ada termin yang diisi. Isi jadwal uang pangkal, biaya perlengkapan, atau keduanya.');
        }

        return DB::transaction(function () use ($idSantri, $data, $idPengguna, $adaIsi) {
            $hasil = [];
            foreach (array_keys(self::KOMPONEN) as $komponen) { // uang_pangkal dulu, lalu perlengkapan
                if (! $adaIsi($komponen)) {
                    continue;
                }
                $hasil[$komponen] = $this->buatRencana($idSantri, [
                    'komponen' => $komponen,
                    'disepakati_pada' => $data['disepakati_pada'],
                    'catatan' => $data['catatan'] ?? null,
                    'termin' => array_values($data[$komponen]),
                ], $idPengguna);
            }

            return $hasil;
        });
    }

    /** Membuat rencana angsuran pertama (versi 1). */
    public function buatRencana(int $idSantri, array $data, int $idPengguna): RencanaAngsuranUangPangkal
    {
        $komponen = $data['komponen'] ?? 'uang_pangkal';
        $tagihan = $this->ambilTagihan($idSantri, $komponen);
        $this->periksaJumlahTermin($data['termin'], $tagihan->nominal);
        $this->periksaUrutanKomponen($idSantri, $komponen, $data['termin']);

        return DB::transaction(function () use ($tagihan, $data, $idPengguna) {
            if (RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->exists()) {
                throw new AppException(409, 'Santri ini sudah punya rencana angsuran aktif. Gunakan Re-negosiasi untuk mengubah jadwalnya.');
            }
            $rencana = RencanaAngsuranUangPangkal::create([
                'id_tagihan' => $tagihan->id, 'versi' => 1, 'status' => 'aktif',
                'disepakati_pada' => $data['disepakati_pada'], 'disepakati_oleh' => $idPengguna, 'catatan' => $data['catatan'] ?? null,
            ]);
            foreach ($this->barisTerminCreate($data['termin']) as $t) {
                $rencana->termin()->create($t);
            }

            return $rencana->load(['termin' => fn ($q) => $q->orderBy('urutan')]);
        });
    }

    /** Re-negosiasi: versi lama digantikan, versi baru aktif (total tetap). */
    public function renegosiasi(int $idSantri, array $data, int $idPengguna): RencanaAngsuranUangPangkal
    {
        $komponen = $data['komponen'] ?? 'uang_pangkal';
        $tagihan = $this->ambilTagihan($idSantri, $komponen);
        $this->periksaJumlahTermin($data['termin'], $tagihan->nominal);
        $this->periksaUrutanKomponen($idSantri, $komponen, $data['termin']);
        $this->assertTiadaPembayaranMenggantung($tagihan->id);

        return DB::transaction(function () use ($tagihan, $data, $idPengguna) {
            $aktif = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->first();
            if (! $aktif) {
                throw new AppException(422, 'Belum ada rencana angsuran aktif untuk direnegosiasi. Buat rencananya lebih dulu.');
            }
            $maks = (int) RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->max('versi');
            $aktif->update(['status' => 'digantikan']);
            $baru = RencanaAngsuranUangPangkal::create([
                'id_tagihan' => $tagihan->id, 'versi' => $maks + 1, 'status' => 'aktif',
                'disepakati_pada' => $data['disepakati_pada'], 'disepakati_oleh' => $idPengguna,
                'alasan' => $data['alasan'] ?? null, 'catatan' => $data['catatan'] ?? null,
            ]);
            foreach ($this->barisTerminCreate($data['termin']) as $t) {
                $baru->termin()->create($t);
            }

            return $baru->load(['termin' => fn ($q) => $q->orderBy('urutan')]);
        });
    }

    public function tandaiDiingatkan(int $idTermin, int $idPengguna, ?string $catatan = null): TerminUangPangkal
    {
        $termin = TerminUangPangkal::find($idTermin);
        if (! $termin) {
            throw new AppException(404, 'Termin tidak ditemukan.');
        }
        $termin->update(['diingatkan_pada' => Carbon::now()->toDateString(), 'diingatkan_oleh' => $idPengguna, 'catatan_reminder' => $catatan]);

        return $termin;
    }

    public function tandaiFeedback(int $idTermin, ?string $feedback): TerminUangPangkal
    {
        $termin = TerminUangPangkal::find($idTermin);
        if (! $termin) {
            throw new AppException(404, 'Termin tidak ditemukan.');
        }
        $termin->update(['feedback' => $feedback]);

        return $termin;
    }

    /** Evaluasi siklus potongan satu tagihan: earned / masih berlaku / hangus. */
    public function evaluasiPotongan(int $idTagihan): array
    {
        $potongan = PotonganUangPangkal::where('id_tagihan', $idTagihan)->first();
        if (! $potongan || $potongan->status !== 'berlaku') {
            return ['status' => $potongan->status ?? null, 'berubah' => false];
        }
        $tagihan = TagihanSantri::findOrFail($idTagihan);
        $ambang = Money::div(Money::mul($tagihan->nominal, (string) $potongan->syarat_persen), '100');

        $verif = PembayaranSantri::where('id_tagihan', $idTagihan)->where('status', 'terverifikasi')->orderBy('tanggal')->get(['tanggal', 'nominal']);
        $kumulatif = '0';
        $tglCapai = null;
        foreach ($verif as $p) {
            $kumulatif = Money::add($kumulatif, $p->nominal);
            if (Money::gte($kumulatif, $ambang)) {
                $tglCapai = Carbon::parse($p->tanggal);
                break;
            }
        }
        $kini = Carbon::now()->startOfDay();
        $tenggat = Carbon::parse($potongan->tenggat);

        // EARNED: 50% dicapai pembayaran bertanggal ≤ tenggat.
        if ($tglCapai && $tglCapai->lte($tenggat)) {
            $potongan->update(['status' => 'earned', 'dinilai_pada' => Carbon::now()]);

            return ['status' => 'earned', 'berubah' => true];
        }
        // Belum tenggat & belum 50% → masih provisional.
        if ($kini->lte($tenggat)) {
            return ['status' => 'berlaku', 'berubah' => false];
        }

        // HANGUS: potongan ditambahkan kembali ke tagihan + termin baru.
        $nominalBaru = Money::add($tagihan->nominal, $potongan->potongan);
        $sisaBaru = Money::add($tagihan->sisa, $potongan->potongan);
        $statusTagihan = Money::lte($sisaBaru, '0') ? 'lunas' : (Money::lt($sisaBaru, $nominalBaru) ? 'sebagian' : 'belum_bayar');

        DB::transaction(function () use ($idTagihan, $potongan, $tagihan, $nominalBaru, $sisaBaru, $statusTagihan) {
            $potongan->update(['status' => 'hangus', 'dinilai_pada' => Carbon::now()]);
            $tagihan->update(['nominal' => $nominalBaru, 'sisa' => $sisaBaru, 'status' => $statusTagihan]);

            $aktif = RencanaAngsuranUangPangkal::where('id_tagihan', $idTagihan)->where('status', 'aktif')
                ->with(['termin' => fn ($q) => $q->orderBy('urutan')])->first();
            if ($aktif) {
                $maks = (int) RencanaAngsuranUangPangkal::where('id_tagihan', $idTagihan)->max('versi');
                $jtTerakhir = $aktif->termin->max('jatuh_tempo');
                $jtTermin = Carbon::parse($jtTerakhir ?? $potongan->tenggat)->addDays(30)->toDateString();
                $aktif->update(['status' => 'digantikan']);
                $baru = RencanaAngsuranUangPangkal::create([
                    'id_tagihan' => $idTagihan, 'versi' => $maks + 1, 'status' => 'aktif',
                    'disepakati_pada' => Carbon::now()->toDateString(), 'disepakati_oleh' => $aktif->disepakati_oleh,
                    'alasan' => 'Potongan gelombang hangus — sisa uang pangkal ditambahkan sebagai termin.',
                ]);
                foreach ($aktif->termin as $t) {
                    $baru->termin()->create(['urutan' => $t->urutan, 'nominal' => $t->nominal, 'jatuh_tempo' => $t->jatuh_tempo, 'keterangan' => $t->keterangan]);
                }
                $baru->termin()->create(['urutan' => $aktif->termin->count() + 1, 'nominal' => $potongan->potongan, 'jatuh_tempo' => $jtTermin, 'keterangan' => 'Potongan gelombang hangus']);
            }
        });

        return ['status' => 'hangus', 'berubah' => true];
    }

    /** Evaluasi seluruh potongan yang masih berlaku. */
    public function evaluasiPotonganSemua(): array
    {
        $rows = PotonganUangPangkal::where('status', 'berlaku')->pluck('id_tagihan');
        $earned = 0;
        $hangus = 0;
        foreach ($rows as $idTagihan) {
            $hasil = $this->evaluasiPotongan($idTagihan);
            if ($hasil['status'] === 'earned') {
                $earned++;
            } elseif ($hasil['status'] === 'hangus') {
                $hangus++;
            }
        }

        return ['dievaluasi' => $rows->count(), 'earned' => $earned, 'hangus' => $hangus];
    }

    /**
     * Daftar rencana AKTIF + ringkasan progres (port list()), **SATU BARIS PER
     * SANTRI**: uang pangkal & biaya perlengkapan dijumlahkan menjadi satu
     * kewajiban. Dua baris untuk orang yang sama membuat pembacaan daftar
     * berat — yang ingin diketahui petugas adalah "berapa lagi kurangnya", dan
     * itu angka gabungan.
     *
     * Rinciannya tidak hilang: tiap baris membawa `komponen` (per komponen:
     * total, sisa, jumlah termin, termin berikutnya) untuk ditampilkan sebagai
     * keterangan kecil, dan halaman detail tetap memisahkan keduanya.
     *
     * `termin_berikut` = tagihan terdekat yang belum tertutup DARI KEDUA
     * komponen — itulah yang benar-benar harus ditagih lebih dulu.
     */
    public function list(): array
    {
        $rows = RencanaAngsuranUangPangkal::where('status', 'aktif')
            ->with(['termin' => fn ($q) => $q->orderBy('urutan'), 'tagihan.santri.wali', 'tagihan.jenis'])
            ->orderByDesc('id')->get();

        $perSantri = [];
        foreach ($rows as $r) {
            $santri = $r->tagihan->santri;
            $komponen = $this->komponenTagihan($r->tagihan);
            $total = Money::of($r->tagihan->nominal);
            $terbayar = Money::sub($total, $r->tagihan->sisa);
            $berikut = collect($this->turunkanCoverage($terbayar, $r->termin))->firstWhere('status_termin', '!=', 'lunas');
            $berikut = $berikut ? [
                'komponen' => $komponen, 'label_komponen' => self::KOMPONEN[$komponen],
                'urutan' => $berikut['termin']->urutan,
                'jatuh_tempo' => $berikut['termin']->jatuh_tempo,
                'nominal' => Money::of($berikut['termin']->nominal),
            ] : null;

            $id = $r->tagihan->id_santri;
            $perSantri[$id] ??= [
                'id_santri' => $id,
                'no_pendaftaran' => $santri?->no_pendaftaran, 'nama' => $santri?->nama,
                'nama_wali' => $santri?->wali?->nama, 'telepon_wali' => $santri?->wali?->telepon,
                'total' => '0', 'terbayar' => '0', 'sisa' => '0',
                'jumlah_termin' => 0, 'termin_berikut' => null, 'komponen' => [],
            ];
            $b = &$perSantri[$id];
            $b['total'] = Money::add($b['total'], $total);
            $b['terbayar'] = Money::add($b['terbayar'], $terbayar);
            $b['sisa'] = Money::add($b['sisa'], $r->tagihan->sisa);
            $b['jumlah_termin'] += $r->termin->count();
            $b['komponen'][$komponen] = [
                'label' => self::KOMPONEN[$komponen],
                'total' => $total, 'sisa' => Money::of($r->tagihan->sisa),
                'jumlah_termin' => $r->termin->count(), 'termin_berikut' => $berikut,
            ];
            // Yang paling dekat jatuh temponya, apa pun komponennya.
            if ($berikut && (! $b['termin_berikut'] || Carbon::parse($berikut['jatuh_tempo'])->lt(Carbon::parse($b['termin_berikut']['jatuh_tempo'])))) {
                $b['termin_berikut'] = $berikut;
            }
            unset($b);
        }

        foreach ($perSantri as &$b) {
            // Status gabungan diturunkan dari angkanya sendiri, bukan disalin dari
            // salah satu tagihan — dua tagihan bisa berbeda status.
            $b['status_tagihan'] = Money::lte($b['sisa'], '0') ? 'lunas'
                : (Money::gtZero($b['terbayar']) ? 'sebagian' : 'belum_bayar');

            // Urutan komponen mengikuti urutan bakunya (uang pangkal dulu), bukan
            // urutan rencana dibuat — perlengkapan yang dijadwalkan belakangan
            // tak boleh tampil mendahului uang pangkal.
            $urut = [];
            foreach (array_keys(self::KOMPONEN) as $k) {
                if (isset($b['komponen'][$k])) {
                    $urut[$k] = $b['komponen'][$k];
                }
            }
            $b['komponen'] = $urut;
            $b['label_komponen'] = collect($urut)->pluck('label')->join(' + ');
        }
        unset($b);

        return array_values($perSantri);
    }

    /**
     * Kedua komponen sekaligus untuk halaman detail satu santri: uang pangkal
     * lebih dulu, lalu perlengkapan. Komponen yang tagihannya tak ada bernilai
     * null — perlengkapan memang boleh tidak dipungut.
     *
     * @return array{id_santri:int,nama:string,no_pendaftaran:?string,nama_wali:?string,telepon_wali:?string,komponen:array<string,?array>}
     */
    public function detailSantri(int $idSantri): array
    {
        $bagian = [];
        foreach (array_keys(self::KOMPONEN) as $komponen) {
            $bagian[$komponen] = $this->cariTagihan($idSantri, $komponen) ? $this->detail($idSantri, $komponen) : null;
        }
        $ada = collect($bagian)->filter()->first();
        if (! $ada) {
            throw new AppException(404, 'Santri ini belum punya tagihan uang pangkal maupun biaya perlengkapan.');
        }

        return [
            'id_santri' => $idSantri,
            'nama' => $ada['nama'],
            'no_pendaftaran' => $ada['no_pendaftaran'],
            'nama_wali' => $ada['nama_wali'],
            'telepon_wali' => $ada['telepon_wali'],
            'komponen' => $bagian,
        ];
    }

    /** Detail rencana aktif satu komponen + riwayat versi + pembayaran (port get()). */
    public function detail(int $idSantri, string $komponen = 'uang_pangkal'): array
    {
        // Cari lewat TIPE tagihannya, jangan menebak ulang baris master: begitu
        // master punya lebih dari satu baris uang pangkal (per jenjang/jalur),
        // tebakan itu meleset dan tagihan yang ada dianggap tak ditemukan.
        $tagihan = $this->ambilTagihan($idSantri, $komponen);
        // Evaluasi lazy agar tampilan mutakhir.
        $this->evaluasiPotongan($tagihan->id);
        $tagihan->refresh();

        $tagihan->loadMissing('santri.wali');
        $pembayaran = PembayaranSantri::where('id_tagihan', $tagihan->id)->orderBy('tanggal')->get();
        $potongan = PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first();
        $rencana = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)
            ->with(['termin' => fn ($q) => $q->orderBy('urutan')])->orderByDesc('versi')->get();

        $total = Money::of($tagihan->nominal);
        $terbayar = Money::sub($total, $tagihan->sisa);
        $aktif = $rencana->firstWhere('status', 'aktif');
        $terminAktif = $aktif ? $this->turunkanCoverage($terbayar, $aktif->termin) : [];

        // Tanggal tiap termin menjadi LUNAS (FIFO pembayaran terverifikasi).
        $verif = $pembayaran->where('status', 'terverifikasi')->sortBy('tanggal')->values();
        $tanggalLunas = [];
        if ($aktif) {
            $ambang = '0';
            foreach ($aktif->termin as $t) {
                $ambang = Money::add($ambang, $t->nominal);
                $kumulatif = '0';
                $tgl = null;
                foreach ($verif as $p) {
                    $kumulatif = Money::add($kumulatif, $p->nominal);
                    if (Money::gte($kumulatif, $ambang)) {
                        $tgl = $p->tanggal;
                        break;
                    }
                }
                $tanggalLunas[$t->id] = $tgl;
            }
        }

        return [
            'id_santri' => $idSantri, 'no_pendaftaran' => $tagihan->santri->no_pendaftaran, 'nama' => $tagihan->santri->nama,
            'nama_wali' => $tagihan->santri->wali?->nama, 'telepon_wali' => $tagihan->santri->wali?->telepon,
            'komponen' => $komponen, 'label_komponen' => self::KOMPONEN[$komponen],
            'total' => $total, 'terbayar' => $terbayar, 'sisa' => Money::of($tagihan->sisa),
            'status_tagihan' => $tagihan->status, 'sudah_akrual' => (bool) $tagihan->sudah_akrual,
            'potongan' => $potongan ? [
                'gelombang' => $potongan->gelombang, 'nominal_normal' => Money::of($potongan->nominal_normal),
                'potongan' => Money::of($potongan->potongan), 'syarat_persen' => $potongan->syarat_persen,
                'tenggat' => $potongan->tenggat, 'status' => $potongan->status,
            ] : null,
            'rencana_aktif' => $aktif ? [
                'id' => $aktif->id, 'versi' => $aktif->versi, 'disepakati_pada' => $aktif->disepakati_pada, 'catatan' => $aktif->catatan,
                'termin' => array_map(fn ($c) => [
                    'id' => $c['termin']->id, 'urutan' => $c['termin']->urutan, 'nominal' => Money::of($c['termin']->nominal),
                    'jatuh_tempo' => $c['termin']->jatuh_tempo, 'keterangan' => $c['termin']->keterangan, 'tertutup' => $c['tertutup'],
                    'status_termin' => $c['status_termin'], 'tanggal_lunas' => $tanggalLunas[$c['termin']->id] ?? null,
                    'diingatkan_pada' => $c['termin']->diingatkan_pada, 'feedback' => $c['termin']->feedback,
                ], $terminAktif),
            ] : null,
            'riwayat' => $rencana->where('status', 'digantikan')->map(fn ($r) => [
                'id' => $r->id, 'versi' => $r->versi, 'disepakati_pada' => $r->disepakati_pada, 'alasan' => $r->alasan,
                'termin' => $r->termin->map(fn ($t) => ['urutan' => $t->urutan, 'nominal' => Money::of($t->nominal), 'jatuh_tempo' => $t->jatuh_tempo])->all(),
            ])->values()->all(),
            'pembayaran' => $pembayaran->map(fn ($p) => [
                'id' => $p->id, 'nomor' => $p->nomor, 'tanggal' => $p->tanggal, 'nominal' => Money::of($p->nominal),
                'metode' => $p->metode, 'status' => $p->status,
            ])->all(),
        ];
    }

    /** Termin yang mendekati / lewat jatuh tempo & belum tertutup penuh (port jatuhTempo()). */
    public function jatuhTempo(int $dalamHari): array
    {
        $kini = Carbon::now()->startOfDay();
        $batas = $kini->copy()->addDays($dalamHari);

        $rencana = RencanaAngsuranUangPangkal::where('status', 'aktif')
            ->with(['termin' => fn ($q) => $q->orderBy('urutan'), 'tagihan.santri.wali', 'tagihan.jenis'])->get();

        $hasil = [];
        foreach ($rencana as $r) {
            $terbayar = Money::sub($r->tagihan->nominal, $r->tagihan->sisa);
            $komponen = $this->komponenTagihan($r->tagihan);
            foreach ($this->turunkanCoverage($terbayar, $r->termin) as $c) {
                $t = $c['termin'];
                if ($c['status_termin'] === 'lunas') {
                    continue;
                }
                if (Carbon::parse($t->jatuh_tempo)->gt($batas)) {
                    continue;
                }
                $hariLewat = $this->selisihHari($kini, $t->jatuh_tempo);
                $hasil[] = [
                    'id_termin' => $t->id, 'id_santri' => $r->tagihan->id_santri, 'no_pendaftaran' => $r->tagihan->santri->no_pendaftaran,
                    'komponen' => $komponen, 'label_komponen' => self::KOMPONEN[$komponen],
                    'nama' => $r->tagihan->santri->nama, 'nama_wali' => $r->tagihan->santri->wali?->nama, 'telepon_wali' => $r->tagihan->santri->wali?->telepon,
                    'urutan' => $t->urutan, 'nominal' => Money::of($t->nominal), 'sisa_termin' => Money::sub($t->nominal, $c['tertutup']),
                    'jatuh_tempo' => $t->jatuh_tempo, 'hari_lewat' => $hariLewat, 'aging' => $this->bucketAging($hariLewat),
                    'diingatkan_pada' => $t->diingatkan_pada, 'feedback' => $t->feedback,
                ];
            }
        }
        usort($hasil, fn ($a, $b) => $b['hari_lewat'] <=> $a['hari_lewat']);

        return $hasil;
    }

    /** Potongan berlaku yang tenggat 50%-nya mendekati/lewat (port potonganJatuhTempo()). */
    public function potonganJatuhTempo(int $dalamHari): array
    {
        $kini = Carbon::now()->startOfDay();
        $batas = $kini->copy()->addDays($dalamHari);

        $rows = PotonganUangPangkal::where('status', 'berlaku')
            ->whereDate('tenggat', '<=', $batas)
            ->with('tagihan.santri.wali')->orderBy('tenggat')->get();

        return $rows->map(function ($p) use ($kini) {
            $total = Money::of($p->tagihan->nominal);
            $terbayar = Money::sub($total, $p->tagihan->sisa);
            $ambang = Money::div(Money::mul($total, (string) $p->syarat_persen), '100');
            $kurang = Money::sub($ambang, $terbayar);

            return [
                'id_tagihan' => $p->id_tagihan, 'id_santri' => $p->tagihan->id_santri, 'no_pendaftaran' => $p->tagihan->santri->no_pendaftaran,
                'nama' => $p->tagihan->santri->nama, 'nama_wali' => $p->tagihan->santri->wali?->nama, 'telepon_wali' => $p->tagihan->santri->wali?->telepon,
                'gelombang' => $p->gelombang, 'potongan' => Money::of($p->potongan), 'syarat_persen' => $p->syarat_persen,
                'ambang' => $ambang, 'terbayar' => $terbayar, 'kurang' => Money::isNegative($kurang) ? '0' : $kurang,
                'tenggat' => $p->tenggat, 'hari_ke_tenggat' => $this->selisihHari($p->tenggat, $kini),
            ];
        })->all();
    }

    // ---- Helper ----

    /** @return array<int,array{termin:TerminUangPangkal,tertutup:string,status_termin:string}> */
    private function turunkanCoverage(string $terbayar, $termin): array
    {
        $sisaBagi = Money::of($terbayar);
        $out = [];
        foreach ($termin as $t) {
            $nominal = Money::of($t->nominal);
            if (Money::lte($sisaBagi, '0')) {
                $tertutup = '0';
            } elseif (Money::gte($sisaBagi, $nominal)) {
                $tertutup = $nominal;
            } else {
                $tertutup = $sisaBagi;
            }
            $sisaBagi = Money::sub($sisaBagi, $tertutup);
            $status = Money::gte($tertutup, $nominal) ? 'lunas' : (Money::gtZero($tertutup) ? 'sebagian' : 'belum');
            $out[] = ['termin' => $t, 'tertutup' => $tertutup, 'status_termin' => $status];
        }

        return $out;
    }

    /** floor((a − b) dalam hari). */
    private function selisihHari($a, $b): int
    {
        $ta = Carbon::parse($a)->startOfDay()->timestamp;
        $tb = Carbon::parse($b)->startOfDay()->timestamp;

        return (int) floor(($ta - $tb) / 86400);
    }

    private function bucketAging(int $hariLewat): string
    {
        if ($hariLewat <= 0) {
            return 'belum_jatuh_tempo';
        }
        if ($hariLewat <= 30) {
            return '1-30';
        }
        if ($hariLewat <= 60) {
            return '31-60';
        }
        if ($hariLewat <= 90) {
            return '61-90';
        }

        return '>90';
    }

    private function ambilTagihan(int $idSantri, string $komponen = 'uang_pangkal'): TagihanSantri
    {
        $this->assertKomponen($komponen);
        $t = $this->cariTagihan($idSantri, $komponen);
        if (! $t) {
            $label = self::KOMPONEN[$komponen];
            throw new AppException(404, "Tagihan {$label} untuk santri ini belum ada.");
        }

        return $t;
    }

    private function cariTagihan(int $idSantri, string $komponen): ?TagihanSantri
    {
        return TagihanSantri::whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku($komponen)))
            ->where('id_santri', $idSantri)->first();
    }

    private function assertKomponen(string $komponen): void
    {
        if (! array_key_exists($komponen, self::KOMPONEN)) {
            throw new AppException(422, "Komponen angsuran \"{$komponen}\" tidak dikenal.");
        }
    }

    /** Komponen sebuah tagihan (untuk melabeli baris daftar & reminder). */
    private function komponenTagihan(?TagihanSantri $tagihan): string
    {
        $perilaku = \App\Models\TipeBiaya::perilakuDari($tagihan?->jenis?->tipe);

        return array_key_exists((string) $perilaku, self::KOMPONEN) ? (string) $perilaku : 'uang_pangkal';
    }

    private function periksaJumlahTermin(array $termin, $total): void
    {
        $jumlah = '0';
        foreach ($termin as $t) {
            if (Money::lte($t['nominal'], '0')) {
                throw new AppException(422, 'Setiap termin harus bernominal lebih dari nol.');
            }
            $jumlah = Money::add($jumlah, $t['nominal']);
        }
        if (! Money::eq($jumlah, $total)) {
            $selisih = Money::sub($jumlah, $total);
            throw new AppException(422, "Jumlah termin ({$jumlah}) harus sama dengan total tagihannya (".Money::of($total)."). Selisih {$selisih}.");
        }
    }

    /**
     * SELURUH termin uang pangkal harus jatuh tempo SEBELUM termin perlengkapan
     * yang paling awal — uang pangkal selalu didahulukan.
     *
     * Dibandingkan dengan rencana AKTIF komponen lawannya, sehingga aturannya
     * tetap tegak walau kedua jadwal dibuat pada waktu yang berbeda, dan juga
     * saat salah satunya di-renegosiasi belakangan. Kalau komponen lawannya
     * belum berjadwal, tak ada yang perlu dibandingkan.
     *
     * @param  list<array{nominal:string,jatuh_tempo:string}>  $termin
     */
    private function periksaUrutanKomponen(int $idSantri, string $komponen, array $termin): void
    {
        $lawan = $komponen === 'uang_pangkal' ? 'perlengkapan' : 'uang_pangkal';
        $tagihanLawan = $this->cariTagihan($idSantri, $lawan);
        if (! $tagihanLawan) {
            return;
        }
        $rencanaLawan = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihanLawan->id)
            ->where('status', 'aktif')->with('termin')->first();
        if (! $rencanaLawan || $rencanaLawan->termin->isEmpty()) {
            return;
        }

        $tanggal = collect($termin)->map(fn ($t) => Carbon::parse($t['jatuh_tempo'])->startOfDay());
        $tanggalLawan = $rencanaLawan->termin->map(fn ($t) => Carbon::parse($t->jatuh_tempo)->startOfDay());

        // Yang dibandingkan selalu: termin uang pangkal TERAKHIR vs termin
        // perlengkapan TERAWAL, dari sisi mana pun jadwal itu sedang disimpan.
        [$akhirUp, $awalPerlengkapan] = $komponen === 'uang_pangkal'
            ? [$tanggal->max(), $tanggalLawan->min()]
            : [$tanggalLawan->max(), $tanggal->min()];

        if ($akhirUp->gte($awalPerlengkapan)) {
            throw new AppException(422, sprintf(
                'Termin uang pangkal harus selesai lebih dulu daripada termin biaya perlengkapan. '
                .'Termin uang pangkal terakhir %s, sedangkan termin perlengkapan pertama %s.',
                $akhirUp->format('d/m/Y'),
                $awalPerlengkapan->format('d/m/Y'),
            ));
        }
    }

    private function assertTiadaPembayaranMenggantung(int $idTagihan): void
    {
        $menunggu = PembayaranSantri::where('id_tagihan', $idTagihan)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran uang pangkal yang menunggu verifikasi. Selesaikan dulu agar nilai yang tertutup tidak keliru saat jadwal diubah.");
        }
    }

    private function barisTerminCreate(array $termin): array
    {
        $out = [];
        foreach ($termin as $i => $t) {
            $out[] = ['urutan' => $i + 1, 'nominal' => Money::of($t['nominal']), 'jatuh_tempo' => $t['jatuh_tempo'], 'keterangan' => $t['keterangan'] ?? null];
        }

        return $out;
    }
}
