<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\ApprovalLog;
use App\Models\ApprovalStep;
use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Ledger\AnggaranPolicy;
use App\Services\Ledger\PeringkatPengajuan;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * MESIN APPROVAL BERTINGKAT — generik, tidak tahu isi dokumennya. Rantai
 * digerakkan PERINGKAT (LevelPengajuan, 1 = tertinggi). Tahap keuangan adalah
 * tahap FUNGSI (di luar tangga pangkat, selalu terakhir). Efek dokumen (posting
 * jurnal dll) ditentukan modul lewat handler yang didaftarkan.
 *
 * Modul dokumen mendaftarkan handler/validator/dll di ServiceProvider (boot).
 */
class ApprovalService
{
    /** @var array<string,callable> */
    private static array $handlers = [];
    /** @var array<string,callable> */
    private static array $selesai = [];
    /** @var array<string,callable> */
    private static array $validators = [];
    /** @var array<string,callable> */
    private static array $pratinjauers = [];
    /** @var array<string,callable> */
    private static array $penolakan = [];

    public static function daftarHandler(string $jenis, callable $fn): void { self::$handlers[$jenis] = $fn; }
    public static function daftarSelesai(string $jenis, callable $fn): void { self::$selesai[$jenis] = $fn; }
    public static function daftarValidator(string $jenis, callable $fn): void { self::$validators[$jenis] = $fn; }
    public static function daftarPratinjau(string $jenis, callable $fn): void { self::$pratinjauers[$jenis] = $fn; }
    public static function daftarPenolakan(string $jenis, callable $fn): void { self::$penolakan[$jenis] = $fn; }

    /** Reset registry (dipakai test). */
    public static function resetRegistry(): void
    {
        self::$handlers = self::$selesai = self::$validators = self::$pratinjauers = self::$penolakan = [];
    }

    private function notif(): NotificationService
    {
        return new NotificationService;
    }

    // ---- Helper murni ----

    private function tahapAktif(ApprovalStep $step, object $inst): bool
    {
        if ($step->nominal_min !== null && Money::lt($inst->nominal ?? 0, $step->nominal_min)) {
            return false;
        }
        if ($step->syarat === 'overbudget' && ! ($inst->overbudget || $inst->belum_dianggarkan)) {
            return false;
        }

        return true;
    }

    private function tahapBerikutnya($steps, object $inst, int $urutan): ?ApprovalStep
    {
        return $steps->first(fn ($s) => $s->urutan > $urutan && $this->tahapAktif($s, $inst));
    }

    private function flowAktif(string $jenisDokumen): ApprovalFlow
    {
        $flow = ApprovalFlow::where('jenis_dokumen', $jenisDokumen)
            ->where('status', 'aktif')
            ->with(['steps' => fn ($q) => $q->orderBy('urutan')])
            ->first();
        if (! $flow) {
            throw new AppException(400, "Belum ada rantai persetujuan aktif untuk {$jenisDokumen}.");
        }
        if ($flow->steps->isEmpty()) {
            throw new AppException(422, "Rantai {$flow->kode_flow} belum punya tahap.");
        }

        return $flow;
    }

    private function berwenangAtas(User $user, ApprovalStep $step): bool
    {
        return $step->fungsi === PeringkatPengajuan::FUNGSI_KEUANGAN
            ? (bool) $user->tim_keuangan
            : $user->peringkat_pengajuan === $step->peringkat;
    }

    private function penyetujuTahap(ApprovalStep $step, object $inst)
    {
        $calon = fn () => User::query()
            ->where('status', 'aktif')
            ->when(
                $step->fungsi === PeringkatPengajuan::FUNGSI_KEUANGAN,
                fn ($q) => $q->where('tim_keuangan', true),
                fn ($q) => $q->where('peringkat_pengajuan', $step->peringkat),
            );

        if ($step->scope === 'bagian' && $inst->kode_bagian) {
            return $calon()->where('kode_bagian', $inst->kode_bagian)->get(['id_pengguna', 'nama']);
        }

        // `induk`: atasan yang MEMBAWAHI bagian pemohon, ditelusuri lewat
        // bagian.kode_induk. Tanpa ini, tahap ber-scope yayasan menawarkan
        // SELURUH pemegang peringkat itu — termasuk mudir direktorat lain yang
        // sama sekali tak membawahi pemohonnya.
        if ($step->scope === 'induk' && $inst->kode_bagian) {
            foreach ($this->rantaiInduk($inst->kode_bagian) as $kodeInduk) {
                $ketemu = $calon()->where('kode_bagian', $kodeInduk)->get(['id_pengguna', 'nama']);
                if ($ketemu->isNotEmpty()) {
                    return $ketemu;
                }
            }
            // Nihil sampai akar → jatuh ke daftar penuh. Dokumen yang tak punya
            // seorang pun penyetuju jauh lebih buruk daripada daftar kelebaran:
            // ia diam di tempat tanpa siapa pun merasa ditagih.
        }

        return $calon()->get(['id_pengguna', 'nama']);
    }

    /**
     * Kode bagian dari INDUK TERDEKAT sampai akar (bagian pemohon sendiri tidak
     * ikut). Berhenti bila induknya melingkar — data master yang salah tak boleh
     * menggantung permintaan sampai kehabisan waktu.
     *
     * @return list<string>
     */
    private function rantaiInduk(string $kodeBagian): array
    {
        $rantai = [];
        $dilewati = [$kodeBagian => true];
        $sekarang = Bagian::find($kodeBagian)?->kode_induk;

        while ($sekarang && ! isset($dilewati[$sekarang])) {
            $rantai[] = $sekarang;
            $dilewati[$sekarang] = true;
            $sekarang = Bagian::find($sekarang)?->kode_induk;
        }

        return $rantai;
    }

    private function sebutanTahap(ApprovalStep $step): string
    {
        if ($step->fungsi) {
            return "tim {$step->fungsi}";
        }
        $lv = LevelPengajuan::find($step->peringkat);

        return $lv->nama ?? "peringkat {$step->peringkat}";
    }

    /**
     * Data tampilan rantai persetujuan (ApprovalTimeline): tahap yang BERLAKU
     * untuk dokumen ini (tahap yang dilewati karena syarat/nominal tak terpenuhi
     * dibuang) + posisi "sekarang menunggu" (tahap berjalan + kandidat penyetuju)
     * + riwayat. Return null bila dokumen belum pernah diajukan.
     *
     * @return array<string,mixed>|null
     */
    public function timeline(string $jenisDokumen, string $idDokumen): ?array
    {
        $inst = ApprovalInstance::with(['logs' => fn ($q) => $q->orderBy('waktu')])
            ->where('jenis_dokumen', $jenisDokumen)
            ->where('id_dokumen', $idDokumen)
            ->first();
        if (! $inst) {
            return null;
        }

        $flow = ApprovalFlow::with(['steps' => fn ($q) => $q->orderBy('urutan')])
            ->where('kode_flow', $inst->kode_flow)->first();
        $steps = $flow ? $flow->steps : collect();

        $tahap = $steps->filter(fn ($s) => $this->tahapAktif($s, $inst))
            ->map(fn ($s) => [
                'urutan' => $s->urutan,
                'nama_tahap' => $s->nama_tahap,
                'fungsi' => $s->fungsi,
                'peringkat' => $s->peringkat,
                'nama_level_pengajuan' => $s->peringkat ? optional(LevelPengajuan::find($s->peringkat))->nama : null,
                'scope' => $s->scope,
            ])->values()->all();

        return [
            'status' => $inst->status,
            'tahap_sekarang' => $inst->tahap_sekarang,
            'overbudget' => (bool) $inst->overbudget,
            'belum_dianggarkan' => (bool) $inst->belum_dianggarkan,
            'nominal' => $inst->nominal,
            'kode_bagian' => $inst->kode_bagian,
            'tahap' => $tahap,
            'menunggu' => $this->posisiSekarang($inst, $steps),
            'logs' => $inst->logs,
        ];
    }

    /**
     * Posisi "sekarang menunggu" RINGKAS sebuah dokumen (untuk kolom daftar).
     * Null bila tak sedang berjalan.
     *
     * @return array<string,mixed>|null
     */
    public function posisi(string $jenisDokumen, string $idDokumen): ?array
    {
        $inst = ApprovalInstance::where('jenis_dokumen', $jenisDokumen)
            ->where('id_dokumen', $idDokumen)->first();
        if (! $inst || $inst->status !== 'berjalan') {
            return null;
        }
        $flow = ApprovalFlow::with(['steps' => fn ($q) => $q->orderBy('urutan')])
            ->where('kode_flow', $inst->kode_flow)->first();

        return $this->posisiSekarang($inst, $flow ? $flow->steps : collect());
    }

    /**
     * Bolehkah pengguna ini memutuskan (setuju/tolak) tahap yang sedang
     * berjalan? Dipakai layar dokumen untuk memunculkan tombolnya.
     *
     * Aturannya SENGAJA dibaca dari sumber yang sama dengan approve(): kalau
     * layar memakai aturannya sendiri, tombol bisa muncul untuk orang yang
     * nanti ditolak rutenya — atau lebih buruk, tak muncul untuk orang yang
     * sebenarnya berwenang.
     */
    public function bolehMemutuskan(string $jenisDokumen, string $idDokumen, int $idPengguna): bool
    {
        $inst = ApprovalInstance::where('jenis_dokumen', $jenisDokumen)
            ->where('id_dokumen', $idDokumen)->first();
        if (! $inst || $inst->status !== 'berjalan') {
            return false;
        }

        $flow = ApprovalFlow::with(['steps' => fn ($q) => $q->orderBy('urutan')])
            ->where('kode_flow', $inst->kode_flow)->first();
        $step = $flow?->steps->firstWhere('urutan', $inst->tahap_sekarang);
        $user = User::find($idPengguna);
        if (! $step || ! $user || $user->status !== 'aktif' || ! $this->berwenangAtas($user, $step)) {
            return false;
        }

        if ($step->scope === 'bagian') {
            return $user->kode_bagian === $inst->kode_bagian;
        }
        if ($step->scope === 'induk') {
            return $this->penyetujuTahap($step, $inst)->contains('id_pengguna', $idPengguna);
        }

        return true;
    }

    /** "Sekarang menunggu di siapa" — tahap berjalan + kandidat penyetuju. */
    private function posisiSekarang(object $inst, $steps): ?array
    {
        if ($inst->status !== 'berjalan') {
            return null;
        }
        $step = $steps->firstWhere('urutan', $inst->tahap_sekarang);
        if (! $step) {
            return null;
        }

        return [
            'nama_tahap' => $step->nama_tahap,
            'peringkat' => $step->peringkat,
            'nama_level_pengajuan' => $step->peringkat ? optional(LevelPengajuan::find($step->peringkat))->nama : null,
            'fungsi' => $step->fungsi,
            'scope' => $step->scope,
            'kandidat' => $this->penyetujuTahap($step, $inst)->pluck('nama')->all(),
        ];
    }

    private function notifTahap(ApprovalStep $step, object $inst): void
    {
        $calon = $this->penyetujuTahap($step, $inst);
        $this->notif()->kirim($calon->map(fn ($u) => [
            'id_pengguna' => $u->id_pengguna,
            'judul' => "Menunggu persetujuan Anda — {$step->nama_tahap}",
            'pesan' => "{$inst->jenis_dokumen} {$inst->id_dokumen} menunggu persetujuan pada tahap \"{$step->nama_tahap}\".",
            'jenis' => 'approval_menunggu',
            'ref_jenis' => $inst->jenis_dokumen,
            'ref_id' => $inst->id_dokumen,
        ])->all());
    }

    /** @return array{overbudget:bool,belum_dianggarkan:bool} */
    private function nilaiAnggaran(array $input): array
    {
        if (! empty($input['evaluasi'])) {
            return $input['evaluasi'];
        }
        if (empty($input['kode_coa']) || empty($input['kode_bagian']) || empty($input['tahun']) || empty($input['bulan']) || empty($input['nominal'])) {
            return ['overbudget' => false, 'belum_dianggarkan' => false];
        }
        $ev = AnggaranPolicy::evaluasiAnggaran([
            'tahun' => $input['tahun'], 'bulan' => $input['bulan'], 'kode_coa' => $input['kode_coa'],
            'kode_bagian' => $input['kode_bagian'], 'nominal' => $input['nominal'],
        ]);

        return ['overbudget' => $ev['overbudget'], 'belum_dianggarkan' => $ev['belum_dianggarkan']];
    }

    // ---- Operasi utama ----

    /** Ajukan dokumen ke rantai persetujuan. 1 dokumen = 1 instance. */
    public function submit(array $input): ApprovalInstance
    {
        return DB::transaction(function () use ($input) {
            $flow = $this->flowAktif($input['jenis_dokumen']);

            $sudah = ApprovalInstance::where('jenis_dokumen', $input['jenis_dokumen'])
                ->where('id_dokumen', $input['id_dokumen'])->first();
            if ($sudah) {
                throw new AppException(409, 'Dokumen ini sudah pernah diajukan.');
            }

            $pemohon = User::find($input['id_pemohon']);
            if (! $pemohon) {
                throw new AppException(400, 'Pemohon tidak ditemukan.');
            }

            ['overbudget' => $overbudget, 'belum_dianggarkan' => $belum] = $this->nilaiAnggaran($input);

            $dasar = [
                'kode_flow' => $flow->kode_flow,
                'jenis_dokumen' => $input['jenis_dokumen'],
                'id_dokumen' => $input['id_dokumen'],
                'kode_bagian' => $input['kode_bagian'] ?? $pemohon->kode_bagian,
                'kode_coa' => $input['kode_coa'] ?? null,
                'tahun' => $input['tahun'] ?? null,
                'bulan' => $input['bulan'] ?? null,
                'nominal' => $input['nominal'] ?? null,
                'overbudget' => $overbudget,
                'belum_dianggarkan' => $belum,
                'id_pemohon' => $input['id_pemohon'],
            ];

            $semu = (object) array_merge($dasar, ['nominal' => $dasar['nominal']]);
            $pertama = $flow->steps->first(fn ($s) => $this->tahapAktif($s, $semu));
            if (! $pertama) {
                throw new AppException(422, 'Tidak ada tahap yang berlaku untuk dokumen ini.');
            }

            $inst = ApprovalInstance::create(array_merge($dasar, ['tahap_sekarang' => $pertama->urutan, 'status' => 'berjalan']));

            ApprovalLog::create([
                'id_instance' => $inst->id, 'urutan' => 0, 'id_pengguna' => $input['id_pemohon'],
                'nama_pengguna' => $pemohon->nama, 'aksi' => 'ajukan',
                'catatan' => $overbudget
                    ? 'Diajukan — terdeteksi OVERBUDGET, rantai menyertakan eskalasi.'
                    : ($belum ? 'Diajukan — akun belum dianggarkan, rantai menyertakan eskalasi.' : 'Diajukan.'),
                'waktu' => now(),
            ]);

            $this->notifTahap($pertama, $inst);

            return $inst;
        });
    }

    /** Ajukan ULANG dokumen yang ditolak. Hanya pemohonnya. Anggaran dinilai ulang. */
    public function ajukanUlang(array $input): ApprovalInstance
    {
        return DB::transaction(function () use ($input) {
            $inst = ApprovalInstance::where('jenis_dokumen', $input['jenis_dokumen'])
                ->where('id_dokumen', $input['id_dokumen'])->first();
            if (! $inst) {
                throw new AppException(404, 'Dokumen ini belum pernah diajukan.');
            }
            if ($inst->status !== 'ditolak') {
                throw new AppException(409, "Hanya pengajuan yang ditolak bisa diajukan ulang; ini {$inst->status}.");
            }
            if ($inst->id_pemohon !== $input['id_pemohon']) {
                throw new AppException(403, 'Hanya pemohon yang bisa mengajukan ulang pengajuannya.');
            }

            $flow = $this->flowAktif($input['jenis_dokumen']);
            $pemohon = User::find($input['id_pemohon']);
            if (! $pemohon) {
                throw new AppException(400, 'Pemohon tidak ditemukan.');
            }

            ['overbudget' => $overbudget, 'belum_dianggarkan' => $belum] = $this->nilaiAnggaran($input);
            $semu = (object) [
                'nominal' => $input['nominal'] ?? null, 'overbudget' => $overbudget,
                'belum_dianggarkan' => $belum, 'kode_bagian' => $inst->kode_bagian,
            ];
            $pertama = $flow->steps->first(fn ($s) => $this->tahapAktif($s, $semu));
            if (! $pertama) {
                throw new AppException(422, 'Tidak ada tahap yang berlaku untuk dokumen ini.');
            }

            $inst->update([
                'tahap_sekarang' => $pertama->urutan, 'status' => 'berjalan',
                'nominal' => $input['nominal'] ?? $inst->nominal,
                'kode_coa' => $input['kode_coa'] ?? $inst->kode_coa,
                'tahun' => $input['tahun'] ?? $inst->tahun,
                'bulan' => $input['bulan'] ?? $inst->bulan,
                'overbudget' => $overbudget, 'belum_dianggarkan' => $belum,
            ]);

            ApprovalLog::create([
                'id_instance' => $inst->id, 'urutan' => 0, 'id_pengguna' => $input['id_pemohon'],
                'nama_pengguna' => $pemohon->nama, 'aksi' => 'ajukan',
                'catatan' => $overbudget
                    ? 'Diajukan ulang setelah perbaikan — terdeteksi OVERBUDGET, rantai menyertakan eskalasi.'
                    : ($belum ? 'Diajukan ulang setelah perbaikan — akun belum dianggarkan, rantai menyertakan eskalasi.' : 'Diajukan ulang setelah perbaikan.'),
                'waktu' => now(),
            ]);

            $this->notifTahap($pertama, $inst->refresh());

            return $inst;
        });
    }

    /** Setujui tahap sekarang. Bila tahap terakhir → status disetujui + dispatch. */
    public function approve(int $idInstance, int $idPengguna, ?string $catatan = null): ApprovalInstance
    {
        $hasil = DB::transaction(function () use ($idInstance, $idPengguna, $catatan) {
            $inst = ApprovalInstance::find($idInstance);
            if (! $inst) {
                throw new AppException(404, 'Pengajuan tidak ditemukan.');
            }
            if ($inst->status !== 'berjalan') {
                throw new AppException(409, "Pengajuan ini sudah {$inst->status}.");
            }

            $flow = $this->flowAktif($inst->jenis_dokumen);
            $step = $flow->steps->firstWhere('urutan', $inst->tahap_sekarang);
            if (! $step) {
                throw new AppException(422, 'Tahap sekarang tidak ada di rantai.');
            }

            $user = User::find($idPengguna);
            if (! $user) {
                throw new AppException(400, 'Pengguna tidak ditemukan.');
            }
            if (! $this->berwenangAtas($user, $step)) {
                throw new AppException(403, "Tahap \"{$step->nama_tahap}\" hanya bisa disetujui oleh {$this->sebutanTahap($step)}.");
            }
            if ($step->scope === 'bagian' && $user->kode_bagian !== $inst->kode_bagian) {
                throw new AppException(403, 'Anda bukan penyetuju dari bagian pemohon.');
            }
            // Scope `induk` ditegakkan lewat daftar kandidat yang sama dengan
            // yang ditampilkan — kalau hanya disaring di layar, mudir direktorat
            // lain tetap bisa menyetujui dengan menebak alamat rutenya.
            if ($step->scope === 'induk'
                && ! $this->penyetujuTahap($step, $inst)->contains('id_pengguna', $idPengguna)) {
                throw new AppException(403, 'Tahap ini hanya bisa disetujui oleh atasan yang membawahi bagian pemohon.');
            }

            ApprovalLog::create([
                'id_instance' => $inst->id, 'urutan' => $step->urutan, 'id_pengguna' => $idPengguna,
                'nama_pengguna' => $user->nama, 'aksi' => 'approve', 'catatan' => $catatan, 'waktu' => now(),
            ]);

            $lanjut = $this->tahapBerikutnya($flow->steps, $inst, $step->urutan);
            if ($lanjut) {
                $inst->update(['tahap_sekarang' => $lanjut->urutan]);
                $this->notifTahap($lanjut, $inst->refresh());

                return ['selesai' => false, 'instance' => $inst];
            }

            // Tahap terakhir → validator dokumen (bila belum siap, approve batal).
            if (isset(self::$validators[$inst->jenis_dokumen])) {
                (self::$validators[$inst->jenis_dokumen])($inst->id_dokumen);
            }

            $inst->update(['status' => 'disetujui']);

            if (isset(self::$selesai[$inst->jenis_dokumen])) {
                (self::$selesai[$inst->jenis_dokumen])($inst->id_dokumen);
            }

            $this->notif()->kirim([[
                'id_pengguna' => $inst->id_pemohon, 'judul' => 'Pengajuan disetujui',
                'pesan' => "{$inst->jenis_dokumen} {$inst->id_dokumen} telah disetujui seluruh tahap.",
                'jenis' => 'approval_selesai', 'ref_jenis' => $inst->jenis_dokumen, 'ref_id' => $inst->id_dokumen,
            ]]);

            return ['selesai' => true, 'instance' => $inst];
        });

        // Dispatch DI LUAR transaksi: efek dokumen punya transaksinya sendiri.
        if ($hasil['selesai']) {
            $inst = $hasil['instance'];
            if (isset(self::$handlers[$inst->jenis_dokumen])) {
                (self::$handlers[$inst->jenis_dokumen])($inst->id_dokumen, $idPengguna);
                $inst->update(['posted' => true]);

                return $inst->refresh();
            }
        }

        return $hasil['instance'];
    }

    public function reject(int $idInstance, int $idPengguna, string $alasan): ApprovalInstance
    {
        return DB::transaction(function () use ($idInstance, $idPengguna, $alasan) {
            $inst = ApprovalInstance::find($idInstance);
            if (! $inst) {
                throw new AppException(404, 'Pengajuan tidak ditemukan.');
            }
            if ($inst->status !== 'berjalan') {
                throw new AppException(409, "Pengajuan ini sudah {$inst->status}.");
            }

            $flow = $this->flowAktif($inst->jenis_dokumen);
            $step = $flow->steps->firstWhere('urutan', $inst->tahap_sekarang);
            $user = User::find($idPengguna);
            if (! $user) {
                throw new AppException(400, 'Pengguna tidak ditemukan.');
            }
            if ($step && ! $this->berwenangAtas($user, $step)) {
                throw new AppException(403, "Tahap \"{$step->nama_tahap}\" hanya bisa ditolak oleh {$this->sebutanTahap($step)}.");
            }
            // Menolak sama menentukannya dengan menyetujui — dokumennya dikembalikan
            // ke pemohon. Scope-nya karena itu dijaga sama; sebelumnya tahap
            // ber-scope `bagian` pun bisa ditolak penyetuju dari bagian lain.
            if ($step && in_array($step->scope, ['bagian', 'induk'], true)
                && ! $this->penyetujuTahap($step, $inst)->contains('id_pengguna', $idPengguna)) {
                throw new AppException(403, 'Anda bukan penyetuju yang berwenang atas bagian pemohon.');
            }

            ApprovalLog::create([
                'id_instance' => $inst->id, 'urutan' => $inst->tahap_sekarang, 'id_pengguna' => $idPengguna,
                'nama_pengguna' => $user->nama, 'aksi' => 'reject', 'catatan' => $alasan, 'waktu' => now(),
            ]);
            $inst->update(['status' => 'ditolak']);

            if (isset(self::$penolakan[$inst->jenis_dokumen])) {
                (self::$penolakan[$inst->jenis_dokumen])($inst->id_dokumen);
            }

            $this->notif()->kirim([[
                'id_pengguna' => $inst->id_pemohon, 'judul' => 'Pengajuan ditolak — dikembalikan ke Anda',
                'pesan' => "{$inst->jenis_dokumen} {$inst->id_dokumen} ditolak pada tahap {$inst->tahap_sekarang}. Alasan: {$alasan}. Perbaiki lalu ajukan ulang, atau batalkan.",
                'jenis' => 'approval_ditolak', 'ref_jenis' => $inst->jenis_dokumen, 'ref_id' => $inst->id_dokumen,
            ]]);

            return $inst;
        });
    }

    /** Pengajuan yang menunggu persetujuan user ini pada tahap sekarang. */
    public function inbox(int $idPengguna)
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(400, 'Pengguna tidak ditemukan.');
        }
        $berjalan = ApprovalInstance::where('status', 'berjalan')->orderBy('created_at')->get();
        $flows = ApprovalFlow::where('status', 'aktif')->with('steps')->get();
        $stepOf = fn ($kodeFlow, $urutan) => optional($flows->firstWhere('kode_flow', $kodeFlow))->steps->firstWhere('urutan', $urutan);

        return $berjalan->filter(function ($i) use ($user, $stepOf) {
            $s = $stepOf($i->kode_flow, $i->tahap_sekarang);
            if (! $s) {
                return false;
            }
            if (! $this->berwenangAtas($user, $s)) {
                return false;
            }
            if ($s->scope === 'bagian' && $user->kode_bagian !== $i->kode_bagian) {
                return false;
            }

            return true;
        })->values();
    }
}
