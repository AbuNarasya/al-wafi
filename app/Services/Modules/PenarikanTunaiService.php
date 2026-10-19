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

    public function ubahSesi(int $idSesi, array $d): SesiTarikTunai
    {
        $sesi = $this->sesiBerjalan($idSesi);
        $this->assertJadwal($d);
        $lewat = $sesi->pengajuan()->whereIn('status', ['diajukan', 'disetujui'])
            ->whereRaw('COALESCE(nominal_disetujui, nominal) > ?', [Money::of($d['maks_per_santri'])])->count();
        if ($lewat) {
            throw new AppException(422, "Ada {$lewat} pengajuan di atas batas baru itu. Putus atau minta wali mengubahnya dulu.");
        }
        $sesi->update([
            'nama' => trim($d['nama']), 'keterangan' => $d['keterangan'] ?? null,
            'batas_pengajuan' => $d['batas_pengajuan'], 'batas_persetujuan' => $d['batas_persetujuan'],
            'tanggal_ambil' => $d['tanggal_ambil'], 'tanggal_distribusi' => $d['tanggal_distribusi'],
            'maks_per_santri' => Money::of($d['maks_per_santri']), 'kode_coa_bank' => $d['kode_coa_bank'], 'kode_coa_kas' => $d['kode_coa_kas'],
        ]);

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

        return DB::transaction(function () use ($wali, $peran, $sesi, $santri, $nominal) {
            $dompet = DompetSantri::where('id_santri', $santri->id)->lockForUpdate()->first();
            self::pastikanTersedia($santri->id, $dompet->saldo, $nominal, "Uang saku {$santri->nama} tidak cukup");
            $base = DocNumber::docBase('TTK', now());

            return PengajuanTarikTunai::create([
                'nomor' => DocNumber::nextDocNumber($base, PengajuanTarikTunai::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_sesi' => $sesi->id_sesi, 'id_santri' => $santri->id, 'id_wali' => $wali->id, 'peran' => $peran,
                'nominal' => $nominal, 'status' => 'diajukan',
            ]);
        });
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
        $urut = [$d['batas_pengajuan'], $d['batas_persetujuan'], $d['tanggal_ambil'].' 23:59:59', $d['tanggal_distribusi'].' 23:59:59'];
        if (strtotime($urut[0]) > strtotime($urut[1])) {
            throw new AppException(422, 'Batas persetujuan harus sesudah batas pengajuan.');
        }
        if (strtotime(substr($urut[1], 0, 10)) > strtotime($d['tanggal_ambil'])) {
            throw new AppException(422, 'Tanggal ambil di bank tak boleh sebelum batas persetujuan.');
        }
        if (strtotime($d['tanggal_ambil']) > strtotime($d['tanggal_distribusi'])) {
            throw new AppException(422, 'Tanggal distribusi tak boleh sebelum tanggal ambil di bank.');
        }
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
            throw new AppException(422, 'Batas pengajuan kegiatan ini sudah lewat ('.$sesi->batas_pengajuan->format('d/m/Y H:i').').');
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
