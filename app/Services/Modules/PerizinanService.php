<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\HakAksesModul;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\TipeIzin;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Services\PortalWali\KabarWali;
use App\Support\Akses;
use App\Support\Teks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERIZINAN INSIDENTAL (keputusan user 4 Nov 2026).
 *
 *   wali mengajukan (portal) / staf atas nama wali
 *     → [tingkat 1] → [tingkat 2]        — tingkat yang berlaku diatur di pengaturan_perizinan (satu atau keduanya)
 *     → disetujui → KELUAR (pos: tap kartu / cari nama) → KEMBALI (pos)
 *   Ditolak (alasan wajib) di tingkat mana pun; wali boleh membatalkan selama santri belum keluar.
 *
 *  • Tipe izin: Sakit, Haji/Umroh, Keperluan Keluarga, Lain-lain (izin PULANG) & Keluar Lingkungan Ma'had (kembali
 *    di HARI YANG SAMA). Keperluan wajib untuk tipe bertanda. Batas waktu pengajuan per tipe (jam sebelum keluar)
 *    hanya berlaku bagi wali — staf boleh mencatat izin mendadak.
 *  • Satu santri tak boleh punya dua izin hidup yang rentangnya bertumpuk.
 *  • Hak: `perizinan` (lihat · buat = atas nama wali · ubah = pos keluar/masuk), `perizinan-tingkat-1` &
 *    `perizinan-tingkat-2` (ubah = setujui/tolak), `perizinan-pengaturan`.
 */
class PerizinanService
{
    public const MODUL_TINGKAT = [1 => 'perizinan-tingkat-1', 2 => 'perizinan-tingkat-2'];

    /** @return object{tingkat1_aktif:bool, tingkat2_aktif:bool, label_tingkat1:string, label_tingkat2:string} */
    public static function pengaturan(): object
    {
        $p = DB::table('pengaturan_perizinan')->where('id', 1)->first()
            ?? (object) ['tingkat1_aktif' => false, 'tingkat2_aktif' => true, 'label_tingkat1' => 'Musyrif / Wali Asrama', 'label_tingkat2' => 'Kesantrian'];
        $p->tingkat1_aktif = (bool) $p->tingkat1_aktif;
        $p->tingkat2_aktif = (bool) $p->tingkat2_aktif;
        if (! $p->tingkat1_aktif && ! $p->tingkat2_aktif) {
            $p->tingkat2_aktif = true; // jaga-jaga: minimal satu tingkat selalu berlaku
        }

        return $p;
    }

    /**
     * @param  array{kode_tipe:string, keperluan?:?string, rencana_keluar:string, rencana_kembali:string, penjemput_nama?:?string, penjemput_hubungan?:?string, penjemput_hp?:?string}  $d
     */
    public function ajukan(Santri $santri, array $d, ?Wali $wali = null, ?string $peran = null, ?User $staf = null, ?UploadedFile $lampiran = null): Perizinan
    {
        if ($wali && (int) $santri->id_wali !== (int) $wali->id) {
            throw new AppException(404, __('Santri tidak ditemukan.'));
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, __('Perizinan hanya untuk santri aktif.'));
        }
        $tipe = TipeIzin::where('kode', $d['kode_tipe'] ?? '')->where('aktif', true)->first()
            ?? throw new AppException(422, __('Tipe izin tidak dikenal atau sedang tidak dibuka.'));
        $keperluan = trim((string) ($d['keperluan'] ?? ''));
        if ($tipe->wajib_keperluan && $keperluan === '') {
            throw new AppException(422, __('Sebutkan keperluannya.'));
        }
        $keluar = Carbon::parse($d['rencana_keluar']);
        $kembali = Carbon::parse($d['rencana_kembali']);
        if ($kembali->lte($keluar)) {
            throw new AppException(422, __('Waktu kembali harus sesudah waktu keluar.'));
        }
        if (! $tipe->pulang && ! $keluar->isSameDay($kembali)) {
            throw new AppException(422, __('Izin keluar lingkungan ma\'had harus kembali di hari yang sama.'));
        }
        // Batas waktu pengajuan hanya untuk wali; staf boleh mencatat izin mendadak.
        if (! $staf && $tipe->batas_jam > 0 && $keluar->lt(now()->addHours($tipe->batas_jam))) {
            throw new AppException(422, __('Izin :tipe diajukan paling lambat :jam jam sebelum waktu keluar.', ['tipe' => __($tipe->nama), 'jam' => $tipe->batas_jam]));
        }
        if (! $staf && $keluar->lt(now()->subMinutes(10))) {
            throw new AppException(422, __('Waktu keluar sudah lewat.'));
        }

        $p = DB::transaction(function () use ($santri, $d, $tipe, $keperluan, $keluar, $kembali, $wali, $peran, $staf, $lampiran) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('perizinan-santri-'.$santri->id)]);
            $bertumpuk = Perizinan::where('id_santri', $santri->id)->whereIn('status', Perizinan::AKTIF)
                ->where('rencana_keluar', '<', $kembali)->where('rencana_kembali', '>', $keluar)->exists();
            if ($bertumpuk) {
                throw new AppException(422, __('Sudah ada izin lain untuk anak ini pada rentang waktu tersebut.'));
            }
            $base = DocNumber::docBase('IZN', now());
            $p = Perizinan::create([
                'nomor' => DocNumber::nextDocNumber($base, Perizinan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'kode_tipe' => $tipe->kode, 'keperluan' => $keperluan ?: null,
                'rencana_keluar' => $keluar, 'rencana_kembali' => $kembali,
                'penjemput_nama' => trim((string) ($d['penjemput_nama'] ?? '')) ?: null,
                'penjemput_hubungan' => trim((string) ($d['penjemput_hubungan'] ?? '')) ?: null,
                'penjemput_hp' => trim((string) ($d['penjemput_hp'] ?? '')) ?: null,
                'status' => self::pengaturan()->tingkat1_aktif ? 'menunggu_1' : 'menunggu_2',
                'id_wali' => $wali?->id ?? $santri->id_wali, 'diajukan_peran' => $peran, 'diajukan_oleh' => $staf?->id_pengguna,
            ]);
            if ($lampiran) {
                $p->update(['lampiran_path' => $lampiran->store("perizinan/{$p->id}", 'local'),
                    'lampiran_nama' => mb_substr($lampiran->getClientOriginalName(), 0, 250)]);
            }

            return $p;
        });

        $this->kabariStaf($p, 'Pengajuan izin perlu disetujui');
        if ($staf) {
            $this->kabariWali($p, Teks::k('Izin :nama dicatat pesantren', ['nama' => $santri->nama]),
                Teks::k('Pesantren mencatat izin :tipe untuk :nama (:keluar – :kembali).', $this->isianWaktu($p)));
        }

        return $p;
    }

    /** Tingkat yang sedang diminta untuk pengajuan ini (1/2), null bila tidak menunggu. */
    public static function tingkatMenunggu(Perizinan $p): ?int
    {
        return match ($p->status) {
            'menunggu_1' => 1,
            'menunggu_2' => 2,
            default => null,
        };
    }

    /** Pengguna ini boleh memutus pengajuan pada tingkatnya sekarang? Tingkat 1 yang dimatikan diambil alih tingkat 2. */
    public static function bolehMemutus(Perizinan $p): bool
    {
        return match (self::tingkatMenunggu($p)) {
            1 => Akses::boleh(self::MODUL_TINGKAT[1], 'ubah') || (! self::pengaturan()->tingkat1_aktif && Akses::boleh(self::MODUL_TINGKAT[2], 'ubah')),
            2 => Akses::boleh(self::MODUL_TINGKAT[2], 'ubah'),
            default => false,
        };
    }

    public function setujui(Perizinan $p, User $u, ?string $catatan = null): Perizinan
    {
        return DB::transaction(function () use ($p, $u, $catatan) {
            $kini = Perizinan::with('santri')->whereKey($p->id)->lockForUpdate()->firstOrFail();
            $tingkat = self::tingkatMenunggu($kini) ?? throw new AppException(422, "{$kini->nomor} berstatus \"{$kini->labelStatus()}\" — tak menunggu persetujuan.");
            if (! self::bolehMemutus($kini)) {
                throw new AppException(403, 'Anda tidak berhak menyetujui pengajuan pada tingkat ini.');
            }
            $set = ["tingkat{$tingkat}_pada" => now(), "tingkat{$tingkat}_oleh" => $u->id_pengguna, "tingkat{$tingkat}_catatan" => trim((string) $catatan) ?: null];
            $lanjut = $tingkat === 1 && self::pengaturan()->tingkat2_aktif;
            $kini->update($set + ['status' => $lanjut ? 'menunggu_2' : 'disetujui']);
            if ($lanjut) {
                $this->kabariStaf($kini, 'Pengajuan izin perlu disetujui');
            } else {
                $this->kabariWali($kini, Teks::k('Izin :nama disetujui', ['nama' => $kini->santri->nama]),
                    Teks::k('Izin :tipe untuk :nama (:keluar – :kembali) disetujui.', $this->isianWaktu($kini)));
            }

            return $kini;
        });
    }

    public function tolak(Perizinan $p, User $u, string $alasan): void
    {
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan penolakan.');
        }
        DB::transaction(function () use ($p, $u, $alasan) {
            $kini = Perizinan::with('santri')->whereKey($p->id)->lockForUpdate()->firstOrFail();
            if (self::tingkatMenunggu($kini) === null) {
                throw new AppException(422, "{$kini->nomor} berstatus \"{$kini->labelStatus()}\" — tak menunggu persetujuan.");
            }
            if (! self::bolehMemutus($kini)) {
                throw new AppException(403, 'Anda tidak berhak memutus pengajuan pada tingkat ini.');
            }
            $kini->update(['status' => 'ditolak', 'ditolak_pada' => now(), 'ditolak_oleh' => $u->id_pengguna, 'alasan_tolak' => trim($alasan)]);
            $this->kabariWali($kini, Teks::k('Izin :nama ditolak', ['nama' => $kini->santri->nama]),
                Teks::k('Izin :tipe untuk :nama ditolak. Alasan: :alasan', $this->isianWaktu($kini) + ['alasan' => trim($alasan)]));
        });
    }

    /** Wali membatalkan pengajuannya sendiri selama santri belum keluar. */
    public function batalkanOlehWali(Perizinan $p, Wali $wali): void
    {
        DB::transaction(function () use ($p, $wali) {
            $kini = Perizinan::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ((int) $kini->id_wali !== (int) $wali->id) {
                throw new AppException(404, __('Pengajuan tidak ditemukan.'));
            }
            if (! in_array($kini->status, ['menunggu_1', 'menunggu_2', 'disetujui'], true)) {
                throw new AppException(422, __('Izin ini tidak bisa dibatalkan lagi.'));
            }
            $kini->update(['status' => 'dibatalkan', 'dibatalkan_pada' => now()]);
        });
    }

    /** Pos: santri keluar (izin harus sudah disetujui). */
    public function catatKeluar(Perizinan $p, User $u, string $cara): void
    {
        $this->pindah($p, 'disetujui', 'keluar', $u, $cara, fn (Perizinan $k) => [
            Teks::k(':nama sudah keluar pondok', ['nama' => $k->santri->nama]),
            Teks::k(':nama keluar pondok :waktu (izin :tipe). Jadwal kembali :kembali.', $this->isianWaktu($k) + ['waktu' => $this->jam(now())]),
        ]);
    }

    /** Pos: santri kembali ke pondok. */
    public function catatKembali(Perizinan $p, User $u, string $cara): void
    {
        $this->pindah($p, 'keluar', 'kembali', $u, $cara, fn (Perizinan $k) => [
            Teks::k(':nama sudah kembali di pondok', ['nama' => $k->santri->nama]),
            Teks::k(':nama kembali di pondok :waktu.', ['nama' => $k->santri->nama, 'waktu' => $this->jam(now())]),
        ]);
    }

    private function pindah(Perizinan $p, string $dari, string $ke, User $u, string $cara, \Closure $kabar): void
    {
        DB::transaction(function () use ($p, $dari, $ke, $u, $cara, $kabar) {
            $kini = Perizinan::with(['santri', 'tipe'])->whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($kini->status !== $dari) {
                throw new AppException(422, "{$kini->nomor} berstatus \"{$kini->labelStatus()}\".");
            }
            $kini->update(['status' => $ke, "{$ke}_pada" => now(), "{$ke}_oleh" => $u->id_pengguna, "{$ke}_cara" => $cara]);
            [$judul, $pesan] = $kabar($kini);
            $this->kabariWali($kini, $judul, $pesan);
        });
    }

    /** Izin santri yang relevan di pos: disetujui (siap keluar) & sedang di luar. */
    public function untukPos(Santri $santri)
    {
        return Perizinan::with('tipe')->where('id_santri', $santri->id)->whereIn('status', ['disetujui', 'keluar'])->orderBy('rencana_keluar')->get();
    }

    /** Penanda Tugas Saya: pengajuan yang menunggu keputusan pengguna yang sedang masuk. */
    public static function jumlahMenungguSaya(): int
    {
        $p = self::pengaturan();
        $status = [];
        if (Akses::boleh(self::MODUL_TINGKAT[1], 'ubah') || (! $p->tingkat1_aktif && Akses::boleh(self::MODUL_TINGKAT[2], 'ubah'))) {
            $status[] = 'menunggu_1';
        }
        if (Akses::boleh(self::MODUL_TINGKAT[2], 'ubah')) {
            $status[] = 'menunggu_2';
        }

        return $status === [] ? 0 : Perizinan::whereIn('status', $status)->count();
    }

    // ---- Kabar ----

    /** @return array<string,string|Teks> */
    private function isianWaktu(Perizinan $p): array
    {
        $p->loadMissing(['santri', 'tipe']);

        return ['nama' => $p->santri->nama, 'tipe' => Teks::k($p->tipe->nama),
            'keluar' => $this->jam($p->rencana_keluar), 'kembali' => $this->jam($p->rencana_kembali)];
    }

    private function jam(Carbon $t): string
    {
        return $t->timezone('Asia/Jakarta')->format('d/m/Y H:i');
    }

    private function kabariWali(Perizinan $p, Teks $judul, Teks $pesan): void
    {
        try {
            KabarWali::keKeluarga($p->wali ?? $p->santri?->wali, ['judul' => $judul, 'pesan' => $pesan, 'jenis' => 'perizinan',
                'ref_jenis' => 'Perizinan', 'ref_id' => (string) $p->id, 'id_santri' => $p->id_santri]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Kabar ke pemegang hak tingkat yang sedang diminta (admin bila belum ada pemegangnya). */
    private function kabariStaf(Perizinan $p, string $judul): void
    {
        $tingkat = self::tingkatMenunggu($p);
        if ($tingkat === null) {
            return;
        }
        $modul = [self::MODUL_TINGKAT[$tingkat]];
        if ($tingkat === 1 && ! self::pengaturan()->tingkat1_aktif) {
            $modul[] = self::MODUL_TINGKAT[2];
        }
        $ids = HakAksesModul::whereIn('kode_modul', $modul)->where('ubah', true)
            ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))->pluck('id_pengguna')->unique()->values()->all();
        if ($ids === []) {
            $ids = User::where('is_admin', true)->where('status', 'aktif')->pluck('id_pengguna')->all();
        }
        $p->loadMissing(['santri:id,nama', 'tipe']);
        try {
            (new NotificationService)->kirim(array_map(fn ($id) => [
                'id_pengguna' => $id, 'judul' => $judul, 'pesan' => "{$p->nomor} — {$p->santri?->nama}: {$p->tipe?->nama}, "
                    .$this->jam($p->rencana_keluar).' s.d. '.$this->jam($p->rencana_kembali).'.',
                'jenis' => 'perizinan', 'ref_jenis' => 'Perizinan', 'ref_id' => (string) $p->id,
            ], $ids));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
