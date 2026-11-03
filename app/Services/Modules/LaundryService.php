<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisPakaianLaundry;
use App\Models\Laundry;
use App\Models\Santri;
use App\Services\Ledger\DocNumber;
use App\Support\Teks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNDRY tahap 1 (keputusan user 26 Okt 2026).
 *
 *   terima    — santri tap kartu (atau dicari manual), petugas MENIMBANG saja → `diterima` (paket 1, 3 Nov 2026)
 *   periksa   — meja pemeriksaan: timbang ulang + jenis & jumlah pakaian (WAJIB) → `diperiksa`; berat ulang yang dihitung kuota
 *   majukan   — papan proses: `diperiksa` → `diproses` → `selesai` (sudah dipacking), satu-satu atau sekaligus banyak
 *   serahkan  — santri tap kartu lagi, pakaian dicocokkan satu per satu → `diserahkan`; kurang = ditandai + catatan wajib
 *   batalkan  — selama masih `diterima` / `diperiksa` (salah catat)
 *   ubah      — berat, daftar pakaian & catatan sampai diserahkan; alasan wajib, tercatat di jejak audit
 *   TERTAHAN  — (paket 3) santri yang menunggak kelebihan laundry ≥ N periode lewat jatuh tempo tak bisa mengambil cucian;
 *               setor tetap boleh. Izin mendesak (`$izin` = alasan) boleh berkali-kali, tercatat di jejak audit.
 *
 * Tiap langkah menyimpan waktu & petugasnya. Kuota & tagihan kelebihan menyusul (tahap 2).
 */
class LaundryService
{
    public const BERAT_MAKS = 30;

    /**
     * @param  list<array{id_jenis_pakaian:int|string, jumlah:int|string}>  $item
     */
    public function terima(Santri $santri, string $berat, array $item, ?string $catatan, string $cara, int $idPengguna): Laundry
    {
        if ($santri->status !== 'aktif') {
            throw new AppException(422, "{$santri->nama} bukan santri aktif.");
        }
        $berat = round((float) $berat, 2);
        if ($berat <= 0 || $berat > self::BERAT_MAKS) {
            throw new AppException(422, 'Berat cucian harus lebih dari 0 dan paling banyak '.self::BERAT_MAKS.' kg — periksa lagi timbangannya.');
        }
        // Daftar pakaian di penerimaan TIDAK wajib lagi — dicatat di meja pemeriksaan (paket 1).
        $baris = $this->rapikanItem($item, wajib: false);

        // Nomor per pusat penerimaan (keputusan user 3 Nov 2026): LDI- ikhwan, LDA- akhwat, urutan masing-masing.
        // Kantong lama tetap LDR-. Kuncinya juga per awalan — dua pusat tak saling menunggu.
        $awalan = self::awalanNomor($santri);

        // Kabar kuota TIDAK dikirim di sini: berat penerimaan hanya formalitas — kabarnya menunggu berat final di pemeriksaan.
        return DB::transaction(function () use ($santri, $berat, $baris, $catatan, $cara, $idPengguna, $awalan) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('laundry-nomor-'.$awalan)]);
            $base = DocNumber::docBase($awalan, now());
            $l = Laundry::create([
                'nomor' => DocNumber::nextDocNumber($base, Laundry::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'berat' => $berat, 'berat_terima' => $berat, 'catatan' => trim((string) $catatan) ?: null,
                'status' => 'diterima', 'cara_terima' => $cara, 'diterima_pada' => now(), 'diterima_oleh' => $idPengguna,
            ]);
            foreach ($baris as $b) {
                $l->item()->create($b);
            }
            return $l;
        });
    }

    /**
     * Majukan satu langkah di papan proses. Kantong yang statusnya tak sesuai (sudah dimajukan petugas lain)
     * dilewati, bukan menggagalkan seluruhnya.
     *
     * @param  list<int>  $ids
     * @return array{dimajukan:int, dilewati:int}
     */
    public function majukan(array $ids, string $dari, int $idPengguna): array
    {
        $ke = Laundry::MAJU[$dari] ?? throw new AppException(422, 'Langkah tidak dikenal.');

        return DB::transaction(function () use ($ids, $dari, $ke, $idPengguna) {
            $baris = Laundry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $sah = $baris->where('status', $dari);
            if ($sah->isNotEmpty()) {
                Laundry::whereIn('id', $sah->pluck('id'))->update(['status' => $ke, "{$ke}_pada" => now(), "{$ke}_oleh" => $idPengguna]);
                if ($ke === 'selesai') {
                    $monitor = new MonitorLaundryService;
                    foreach (Laundry::with(['item', 'santri.wali'])->whereIn('id', $sah->pluck('id'))->get() as $l) {
                        // Paket 3: santri tertahan dikabari bahwa cucian sudah selesai TETAPI belum bisa diambil.
                        if ($tahan = $monitor->tertahan($l->santri)) {
                            $this->kabari($l, Teks::k('Cucian :nama selesai, tetapi tertahan', ['nama' => $l->santri->nama]),
                                Teks::k(':nomor sudah selesai dicuci, tetapi belum bisa diambil karena tunggakan kelebihan laundry :periode periode (Rp :nominal) sudah lewat jatuh tempo. Silakan lunasi di menu Tagihan.',
                                    ['nomor' => $l->nomor, 'periode' => $tahan->jumlah, 'nominal' => number_format((float) $tahan->sisa, 0, ',', '.')]), 'laundry_tertahan');

                            continue;
                        }
                        $this->kabari($l, Teks::k('Cucian :nama siap diambil', ['nama' => $l->santri->nama]),
                            Teks::k(':nomor (:berat kg, :potong potong) sudah selesai dicuci dan bisa diambil di laundry.',
                                ['nomor' => $l->nomor, 'berat' => self::kg($l->berat), 'potong' => $l->jumlahPotong()]), 'laundry_selesai');
                    }
                }
            }

            return ['dimajukan' => $sah->count(), 'dilewati' => count($ids) - $sah->count()];
        });
    }

    /** Kantong santri yang siap diambil (selesai) — dan yang belum selesai, untuk diberitahukan di layar penyerahan. */
    public function untukDiserahkan(Santri $santri): Collection
    {
        return Laundry::with('item')->where('id_santri', $santri->id)->whereIn('status', Laundry::BERJALAN)
            ->orderBy('diterima_pada')->get();
    }

    /**
     * Serahkan kantong-kantong `selesai` milik SATU santri. `$kembali` = [id_item => jumlah yang dikembalikan];
     * kurang dari yang diterima = ditandai kurang, dan catatannya wajib.
     *
     * @param  list<int>  $ids
     * @param  array<int|string, int|string>  $kembali
     */
    public function serahkan(Santri $santri, array $ids, array $kembali, ?string $catatan, string $cara, int $idPengguna, ?string $izin = null): int
    {
        if ($ids === []) {
            throw new AppException(422, 'Pilih kantong yang diserahkan.');
        }
        $tahan = (new MonitorLaundryService)->tertahan($santri);
        $izin = trim((string) $izin);
        if ($tahan && $izin === '') {
            throw new AppException(423, "Cucian {$santri->nama} TERTAHAN: tunggakan kelebihan laundry {$tahan->jumlah} periode (Rp "
                .number_format((float) $tahan->sisa, 0, ',', '.').') sudah lewat jatuh tempo. Lunasi dulu, atau minta izin mendesak dari admin laundry.');
        }

        return DB::transaction(function () use ($santri, $ids, $kembali, $catatan, $cara, $idPengguna, $tahan, $izin) {
            $kantong = Laundry::with('item')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($kantong as $l) {
                if ((int) $l->id_santri !== (int) $santri->id) {
                    throw new AppException(422, "Kantong {$l->nomor} bukan milik {$santri->nama}.");
                }
                if ($l->status !== 'selesai') {
                    throw new AppException(422, "Kantong {$l->nomor} berstatus \"{$l->labelStatus()}\" — hanya yang Selesai yang bisa diserahkan.");
                }
            }
            if ($kantong->count() !== count(array_unique($ids))) {
                throw new AppException(404, 'Ada kantong yang tidak ditemukan.');
            }

            $adaKurang = false;
            foreach ($kantong as $l) {
                $kurangDiKantong = false;
                foreach ($l->item as $it) {
                    $n = array_key_exists($it->id, $kembali) ? (int) $kembali[$it->id] : $it->jumlah;
                    if ($n < 0 || $n > $it->jumlah) {
                        throw new AppException(422, "Jumlah {$it->nama_pakaian} yang dikembalikan harus antara 0 dan {$it->jumlah}.");
                    }
                    $it->update(['jumlah_kembali' => $n]);
                    $kurangDiKantong = $kurangDiKantong || $n < $it->jumlah;
                }
                $adaKurang = $adaKurang || $kurangDiKantong;
                $l->update(['status' => 'diserahkan', 'diserahkan_pada' => now(), 'diserahkan_oleh' => $idPengguna, 'cara_serah' => $cara,
                    'ada_kurang' => $kurangDiKantong, 'catatan_serah' => trim((string) $catatan) ?: null]);
            }
            if ($adaKurang && trim((string) $catatan) === '') {
                throw new AppException(422, 'Ada pakaian yang kurang — tulis catatan (mis. dicari dulu / diganti / sudah dikonfirmasi santri).');
            }
            if ($tahan) {
                \App\Support\Audit\Jejak::catat('izin_laundry_tertahan', ['modul' => 'laundry-izin', 'ref_jenis' => 'Santri', 'ref_id' => $santri->id,
                    'id_pengguna' => $idPengguna, 'detail' => ['santri' => $santri->nama, 'kantong' => $kantong->pluck('nomor')->all(), 'alasan' => $izin,
                        'tunggakan_periode' => $tahan->periode, 'tunggakan_rp' => (string) $tahan->sisa]]);
            }
            foreach ($kantong as $l) {
                $l->setRelation('santri', $santri->loadMissing('wali'));
                $kurang = $l->item->filter(fn ($it) => $it->kurang() > 0)->map(fn ($it) => "{$it->nama_pakaian} {$it->kurang()}")->join(', ');
                $this->kabari($l, Teks::k('Cucian :nama sudah diserahkan', ['nama' => $santri->nama]), $kurang === ''
                    ? Teks::k(':nomor sudah diterima santri, lengkap :potong potong.', ['nomor' => $l->nomor, 'potong' => $l->jumlahPotong()])
                    : Teks::k(':nomor sudah diterima santri, tetapi ada pakaian yang kurang: :kurang. Catatan petugas: :catatan',
                        ['nomor' => $l->nomor, 'kurang' => $kurang, 'catatan' => trim((string) $catatan)]), 'laundry_diserahkan');
            }

            return $kantong->count();
        });
    }

    public function batalkan(Laundry $l, string $alasan, int $idPengguna): void
    {
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan pembatalan.');
        }
        DB::transaction(function () use ($l, $alasan, $idPengguna) {
            $kini = Laundry::whereKey($l->id)->lockForUpdate()->first();
            if (! in_array($kini->status, ['diterima', 'diperiksa'], true)) {
                throw new AppException(422, 'Hanya kantong yang belum dicuci (Diterima / Diperiksa) yang bisa dibatalkan.');
            }
            $this->assertBelumDitagih($kini);
            $kini->update(['status' => 'dibatalkan', 'dibatalkan_pada' => now(), 'dibatalkan_oleh' => $idPengguna, 'alasan_batal' => trim($alasan)]);
        });
    }

    /** Kabar ke keluarga (kategori push "Progress laundry"), disusun per orang dalam bahasanya. Tak pernah menggagalkan transaksi. */
    /** Awalan nomor kantong menurut pusat penerimaan: LDI (ikhwan), LDA (akhwat). */
    public static function awalanNomor(Santri $santri): string
    {
        return match (\App\Support\BagianLaundry::dariJk($santri->jenis_kelamin)) {
            'akhwat' => 'LDA',
            'ikhwan' => 'LDI',
            default => throw new AppException(422, "Jenis kelamin {$santri->nama} belum diisi, jadi belum masuk laundry ikhwan maupun akhwat. Lengkapi datanya dulu."),
        };
    }

    private function kabari(Laundry $l, Teks $judul, Teks $pesan, string $jenis): void
    {
        try {
            \App\Services\PortalWali\KabarWali::keKeluarga($l->santri?->wali, ["judul" => $judul, "pesan" => $pesan, "jenis" => $jenis,
                "ref_jenis" => "Laundry", "ref_id" => (string) $l->id, "id_santri" => $l->id_santri]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Kabar kuota SEKALI saat pemakaian bulan ini melewati 80% atau 100% kuota (bukan tiap setoran). */
    private function kabariKuota(Santri $santri, array $sebelum, array $sesudah): void
    {
        $kuota = (float) $sesudah['kuota'];
        if ($kuota <= 0) {
            return;
        }
        [$a, $b] = [(float) $sebelum['terpakai'] / $kuota, (float) $sesudah['terpakai'] / $kuota];
        $l = new Laundry(['id_santri' => $santri->id]);
        $l->setRelation('santri', $santri->loadMissing('wali'));
        if ($a <= 1 && $b > 1) {
            $this->kabari($l, Teks::k('Kuota laundry :nama terlampaui', ['nama' => $santri->nama]), $sesudah['perkiraan'] !== null
                ? Teks::k('Pemakaian bulan ini :terpakai kg dari kuota :kuota kg. Kelebihannya (perkiraan Rp :nominal) ditagih awal bulan depan.',
                    ['terpakai' => self::kg($sesudah['terpakai']), 'kuota' => self::kg($sesudah['kuota']), 'nominal' => number_format((float) $sesudah['perkiraan'], 0, ',', '.')])
                : Teks::k('Pemakaian bulan ini :terpakai kg dari kuota :kuota kg. Kelebihannya ditagih awal bulan depan.',
                    ['terpakai' => self::kg($sesudah['terpakai']), 'kuota' => self::kg($sesudah['kuota'])]), 'laundry_kuota');
        } elseif ($a < 0.8 && $b >= 0.8 && $b <= 1) {
            $this->kabari($l, Teks::k('Kuota laundry :nama hampir habis', ['nama' => $santri->nama]),
                Teks::k('Sisa kuota bulan ini :sisa kg dari :kuota kg.', ['sisa' => self::kg($sesudah['sisa']), 'kuota' => self::kg($sesudah['kuota'])]), 'laundry_kuota');
        }
    }

    private static function kg(string|float $kg): string
    {
        return rtrim(rtrim(number_format((float) $kg, 2, ',', '.'), '0'), ',');
    }

    /** Koreksi salah timbang — selama bulannya belum ditagih. Tercatat di jejak audit (lama → baru + alasan). */
    /**
     * MEJA PEMERIKSAAN (paket 1, 3 Nov 2026): timbang ulang + daftar pakaian (wajib) → `diperiksa`. Berat ulang inilah
     * yang dihitung kuota; berat penerimaan tetap di `berat_terima`. Kabar kuota dihitung ulang dari berat baru.
     *
     * @param  list<array{id_jenis_pakaian:int|string, jumlah:int|string}>  $item
     */
    public function periksa(Laundry $l, string $berat, array $item, ?string $catatan, int $idPengguna): Laundry
    {
        $baru = $this->beratSah($berat);
        $baris = $this->rapikanItem($item);
        $kuota = new TagihanLaundryService;

        return DB::transaction(function () use ($l, $baru, $baris, $catatan, $idPengguna, $kuota) {
            $kini = Laundry::with('santri.wali')->whereKey($l->id)->lockForUpdate()->firstOrFail();
            if ($kini->status !== 'diterima') {
                throw new AppException(422, "{$kini->nomor} berstatus \"{$kini->labelStatus()}\" — hanya kantong yang baru Diterima yang diperiksa.");
            }
            if (round((float) $kini->berat, 2) !== $baru) {
                $this->assertBelumDitagih($kini);
            }
            $sebelum = $kuota->ringkas($kini->santri, hanyaDiperiksa: true);
            $kini->item()->delete();
            foreach ($baris as $b) {
                $kini->item()->create($b);
            }
            $tambahan = trim((string) $catatan);
            $kini->update(['berat' => $baru, 'status' => 'diperiksa', 'diperiksa_pada' => now(), 'diperiksa_oleh' => $idPengguna,
                'catatan' => $tambahan === '' ? $kini->catatan : trim(($kini->catatan ? $kini->catatan."\n" : '').$tambahan)]);
            $this->kabariKuota($kini->santri, $sebelum, $kuota->ringkas($kini->santri, hanyaDiperiksa: true));

            return $kini->load('item');
        });
    }

    /**
     * UBAH DATA (paket 1): berat, daftar pakaian, catatan — sampai kantong diserahkan. Alasan wajib; isi lama & baru
     * dicatat di jejak audit. Berat terkunci bila kelebihan bulan itu sudah ditagih. Daftar pakaian wajib sejak diperiksa.
     *
     * @param  list<array{id_jenis_pakaian:int|string, jumlah:int|string}>  $item
     */
    public function ubah(Laundry $l, string $berat, array $item, ?string $catatan, string $alasan, int $idPengguna): void
    {
        $baru = $this->beratSah($berat);
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan perubahan data.');
        }

        DB::transaction(function () use ($l, $baru, $item, $catatan, $alasan, $idPengguna) {
            $kini = Laundry::with('item')->whereKey($l->id)->lockForUpdate()->firstOrFail();
            if (! in_array($kini->status, Laundry::BERJALAN, true)) {
                throw new AppException(422, "{$kini->nomor} sudah {$kini->labelStatus()} — datanya tak bisa diubah lagi.");
            }
            $baris = $this->rapikanItem($item, wajib: $kini->status !== 'diterima');
            $daftar = fn ($rows) => collect($rows)->map(fn ($b) => "{$b['nama_pakaian']} {$b['jumlah']}")->implode(', ');
            $lama = ['berat' => (string) $kini->berat, 'pakaian' => $daftar($kini->item->map->only(['nama_pakaian', 'jumlah'])), 'catatan' => (string) $kini->catatan];
            $sesudah = ['berat' => number_format($baru, 2, '.', ''), 'pakaian' => $daftar($baris), 'catatan' => trim((string) $catatan)];
            $berubah = array_keys(array_filter($sesudah, fn ($v, $k) => $v !== $lama[$k], ARRAY_FILTER_USE_BOTH));
            if ($berubah === []) {
                throw new AppException(422, 'Tidak ada data yang berubah.');
            }
            if (in_array('berat', $berubah, true)) {
                $this->assertBelumDitagih($kini);
            }
            if (in_array('pakaian', $berubah, true)) {
                $kini->item()->delete();
                foreach ($baris as $b) {
                    $kini->item()->create($b);
                }
            }
            $kini->update(['berat' => $baru, 'catatan' => $sesudah['catatan'] ?: null]);
            \App\Support\Audit\Jejak::catat('ubah_laundry', ['modul' => 'laundry', 'ref_jenis' => 'Laundry', 'ref_id' => $kini->id,
                'id_pengguna' => $idPengguna, 'detail' => ['nomor' => $kini->nomor, 'alasan' => trim($alasan)]
                    + collect($berubah)->mapWithKeys(fn ($k) => [$k => ['lama' => $lama[$k], 'baru' => $sesudah[$k]]])->all()]);
        });
    }

    private function beratSah(string $berat): float
    {
        $b = round((float) $berat, 2);
        if ($b <= 0 || $b > self::BERAT_MAKS) {
            throw new AppException(422, 'Berat harus lebih dari 0 dan paling banyak '.self::BERAT_MAKS.' kg — periksa lagi timbangannya.');
        }

        return $b;
    }

    /** Bulan yang kelebihannya sudah DITAGIH terkunci: angkanya sudah jadi tagihan wali. */
    private function assertBelumDitagih(Laundry $l): void
    {
        if ((new TagihanLaundryService)->terkunci($l->id_santri, $l->diterima_pada->format('Y-m'))) {
            throw new AppException(422, 'Kelebihan laundry bulan '.$l->diterima_pada->format('m/Y').' santri ini sudah ditagih — kantongnya terkunci. Koreksi lewat Koreksi Nominal Tagihan.');
        }
    }

    /**
     * Daftar pakaian WAJIB (keputusan user): minimal satu baris, jenis aktif, jumlah 1–200; jenis yang sama digabung.
     *
     * @return list<array{id_jenis_pakaian:int, nama_pakaian:string, jumlah:int}>
     */
    private function rapikanItem(array $item, bool $wajib = true): array
    {
        $gabung = [];
        foreach ($item as $b) {
            $id = (int) ($b['id_jenis_pakaian'] ?? 0);
            $n = (int) ($b['jumlah'] ?? 0);
            if ($id === 0 && $n === 0) {
                continue; // baris kosong di formulir
            }
            if ($id === 0 || $n < 1 || $n > 200) {
                throw new AppException(422, 'Setiap baris pakaian butuh jenis dan jumlah (1–200).');
            }
            $gabung[$id] = ($gabung[$id] ?? 0) + $n;
        }
        if ($gabung === []) {
            if (! $wajib) {
                return [];
            }
            throw new AppException(422, 'Daftar pakaian wajib diisi — catat jenis dan jumlah tiap pakaian.');
        }
        $jenis = JenisPakaianLaundry::whereIn('id', array_keys($gabung))->where('aktif', true)->pluck('nama', 'id');
        if ($jenis->count() !== count($gabung)) {
            throw new AppException(422, 'Ada jenis pakaian yang tidak dikenal atau sudah nonaktif.');
        }

        return array_map(fn ($id) => ['id_jenis_pakaian' => $id, 'nama_pakaian' => $jenis[$id], 'jumlah' => $gabung[$id]], array_keys($gabung));
    }
}
