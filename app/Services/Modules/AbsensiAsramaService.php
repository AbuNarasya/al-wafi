<?php

namespace App\Services\Modules;

use App\Models\AbsensiAsrama;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Services\PortalWali\KabarWali;
use App\Support\Teks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ABSENSI ASRAMA DARI ISE (keputusan user 4 Nov 2026) — ISE mengirim (push) catatan absen ke ERP; portal wali
 * menampilkannya di menu Kehadiran Asrama. Spesifikasi untuk pengembang ISE: docs/integrasi-ise-absensi.md.
 *
 *  • Idempoten per `id` absen ISE: kiriman ulang / koreksi menimpa; `dihapus: true` menghapus.
 *  • Santri dicocokkan lewat id santri ERP, lalu NIS. Tak cocok → tetap disimpan (id_santri NULL), tampil di layar staf.
 *  • Status ISE diterjemahkan ke KATEGORI (hadir/terlambat/tidak_hadir/izin/uzur/lainnya). Status tak dikenal = lainnya.
 *  • Ketidakhadiran di dalam rentang Perizinan Insidental yang disetujui tampil sebagai IZIN di portal (ISE belum tahu izinnya).
 *  • Wali TAK melihat catatan internal & nama pencatat; "Haid" tampil "Uzur".
 *  • Kabar HP saat santri tercatat TIDAK HADIR (bukan dalam izin), sekali per catatan, hanya untuk absen ≤ 24 jam lalu
 *    (kiriman susulan data lama tak membanjiri HP wali).
 */
class AbsensiAsramaService
{
    public const MAKS_PER_KIRIMAN = 500;

    /** Status ISE (huruf kecil) → kategori. */
    public const KATEGORI = [
        'hadir' => 'hadir', 'present' => 'hadir', 'shalat' => 'hadir', 'dzikir' => 'hadir', "tasmi'" => 'hadir', 'mandi' => 'hadir',
        'puasa' => 'hadir', 'sebelum adzan' => 'hadir', 'check in tepat waktu' => 'hadir',
        'terlambat' => 'terlambat', 'late' => 'terlambat', 'setelah adzan' => 'terlambat', 'masbuq' => 'terlambat', 'check in terlambat' => 'terlambat',
        'alpa' => 'tidak_hadir', 'absent' => 'tidak_hadir', 'tidak shalat' => 'tidak_hadir', "tidak jama'ah" => 'tidak_hadir',
        'tidak dzikir' => 'tidak_hadir', "tidak tasmi'" => 'tidak_hadir', 'tidak mandi' => 'tidak_hadir', 'tidak puasa' => 'tidak_hadir',
        'izin' => 'izin', 'excused' => 'izin', 'sakit' => 'izin',
        'haid' => 'uzur',
    ];

    public const LABEL_KATEGORI = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'tidak_hadir' => 'Tidak hadir', 'izin' => 'Izin', 'uzur' => 'Uzur', 'lainnya' => 'Lainnya'];

    public static function kategori(string $status): string
    {
        return self::KATEGORI[mb_strtolower(trim($status))] ?? 'lainnya';
    }

    /** Status yang boleh dilihat wali: Haid → Uzur, selebihnya apa adanya dari ISE. */
    public static function statusUntukWali(AbsensiAsrama $a): string
    {
        return $a->kategori === 'uzur' ? 'Uzur' : $a->status;
    }

    /**
     * Terima satu kiriman ISE.
     *
     * @param  list<array<string,mixed>>  $data
     * @return array{jumlah:int, disimpan:int, dihapus:int, ditolak:int, tak_tercocok:int, galat:list<array{indeks:int, id:?string, pesan:string}>}
     */
    public function terima(array $data, ?string $ip = null): array
    {
        $hasil = ['jumlah' => count($data), 'disimpan' => 0, 'dihapus' => 0, 'ditolak' => 0, 'tak_tercocok' => 0, 'galat' => []];
        $tolak = function (int $i, ?string $id, string $pesan) use (&$hasil) {
            $hasil['ditolak']++;
            $hasil['galat'][] = ['indeks' => $i, 'id' => $id, 'pesan' => $pesan];
        };
        $baru = [];

        foreach (array_values($data) as $i => $r) {
            $id = is_array($r) ? trim((string) ($r['id'] ?? '')) : '';
            if ($id === '' || mb_strlen($id) > 100) {
                $tolak($i, $id ?: null, 'id wajib (≤ 100 karakter)');

                continue;
            }
            if (! empty($r['dihapus'])) {
                $hasil['dihapus'] += AbsensiAsrama::where('ise_id', $id)->delete();

                continue;
            }
            $sesi = trim((string) ($r['tipe'] ?? ''));
            $status = trim((string) ($r['status'] ?? ''));
            try {
                $waktu = Carbon::parse((string) ($r['waktu'] ?? ''));
            } catch (\Throwable) {
                $waktu = null;
            }
            if ($sesi === '' || $status === '' || ! $waktu || empty($r['waktu'])) {
                $tolak($i, $id, 'waktu, tipe, dan status wajib diisi (waktu ISO-8601)');

                continue;
            }
            $s = is_array($r['santri'] ?? null) ? $r['santri'] : [];
            $santri = $this->cocokkan($s);
            if (! $santri) {
                $hasil['tak_tercocok']++;
            }
            $a = AbsensiAsrama::updateOrCreate(['ise_id' => $id], [
                'id_santri' => $santri?->id,
                'ise_santri_id' => isset($s['id_ise']) ? mb_substr((string) $s['id_ise'], 0, 100) : null,
                'nis' => isset($s['nis']) ? mb_substr(trim((string) $s['nis']), 0, 50) : null,
                'nama_santri' => isset($s['nama']) ? mb_substr(trim((string) $s['nama']), 0, 255) : null,
                'waktu' => $waktu, 'asrama' => isset($r['asrama']) ? mb_substr(trim((string) $r['asrama']), 0, 100) : null,
                'sesi' => mb_substr($sesi, 0, 150), 'status' => mb_substr($status, 0, 100), 'kategori' => self::kategori($status),
                'catatan' => isset($r['catatan']) ? mb_substr(trim((string) $r['catatan']), 0, 2000) ?: null : null,
                'pencatat' => isset($r['pencatat']) ? mb_substr(trim((string) $r['pencatat']), 0, 255) ?: null : null,
            ]);
            $hasil['disimpan']++;
            $baru[] = $a;
        }

        DB::table('log_kiriman_ise')->insert([
            'jenis' => 'absensi_asrama', 'jumlah' => $hasil['jumlah'], 'disimpan' => $hasil['disimpan'], 'dihapus' => $hasil['dihapus'],
            'ditolak' => $hasil['ditolak'], 'tak_tercocok' => $hasil['tak_tercocok'],
            'galat' => $hasil['galat'] ? mb_substr(json_encode(array_slice($hasil['galat'], 0, 20), JSON_UNESCAPED_UNICODE), 0, 5000) : null,
            'ip' => $ip, 'created_at' => now(),
        ]);
        foreach ($baru as $a) {
            $this->kabariBilaTidakHadir($a);
        }

        return $hasil;
    }

    /** Id santri ERP dulu, lalu NIS (kelak ISE memakai data santri ERP). */
    private function cocokkan(array $s): ?Santri
    {
        if (! empty($s['id_erp']) && ($santri = Santri::find((int) $s['id_erp']))) {
            return $santri;
        }
        $nis = trim((string) ($s['nis'] ?? ''));

        return $nis !== '' && $nis !== '-' ? Santri::where('nis', $nis)->first() : null;
    }

    /** Santri sedang dalam Perizinan Insidental yang disetujui pada waktu itu? */
    public static function dalamIzin(int $idSantri, Carbon $waktu): bool
    {
        return Perizinan::where('id_santri', $idSantri)->whereIn('status', ['disetujui', 'keluar', 'kembali'])
            ->whereRaw('coalesce(keluar_pada, rencana_keluar) <= ?', [$waktu])
            ->whereRaw('coalesce(kembali_pada, rencana_kembali) >= ?', [$waktu])->exists();
    }

    /** Kategori yang dilihat wali: tidak hadir di dalam izin yang disetujui → izin. */
    public static function kategoriEfektif(AbsensiAsrama $a, ?Collection $izin = null): string
    {
        if ($a->kategori !== 'tidak_hadir' || ! $a->id_santri) {
            return $a->kategori;
        }
        $dalam = $izin !== null
            ? $izin->contains(fn ($z) => ($z->keluar_pada ?? $z->rencana_keluar)->lte($a->waktu) && ($z->kembali_pada ?? $z->rencana_kembali)->gte($a->waktu))
            : self::dalamIzin($a->id_santri, $a->waktu);

        return $dalam ? 'izin' : 'tidak_hadir';
    }

    /** Izin yang beririsan dengan rentang — dipakai sekali per halaman, bukan per baris. */
    public static function izinDalam(int $idSantri, Carbon $dari, Carbon $sampai): Collection
    {
        return Perizinan::where('id_santri', $idSantri)->whereIn('status', ['disetujui', 'keluar', 'kembali'])
            ->whereRaw('coalesce(keluar_pada, rencana_keluar) <= ?', [$sampai])
            ->whereRaw('coalesce(kembali_pada, rencana_kembali) >= ?', [$dari])->get();
    }

    /**
     * Ringkasan per sesi untuk rentang [dari, sampai].
     *
     * @return Collection<string, array{hadir:int, terlambat:int, tidak_hadir:int, izin:int, uzur:int, lainnya:int, total:int}>
     */
    public function ringkasan(Santri $santri, Carbon $dari, Carbon $sampai): Collection
    {
        $izin = self::izinDalam($santri->id, $dari, $sampai);
        $kosong = array_fill_keys(array_keys(self::LABEL_KATEGORI), 0) + ['total' => 0];

        return AbsensiAsrama::where('id_santri', $santri->id)->whereBetween('waktu', [$dari, $sampai])->orderBy('sesi')->get()
            ->groupBy('sesi')->map(function ($baris) use ($izin, $kosong) {
                $r = $kosong;
                foreach ($baris as $a) {
                    $r[self::kategoriEfektif($a, $izin)]++;
                    $r['total']++;
                }

                return $r;
            });
    }

    private function kabariBilaTidakHadir(AbsensiAsrama $a): void
    {
        if ($a->kategori !== 'tidak_hadir' || ! $a->id_santri || $a->dikabarkan_pada || $a->waktu->lt(now()->subDay())
            || self::dalamIzin($a->id_santri, $a->waktu)) {
            return;
        }
        $santri = Santri::with('wali')->find($a->id_santri);
        if (! $santri?->wali) {
            return;
        }
        try {
            KabarWali::keKeluarga($santri->wali, [
                'judul' => Teks::k(':nama tercatat tidak hadir', ['nama' => $santri->nama]),
                'pesan' => Teks::k(':nama tercatat ":status" pada :sesi, :waktu.', ['nama' => $santri->nama, 'status' => $a->status,
                    'sesi' => $a->sesi, 'waktu' => $a->waktu->timezone('Asia/Jakarta')->format('d/m/Y H:i')]),
                'jenis' => 'absensi_asrama', 'ref_jenis' => 'AbsensiAsrama', 'ref_id' => (string) $a->id, 'id_santri' => $santri->id,
            ]);
            $a->forceFill(['dikabarkan_pada' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
