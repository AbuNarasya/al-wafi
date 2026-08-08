<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\JenisBiaya;
use App\Models\PesertaTagihanLain;
use App\Models\Santri;
use App\Models\TarifTagihanLain;
use App\Models\TipeBiaya;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * KELUARGA B — kegiatan yang ditagihkan menurut KEPESERTAAN.
 *
 * Dua hal yang diurus di sini, dan keduanya sengaja terpisah: berapa (matriks
 * tarif per jenjang) dan siapa (daftar peserta). Nominal seorang peserta baru
 * dihitung saat dibutuhkan — dari tarif jenjangnya SEKARANG, kecuali ia memang
 * diberi keringanan. Dengan begitu tarif yang dikoreksi di matriks langsung
 * berlaku tanpa menyentuh satu baris peserta pun.
 */
class KepesertaanLainService
{
    /** @var array<string,string>|null peta kode → nama jenjang, dibaca sekali */
    private ?array $petaJenjang = null;

    /** Jenis biaya yang ditagih menurut kepesertaan, untuk pemilih & matriks. */
    public function jenisKepesertaan()
    {
        return JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('lain'))
            ->where('cara_tagih', 'kepesertaan')
            ->orderBy('nama')->get();
    }

    public function jenis(string $kode): JenisBiaya
    {
        $jenis = JenisBiaya::find($kode);
        if (! $jenis || $jenis->cara_tagih !== 'kepesertaan') {
            throw new AppException(404, 'Kegiatan tidak ditemukan, atau jenis biayanya tidak ditagih menurut kepesertaan.');
        }

        return $jenis;
    }

    /**
     * Matriks tarif: baris kegiatan × kolom jenjang.
     *
     * @return array{jenjang:list<array{kode:string,nama:string}>,baris:list<array{kode:string,nama:string,status:string,sel:array<string,?string>}>}
     */
    public function grid(): array
    {
        $jenjang = Jenjang::orderBy('urutan')->orderBy('kode')->get(['kode', 'nama']);
        $sel = TarifTagihanLain::get()->groupBy('kode_jenis');

        return [
            'jenjang' => $jenjang->map(fn ($j) => ['kode' => $j->kode, 'nama' => $j->nama])->all(),
            'baris' => $this->jenisKepesertaan()->map(function ($jb) use ($jenjang, $sel) {
                $milik = ($sel[$jb->kode] ?? collect())->keyBy('kode_jenjang');

                return [
                    'kode' => $jb->kode,
                    'nama' => $jb->nama,
                    'status' => $jb->status,
                    'sel' => $jenjang->mapWithKeys(fn ($j) => [$j->kode => $milik[$j->kode]->nominal ?? null])->all(),
                ];
            })->all(),
        ];
    }

    /**
     * Simpan seluruh matriks sekaligus.
     *
     * Sel yang dikosongkan DIHAPUS barisnya, bukan disimpan sebagai nol — nol
     * adalah tarif yang sah (kegiatan gratis yang tetap perlu tagihan Rp 0 untuk
     * mencatat kepesertaan), sedangkan ketiadaan baris berarti jenjang itu tidak
     * ikut sama sekali.
     *
     * Peserta yang jenjangnya baru saja dicabut TIDAK dihapus diam-diam; ia
     * disebut dalam hasilnya supaya petugas tahu daftar pesertanya kini memuat
     * orang yang tak lagi bisa ditagih.
     *
     * @param  array<string,array<string,string|null>>  $sel
     * @return array{tersimpan:int,dihapus:int,peserta_menggantung:list<string>}
     */
    public function simpanGrid(array $sel): array
    {
        $jenisSah = $this->jenisKepesertaan()->pluck('kode')->flip();
        $jenjangSah = Jenjang::pluck('kode')->flip();
        $tersimpan = $dihapus = 0;

        DB::transaction(function () use ($sel, $jenisSah, $jenjangSah, &$tersimpan, &$dihapus) {
            foreach ($sel as $kodeJenis => $kolom) {
                if (! isset($jenisSah[$kodeJenis])) {
                    continue;
                }
                foreach ($kolom as $kodeJenjang => $nilai) {
                    if (! isset($jenjangSah[$kodeJenjang])) {
                        continue;
                    }
                    $kunci = ['kode_jenis' => $kodeJenis, 'kode_jenjang' => $kodeJenjang];

                    if ($nilai === null || trim((string) $nilai) === '') {
                        $dihapus += TarifTagihanLain::where($kunci)->delete();

                        continue;
                    }

                    TarifTagihanLain::updateOrCreate($kunci, ['nominal' => Money::of($nilai)]);
                    $tersimpan++;
                }
            }
        });

        return [
            'tersimpan' => $tersimpan,
            'dihapus' => $dihapus,
            'peserta_menggantung' => $this->pesertaTanpaTarif(),
        ];
    }

    /**
     * Peserta yang masih "ikut" tetapi jenjangnya tak punya tarif — biasanya
     * karena selnya baru dikosongkan, atau santrinya pindah jenjang.
     *
     * @return list<string>
     */
    public function pesertaTanpaTarif(): array
    {
        return PesertaTagihanLain::where('status', 'ikut')
            ->with(['santri:id,nama,kode_jenjang', 'jenis:kode,nama'])
            ->get()
            ->filter(fn ($p) => $p->nominal === null && $this->tarifJenjang($p->kode_jenis, $p->santri?->kode_jenjang) === null)
            ->map(fn ($p) => "{$p->santri?->nama} ({$p->jenis?->nama})")
            ->values()->all();
    }

    /**
     * NAMA jenjang, bukan kode `J001` — aturan tetap di aplikasi ini, dan
     * berlaku juga untuk pesan galat: "Jenjang J001 belum punya tarif" tak
     * memberi tahu petugas jenjang mana yang dimaksud.
     */
    private function namaJenjang(?string $kode): string
    {
        if ($kode === null) {
            return '(tanpa jenjang)';
        }

        $this->petaJenjang ??= Jenjang::pluck('nama', 'kode')->all();

        return $this->petaJenjang[$kode] ?? $kode;
    }

    public function tarifJenjang(string $kodeJenis, ?string $kodeJenjang): ?string
    {
        if ($kodeJenjang === null) {
            return null;
        }

        $n = TarifTagihanLain::where('kode_jenis', $kodeJenis)->where('kode_jenjang', $kodeJenjang)->value('nominal');

        return $n === null ? null : Money::of($n);
    }

    /**
     * Daftar peserta beserta nominal yang berlaku baginya.
     *
     * @return list<array{rec:PesertaTagihanLain,tarif:?string,nominal:?string,keringanan:bool}>
     */
    public function peserta(string $kodeJenis): array
    {
        return PesertaTagihanLain::where('kode_jenis', $kodeJenis)
            ->with(['santri:id,nis,nama,kode_jenjang,tingkat,status'])
            ->get()
            ->sortBy(fn ($p) => $p->santri?->nama)
            ->map(function ($p) use ($kodeJenis) {
                $tarif = $this->tarifJenjang($kodeJenis, $p->santri?->kode_jenjang);
                $nominal = $p->nominal !== null ? Money::of($p->nominal) : $tarif;

                return [
                    'rec' => $p,
                    'nama_jenjang' => $this->namaJenjang($p->santri?->kode_jenjang),
                    'tarif' => $tarif,
                    'nominal' => $nominal,
                    'keringanan' => $p->nominal !== null && $tarif !== null && ! Money::eq(Money::of($p->nominal), $tarif),
                ];
            })->values()->all();
    }

    public function tambah(string $kodeJenis, int $idSantri, ?string $nominal = null): PesertaTagihanLain
    {
        $this->jenis($kodeJenis);

        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, "{$santri->nama} sudah tidak berstatus aktif, jadi tak bisa didaftarkan sebagai peserta.");
        }

        // Jenjang tanpa sel tarif = memang tidak ikut kegiatan ini. Ditolak di
        // sini supaya kekeliruannya ketahuan saat mendaftarkan, bukan berbulan
        // kemudian saat penerbitan diam-diam melewatinya.
        if ($nominal === null && $this->tarifJenjang($kodeJenis, $santri->kode_jenjang) === null) {
            throw new AppException(422, 'Jenjang '.$this->namaJenjang($santri->kode_jenjang)." belum punya tarif untuk kegiatan ini, jadi jenjang itu dianggap tidak ikut. Isi dulu selnya di Matriks Tarif, atau beri {$santri->nama} nominal khusus.");
        }

        if (PesertaTagihanLain::where('kode_jenis', $kodeJenis)->where('id_santri', $idSantri)->exists()) {
            throw new AppException(422, "{$santri->nama} sudah terdaftar sebagai peserta kegiatan ini.");
        }

        return PesertaTagihanLain::create([
            'kode_jenis' => $kodeJenis,
            'id_santri' => $idSantri,
            'nominal' => $nominal === null || trim($nominal) === '' ? null : Money::of($nominal),
            'status' => 'ikut',
        ]);
    }

    /** Nominal khusus; dikosongkan berarti kembali mengikuti tarif jenjangnya. */
    public function ubahNominal(int $id, ?string $nominal): PesertaTagihanLain
    {
        $p = $this->cari($id);
        $p->update(['nominal' => $nominal === null || trim($nominal) === '' ? null : Money::of($nominal)]);

        return $p;
    }

    public function ubahStatus(int $id, string $status): PesertaTagihanLain
    {
        if (! in_array($status, ['ikut', 'berhenti'], true)) {
            throw new AppException(422, 'Status peserta hanya boleh "ikut" atau "berhenti".');
        }
        $p = $this->cari($id);
        $p->update(['status' => $status]);

        return $p;
    }

    private function cari(int $id): PesertaTagihanLain
    {
        $p = PesertaTagihanLain::find($id);
        if (! $p) {
            throw new AppException(404, 'Peserta tidak ditemukan.');
        }

        return $p;
    }

    /**
     * Nominal yang akan ditagihkan per santri, untuk penerbitan.
     *
     * Hanya peserta berstatus "ikut", bersantri aktif, dan bernominal terhitung.
     * Yang gugur dikembalikan terpisah beserta sebabnya — penerbitan yang diam
     * soal siapa yang tak kebagian adalah persis kekeliruan yang dibetulkan di
     * langkah 1.
     *
     * @return array{nominal:array<int,string>,gugur:list<string>}
     */
    public function nominalPeserta(string $kodeJenis): array
    {
        $nominal = [];
        $gugur = [];

        foreach ($this->peserta($kodeJenis) as $p) {
            $santri = $p['rec']->santri;
            $nama = $santri?->nama ?? "santri #{$p['rec']->id_santri}";

            if (! $p['rec']->ikut()) {
                continue; // berhenti = memang tak ditagih, bukan kegagalan
            }
            if ($santri?->status !== 'aktif') {
                $gugur[] = "{$nama} — sudah tidak berstatus aktif";

                continue;
            }
            if ($p['nominal'] === null) {
                $gugur[] = "{$nama} — jenjang ".$this->namaJenjang($santri->kode_jenjang).' belum punya tarif';

                continue;
            }

            $nominal[$p['rec']->id_santri] = $p['nominal'];
        }

        return ['nominal' => $nominal, 'gugur' => $gugur];
    }
}
