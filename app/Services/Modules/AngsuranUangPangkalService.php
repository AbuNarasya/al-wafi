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
 */
class AngsuranUangPangkalService
{
    /** Membuat rencana angsuran pertama (versi 1). */
    public function buatRencana(int $idSantri, array $data, int $idPengguna): RencanaAngsuranUangPangkal
    {
        $tagihan = $this->ambilTagihan($idSantri);
        $this->periksaJumlahTermin($data['termin'], $tagihan->nominal);

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
        $tagihan = $this->ambilTagihan($idSantri);
        $this->periksaJumlahTermin($data['termin'], $tagihan->nominal);
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

    /** Daftar rencana AKTIF + ringkasan progres (port list()). */
    public function list(): array
    {
        $rows = RencanaAngsuranUangPangkal::where('status', 'aktif')
            ->with(['termin' => fn ($q) => $q->orderBy('urutan'), 'tagihan.santri.wali'])
            ->orderByDesc('id')->get();

        return $rows->map(function ($r) {
            $total = Money::of($r->tagihan->nominal);
            $terbayar = Money::sub($total, $r->tagihan->sisa);
            $cov = $this->turunkanCoverage($terbayar, $r->termin);
            $berikut = collect($cov)->firstWhere('status_termin', '!=', 'lunas');

            return [
                'id_rencana' => $r->id, 'id_santri' => $r->tagihan->id_santri,
                'no_pendaftaran' => $r->tagihan->santri->no_pendaftaran, 'nama' => $r->tagihan->santri->nama,
                'nama_wali' => $r->tagihan->santri->wali?->nama, 'telepon_wali' => $r->tagihan->santri->wali?->telepon,
                'total' => $total, 'terbayar' => $terbayar, 'sisa' => Money::of($r->tagihan->sisa),
                'status_tagihan' => $r->tagihan->status, 'jumlah_termin' => $r->termin->count(),
                'termin_berikut' => $berikut ? ['urutan' => $berikut['termin']->urutan, 'jatuh_tempo' => $berikut['termin']->jatuh_tempo, 'nominal' => Money::of($berikut['termin']->nominal)] : null,
            ];
        })->all();
    }

    /** Detail rencana aktif satu santri + riwayat versi + pembayaran (port get()). */
    public function detail(int $idSantri): array
    {
        // Cari lewat TIPE tagihannya, jangan menebak ulang baris master: begitu
        // master punya lebih dari satu baris uang pangkal (per jenjang/jalur),
        // tebakan itu meleset dan tagihan yang ada dianggap tak ditemukan.
        $tagihan = TagihanSantri::where('id_santri', $idSantri)
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))->first();
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan uang pangkal santri ini tidak ditemukan.');
        }
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
            ->with(['termin' => fn ($q) => $q->orderBy('urutan'), 'tagihan.santri.wali'])->get();

        $hasil = [];
        foreach ($rencana as $r) {
            $terbayar = Money::sub($r->tagihan->nominal, $r->tagihan->sisa);
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

    private function ambilTagihan(int $idSantri): TagihanSantri
    {
        $t = TagihanSantri::whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))
            ->where('id_santri', $idSantri)->first();
        if (! $t) {
            throw new AppException(404, 'Tagihan uang pangkal untuk santri ini belum ada.');
        }

        return $t;
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
            throw new AppException(422, "Jumlah termin ({$jumlah}) harus sama dengan total uang pangkal (".Money::of($total)."). Selisih {$selisih}.");
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
