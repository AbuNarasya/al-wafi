<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DompetSantri;
use App\Models\PengajuanTarikTunai;
use App\Models\Santri;
use App\Models\SesiTarikTunai;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Services\PortalWali\KabarWali;
use App\Services\PortalWali\PinTransaksi;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PENARIKAN TUNAI KEGIATAN (outbound/rihlah) — tahap E1: sesi, pengajuan wali, persetujuan.
 *
 *  • Sumber dana HANYA Dompet Santri. Nominal kelipatan Rp 50.000, tak melebihi batas sesi.
 *  • Saat diajukan saldo DICADANGKAN tanpa jurnal. Cadangan = jumlah pengajuan yang masih
 *    hidup (disetujui, atau diajukan dan batas persetujuannya belum lewat) — dihitung dari
 *    tabel pengajuan, dan WAJIB dihormati semua jalur yang memotong Dompet Santri lewat
 *    [[self::pastikanTersedia]]: belanja kartu, pindah ke wali/tabungan, pindah antar-anak,
 *    tarik oleh staf. Terlewat satu = uang kegiatan bisa terpakai lebih dulu.
 *  • `kunci_tarik` BERLAKU di sini (inilah gunanya), tidak di jalur pindah.
 *  • PIN wali diperiksa SESUDAH validasi kepemilikan & DI LUAR transaksi (lihat PinTransaksi).
 */
class PenarikanTunaiService
{
    public const KELIPATAN = 50000;

    // ---- Cadangan saldo (dipakai jalur dompet lain) ----

    public static function cadangan(int $idSantri, ?int $kecuali = null): string
    {
        $jumlah = PengajuanTarikTunai::query()
            ->join('sesi_tarik_tunai as s', 's.id_sesi', '=', 'pengajuan_tarik_tunai.id_sesi')
            ->where('pengajuan_tarik_tunai.id_santri', $idSantri)
            ->whereIn('s.status', ['berjalan', 'dana_diambil'])
            ->where(fn ($q) => $q->where('pengajuan_tarik_tunai.status', 'disetujui')
                ->orWhere(fn ($w) => $w->where('pengajuan_tarik_tunai.status', 'diajukan')->where('s.batas_persetujuan', '>=', now())))
            ->when($kecuali, fn ($q) => $q->where('pengajuan_tarik_tunai.id', '!=', $kecuali))
            ->sum(DB::raw('COALESCE(pengajuan_tarik_tunai.nominal_disetujui, pengajuan_tarik_tunai.nominal)'));

        return Money::of($jumlah);
    }

    /** Saldo Dompet Santri yang boleh dipakai = saldo − cadangan kegiatan. */
    public static function tersedia(int $idSantri, string|int|float|null $saldo = null): string
    {
        $saldo ??= DompetSantri::where('id_santri', $idSantri)->value('saldo') ?? '0';

        return Money::sub($saldo, self::cadangan($idSantri));
    }

    /** @throws AppException 422 bila `$nominal` melebihi saldo tersedia. */
    public static function pastikanTersedia(int $idSantri, string|int|float $saldo, string $nominal, string $awalan = 'Saldo uang saku tidak cukup', ?int $kecuali = null): void
    {
        $cadangan = self::cadangan($idSantri, $kecuali);
        $tersedia = Money::sub($saldo, $cadangan);
        if (Money::lt($tersedia, $nominal)) {
            throw new AppException(422, $awalan.' (tersedia '.self::rp(Money::isNegative($tersedia) ? '0' : $tersedia)
                .(Money::gtZero($cadangan) ? ', '.self::rp($cadangan).' dicadangkan untuk penarikan tunai kegiatan' : '').').');
        }
    }

    // ---- Sesi (keuangan) ----

    /** Santri aktif sejenjang, opsional dibatasi tingkat — titik awal daftar peserta. */
    public function calonPeserta(string $kodeJenjang, array $tingkat = []): Collection
    {
        return Santri::where('status', 'aktif')->where('kode_jenjang', $kodeJenjang)
            ->when($tingkat, fn ($q) => $q->whereIn('tingkat', $tingkat))->orderBy('nama')->pluck('id');
    }

    /** @param  array<int,int>  $peserta  id santri */
    public function buatSesi(array $d, array $peserta, int $idPengguna): SesiTarikTunai
    {
        $d = self::batasAkhirHari($d);
        $this->assertJadwal($d);
        if ($peserta === []) {
            throw new AppException(422, 'Belum ada peserta — tak ada santri aktif yang cocok dengan saringan itu.');
        }

        return DB::transaction(function () use ($d, $peserta, $idPengguna) {
            $base = DocNumber::docBase('STT', now());
            $sesi = SesiTarikTunai::create([
                'nomor' => DocNumber::nextDocNumber($base, SesiTarikTunai::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'nama' => trim($d['nama']), 'keterangan' => $d['keterangan'] ?? null,
                'batas_pengajuan' => $d['batas_pengajuan'], 'batas_persetujuan' => $d['batas_persetujuan'],
                'tanggal_ambil' => $d['tanggal_ambil'], 'tanggal_distribusi' => $d['tanggal_distribusi'],
                'maks_per_santri' => Money::of($d['maks_per_santri']), 'kode_coa_bank' => $d['kode_coa_bank'],
                'kode_coa_kas' => $d['kode_coa_kas'], 'dibuka' => false, 'status' => 'berjalan', 'dibuat_oleh' => $idPengguna,
            ]);
            $sesi->santri()->sync(array_values(array_unique($peserta)));

            return $sesi;
        });
    }

    /** Data sesi (nama, batas nominal, rekening) — keuangan saja. Jadwal lewat [[self::ubahJadwal]]. */
    public function ubahSesi(int $idSesi, array $d): SesiTarikTunai
    {
        $sesi = $this->sesiBerjalan($idSesi);
        $this->assertDataSesi($d);
        $lewat = $sesi->pengajuan()->whereIn('status', ['diajukan', 'disetujui'])
            ->whereRaw('COALESCE(nominal_disetujui, nominal) > ?', [Money::of($d['maks_per_santri'])])->count();
        if ($lewat) {
            throw new AppException(422, "Ada {$lewat} pengajuan di atas batas baru itu. Putus atau minta wali mengubahnya dulu.");
        }
        $sesi->update([
            'nama' => trim($d['nama']), 'keterangan' => $d['keterangan'] ?? null,
            'maks_per_santri' => Money::of($d['maks_per_santri']), 'kode_coa_bank' => $d['kode_coa_bank'], 'kode_coa_kas' => $d['kode_coa_kas'],
        ]);

        return $sesi;
    }

    /**
     * Ubah jadwal (keputusan user 20 Okt 2026) — pembagian wewenang:
     *   batas pengajuan & persetujuan : keuangan ATAU kesantrian, sebelum dana diambil
     *   tanggal ambil di bank         : keuangan, sebelum dana diambil
     *   tanggal distribusi            : keuangan, juga SESUDAH dana diambil (selama sesi belum selesai)
     * Isian yang tak dikirim dibiarkan. Memajukan batas persetujuan ke waktu lampau = menutup persetujuan
     * sekarang (yang belum diputus gugur). Perubahan tercatat di jejak audit (model MencatatJejak); wali
     * yang pengajuannya masih hidup dikabari bila batas pengajuan atau tanggal distribusi berubah.
     *
     * @param  array{batas_pengajuan?:?string, batas_persetujuan?:?string, tanggal_ambil?:?string, tanggal_distribusi?:?string}  $d
     */
    public function ubahJadwal(int $idSesi, array $d, bool $hakKeuangan, bool $hakKesantrian): SesiTarikTunai
    {
        $sesi = SesiTarikTunai::find($idSesi) ?? throw new AppException(404, 'Sesi tidak ditemukan.');
        if (! in_array($sesi->status, ['berjalan', 'dana_diambil'], true)) {
            throw new AppException(422, 'Sesi ini sudah '.strtolower($sesi->labelTahap()).' — jadwalnya tak bisa diubah lagi.');
        }
        $d = self::batasAkhirHari(array_filter($d, fn ($v) => $v !== null && $v !== ''));
        if ($d === []) {
            throw new AppException(422, 'Tidak ada tanggal yang diubah.');
        }
        $berjalan = $sesi->status === 'berjalan';
        foreach (array_keys($d) as $kolom) {
            [$boleh, $alasan] = match ($kolom) {
                'batas_pengajuan', 'batas_persetujuan' => [($hakKeuangan || $hakKesantrian) && $berjalan, 'sebelum dana diambil, oleh keuangan atau kesantrian'],
                'tanggal_ambil' => [$hakKeuangan && $berjalan, 'sebelum dana diambil, oleh keuangan'],
                'tanggal_distribusi' => [$hakKeuangan, 'oleh keuangan'],
                default => [false, '-'],
            };
            if (! $boleh) {
                throw new AppException(403, ucfirst(str_replace('_', ' ', $kolom))." hanya bisa diubah {$alasan}.");
            }
        }

        $baru = [
            'batas_pengajuan' => $d['batas_pengajuan'] ?? $sesi->batas_pengajuan->format('Y-m-d H:i'),
            'batas_persetujuan' => $d['batas_persetujuan'] ?? $sesi->batas_persetujuan->format('Y-m-d H:i'),
            'tanggal_ambil' => $d['tanggal_ambil'] ?? $sesi->tanggal_ambil->toDateString(),
            'tanggal_distribusi' => $d['tanggal_distribusi'] ?? $sesi->tanggal_distribusi->toDateString(),
        ];
        $this->assertUrutan(...array_values($baru));

        $lama = ['pengajuan' => $sesi->batas_pengajuan, 'distribusi' => $sesi->tanggal_distribusi];
        $sesi->update(array_intersect_key($baru, $d));

        $kabar = [];
        if (isset($d['batas_pengajuan']) && ! $lama['pengajuan']->eq($sesi->batas_pengajuan)) {
            $kabar[] = 'batas pengajuan kini sampai akhir '.$sesi->batas_pengajuan->translatedFormat('j M Y');
        }
        if (isset($d['tanggal_distribusi']) && ! $lama['distribusi']->eq($sesi->tanggal_distribusi)) {
            $kabar[] = 'uang diserahkan '.$sesi->tanggal_distribusi->translatedFormat('j F Y');
        }
        if ($kabar) {
            foreach ($sesi->pengajuan()->with('santri:id,nama')->whereIn('status', ['diajukan', 'disetujui'])->get() as $p) {
                $this->kabari($p, 'Jadwal uang saku kegiatan berubah', "{$sesi->nama}: ".implode('; ', $kabar).'.');
            }
        }

        return $sesi;
    }

    public function aturDibuka(int $idSesi, bool $dibuka): SesiTarikTunai
    {
        $sesi = $this->sesiBerjalan($idSesi);
        $sesi->update(['dibuka' => $dibuka]);

        return $sesi;
    }

    /** @param  array<int,int>  $idSantri */
    public function tambahPeserta(int $idSesi, array $idSantri): int
    {
        $sesi = $this->sesiBerjalan($idSesi);
        $aktif = Santri::whereIn('id', $idSantri)->where('status', 'aktif')->pluck('id')->all();
        if ($aktif === []) {
            throw new AppException(422, 'Santri tidak ditemukan atau tidak berstatus aktif.');
        }
        $baru = array_diff($aktif, $sesi->santri()->pluck('santri.id')->all());
        $sesi->santri()->attach($baru);

        return count($baru);
    }

    public function hapusPeserta(int $idSesi, int $idSantri): void
    {
        $sesi = $this->sesiBerjalan($idSesi);
        if ($sesi->pengajuan()->where('id_santri', $idSantri)->whereIn('status', ['diajukan', 'disetujui'])->exists()) {
            throw new AppException(422, 'Santri ini sudah punya pengajuan. Tolak dulu pengajuannya, baru keluarkan dari peserta.');
        }
        $sesi->santri()->detach($idSantri);
    }

    /** Batal hanya sebelum dana diambil; pengajuan yang hidup ikut batal & cadangannya lepas. */
    public function batalSesi(int $idSesi, string $alasan, int $idPengguna): SesiTarikTunai
    {
        $sesi = $this->sesiBerjalan($idSesi);
        $kena = DB::transaction(function () use ($sesi, $alasan) {
            $hidup = $sesi->pengajuan()->with('santri:id,nama')->whereIn('status', ['diajukan', 'disetujui'])->lockForUpdate()->get();
            $sesi->pengajuan()->whereIn('status', ['diajukan', 'disetujui'])->update(['status' => 'dibatalkan']);
            $sesi->update(['status' => 'batal', 'dibuka' => false, 'alasan_batal' => $alasan]);

            return $hidup;
        });
        foreach ($kena as $p) {
            $this->kabari($p, 'Penarikan tunai kegiatan dibatalkan', "{$sesi->nama}: sesi dibatalkan pesantren ({$alasan}). Uang saku {$p->santri->nama} kembali bisa dipakai.");
        }

        return $sesi;
    }

    // ---- Pengajuan (Portal Wali) ----

    /** Sesi yang tampil bagi keluarga ini: dibuka, belum selesai/batal, & ada anaknya sebagai peserta. */
    public function sesiUntukWali(Wali $wali): Collection
    {
        $anak = Santri::where('id_wali', $wali->id)->where('status', 'aktif')->pluck('id');

        return SesiTarikTunai::where('dibuka', true)->whereIn('status', ['berjalan', 'dana_diambil'])
            ->whereHas('santri', fn ($q) => $q->whereIn('santri.id', $anak))
            ->with(['santri' => fn ($q) => $q->whereIn('santri.id', $anak)->with('dompet')->orderBy('nama')])
            ->with(['pengajuan' => fn ($q) => $q->whereIn('id_santri', $anak)->orderByDesc('id')])
            ->orderBy('batas_pengajuan')->get();
    }

    public function ajukan(Wali $wali, string $peran, int $idSesi, int $idSantri, string $nominal, ?string $pin): PengajuanTarikTunai
    {
        $sesi = $this->sesiTerbukaUntukWali($idSesi);
        $santri = Santri::where('id', $idSantri)->where('id_wali', $wali->id)->where('status', 'aktif')->first()
            ?? throw new AppException(422, 'Anak tidak ditemukan atau belum berstatus santri aktif.');
        if (! $sesi->santri()->where('santri.id', $santri->id)->exists()) {
            throw new AppException(422, "{$santri->nama} bukan peserta kegiatan ini.");
        }
        if ($sesi->pengajuan()->where('id_santri', $santri->id)->whereIn('status', ['diajukan', 'disetujui', 'diserahkan'])->exists()) {
            throw new AppException(422, "Sudah ada pengajuan untuk {$santri->nama} — ubah pengajuan itu saja.");
        }
        $nominal = $this->assertNominal($sesi, $nominal);
        $this->assertDompet($santri, $nominal);
        (new PinTransaksi($wali, $peran))->periksa($pin);

        $p = DB::transaction(function () use ($wali, $peran, $sesi, $santri, $nominal) {
            $dompet = DompetSantri::where('id_santri', $santri->id)->lockForUpdate()->first();
            self::pastikanTersedia($santri->id, $dompet->saldo, $nominal, "Uang saku {$santri->nama} tidak cukup");
            $base = DocNumber::docBase('TTK', now());

            return PengajuanTarikTunai::create([
                'nomor' => DocNumber::nextDocNumber($base, PengajuanTarikTunai::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_sesi' => $sesi->id_sesi, 'id_santri' => $santri->id, 'id_wali' => $wali->id, 'peran' => $peran,
                'nominal' => $nominal, 'status' => 'diajukan',
            ]);
        });
        $this->kabariPemutus($sesi);

        return $p;
    }

    /**
     * Kabar ke pemegang hak memutus — SATU per sesi per hari per orang, bukan per pengajuan
     * (200 santri = 200 notifikasi tak terbaca). Angka pastinya ada di Tugas Saya.
     */
    private function kabariPemutus(SesiTarikTunai $sesi): void
    {
        $pemegang = \App\Models\HakAksesModul::where('kode_modul', 'penarikan-tunai')->where('ubah', true)
            ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))->pluck('id_pengguna');
        $sudah = \App\Models\Notification::where('jenis', 'tarik_tunai_menunggu')->where('ref_id', (string) $sesi->id_sesi)
            ->whereDate('created_at', now()->toDateString())->pluck('id_pengguna');
        $tujuan = $pemegang->diff($sudah);
        if ($tujuan->isEmpty()) {
            return;
        }
        (new NotificationService)->kirim($tujuan->map(fn ($id) => [
            'id_pengguna' => $id, 'judul' => 'Pengajuan uang saku kegiatan masuk',
            'pesan' => "{$sesi->nama}: ada pengajuan wali yang menunggu diputus paling lambat ".$sesi->batas_persetujuan->format('d/m/Y').'.',
            'jenis' => 'tarik_tunai_menunggu', 'ref_jenis' => 'SesiTarikTunai', 'ref_id' => (string) $sesi->id_sesi,
        ])->values()->all());
    }

    /** Ubah nominal sampai batas pengajuan. Putusan yang sudah ada gugur — kesantrian memutus ulang. */
    public function ubahPengajuan(Wali $wali, string $peran, int $id, string $nominal, ?string $pin): PengajuanTarikTunai
    {
        $p = $this->pengajuanMilik($wali, $id);
        $sesi = $this->sesiTerbukaUntukWali($p->id_sesi);
        $nominal = $this->assertNominal($sesi, $nominal);
        $this->assertDompet($p->santri, $nominal, $p->id);
        (new PinTransaksi($wali, $peran))->periksa($pin);

        return DB::transaction(function () use ($p, $peran, $nominal) {
            $dompet = DompetSantri::where('id_santri', $p->id_santri)->lockForUpdate()->first();
            $p->refresh();
            if (! in_array($p->status, ['diajukan', 'disetujui'], true)) {
                throw new AppException(422, 'Pengajuan ini sudah tidak bisa diubah.');
            }
            self::pastikanTersedia($p->id_santri, $dompet->saldo, $nominal, "Uang saku {$p->santri->nama} tidak cukup", $p->id);
            $p->update(['nominal' => $nominal, 'nominal_disetujui' => null, 'status' => 'diajukan', 'peran' => $peran,
                'diputus_oleh' => null, 'diputus_pada' => null, 'alasan_tolak' => null]);

            return $p;
        });
    }

    public function batalPengajuan(Wali $wali, int $id): PengajuanTarikTunai
    {
        $p = $this->pengajuanMilik($wali, $id);
        if ($p->sesi->status !== 'berjalan' || $p->sesi->tahap() !== 'pengajuan') {
            throw new AppException(422, 'Batas pengajuan sudah lewat — pengajuan tak bisa dibatalkan dari portal lagi.');
        }
        $p->update(['status' => 'dibatalkan']);

        return $p;
    }

    // ---- Persetujuan (kesantrian) ----

    /** Setujui — boleh dengan nominal LEBIH KECIL (kelipatan Rp 50.000). */
    public function setujui(int $id, ?string $nominal, int $idPengguna): PengajuanTarikTunai
    {
        $p = DB::transaction(function () use ($id, $nominal, $idPengguna) {
            $p = $this->pengajuanUntukDiputus($id);
            $setuju = $nominal === null || $nominal === '' ? Money::of($p->nominal) : $this->assertKelipatan($nominal);
            if (Money::gt($setuju, $p->nominal)) {
                throw new AppException(422, 'Nominal disetujui tak boleh melebihi yang diajukan ('.self::rp($p->nominal).').');
            }
            $p->update(['status' => 'disetujui', 'nominal_disetujui' => $setuju, 'diputus_oleh' => $idPengguna, 'diputus_pada' => now()]);

            return $p;
        });

        $lebihKecil = Money::lt($p->nominal_disetujui, $p->nominal) ? ' (diajukan '.self::rp($p->nominal).')' : '';
        $this->kabari($p, 'Penarikan tunai kegiatan disetujui',
            "{$p->sesi->nama}: ".self::rp($p->nominal_disetujui)."{$lebihKecil} untuk {$p->santri->nama}, diserahkan "
            .$p->sesi->tanggal_distribusi->translatedFormat('j F Y').'.');

        return $p;
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PengajuanTarikTunai
    {
        $p = DB::transaction(function () use ($id, $alasan, $idPengguna) {
            $p = $this->pengajuanUntukDiputus($id);
            $p->update(['status' => 'ditolak', 'alasan_tolak' => $alasan, 'diputus_oleh' => $idPengguna, 'diputus_pada' => now()]);

            return $p;
        });
        $this->kabari($p, 'Penarikan tunai kegiatan ditolak', "{$p->sesi->nama} — {$p->santri->nama}: {$alasan}");

        return $p;
    }

    // ---- E2: ambil dana, distribusi, selesai (keuangan) ----

    /** Untuk slip penarikan bank: pengajuan disetujui/diserahkan, totalnya, & jumlah lembar per pecahan. */
    public function rekapAmbil(SesiTarikTunai $sesi): array
    {
        $baris = $sesi->pengajuan()->with('santri:id,nama,nis')->whereIn('status', ['disetujui', 'diserahkan'])->orderBy('id')->get();
        $total = '0';
        $lembar = ['100000' => 0, '50000' => 0];
        foreach ($baris as $p) {
            $total = Money::add($total, $p->nominalBerlaku());
            foreach ($p->pecahan() as $pecahan => $n) {
                $lembar[$pecahan] += $n;
            }
        }

        return ['baris' => $baris, 'total' => Money::of($total), 'lembar' => $lembar];
    }

    /** "Dana sudah diambil": D Kas Tunai Kegiatan / K Bank sebesar seluruh yang disetujui. */
    public function ambilDana(int $idSesi, int $idPengguna): SesiTarikTunai
    {
        return DB::transaction(function () use ($idSesi, $idPengguna) {
            $sesi = SesiTarikTunai::lockForUpdate()->find($idSesi) ?? throw new AppException(404, 'Sesi tidak ditemukan.');
            if ($sesi->status !== 'berjalan') {
                throw new AppException(422, 'Dana sesi ini sudah diambil atau sesinya sudah ditutup.');
            }
            if ($sesi->tahap() !== 'siap_ambil') {
                throw new AppException(422, 'Dana baru bisa diambil sesudah batas persetujuan lewat (akhir '.$sesi->batas_persetujuan->format('d/m/Y').').');
            }
            $total = $this->rekapAmbil($sesi)['total'];
            if (! Money::gtZero($total)) {
                throw new AppException(422, 'Tidak ada pengajuan yang disetujui. Batalkan sesi ini bila kegiatannya tak jadi memakai uang tunai.');
            }
            $jurnal = \App\Services\Ledger\PostingService::postJournal([
                'referensi' => $sesi->nomor, 'tanggal' => now()->toDateString(), 'sumber_modul' => SesiTarikTunai::SUMBER,
                'id_sumber' => $sesi->nomor, 'id_pengguna' => $idPengguna, 'keterangan' => "Ambil dana uang saku kegiatan — {$sesi->nama}",
                'lines' => [
                    ['kode_coa' => $sesi->kode_coa_kas, 'debet' => $total, 'kredit' => '0'],
                    ['kode_coa' => $sesi->kode_coa_bank, 'debet' => '0', 'kredit' => $total],
                ],
            ]);
            $sesi->update(['status' => 'dana_diambil', 'dibuka' => false, 'total_diambil' => $total, 'dana_diambil_pada' => now(),
                'dana_diambil_oleh' => $idPengguna, 'journal_ambil_id' => $jurnal->id]);

            return $sesi;
        });
    }

    /** Pengajuan disetujui milik kartu yang ditempel — untuk ditampilkan sebelum diserahkan. */
    public function pengajuanUntukKartu(int $idSesi, ?string $uid): PengajuanTarikTunai
    {
        $kartu = (new KartuSantriService)->cariAktif($uid) ?? throw new AppException(422, 'Kartu tidak dikenal atau sudah diblokir.');
        $p = PengajuanTarikTunai::with(['santri', 'sesi'])->where('id_sesi', $idSesi)->where('id_santri', $kartu->id_santri)
            ->whereIn('status', ['disetujui', 'diserahkan'])->first();
        if (! $p) {
            throw new AppException(422, "{$kartu->santri->nama} tidak punya pengajuan yang disetujui di sesi ini.");
        }
        if ($p->status === 'diserahkan') {
            throw new AppException(422, "{$kartu->santri->nama} SUDAH menerima ".self::rp($p->nominal_disetujui).' pada '.$p->diserahkan_pada->format('d/m H:i').'.');
        }

        return $p;
    }

    public function serahLewatKartu(int $idSesi, ?string $uid, int $idPengguna): PengajuanTarikTunai
    {
        return $this->serah($this->pengajuanUntukKartu($idSesi, $uid)->id, 'kartu', null, $idPengguna);
    }

    /** Kartu hilang/rusak: penyerahan dipilih dari daftar, alasannya wajib & tercatat. */
    public function serahManual(int $idPengajuan, string $alasan, int $idPengguna): PengajuanTarikTunai
    {
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan penyerahan tanpa kartu wajib diisi.');
        }

        return $this->serah($idPengajuan, 'manual', trim($alasan), $idPengguna);
    }

    /** Penyerahan keliru — selama sesi belum selesai: saldo kembali, mutasinya ditandai ditolak beserta alasan. */
    public function batalSerah(int $idPengajuan, string $alasan, int $idPengguna): PengajuanTarikTunai
    {
        return DB::transaction(function () use ($idPengajuan, $alasan, $idPengguna) {
            $p = PengajuanTarikTunai::with(['sesi', 'santri'])->lockForUpdate()->find($idPengajuan) ?? throw new AppException(404, 'Pengajuan tidak ditemukan.');
            if ($p->status !== 'diserahkan' || $p->sesi->status !== 'dana_diambil') {
                throw new AppException(422, 'Hanya penyerahan di sesi yang belum diselesaikan yang bisa dibatalkan.');
            }
            $dompet = DompetSantri::where('id_santri', $p->id_santri)->lockForUpdate()->first();
            $dompet->update(['saldo' => Money::add($dompet->saldo, $p->nominal_disetujui)]);
            $mutasi = \App\Models\MutasiDompet::find($p->id_mutasi_dompet);
            $mutasi?->update(['status' => 'ditolak', 'keterangan' => "{$mutasi->keterangan} — DIBATALKAN: {$alasan}"]);
            \App\Support\Audit\Jejak::catat('batal_serah_tarik_tunai', [
                'modul' => 'sesi-tarik-tunai', 'ref_jenis' => 'PengajuanTarikTunai', 'ref_id' => $p->id,
                'detail' => ['nominal' => (string) $p->nominal_disetujui, 'alasan' => $alasan, 'mutasi' => $mutasi?->nomor], 'id_pengguna' => $idPengguna,
            ]);
            $p->update(['status' => 'disetujui', 'diserahkan_pada' => null, 'diserahkan_oleh' => null, 'cara_serah' => null,
                'alasan_manual' => null, 'id_mutasi_dompet' => null]);

            return $p;
        });
    }

    /**
     * Tutup sesi. Uang fisik sisa WAJIB sama dengan hitungan (keputusan user 19 Okt 2026) —
     * selisih berarti ada salah serah yang harus dicari dulu. Jurnal ringkas:
     * D Titipan Santri (diserahkan) + D Bank (sisa disetor) / K Kas Tunai Kegiatan (diambil).
     */
    public function selesaikan(int $idSesi, string $uangFisik, int $idPengguna): SesiTarikTunai
    {
        [$sesi, $takDiambil] = DB::transaction(function () use ($idSesi, $uangFisik, $idPengguna) {
            $sesi = SesiTarikTunai::lockForUpdate()->find($idSesi) ?? throw new AppException(404, 'Sesi tidak ditemukan.');
            if ($sesi->status !== 'dana_diambil') {
                throw new AppException(422, 'Hanya sesi yang dananya sudah diambil yang bisa diselesaikan.');
            }
            $diserahkan = $sesi->pengajuan()->where('status', 'diserahkan')->get();
            $totalSerah = Money::of($diserahkan->reduce(fn ($t, $p) => Money::add($t, $p->nominal_disetujui), '0'));
            $sisa = Money::sub($sesi->total_diambil, $totalSerah);
            $fisik = Money::of($uangFisik);
            if (! Money::eq($fisik, $sisa)) {
                throw new AppException(422, 'Uang fisik '.self::rp($fisik).' tidak sama dengan hitungan '.self::rp($sisa)
                    .' (diambil '.self::rp($sesi->total_diambil).' − diserahkan '.self::rp($totalSerah).'). Periksa penyerahan yang keliru dulu.');
            }

            $baris = [];
            if (Money::gtZero($totalSerah)) {
                $baris[] = ['kode_coa' => \App\Services\Ppsb\DompetPolicy::COA_TITIPAN['santri'], 'debet' => $totalSerah, 'kredit' => '0'];
            }
            if (Money::gtZero($sisa)) {
                $baris[] = ['kode_coa' => $sesi->kode_coa_bank, 'debet' => $sisa, 'kredit' => '0'];
            }
            $baris[] = ['kode_coa' => $sesi->kode_coa_kas, 'debet' => '0', 'kredit' => $sesi->total_diambil];
            $jurnal = \App\Services\Ledger\PostingService::postJournal([
                'referensi' => $sesi->nomor.'-S', 'tanggal' => now()->toDateString(), 'sumber_modul' => SesiTarikTunai::SUMBER,
                'id_sumber' => $sesi->nomor, 'id_pengguna' => $idPengguna,
                'keterangan' => "Penutup uang saku kegiatan — {$sesi->nama}: diserahkan ".self::rp($totalSerah).', disetor balik '.self::rp($sisa),
                'lines' => $baris,
            ]);
            \App\Models\MutasiDompet::whereIn('id', $diserahkan->pluck('id_mutasi_dompet')->filter())->update(['journal_entry_id' => $jurnal->id]);

            $takDiambil = $sesi->pengajuan()->with('santri:id,nama')->where('status', 'disetujui')->get();
            $sesi->pengajuan()->where('status', 'disetujui')->update(['status' => 'tak_diambil']);
            $sesi->update(['status' => 'selesai', 'total_diserahkan' => $totalSerah, 'sisa_disetor' => $sisa,
                'selesai_pada' => now(), 'selesai_oleh' => $idPengguna, 'journal_selesai_id' => $jurnal->id]);

            return [$sesi, $takDiambil];
        });
        foreach ($takDiambil as $p) {
            $this->kabari($p, 'Uang saku kegiatan tidak diambil', "{$sesi->nama}: ".self::rp($p->nominal_disetujui)
                ." untuk {$p->santri->nama} tidak diambil. Uang saku kembali bisa dipakai seperti biasa.");
        }

        return $sesi;
    }

    private function serah(int $idPengajuan, string $cara, ?string $alasan, int $idPengguna): PengajuanTarikTunai
    {
        $p = DB::transaction(function () use ($idPengajuan, $cara, $alasan, $idPengguna) {
            $p = PengajuanTarikTunai::with(['sesi', 'santri'])->lockForUpdate()->find($idPengajuan) ?? throw new AppException(404, 'Pengajuan tidak ditemukan.');
            if ($p->sesi->status !== 'dana_diambil') {
                throw new AppException(422, 'Distribusi hanya sesudah dana diambil dan sebelum sesi diselesaikan.');
            }
            if ($p->status === 'diserahkan') {
                throw new AppException(422, "{$p->santri->nama} SUDAH menerima uangnya.");
            }
            if ($p->status !== 'disetujui') {
                throw new AppException(422, "Pengajuan {$p->santri->nama} tidak berstatus disetujui.");
            }
            $nominal = Money::of($p->nominal_disetujui);
            $dompet = DompetSantri::where('id_santri', $p->id_santri)->lockForUpdate()->first();
            if (! $dompet || $dompet->kunci_tarik) {
                throw new AppException(422, "Uang saku {$p->santri->nama} sedang dikunci dari penarikan. Buka kuncinya dulu di menu Dompet & Tabungan.");
            }
            if (Money::lt($dompet->saldo, $nominal)) {
                throw new AppException(422, "Saldo uang saku {$p->santri->nama} kini hanya ".self::rp($dompet->saldo).' — tak cukup untuk '.self::rp($nominal).'.');
            }
            $saldo = Money::sub($dompet->saldo, $nominal);
            $dompet->update(['saldo' => $saldo]);
            $base = DocNumber::docBase('DMP', now());
            // Tanpa jurnal di sini — jurnalnya ringkas saat sesi diselesaikan, lalu ditautkan ke mutasi ini.
            $mutasi = \App\Models\MutasiDompet::create([
                'nomor' => DocNumber::nextDocNumber($base, \App\Models\MutasiDompet::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'pemilik' => 'santri', 'id_dompet' => $dompet->id, 'jenis' => 'tarik', 'nominal' => $nominal, 'saldo_setelah' => $saldo,
                'tanggal' => now()->toDateString(), 'status' => 'terverifikasi', 'kode_rekening' => $p->sesi->kode_coa_kas,
                'keterangan' => "Uang saku kegiatan {$p->sesi->nama} — {$p->nomor}".($cara === 'manual' ? " (tanpa kartu: {$alasan})" : ''),
                'dicatat_oleh' => $idPengguna, 'diverifikasi_oleh' => $idPengguna, 'diverifikasi_pada' => now(),
            ]);
            $p->update(['status' => 'diserahkan', 'diserahkan_pada' => now(), 'diserahkan_oleh' => $idPengguna, 'cara_serah' => $cara,
                'alasan_manual' => $alasan, 'id_mutasi_dompet' => $mutasi->id]);

            return $p;
        });
        $this->kabari($p, 'Uang saku kegiatan sudah diterima', "{$p->sesi->nama}: ".self::rp($p->nominal_disetujui)." diterima {$p->santri->nama}.");

        return $p;
    }

    /** Ringkasan per sesi untuk layar staf. */
    public function rekap(SesiTarikTunai $sesi): array
    {
        $baris = $sesi->pengajuan()->with('sesi')->get();
        $per = $baris->groupBy(fn ($p) => $p->statusEfektif());

        return [
            'peserta' => $sesi->santri()->count(),
            'jumlah' => $per->map->count()->all(),
            'diajukan' => Money::of($per->get('diajukan', collect())->sum(fn ($p) => (float) $p->nominal)),
            'disetujui' => Money::of($per->get('disetujui', collect())->sum(fn ($p) => (float) $p->nominal_disetujui)),
        ];
    }

    // ---- pembantu ----

    private function assertJadwal(array $d): void
    {
        $this->assertUrutan($d['batas_pengajuan'], $d['batas_persetujuan'], $d['tanggal_ambil'], $d['tanggal_distribusi']);
        $this->assertDataSesi($d);
    }

    /**
     * Batas pengajuan & persetujuan diisi TANGGAL saja di layar (permintaan user 20 Okt 2026) dan berlaku
     * SAMPAI AKHIR HARI itu: "2026-10-24" → "2026-10-24 23:59:59". Nilai yang sudah berjam dibiarkan.
     */
    private static function batasAkhirHari(array $d): array
    {
        foreach (['batas_pengajuan', 'batas_persetujuan'] as $k) {
            if (isset($d[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d[$k])) {
                $d[$k] .= ' 23:59:59';
            }
        }

        return $d;
    }

    /** Pengajuan ≤ persetujuan ≤ ambil di bank ≤ distribusi (dua terakhir tanggal saja). */
    private function assertUrutan(string $pengajuan, string $persetujuan, string $ambil, string $distribusi): void
    {
        if (strtotime($pengajuan) > strtotime($persetujuan)) {
            throw new AppException(422, 'Batas persetujuan harus sesudah batas pengajuan.');
        }
        if (strtotime(date('Y-m-d', strtotime($persetujuan))) > strtotime($ambil)) {
            throw new AppException(422, 'Tanggal ambil di bank tak boleh sebelum batas persetujuan.');
        }
        if (strtotime($ambil) > strtotime($distribusi)) {
            throw new AppException(422, 'Tanggal distribusi tak boleh sebelum tanggal ambil di bank.');
        }
    }

    private function assertDataSesi(array $d): void
    {
        if ($d['kode_coa_bank'] === $d['kode_coa_kas']) {
            throw new AppException(422, 'Rekening bank dan kas tunai kegiatan harus berbeda.');
        }
        $this->assertKelipatan((string) $d['maks_per_santri'], 'Batas per santri');
    }

    private function assertKelipatan(string $nominal, string $nama = 'Nominal'): string
    {
        $n = Money::of($nominal);
        if (! Money::gtZero($n) || ! preg_match('/^\d+\.00$/', $n) || ((int) $n) % self::KELIPATAN !== 0) {
            throw new AppException(422, "{$nama} harus kelipatan Rp 50.000.");
        }

        return $n;
    }

    private function assertNominal(SesiTarikTunai $sesi, string $nominal): string
    {
        $n = $this->assertKelipatan($nominal);
        if (Money::gt($n, $sesi->maks_per_santri)) {
            throw new AppException(422, 'Melebihi batas kegiatan ini, '.self::rp($sesi->maks_per_santri).' per santri.');
        }

        return $n;
    }

    /** Dompet ada, tak dikunci, saldo tersedia cukup (diulang di dalam transaksi). */
    private function assertDompet(Santri $santri, string $nominal, ?int $kecuali = null): void
    {
        $dompet = DompetSantri::where('id_santri', $santri->id)->first();
        if (! $dompet) {
            throw new AppException(422, "{$santri->nama} belum punya uang saku.");
        }
        if ($dompet->kunci_tarik) {
            throw new AppException(422, "Uang saku {$santri->nama} sedang dikunci dari penarikan oleh pesantren. Hubungi bagian kesantrian.");
        }
        $tersedia = Money::sub($dompet->saldo, self::cadangan($santri->id, $kecuali));
        if (Money::lt($tersedia, $nominal)) {
            throw new AppException(422, "Uang saku {$santri->nama} tidak cukup (tersedia ".self::rp(Money::isNegative($tersedia) ? '0' : $tersedia).').');
        }
    }

    private function sesiBerjalan(int $idSesi): SesiTarikTunai
    {
        $sesi = SesiTarikTunai::find($idSesi) ?? throw new AppException(404, 'Sesi tidak ditemukan.');
        if ($sesi->status !== 'berjalan') {
            throw new AppException(422, 'Sesi ini sudah '.strtolower($sesi->labelTahap()).' — tak bisa diubah lagi.');
        }

        return $sesi;
    }

    private function sesiTerbukaUntukWali(int $idSesi): SesiTarikTunai
    {
        $sesi = SesiTarikTunai::find($idSesi);
        if (! $sesi || ! $sesi->dibuka || $sesi->status !== 'berjalan') {
            throw new AppException(422, 'Penarikan tunai kegiatan ini tidak dibuka.');
        }
        if ($sesi->tahap() !== 'pengajuan') {
            throw new AppException(422, 'Batas pengajuan kegiatan ini sudah lewat (akhir '.$sesi->batas_pengajuan->format('d/m/Y').').');
        }

        return $sesi;
    }

    private function pengajuanMilik(Wali $wali, int $id): PengajuanTarikTunai
    {
        $p = PengajuanTarikTunai::with(['sesi', 'santri'])->where('id', $id)->where('id_wali', $wali->id)->first();
        if (! $p || ! in_array($p->status, ['diajukan', 'disetujui'], true)) {
            throw new AppException(422, 'Pengajuan tidak ditemukan atau sudah tidak bisa diubah.');
        }

        return $p;
    }

    private function pengajuanUntukDiputus(int $id): PengajuanTarikTunai
    {
        $p = PengajuanTarikTunai::with(['sesi', 'santri'])->lockForUpdate()->find($id) ?? throw new AppException(404, 'Pengajuan tidak ditemukan.');
        if ($p->status !== 'diajukan') {
            throw new AppException(422, 'Pengajuan ini sudah berstatus "'.strtolower($p->labelStatus()).'".');
        }
        if ($p->sesi->status !== 'berjalan' || now()->gt($p->sesi->batas_persetujuan)) {
            throw new AppException(422, 'Batas persetujuan sudah lewat — pengajuan ini gugur.');
        }

        return $p;
    }

    private function kabari(PengajuanTarikTunai $p, string $judul, string $pesan): void
    {
        KabarWali::kePencatat((object) ['dicatat_oleh' => null, 'dicatat_oleh_wali' => $p->id_wali, 'dicatat_oleh_peran' => $p->peran], [
            'judul' => $judul, 'pesan' => $pesan, 'jenis' => 'tarik_tunai', 'id_santri' => $p->id_santri,
            'ref_jenis' => 'PengajuanTarikTunai', 'ref_id' => (string) $p->id,
        ]);
    }

    private static function rp(string|int|float $n): string
    {
        return 'Rp '.number_format((float) $n, 0, ',', '.');
    }
}
