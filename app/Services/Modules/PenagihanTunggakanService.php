<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CatatanPenagihan;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PENAGIHAN TUNGGAKAN (keputusan user 3 Nov 2026) — admin sekolah menagih wali secara manual.
 *
 * Daftar per SANTRI dari semua tagihan yang sudah LEWAT jatuh tempo, apa pun jenisnya (SPP, daftar ulang,
 * laundry, tagihan lain, uang pangkal …), semua jenjang. Catatan penagihan disimpan PER TAGIHAN
 * (keputusan user) — satu kali menelepon wali yang menunggak tiga tagihan = tiga baris catatan dengan isi
 * sama; formulirnya mencentang tagihan mana saja yang dibicarakan.
 *
 * Dibaca langsung dari `tagihan_santri`: santri hilang sendiri dari daftar begitu tunggakannya lunas.
 * Tagihan yang dicicil lewat rencana angsuran dilewati — jatuh temponya mengikuti termin, ditagih dari
 * modul Angsuran Uang Pangkal.
 */
class PenagihanTunggakanService
{
    /** Tagihan yang masih menggantung (belum lunas, berlaku, ada sisa). */
    public static function menggantung(): Builder
    {
        return TagihanSantri::query()->berlaku()
            ->whereIn('status', ['belum_bayar', 'sebagian'])->where('sisa', '>', 0)
            ->whereDoesntHave('rencanaAngsuran', fn ($q) => $q->where('status', 'aktif'));
    }

    /** …dan sudah lewat jatuh tempo. */
    public static function lewatTempo(): Builder
    {
        return self::menggantung()->whereNotNull('jatuh_tempo')->whereDate('jatuh_tempo', '<', Carbon::today());
    }

    /**
     * @param  array{jenjang?:string, kelas?:string, umur?:string, status?:string, q?:string}  $filter
     *                                                                                             umur: 30|60|90 (minimal hari lewat, tagihan terlama);
     *                                                                                             status: belum_ditagih|sudah_ditagih|janji_lewat|janji_berjalan
     * @return list<array<string,mixed>>
     */
    public function daftar(array $filter = []): array
    {
        $jenjang = trim((string) ($filter['jenjang'] ?? ''));
        $kelas = trim((string) ($filter['kelas'] ?? ''));
        $cari = trim((string) ($filter['q'] ?? ''));
        $umur = (int) ($filter['umur'] ?? 0);
        $status = trim((string) ($filter['status'] ?? ''));
        $kini = Carbon::today();

        $rows = self::lewatTempo()
            ->whereHas('santri', function ($s) use ($jenjang, $kelas, $cari) {
                $s->when($jenjang !== '', fn ($q) => $q->where('kode_jenjang', $jenjang))
                    ->when($kelas !== '', fn ($q) => $q->where('id_kelas', (int) $kelas))
                    ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%")));
            })
            ->with(['jenis', 'santri.wali', 'santri.kelas', 'catatanPenagihan.pencatat'])
            ->get();

        $namaJenjang = Jenjang::pluck('nama', 'kode')->all();
        $hasil = [];
        foreach ($rows->groupBy('id_santri') as $daftar) {
            $s = $daftar->first()->santri;
            $terakhir = $daftar->map(fn ($t) => $t->catatanPenagihan->first())->filter()->sortByDesc('id')->values();
            $janji = $this->janjiTerkini($daftar);
            $baris = [
                'id_santri' => $s->id,
                'nama' => $s->nama,
                'nis' => $s->nis,
                'status_santri' => $s->status,
                'jenjang' => $namaJenjang[$s->kode_jenjang] ?? $s->kode_jenjang,
                'kelas' => $s->kelas?->nama,
                'nama_wali' => $s->wali?->nama,
                'telepon_wali' => $s->wali?->telepon,
                'jumlah' => $daftar->count(),
                'sisa' => $daftar->reduce(fn ($a, $t) => Money::add($a, $t->sisa), '0'),
                'hari_terlama' => (int) $daftar->max(fn ($t) => Carbon::parse($t->jatuh_tempo)->startOfDay()->diffInDays($kini)),
                'tagihan' => $daftar->map(fn ($t) => self::namaTagihan($t))->values()->all(),
                'catatan_terakhir' => $terakhir->first(),
                'kali_ditagih' => $daftar->sum(fn ($t) => $t->catatanPenagihan->count()),
                'janji_lewat' => $janji['lewat'],
                'janji_berikut' => $janji['berikut'],
            ];

            $lolos = match ($status) {
                'belum_ditagih' => $baris['catatan_terakhir'] === null,
                'sudah_ditagih' => $baris['catatan_terakhir'] !== null,
                'janji_lewat' => $baris['janji_lewat'] !== null,
                'janji_berjalan' => $baris['janji_berikut'] !== null,
                default => true,
            };
            if ($lolos && $baris['hari_terlama'] >= $umur) {
                $hasil[] = $baris;
            }
        }

        // Janji bayar yang sudah lewat lebih dulu (harus ditagih lagi), lalu yang paling lama menunggak.
        usort($hasil, fn ($a, $b) => [$b['janji_lewat'] !== null, $b['hari_terlama'], $a['nama']]
            <=> [$a['janji_lewat'] !== null, $a['hari_terlama'], $b['nama']]);

        return $hasil;
    }

    /** @param  list<array<string,mixed>>  $daftar */
    public function ringkasan(array $daftar): array
    {
        return [
            'santri' => count($daftar),
            'tagihan' => array_sum(array_column($daftar, 'jumlah')),
            'sisa' => array_reduce($daftar, fn ($a, $r) => Money::add($a, $r['sisa']), '0'),
            'belum_ditagih' => count(array_filter($daftar, fn ($r) => $r['catatan_terakhir'] === null)),
            'janji_lewat' => count(array_filter($daftar, fn ($r) => $r['janji_lewat'] !== null)),
        ];
    }

    /**
     * Rincian satu santri: tagihan lewat jatuh tempo (bisa dicatat) + tagihan menggantung yang belum jatuh
     * tempo (sekadar informasi), masing-masing dengan riwayat catatannya.
     *
     * @return array{lewat: Collection<int,TagihanSantri>, belum: Collection<int,TagihanSantri>}
     */
    public function rincian(Santri $santri): array
    {
        $semua = self::menggantung()->where('id_santri', $santri->id)
            ->with(['jenis', 'catatanPenagihan.pencatat'])->orderBy('jatuh_tempo')->orderBy('id')->get();
        $kini = Carbon::today();
        [$lewat, $belum] = $semua->partition(fn ($t) => $t->jatuh_tempo && Carbon::parse($t->jatuh_tempo)->lt($kini));

        return ['lewat' => $lewat->values(), 'belum' => $belum->values()];
    }

    /**
     * Catat satu kali penagihan untuk tagihan-tagihan yang dicentang (satu baris per tagihan).
     *
     * @param  array{tagihan: list<int|string>, ditagih_pada: string, cara: string, tanggapan: string, janji_tanggal?: ?string, janji_nominal?: mixed, tindak_lanjut?: ?string}  $data
     * @return int jumlah tagihan yang dicatat
     *
     * @throws AppException
     */
    public function catat(Santri $santri, array $data, ?int $idPengguna): int
    {
        $ids = array_values(array_unique(array_map('intval', $data['tagihan'] ?? [])));
        $tagihan = self::menggantung()->where('id_santri', $santri->id)->whereIn('id', $ids)->pluck('id');
        if ($ids === [] || $tagihan->count() !== count($ids)) {
            throw new AppException(422, 'Pilih tagihan milik santri ini yang masih belum lunas.');
        }
        if (! empty($data['janji_tanggal']) && Carbon::parse($data['janji_tanggal'])->lt(Carbon::parse($data['ditagih_pada']))) {
            throw new AppException(422, 'Tanggal janji bayar tidak boleh sebelum tanggal penagihan.');
        }

        DB::transaction(function () use ($tagihan, $santri, $data, $idPengguna) {
            foreach ($tagihan as $id) {
                CatatanPenagihan::create([
                    'id_tagihan' => $id, 'id_santri' => $santri->id,
                    'ditagih_pada' => $data['ditagih_pada'], 'cara' => $data['cara'],
                    'tanggapan' => trim($data['tanggapan']),
                    'janji_tanggal' => $data['janji_tanggal'] ?: null,
                    'janji_nominal' => isset($data['janji_nominal']) && $data['janji_nominal'] !== '' ? $data['janji_nominal'] : null,
                    'tindak_lanjut' => filled($data['tindak_lanjut'] ?? null) ? trim($data['tindak_lanjut']) : null,
                    'dicatat_oleh' => $idPengguna,
                ]);
            }
        });

        return $tagihan->count();
    }

    /**
     * Penanda Tugas Saya: santri yang JANJI BAYAR-nya (pada catatan TERAKHIR sebuah tagihan) sudah lewat
     * sementara tagihannya belum lunas — saatnya menagih lagi.
     */
    public static function jumlahJanjiLewat(): int
    {
        return (int) self::menggantung()
            ->whereExists(fn ($q) => $q->from('catatan_penagihan as c')
                ->whereColumn('c.id_tagihan', 'tagihan_santri.id')
                ->whereDate('c.janji_tanggal', '<', Carbon::today())
                ->whereRaw('c.id = (SELECT MAX(c2.id) FROM catatan_penagihan c2 WHERE c2.id_tagihan = tagihan_santri.id)'))
            ->distinct()->count('id_santri');
    }

    public static function namaTagihan(TagihanSantri $t): string
    {
        return trim(($t->jenis?->nama ?? $t->kode_jenis).($t->periode ? ' '.$t->periode : ''));
    }

    /**
     * Janji bayar dari catatan TERAKHIR tiap tagihan: yang sudah lewat (terlama) & yang masih berjalan (terdekat).
     *
     * @param  Collection<int,TagihanSantri>  $daftar
     * @return array{lewat: ?Carbon, berikut: ?Carbon}
     */
    private function janjiTerkini(Collection $daftar): array
    {
        $kini = Carbon::today();
        $janji = $daftar->map(fn ($t) => $t->catatanPenagihan->first()?->janji_tanggal)->filter();

        return [
            'lewat' => $janji->filter(fn ($d) => $d->lt($kini))->sort()->first(),
            'berikut' => $janji->filter(fn ($d) => $d->gte($kini))->sort()->first(),
        ];
    }
}
