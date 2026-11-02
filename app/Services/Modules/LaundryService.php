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
 *   terima    — santri tap kartu (atau dicari manual), petugas mencatat berat + daftar pakaian (WAJIB) → `diterima`
 *   majukan   — papan proses: `diterima` → `diproses` → `selesai`, satu-satu atau sekaligus banyak
 *   serahkan  — santri tap kartu lagi, pakaian dicocokkan satu per satu → `diserahkan`; kurang = ditandai + catatan wajib
 *   batalkan  — hanya selama masih `diterima` (salah catat)
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
        $baris = $this->rapikanItem($item);

        // Pemakaian bulan ini SEBELUM setoran — untuk kabar kuota yang dikirim sekali saat melewati 80% / 100%.
        $kuota = new TagihanLaundryService;
        $sebelum = $kuota->ringkas($santri);

        return DB::transaction(function () use ($santri, $berat, $baris, $catatan, $cara, $idPengguna, $kuota, $sebelum) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('laundry-nomor')]);
            $base = DocNumber::docBase('LDR', now());
            $l = Laundry::create([
                'nomor' => DocNumber::nextDocNumber($base, Laundry::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'berat' => $berat, 'catatan' => trim((string) $catatan) ?: null,
                'status' => 'diterima', 'cara_terima' => $cara, 'diterima_pada' => now(), 'diterima_oleh' => $idPengguna,
            ]);
            foreach ($baris as $b) {
                $l->item()->create($b);
            }
            $this->kabariKuota($santri, $sebelum, $kuota->ringkas($santri));

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
                    foreach (Laundry::with(['item', 'santri.wali'])->whereIn('id', $sah->pluck('id'))->get() as $l) {
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
        return Laundry::with('item')->where('id_santri', $santri->id)->whereIn('status', ['diterima', 'diproses', 'selesai'])
            ->orderBy('diterima_pada')->get();
    }

    /**
     * Serahkan kantong-kantong `selesai` milik SATU santri. `$kembali` = [id_item => jumlah yang dikembalikan];
     * kurang dari yang diterima = ditandai kurang, dan catatannya wajib.
     *
     * @param  list<int>  $ids
     * @param  array<int|string, int|string>  $kembali
     */
    public function serahkan(Santri $santri, array $ids, array $kembali, ?string $catatan, string $cara, int $idPengguna): int
    {
        if ($ids === []) {
            throw new AppException(422, 'Pilih kantong yang diserahkan.');
        }

        return DB::transaction(function () use ($santri, $ids, $kembali, $catatan, $cara, $idPengguna) {
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
            if ($kini->status !== 'diterima') {
                throw new AppException(422, 'Hanya kantong yang masih berstatus Diterima yang bisa dibatalkan.');
            }
            $this->assertBelumDitagih($kini);
            $kini->update(['status' => 'dibatalkan', 'dibatalkan_pada' => now(), 'dibatalkan_oleh' => $idPengguna, 'alasan_batal' => trim($alasan)]);
        });
    }

    /** Kabar ke keluarga (kategori push "Progress laundry"), disusun per orang dalam bahasanya. Tak pernah menggagalkan transaksi. */
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
    public function koreksiBerat(Laundry $l, string $berat, string $alasan, int $idPengguna): void
    {
        $baru = round((float) $berat, 2);
        if ($baru <= 0 || $baru > self::BERAT_MAKS) {
            throw new AppException(422, 'Berat harus lebih dari 0 dan paling banyak '.self::BERAT_MAKS.' kg.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan koreksi berat.');
        }
        DB::transaction(function () use ($l, $baru, $alasan, $idPengguna) {
            $kini = Laundry::whereKey($l->id)->lockForUpdate()->first();
            if ($kini->status === 'dibatalkan') {
                throw new AppException(422, 'Kantong yang dibatalkan tak bisa dikoreksi.');
            }
            $this->assertBelumDitagih($kini);
            $lama = (string) $kini->berat;
            $kini->update(['berat' => $baru]);
            \App\Support\Audit\Jejak::catat('koreksi_berat_laundry', ['modul' => 'laundry', 'ref_jenis' => 'Laundry', 'ref_id' => $kini->id,
                'id_pengguna' => $idPengguna, 'detail' => ['nomor' => $kini->nomor, 'lama' => $lama, 'baru' => (string) $baru, 'alasan' => trim($alasan)]]);
        });
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
    private function rapikanItem(array $item): array
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
            throw new AppException(422, 'Daftar pakaian wajib diisi — catat jenis dan jumlah tiap pakaian.');
        }
        $jenis = JenisPakaianLaundry::whereIn('id', array_keys($gabung))->where('aktif', true)->pluck('nama', 'id');
        if ($jenis->count() !== count($gabung)) {
            throw new AppException(422, 'Ada jenis pakaian yang tidak dikenal atau sudah nonaktif.');
        }

        return array_map(fn ($id) => ['id_jenis_pakaian' => $id, 'nama_pakaian' => $jenis[$id], 'jumlah' => $gabung[$id]], array_keys($gabung));
    }
}
