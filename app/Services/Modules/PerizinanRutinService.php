<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\AturanPerizinanRutin;
use App\Models\JadwalPerizinanRutin;
use App\Models\Jenjang;
use App\Models\NotifikasiWali;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\TipeIzin;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\KabarWali;
use App\Support\Teks;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PERIZINAN RUTIN — Penjengukan & Kepulangan (Tahap 2, keputusan user 4 Nov 2026).
 *
 *  • Petugas (hak `perizinan-jadwal`) mengisi MATRIKS aturan per jenis × jenjang × ikhwan/akhwat, lalu menekan
 *    "Buat jadwal N bulan" → jadwal dibangkitkan; sel bertanggal-jam sama digabung jadi satu jadwal bercakupan.
 *  • Jadwal tetap bisa digeser, DITIADAKAN (alasan wajib, wali dikabari, pengajuan yang hidup dibatalkan),
 *    diaktifkan lagi, atau ditambah jadwal KHUSUS.
 *  • Wali mengajukan per jadwal (boleh beberapa anak sekaligus) — baris `perizinan` ber-`id_jadwal`, waktu
 *    keluar/kembali = waktu jadwal. Batas H-n, mode persetujuan, kebijakan tunggakan & penjemput mengikuti
 *    pengaturan jenis izinnya (PerizinanService).
 *  • Penjengukan dianggap selalu KELUAR bersama wali (keputusan user) → tercatat keluar/masuk di pos.
 */
class PerizinanRutinService
{
    public const MODUL_JADWAL = 'perizinan-jadwal';

    /** @return \Illuminate\Database\Eloquent\Collection<int,TipeIzin> */
    public static function tipeRutin()
    {
        return TipeIzin::where('rutin', true)->orderBy('urutan')->get();
    }

    // ---- Matriks aturan ----

    /**
     * Simpan matriks satu jenis. $sel[kode_jenjang][L|P] = [aktif, hari, pekan_ke, jam_mulai, lama_hari, jam_selesai].
     */
    public function simpanAturan(string $kodeTipe, array $sel): void
    {
        $tipe = TipeIzin::where('kode', $kodeTipe)->where('rutin', true)->first() ?? throw new AppException(422, 'Jenis perizinan rutin tidak dikenal.');
        $jenjang = Jenjang::pluck('nama', 'kode');
        DB::transaction(function () use ($tipe, $sel, $jenjang) {
            foreach ($jenjang as $kode => $nama) {
                foreach (['L', 'P'] as $jk) {
                    $s = $sel[$kode][$jk] ?? [];
                    $kunci = ['kode_tipe' => $tipe->kode, 'kode_jenjang' => $kode, 'jenis_kelamin' => $jk];
                    if (empty($s['aktif'])) {
                        AturanPerizinanRutin::where($kunci)->delete();

                        continue;
                    }
                    foreach (['hari', 'pekan_ke', 'jam_mulai', 'jam_selesai'] as $wajib) {
                        if (($s[$wajib] ?? '') === '') {
                            throw new AppException(422, "{$tipe->nama} {$nama} ".($jk === 'L' ? 'Ikhwan' : 'Akhwat').': lengkapi hari, pekan, dan jam.');
                        }
                    }
                    $lama = (int) ($s['lama_hari'] ?? 0);
                    if ($lama === 0 && $s['jam_selesai'] <= $s['jam_mulai']) {
                        throw new AppException(422, "{$tipe->nama} {$nama} ".($jk === 'L' ? 'Ikhwan' : 'Akhwat').': jam selesai harus sesudah jam mulai.');
                    }
                    AturanPerizinanRutin::updateOrCreate($kunci, ['hari' => (int) $s['hari'], 'pekan_ke' => (int) $s['pekan_ke'],
                        'jam_mulai' => $s['jam_mulai'], 'lama_hari' => $lama, 'jam_selesai' => $s['jam_selesai']]);
                }
            }
        });
    }

    /**
     * Bangkitkan jadwal untuk $bulan bulan mulai bulan $dari (Y-m). Sel yang sudah punya jadwal (jenis sama,
     * cakupan sama, bulan sama — aktif ATAU ditiadakan) dilewati, jadi tombolnya aman ditekan berulang.
     *
     * @return int jumlah jadwal baru
     */
    public function bangkitkan(string $dari, int $bulan, User $u): int
    {
        $awal = CarbonImmutable::createFromFormat('Y-m-d', $dari.'-01')->startOfDay();
        $aturan = AturanPerizinanRutin::query()->whereHas('tipe', fn ($q) => $q->where('aktif', true))->get();
        if ($aturan->isEmpty()) {
            throw new AppException(422, 'Matriks aturan masih kosong. Isi dulu hari & jam tiap jenjang.');
        }

        return DB::transaction(function () use ($awal, $bulan, $aturan, $u) {
            $baru = 0;
            for ($i = 0; $i < max(1, min(12, $bulan)); $i++) {
                $b = $awal->addMonths($i);
                $kelompok = [];
                foreach ($aturan as $a) {
                    $w = $a->waktuPada($b->year, $b->month);
                    if (! $w || $this->sudahAda($a, $b)) {
                        continue;
                    }
                    if ($w[0]->lt(now())) {
                        continue;   // tak membuat jadwal yang sudah lewat
                    }
                    $k = $a->kode_tipe.'|'.$w[0]->toDateTimeString().'|'.$w[1]->toDateTimeString();
                    $kelompok[$k] ??= ['tipe' => $a->kode_tipe, 'mulai' => $w[0], 'selesai' => $w[1], 'cakupan' => []];
                    $kelompok[$k]['cakupan'][] = ['kode_jenjang' => $a->kode_jenjang, 'jenis_kelamin' => $a->jenis_kelamin];
                }
                foreach ($kelompok as $g) {
                    $j = JadwalPerizinanRutin::create(['kode_tipe' => $g['tipe'], 'mulai' => $g['mulai'], 'selesai' => $g['selesai'], 'dibuat_oleh' => $u->id_pengguna]);
                    $j->cakupan()->createMany($g['cakupan']);
                    $baru++;
                }
            }

            return $baru;
        });
    }

    private function sudahAda(AturanPerizinanRutin $a, CarbonImmutable $bulan): bool
    {
        return JadwalPerizinanRutin::where('kode_tipe', $a->kode_tipe)
            ->where('mulai', '>=', $bulan->startOfMonth())->where('mulai', '<', $bulan->startOfMonth()->addMonth())
            ->whereHas('cakupan', fn ($c) => $c->where('kode_jenjang', $a->kode_jenjang)->where('jenis_kelamin', $a->jenis_kelamin))
            ->exists();
    }

    // ---- Jadwal satuan ----

    /** Tambah jadwal khusus. $cakupan = list "kode_jenjang|L". */
    public function tambah(array $d, User $u): JadwalPerizinanRutin
    {
        $tipe = TipeIzin::where('kode', $d['kode_tipe'] ?? '')->where('rutin', true)->first() ?? throw new AppException(422, 'Pilih jenis perizinan rutin.');
        [$mulai, $selesai] = $this->rentang($d);
        $cakupan = $this->cakupan($d['cakupan'] ?? []);

        return DB::transaction(function () use ($tipe, $mulai, $selesai, $cakupan, $d, $u) {
            $j = JadwalPerizinanRutin::create(['kode_tipe' => $tipe->kode, 'mulai' => $mulai, 'selesai' => $selesai, 'khusus' => true,
                'catatan' => trim((string) ($d['catatan'] ?? '')) ?: null, 'dibuat_oleh' => $u->id_pengguna]);
            $j->cakupan()->createMany($cakupan);

            return $j;
        });
    }

    /** Geser waktu / ubah catatan & cakupan. Pengajuan yang sudah ada ikut digeser waktunya. */
    public function ubah(JadwalPerizinanRutin $j, array $d): JadwalPerizinanRutin
    {
        [$mulai, $selesai] = $this->rentang($d);
        $cakupan = $this->cakupan($d['cakupan'] ?? []);
        DB::transaction(function () use ($j, $mulai, $selesai, $cakupan, $d) {
            $j->update(['mulai' => $mulai, 'selesai' => $selesai, 'catatan' => trim((string) ($d['catatan'] ?? '')) ?: null, 'pengingat_pada' => null]);
            $j->cakupan()->delete();
            $j->cakupan()->createMany($cakupan);
            // Izin yang belum keluar mengikuti waktu jadwal yang baru.
            Perizinan::where('id_jadwal', $j->id)->whereIn('status', ['menunggu_1', 'menunggu_2', 'disetujui'])
                ->update(['rencana_keluar' => $mulai, 'rencana_kembali' => $selesai]);
        });

        return $j->fresh('cakupan');
    }

    /** Tiadakan: alasan wajib; pengajuan yang belum keluar DIBATALKAN; keluarga dalam cakupan dikabari. */
    public function tiadakan(JadwalPerizinanRutin $j, string $alasan): void
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new AppException(422, 'Tulis alasan jadwal ditiadakan — alasan ini dibaca wali.');
        }
        if ($j->status !== 'aktif') {
            throw new AppException(422, 'Jadwal ini sudah ditiadakan.');
        }
        if (Perizinan::where('id_jadwal', $j->id)->where('status', 'keluar')->exists()) {
            throw new AppException(422, 'Sudah ada santri yang keluar dengan jadwal ini — jadwal tak bisa ditiadakan.');
        }
        DB::transaction(function () use ($j, $alasan) {
            $j->update(['status' => 'ditiadakan', 'alasan_ditiadakan' => $alasan]);
            Perizinan::where('id_jadwal', $j->id)->whereIn('status', ['menunggu_1', 'menunggu_2', 'disetujui'])
                ->update(['status' => 'dibatalkan', 'dibatalkan_pada' => now()]);
        });
        $j->load('tipe');
        $isi = ['tipe' => Teks::k($j->tipe->nama), 'tanggal' => $j->mulai->format('d/m/Y'), 'alasan' => $alasan];
        $this->kabariCakupan($j, Teks::k(':tipe :tanggal ditiadakan', $isi), Teks::k(':tipe pada :tanggal ditiadakan. Alasan: :alasan', $isi), 'tiada');
    }

    public function aktifkanLagi(JadwalPerizinanRutin $j): void
    {
        if ($j->status !== 'ditiadakan') {
            throw new AppException(422, 'Jadwal ini masih aktif.');
        }
        $j->update(['status' => 'aktif', 'alasan_ditiadakan' => null, 'pengingat_pada' => null]);
    }

    public function hapus(JadwalPerizinanRutin $j): void
    {
        if (Perizinan::where('id_jadwal', $j->id)->exists()) {
            throw new AppException(422, 'Jadwal ini sudah punya pengajuan — tiadakan saja, jangan dihapus.');
        }
        $j->delete();
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function rentang(array $d): array
    {
        if (empty($d['mulai']) || empty($d['selesai'])) {
            throw new AppException(422, 'Isi waktu mulai dan selesai.');
        }
        $mulai = Carbon::parse($d['mulai']);
        $selesai = Carbon::parse($d['selesai']);
        if ($selesai->lte($mulai)) {
            throw new AppException(422, 'Waktu selesai harus sesudah waktu mulai.');
        }

        return [$mulai, $selesai];
    }

    /** @return list<array{kode_jenjang:string,jenis_kelamin:string}> */
    private function cakupan(array $daftar): array
    {
        $jenjang = Jenjang::pluck('kode')->all();
        $hasil = [];
        foreach (array_unique($daftar) as $c) {
            [$kode, $jk] = array_pad(explode('|', (string) $c, 2), 2, '');
            if (in_array($kode, $jenjang, true) && in_array($jk, ['L', 'P'], true)) {
                $hasil[] = ['kode_jenjang' => $kode, 'jenis_kelamin' => $jk];
            }
        }
        if ($hasil === []) {
            throw new AppException(422, 'Pilih minimal satu jenjang & ikhwan/akhwat yang dicakup jadwal ini.');
        }

        return $hasil;
    }

    // ---- Pengajuan ----

    /**
     * Wali mengajukan untuk beberapa anak sekaligus. Tiap anak diproses sendiri — yang gagal dilaporkan
     * tanpa menggagalkan yang lain.
     *
     * @return array{berhasil:list<Perizinan>, gagal:array<string,string>}
     */
    public function ajukanBanyak(JadwalPerizinanRutin $j, Collection $anak, array $d, ?Wali $wali, ?string $peran, ?User $staf = null, ?UploadedFile $foto = null): array
    {
        $hasil = ['berhasil' => [], 'gagal' => []];
        foreach ($anak as $s) {
            try {
                $hasil['berhasil'][] = (new PerizinanService)->ajukan($s, $d + ['kode_tipe' => $j->kode_tipe], $wali, $peran, $staf, fotoIdentitas: $foto, jadwal: $j);
            } catch (AppException $e) {
                $hasil['gagal'][$s->nama] = $e->getMessage();
            }
        }

        return $hasil;
    }

    /** Setujui semua pengajuan jadwal ini yang menunggu keputusan PENGGUNA INI. @return int */
    public function setujuiSemua(JadwalPerizinanRutin $j, User $u): int
    {
        $n = 0;
        foreach (Perizinan::where('id_jadwal', $j->id)->whereIn('status', Perizinan::MENUNGGU)->orderBy('id')->get() as $z) {
            if (PerizinanService::bolehMemutus($z)) {
                (new PerizinanService)->setujui($z, $u);
                $n++;
            }
        }

        return $n;
    }

    /** Jadwal yang relevan untuk keluarga: aktif/ditiadakan, belum selesai, mulai ≤ 60 hari lagi, mencakup salah satu anak. */
    public static function untukKeluarga(Collection $anak): Collection
    {
        if ($anak->isEmpty()) {
            return collect();
        }

        return JadwalPerizinanRutin::with(['tipe', 'cakupan'])->where('selesai', '>=', now())->where('mulai', '<=', now()->addDays(60))
            ->whereHas('tipe', fn ($q) => $q->where('aktif', true))
            ->orderBy('mulai')->get()
            ->filter(fn (JadwalPerizinanRutin $j) => $anak->contains(fn ($s) => $j->mencakup($s)))->values();
    }

    // ---- Pengingat & Tugas Saya ----

    /**
     * Kabar sehari sebelum batas pengajuan (atau 2 hari sebelum mulai bila tanpa batas) ke keluarga yang
     * anaknya tercakup dan belum mengajukan. Sekali per jadwal (`pengingat_pada`).
     */
    public function kirimPengingat(): int
    {
        $n = 0;
        $jadwal = JadwalPerizinanRutin::with(['tipe', 'cakupan'])->where('status', 'aktif')->whereNull('pengingat_pada')
            ->where('mulai', '>', now())->where('mulai', '<=', now()->addDays(60))->get();
        foreach ($jadwal as $j) {
            $batas = $j->batasPengajuan() ?? $j->mulai->copy()->subDays(1)->endOfDay();
            if (now()->lt($batas->copy()->subDay()->startOfDay()) || now()->gt($batas)) {
                continue;
            }
            $isi = ['tipe' => Teks::k($j->tipe->nama), 'tanggal' => $j->mulai->format('d/m/Y'), 'batas' => $batas->format('d/m/Y')];
            $n += $this->kabariCakupan($j, Teks::k('Pengajuan :tipe ditutup :batas', $isi),
                Teks::k(':tipe :tanggal: pengajuan ditutup :batas. Ajukan lewat menu Perizinan Rutin.', $isi), 'pengingat', hanyaBelumMengajukan: true);
            $j->update(['pengingat_pada' => now()]);
        }

        return $n;
    }

    /** @return int jumlah keluarga yang dikabari */
    private function kabariCakupan(JadwalPerizinanRutin $j, Teks $judul, Teks $pesan, string $kunci, bool $hanyaBelumMengajukan = false): int
    {
        $sudah = $hanyaBelumMengajukan ? Perizinan::where('id_jadwal', $j->id)->whereNotIn('status', ['dibatalkan', 'ditolak'])->pluck('id_santri')->all() : [];
        $keluarga = $j->santriQuery()->whereNotIn('id', $sudah)->pluck('id_wali')->filter()->unique();
        $n = 0;
        foreach (Wali::whereIn('id', $keluarga)->where('status', 'aktif')->get() as $w) {
            try {
                KabarWali::keKeluarga($w, ['judul' => $judul, 'pesan' => $pesan, 'jenis' => 'perizinan',
                    'ref_jenis' => 'JadwalPerizinanRutin', 'ref_id' => $j->id.':'.$kunci]);
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    /** Tugas Saya (hak perizinan-jadwal): ada aturan berlaku tetapi bulan depan belum berjadwal sama sekali. */
    public static function jadwalBulanDepanBelumAda(): int
    {
        $bulan = now()->addMonth()->startOfMonth();
        $tipe = AturanPerizinanRutin::query()->distinct()->pluck('kode_tipe');
        $n = 0;
        foreach ($tipe as $k) {
            $ada = JadwalPerizinanRutin::where('kode_tipe', $k)->where('mulai', '>=', $bulan)->where('mulai', '<', $bulan->copy()->addMonth())->exists();
            $n += $ada ? 0 : 1;
        }

        return $n;
    }
}
