<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\KebijakanKhusus;
use App\Models\LampiranDokumen;
use App\Models\Santri;
use App\Support\Audit\Jejak;
use App\Support\Money;
use App\Support\SumberLampiran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * KEBIJAKAN KHUSUS — keringanan, potongan, beasiswa, dan sejenisnya.
 *
 * Tiga hal yang membedakannya dari `santri.nominal_spp` yang digantikannya:
 * ada ALASAN-nya, ada yang MENYETUJUI-nya, dan ada MASA BERLAKU-nya. Ditambah
 * satu syarat yang diminta yayasan: dua surat wajib terlampir sebelum boleh
 * disetujui — permohonan dari wali, persetujuan dari yayasan.
 */
class KebijakanKhususService
{
    public const MODUL = 'kebijakan-khusus';

    /** Ajukan kebijakan baru. Lahir berstatus `diajukan` — belum berlaku apa pun. */
    public function ajukan(array $data, ?int $idPengguna): KebijakanKhusus
    {
        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        $alasan = trim((string) ($data['alasan'] ?? ''));
        if ($alasan === '') {
            throw new AppException(422, 'Alasan wajib diisi — inilah yang membedakan keringanan dari angka yang ditimpa begitu saja.');
        }

        $besaran = Money::of($data['besaran'] ?? 0);
        if (($data['cara'] ?? '') === 'persen' && Money::gt($besaran, '100')) {
            throw new AppException(422, 'Potongan persen tidak boleh lebih dari 100%.');
        }
        if (Money::isNegative($besaran)) {
            throw new AppException(422, 'Besaran tidak boleh negatif.');
        }

        return KebijakanKhusus::create([
            'id_santri' => $santri->id,
            'jenis' => $data['jenis'] ?? 'keringanan',
            'perilaku' => $data['perilaku'],
            'cara' => $data['cara'] ?? 'nominal_khusus',
            'besaran' => $besaran,
            'tahun_ajaran' => $data['tahun_ajaran'] ?? null,
            'berlaku_mulai' => $data['berlaku_mulai'] ?? null,
            'berlaku_sampai' => $data['berlaku_sampai'] ?? null,
            'alasan' => $alasan,
            'status' => 'diajukan',
            'diajukan_oleh' => $idPengguna,
            'diajukan_pada' => now(),
        ]);
    }

    /**
     * Setujui — hanya bila KEDUA surat sudah terlampir.
     *
     * Syarat ini ditegakkan di sini, bukan hanya diingatkan di layar: keringanan
     * adalah pengurangan penerimaan pesantren, dan yang membedakannya dari
     * kehilangan uang adalah persetujuan tertulis yayasan.
     */
    public function setujui(int $id, ?int $idPengguna, ?string $catatan = null): KebijakanKhusus
    {
        $k = $this->siapDiputus($id);
        $kurang = $this->suratYangKurang($id);

        if ($kurang !== []) {
            throw new AppException(422,
                'Belum bisa disetujui — '.implode(' dan ', $kurang).' belum dilampirkan. '
                .'Unggah dulu lewat tombol Lampiran pada barisnya.');
        }

        try {
            $k->update([
                'status' => 'disetujui',
                'diputus_oleh' => $idPengguna,
                'diputus_pada' => now(),
                'catatan_keputusan' => $catatan,
            ]);
        } catch (QueryException) {
            // Indeks unik parsial `kebijakan_khusus_berlaku`.
            throw new AppException(409,
                'Santri ini sudah punya kebijakan yang BERLAKU untuk perilaku & tahun ajaran yang sama. '
                .'Akhiri dulu yang lama sebelum menyetujui yang baru.');
        }

        Jejak::catat('setujui_kebijakan_khusus', [
            'modul' => self::MODUL,
            'ref_jenis' => 'KebijakanKhusus',
            'ref_id' => $k->id,
            'detail' => [
                'santri' => $k->santri?->nama,
                'perilaku' => $k->perilaku,
                'kebijakan' => $k->ringkas(),
                'alasan' => $k->alasan,
            ],
            'id_pengguna' => $idPengguna,
        ]);

        return $k->refresh();
    }

    public function tolak(int $id, ?int $idPengguna, string $catatan): KebijakanKhusus
    {
        $k = $this->siapDiputus($id);
        if (trim($catatan) === '') {
            throw new AppException(422, 'Alasan penolakan wajib diisi.');
        }

        $k->update([
            'status' => 'ditolak',
            'diputus_oleh' => $idPengguna,
            'diputus_pada' => now(),
            'catatan_keputusan' => $catatan,
        ]);

        return $k->refresh();
    }

    /** Akhiri kebijakan yang sedang berlaku (mis. keadaan walinya sudah membaik). */
    public function akhiri(int $id, ?int $idPengguna, string $catatan): KebijakanKhusus
    {
        $k = KebijakanKhusus::find($id);
        if (! $k) {
            throw new AppException(404, 'Kebijakan tidak ditemukan.');
        }
        if ($k->status !== 'disetujui') {
            throw new AppException(409, 'Hanya kebijakan yang sedang berlaku yang bisa diakhiri.');
        }

        $k->update(['status' => 'berakhir', 'catatan_keputusan' => $catatan]);

        Jejak::catat('akhiri_kebijakan_khusus', [
            'modul' => self::MODUL, 'ref_jenis' => 'KebijakanKhusus', 'ref_id' => $k->id,
            'detail' => ['alasan' => $catatan], 'id_pengguna' => $idPengguna,
        ]);

        return $k->refresh();
    }

    /**
     * TERAPKAN pada sebuah nominal tagihan.
     *
     * Dipanggil dari tiap titik penagihan yang mengenal santrinya. Tagihan yang
     * SUDAH terbit tak pernah disentuh dari sini — angka yang sudah dijanjikan
     * ke wali tetap seperti semula, dan yang berubah hanyalah penerbitan
     * berikutnya. Aturan itu diwarisi dari `setNominalKhusus()` yang digantikan
     * modul ini, dan alasannya masih berlaku.
     *
     * @return array{nominal:string, kebijakan:?KebijakanKhusus}
     */
    public function terapkan(int $idSantri, string $perilaku, ?string $tahunAjaran, string $nominalAsli, ?string $tanggal = null): array
    {
        $k = $this->berlaku($idSantri, $perilaku, $tahunAjaran, $tanggal);
        if (! $k) {
            return ['nominal' => Money::of($nominalAsli), 'kebijakan' => null];
        }

        return ['nominal' => $this->hitung($k, $nominalAsli), 'kebijakan' => $k];
    }

    /** Nominal sesudah sebuah kebijakan dikenakan pada nominal asli — tanpa kueri. */
    public function hitung(KebijakanKhusus $k, string $nominalAsli): string
    {
        $nominal = match ($k->cara) {
            'nominal_khusus' => Money::of($k->besaran),
            'nominal' => Money::sub($nominalAsli, $k->besaran),
            'persen' => Money::sub($nominalAsli, Money::div(Money::mul($nominalAsli, $k->besaran), '100')),
            default => Money::of($nominalAsli),
        };

        // Potongan tak boleh membuat tagihan negatif — nol adalah batas
        // bawahnya, dan nol tetap angka yang sah (tagihan terbit senilai nol).
        if (Money::isNegative($nominal)) {
            $nominal = Money::of('0');
        }

        return $nominal;
    }

    /** Kebijakan yang sedang berlaku untuk satu sel penagihan, bila ada. */
    public function berlaku(int $idSantri, string $perilaku, ?string $tahunAjaran, ?string $tanggal = null): ?KebijakanKhusus
    {
        return $this->kueriBerlaku($perilaku, $tahunAjaran, $tanggal)
            ->where('id_santri', $idSantri)
            ->first();
    }

    /**
     * Bentuk MASSAL berlaku() — satu kueri untuk seluruh daftar. Pratinjau SPP
     * memutar ratusan santri; sebaris-sebaris, kueri ini saja sudah ratusan
     * perjalanan ke database. Syarat & urutannya SAMA persis (kueriBerlaku),
     * jadi yang terpilih per santri tak mungkin berbeda dari berlaku().
     *
     * @param  list<int>  $idSantri
     * @return array<int,KebijakanKhusus> diindeks id santri; yang tak berkebijakan tak ada kuncinya
     */
    public function berlakuMassal(array $idSantri, string $perilaku, ?string $tahunAjaran, ?string $tanggal = null): array
    {
        if ($idSantri === []) {
            return [];
        }

        // Baris pertama tiap santri menurut urutan kueriBerlaku = yang dipilih first().
        return $this->kueriBerlaku($perilaku, $tahunAjaran, $tanggal)
            ->whereIn('id_santri', $idSantri)
            ->get()
            ->groupBy('id_santri')
            ->map->first()
            ->all();
    }

    /** Syarat & urutan "kebijakan yang berlaku" — satu tempat untuk berlaku() dan berlakuMassal(). */
    private function kueriBerlaku(string $perilaku, ?string $tahunAjaran, ?string $tanggal): Builder
    {
        $tgl = $tanggal ? Carbon::parse($tanggal) : Carbon::now();

        return KebijakanKhusus::query()
            ->where('perilaku', $perilaku)
            ->where('status', 'disetujui')
            // Kebijakan tanpa tahun ajaran berlaku untuk semua tahun; yang
            // menyebutnya hanya untuk tahun itu.
            ->where(fn ($q) => $q->whereNull('tahun_ajaran')->orWhere('tahun_ajaran', $tahunAjaran))
            ->where(fn ($q) => $q->whereNull('berlaku_mulai')->orWhere('berlaku_mulai', '<=', $tgl->toDateString()))
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tgl->toDateString()))
            // Yang menyebut tahun ajaran secara eksplisit lebih spesifik, jadi
            // ia menang atas yang berlaku umum.
            ->orderByRaw('CASE WHEN tahun_ajaran IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id');
    }

    /** @return list<string> nama surat yang belum terlampir */
    public function suratYangKurang(int $id): array
    {
        $kurang = [];
        foreach ([
            SumberLampiran::KEBIJAKAN_PERMOHONAN => 'surat permohonan wali',
            SumberLampiran::KEBIJAKAN_PERSETUJUAN => 'surat persetujuan yayasan',
        ] as $jenis => $label) {
            if (! LampiranDokumen::where('jenis_dokumen', $jenis)->where('id_dokumen', (string) $id)->exists()) {
                $kurang[] = $label;
            }
        }

        return $kurang;
    }

    public function daftar(?string $status = null, ?int $idSantri = null)
    {
        return KebijakanKhusus::with(['santri:id,nama,nis', 'pemohon:id_pengguna,nama', 'pemutus:id_pengguna,nama'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($idSantri, fn ($q) => $q->where('id_santri', $idSantri))
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();
    }

    private function siapDiputus(int $id): KebijakanKhusus
    {
        $k = KebijakanKhusus::with('santri')->find($id);
        if (! $k) {
            throw new AppException(404, 'Kebijakan tidak ditemukan.');
        }
        if ($k->status !== 'diajukan') {
            throw new AppException(409, 'Kebijakan ini sudah diputuskan.');
        }

        return $k;
    }
}
