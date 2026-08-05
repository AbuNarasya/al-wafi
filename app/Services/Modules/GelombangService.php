<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Gelombang;
use App\Models\Jenjang;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Master Gelombang + MATRIKS potongannya (gelombang × jenjang).
 *
 * Bentuknya sengaja meniru grid Tarif: satu layar per tahun ajaran, sel diisi
 * berbarengan, dan TIDAK ada kolom "Umum (semua jenjang)". Jenjang selalu
 * diketahui saat menagih, jadi kolom cadangan itu hanya akan menagihkan angka
 * dari sel yang di layar tampak kosong.
 */
class GelombangService
{
    // ---- Master ----

    /** @return \Illuminate\Support\Collection<int,Gelombang> */
    public function daftar(?string $tahunAjaran = null)
    {
        return Gelombang::when($tahunAjaran, fn ($q) => $q->where('tahun_ajaran', $tahunAjaran))
            ->orderByDesc('tahun_ajaran')->orderBy('kode')->get();
    }

    public function get(int $id): Gelombang
    {
        $row = Gelombang::find($id);
        if (! $row) {
            throw new AppException(404, 'Gelombang tidak ditemukan.');
        }

        return $row;
    }

    public function simpanMaster(array $data, ?int $id = null): Gelombang
    {
        $kode = trim((string) ($data['kode'] ?? ''));
        $ta = (string) ($data['tahun_ajaran'] ?? '');
        $this->assertPeriodeSah($data['berlaku_mulai'] ?? null, $data['berlaku_sampai'] ?? null);

        $bentrok = Gelombang::where('tahun_ajaran', $ta)->where('kode', $kode)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($bentrok) {
            throw new AppException(409, "Gelombang \"{$kode}\" untuk T.A {$ta} sudah ada. Sunting baris itu.");
        }

        $isi = [
            'tahun_ajaran' => $ta,
            'kode' => $kode,
            'nama' => trim((string) ($data['nama'] ?? '')) ?: $kode,
            'berlaku_mulai' => $data['berlaku_mulai'] ?: null,
            'berlaku_sampai' => $data['berlaku_sampai'] ?: null,
            'masa_berlaku_hari' => (int) ($data['masa_berlaku_hari'] ?? 7),
            'status' => ($data['status'] ?? 'aktif') === 'arsip' ? 'arsip' : 'aktif',
            'keterangan' => $data['keterangan'] ?? null,
        ];

        if ($id) {
            $lama = $this->get($id);
            // Mengganti kode berarti memutus sel matriks & data santri yang
            // menyebutnya; ikut dialihkan supaya tak ada yang menggantung.
            if ($lama->kode !== $kode) {
                $this->alihkanKode($lama, $kode);
            }
            $lama->update($isi);

            return $lama->refresh();
        }

        return Gelombang::create($isi);
    }

    /** Ganti kode gelombang berikut seluruh perujuknya, dalam satu transaksi. */
    private function alihkanKode(Gelombang $lama, string $kodeBaru): void
    {
        DB::transaction(function () use ($lama, $kodeBaru) {
            PotonganGelombang::where('tahun_ajaran', $lama->tahun_ajaran)
                ->where('gelombang', $lama->kode)->update(['gelombang' => $kodeBaru]);
            Santri::where('tahun_ajaran', $lama->tahun_ajaran)
                ->where('gelombang', $lama->kode)->update(['gelombang' => $kodeBaru]);
        });
    }

    public function hapus(int $id): void
    {
        $row = $this->get($id);

        $dipakai = Santri::where('tahun_ajaran', $row->tahun_ajaran)->where('gelombang', $row->kode)->count();
        if ($dipakai > 0) {
            throw new AppException(409, "Gelombang ini masih dipakai {$dipakai} santri, jadi tidak bisa dihapus. "
                .'Ubah statusnya menjadi Arsip agar tak lagi ditawarkan saat registrasi.');
        }

        DB::transaction(function () use ($row) {
            PotonganGelombang::where('tahun_ajaran', $row->tahun_ajaran)->where('gelombang', $row->kode)->delete();
            $row->delete();
        });
    }

    /**
     * Kode gelombang yang boleh dipilih saat registrasi: aktif, dan periodenya
     * sedang berjalan. Kedaluwarsa/belum mulai sengaja tak ditawarkan — memilihnya
     * hanya menghasilkan calon yang nanti tak dapat potongan tanpa sebab terlihat.
     *
     * @return array<string,string> kode => label
     */
    public function opsiRegistrasi(?string $tahunAjaran, ?string $tanggal = null): array
    {
        if (! $tahunAjaran) {
            return [];
        }

        return $this->daftar($tahunAjaran)
            ->filter(fn ($g) => $g->keadaan($tanggal) === 'berlaku')
            ->mapWithKeys(fn ($g) => [$g->kode => $g->nama])
            ->all();
    }

    // ---- Matriks potongan ----

    /**
     * Matriks satu tahun ajaran: baris = gelombang aktif, kolom = jenjang.
     *
     * @return array{tahun_ajaran:string,jenjang:list<array{kode:string,nama:string}>,baris:list<array<string,mixed>>,arsip:list<string>}
     */
    public function grid(string $tahunAjaran): array
    {
        $jenjang = Jenjang::where('status', 'aktif')->orderBy('urutan')->get(['kode', 'nama']);
        $semua = $this->daftar($tahunAjaran);

        $sel = PotonganGelombang::where('tahun_ajaran', $tahunAjaran)->get()
            ->keyBy(fn ($p) => $p->gelombang.'|'.$p->kode_jenjang);

        $baris = [];
        foreach ($semua->where('status', 'aktif') as $g) {
            $isi = [];
            foreach ($jenjang as $j) {
                $row = $sel[$g->kode.'|'.$j->kode] ?? null;
                $isi[$j->kode] = $row ? Money::of($row->potongan) : null;
            }
            $baris[] = [
                'kode' => $g->kode,
                'nama' => $g->nama,
                'keadaan' => $g->keadaan(),
                'periode' => $g->labelPeriode(),
                'sel' => $isi,
            ];
        }

        return [
            'tahun_ajaran' => $tahunAjaran,
            'jenjang' => $jenjang->map(fn ($j) => ['kode' => $j->kode, 'nama' => $j->nama])->values()->all(),
            'baris' => $baris,
            'arsip' => $semua->where('status', 'arsip')->pluck('nama')->values()->all(),
        ];
    }

    /**
     * Simpan seluruh sel sekaligus. Sel yang dikosongkan DIHAPUS barisnya —
     * "tidak ada potongan" bukan "potongan nol"; nol adalah angka sah yang
     * berarti tagihan tetap terbit sebesar penuh.
     *
     * @param  array<string,array<string,string|null>>  $sel  [kode gelombang => [kode jenjang => nominal]]
     */
    public function simpanGrid(string $tahunAjaran, array $sel): int
    {
        $gelombangSah = $this->daftar($tahunAjaran)->pluck('kode')->flip();
        $jenjangSah = Jenjang::pluck('kode')->flip();

        return DB::transaction(function () use ($tahunAjaran, $sel, $gelombangSah, $jenjangSah) {
            $tersentuh = 0;
            foreach ($sel as $kodeGelombang => $perJenjang) {
                if (! isset($gelombangSah[$kodeGelombang])) {
                    throw new AppException(422, "Gelombang \"{$kodeGelombang}\" tidak terdaftar di T.A {$tahunAjaran}.");
                }
                foreach ($perJenjang as $kodeJenjang => $nominal) {
                    if (! isset($jenjangSah[$kodeJenjang])) {
                        throw new AppException(422, "Jenjang \"{$kodeJenjang}\" tidak terdaftar.");
                    }
                    $tersentuh += $this->tulisSel($tahunAjaran, (string) $kodeGelombang, (string) $kodeJenjang, $nominal);
                }
            }

            return $tersentuh;
        });
    }

    private function tulisSel(string $ta, string $gelombang, string $jenjang, mixed $nominal): int
    {
        $kunci = ['tahun_ajaran' => $ta, 'gelombang' => $gelombang, 'kode_jenjang' => $jenjang];
        $bersih = is_string($nominal) ? trim($nominal) : $nominal;

        if ($bersih === null || $bersih === '') {
            return PotonganGelombang::where($kunci)->delete() > 0 ? 1 : 0;
        }
        if (Money::isNegative($bersih)) {
            throw new AppException(422, 'Potongan tidak boleh negatif.');
        }

        PotonganGelombang::updateOrCreate($kunci, ['potongan' => Money::of($bersih)]);

        return 1;
    }

    private function assertPeriodeSah(mixed $mulai, mixed $sampai): void
    {
        if ($mulai && $sampai && (string) $sampai < (string) $mulai) {
            throw new AppException(422, 'Tanggal selesai periode tidak boleh mendahului tanggal mulai.');
        }
    }
}
