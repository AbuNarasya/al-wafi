<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\Jenjang;
use App\Models\NisSantri;
use App\Models\PengaturanNis;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NIS — diterbitkan MANUAL & MASSAL oleh staf, bukan otomatis saat daftar ulang.
 *
 * Sebabnya urutan: nomornya berurut menurut ABJAD nama dalam satu angkatan
 * jenjang. Abjad hanya bisa ditentukan setelah seluruh angkatan diterima, jadi
 * menerbitkannya satu per satu saat daftar ulang akan menghasilkan urutan
 * kedatangan, bukan urutan abjad.
 *
 * Satu santri bisa punya BEBERAPA NIS: setiap kali ia masuk jenjang baru,
 * nomornya diterbitkan ulang. Yang lama tetap tersimpan di `nis_santri` supaya
 * kartu & rapor lama tetap bisa ditelusuri.
 *
 * FORMAT bertoken, disetel di Setting Awal:
 *   {TA4}      4 digit tahun ajaran masuk jenjang — "2026/2027" → 2627
 *   {TA2}      2 digit tahun pertamanya — "2026/2027" → 26
 *   {TINGKAT2} 2 digit tingkat saat masuk jenjang itu — 07
 *   {URUT3}    3 digit urutan abjad — 001
 *   {JENJANG}  kode jenjangnya
 * Angka di belakang TINGKAT/URUT = jumlah digitnya, bebas diubah.
 */
class NisService
{
    private const ID = 1;

    /** Contoh nilai untuk pratinjau format di layar pengaturan. */
    private const CONTOH = ['tahun_ajaran' => '2026/2027', 'tingkat' => 7, 'urut' => 1, 'kode_jenjang' => 'SMP'];

    public function pengaturan(): PengaturanNis
    {
        return PengaturanNis::firstOrCreate(['id' => self::ID], ['format' => '{TA4}{TINGKAT2}{URUT3}']);
    }

    public function simpanFormat(string $format): PengaturanNis
    {
        $format = trim($format);
        if ($format === '') {
            throw new AppException(422, 'Format NIS tidak boleh kosong.');
        }
        // Tanpa {URUT} setiap santri dalam satu angkatan jenjang akan bernomor
        // SAMA — dan indeks uniknya akan menolak yang kedua. Ditolak di sini
        // supaya sebabnya jelas, bukan muncul sebagai galat basis data.
        if (! str_contains($format, '{URUT')) {
            throw new AppException(422, 'Format wajib memuat {URUT…} — tanpa nomor urut, '
                .'santri dalam satu angkatan jenjang akan mendapat NIS yang sama.');
        }
        $sisa = preg_replace('/\{(TA4|TA2|TINGKAT\d|URUT\d|JENJANG)\}/', '', $format);
        if (preg_match('/\{[^}]*\}/', (string) $sisa, $m)) {
            throw new AppException(422, "Token \"{$m[0]}\" tidak dikenal. Yang tersedia: "
                .'{TA4}, {TA2}, {TINGKAT2}, {URUT3}, {JENJANG}.');
        }

        $row = $this->pengaturan();
        $row->update(['format' => $format]);

        return $row->refresh();
    }

    /** Contoh hasil sebuah format — ditampilkan hidup saat petugas menyetelnya. */
    public function contoh(?string $format = null): string
    {
        return $this->rakit($format ?? $this->pengaturan()->format, self::CONTOH);
    }

    /**
     * Rakit satu NIS dari format + bahannya.
     *
     * @param  array{tahun_ajaran:?string, tingkat:?int, urut:int, kode_jenjang:?string}  $bahan
     */
    public function rakit(string $format, array $bahan): string
    {
        // "2026/2027" → "2627". Hanya digitnya yang diambil supaya pemisahnya
        // (garis miring, strip, spasi) tak ikut masuk ke nomor.
        $digitTa = preg_replace('/\D/', '', (string) ($bahan['tahun_ajaran'] ?? ''));
        $ta4 = strlen($digitTa) >= 8 ? substr($digitTa, 2, 2).substr($digitTa, 6, 2) : substr($digitTa, 0, 4);
        $ta2 = substr($digitTa, 2, 2);

        return preg_replace_callback('/\{(TA4|TA2|TINGKAT(\d)|URUT(\d)|JENJANG)\}/', function ($m) use ($ta4, $ta2, $bahan) {
            return match (true) {
                $m[1] === 'TA4' => $ta4,
                $m[1] === 'TA2' => $ta2,
                $m[1] === 'JENJANG' => (string) ($bahan['kode_jenjang'] ?? ''),
                str_starts_with($m[1], 'TINGKAT') => str_pad((string) (int) ($bahan['tingkat'] ?? 0), (int) $m[2], '0', STR_PAD_LEFT),
                default => str_pad((string) (int) $bahan['urut'], (int) $m[3], '0', STR_PAD_LEFT),
            };
        }, $format);
    }

    /**
     * TAHUN AJARAN & TINGKAT saat santri masuk jenjangnya YANG SEKARANG.
     *
     * Diambil dari riwayat tingkat — baris paling awal untuk jenjang itu. Bukan
     * dari `santri.tahun_ajaran` (angkatan masuk pesantren) maupun `tingkat`
     * sekarang: santri SMA tingkat 12 harus tetap ber-NIS tingkat 10, karena
     * itulah tingkat saat ia masuk SMA.
     *
     * @return array{tahun_ajaran:?string, tingkat:?int}
     */
    public function masukJenjang(Santri $santri): array
    {
        $awal = RiwayatTingkat::where('id_santri', $santri->id)
            ->where('kode_jenjang', $santri->kode_jenjang)
            ->orderBy('tahun_ajaran')->orderBy('id')->first();

        return [
            'tahun_ajaran' => $awal->tahun_ajaran ?? $santri->taBerjalan() ?? $santri->tahun_ajaran,
            'tingkat' => $awal->tingkat ?? $santri->tingkat,
        ];
    }

    /**
     * Santri yang BELUM punya NIS untuk jenjangnya sekarang.
     *
     * Termasuk yang sudah pernah ber-NIS di jenjang sebelumnya — merekalah yang
     * baru naik jenjang dan menunggu nomor barunya.
     *
     * @param  array{jenjang?:string, tahun_ajaran?:string}  $filter
     * @return list<array<string,mixed>>
     */
    public function pratinjau(array $filter = []): array
    {
        $jenjangFilter = trim((string) ($filter['jenjang'] ?? ''));
        $format = $this->pengaturan()->format;
        $namaJenjang = Jenjang::pluck('nama', 'kode')->all();

        $santri = Santri::where('status', 'aktif')
            ->when($jenjangFilter !== '', fn ($q) => $q->where('kode_jenjang', $jenjangFilter))
            ->orderBy('nama')->orderBy('id')
            ->get(['id', 'nama', 'nis', 'no_pendaftaran', 'kode_jenjang', 'tingkat', 'tahun_ajaran', 'tahun_ajaran_berjalan']);

        // Sudah punya NIS untuk jenjang ini? Diperiksa lewat RIWAYAT, bukan lewat
        // `santri.nis`: yang baru naik jenjang masih memegang NIS jenjang lamanya.
        $sudah = NisSantri::whereIn('id_santri', $santri->pluck('id'))
            ->get(['id_santri', 'kode_jenjang'])
            ->map(fn ($n) => $n->id_santri.'|'.$n->kode_jenjang)->flip();

        $calon = [];
        foreach ($santri as $s) {
            if (isset($sudah[$s->id.'|'.$s->kode_jenjang])) {
                continue;
            }
            $masuk = $this->masukJenjang($s);
            if (($filter['tahun_ajaran'] ?? '') !== '' && $masuk['tahun_ajaran'] !== $filter['tahun_ajaran']) {
                continue;
            }
            $calon[] = ['santri' => $s, 'masuk' => $masuk];
        }

        // Nomor urut MELANJUTKAN yang sudah ada dalam satu (T.A masuk, jenjang) —
        // santri yang masuk di tengah tahun tetap dapat nomor berikutnya, walau
        // abjadnya jadi tak berurutan. Itu konsekuensi yang disengaja: nomor yang
        // sudah tercetak tak boleh bergeser hanya karena ada anak baru.
        $urutBerikut = [];
        $hasil = [];
        foreach ($calon as $c) {
            $s = $c['santri'];
            $kunci = ($c['masuk']['tahun_ajaran'] ?? '-').'|'.$s->kode_jenjang;
            $urutBerikut[$kunci] ??= (int) NisSantri::where('kode_jenjang', $s->kode_jenjang)
                ->where('tahun_ajaran', $c['masuk']['tahun_ajaran'])->max('urut');
            $urut = ++$urutBerikut[$kunci];

            $hasil[] = [
                'id_santri' => $s->id,
                'nama' => $s->nama,
                'no_pendaftaran' => $s->no_pendaftaran,
                'nis_lama' => $s->nis,
                'kode_jenjang' => $s->kode_jenjang,
                'jenjang' => $namaJenjang[$s->kode_jenjang] ?? $s->kode_jenjang,
                'tahun_ajaran' => $c['masuk']['tahun_ajaran'],
                'tingkat' => $c['masuk']['tingkat'],
                'urut' => $urut,
                'nis' => $this->rakit($format, [
                    'tahun_ajaran' => $c['masuk']['tahun_ajaran'],
                    'tingkat' => $c['masuk']['tingkat'],
                    'urut' => $urut,
                    'kode_jenjang' => $s->kode_jenjang,
                ]),
            ];
        }

        return $hasil;
    }

    /**
     * Terbitkan NIS untuk santri terpilih.
     *
     * Nomornya DIHITUNG ULANG di sini, tidak diambil dari kiriman layar: antara
     * pratinjau dibuka dan tombol ditekan, orang lain bisa saja sudah menerbitkan
     * nomor yang sama.
     *
     * @param  list<int>  $idSantri
     * @return array{terbit:int, dilewati:int, rincian:list<array<string,mixed>>}
     */
    public function terbitkan(array $idSantri, int $idPengguna): array
    {
        $idSantri = array_values(array_unique(array_map('intval', $idSantri)));
        if ($idSantri === []) {
            throw new AppException(422, 'Tidak ada santri yang dipilih.');
        }

        return DB::transaction(function () use ($idSantri, $idPengguna) {
            // Disaring ulang lewat pratinjau supaya aturan "belum punya NIS untuk
            // jenjang ini" hanya hidup di SATU tempat.
            $rencana = collect($this->pratinjau())->whereIn('id_santri', $idSantri)->values();
            if ($rencana->isEmpty()) {
                throw new AppException(422, 'Seluruh santri yang dipilih sudah punya NIS untuk jenjangnya sekarang.');
            }

            $rincian = [];
            foreach ($rencana as $r) {
                // NIS lama tetap tersimpan, hanya ditandai tak berlaku lagi.
                NisSantri::where('id_santri', $r['id_santri'])->update(['berlaku' => false]);

                NisSantri::create([
                    'id_santri' => $r['id_santri'], 'nis' => $r['nis'],
                    'kode_jenjang' => $r['kode_jenjang'], 'tingkat' => $r['tingkat'],
                    'tahun_ajaran' => $r['tahun_ajaran'], 'urut' => $r['urut'],
                    'berlaku' => true, 'diterbitkan_pada' => Carbon::now()->toDateString(),
                    'diterbitkan_oleh' => $idPengguna,
                ]);
                Santri::where('id', $r['id_santri'])->update(['nis' => $r['nis']]);

                $rincian[] = ['id_santri' => $r['id_santri'], 'nama' => $r['nama'],
                    'nis_lama' => $r['nis_lama'], 'nis' => $r['nis']];
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'terbitkan_nis_massal',
                'detail' => json_encode(['jumlah' => count($rincian), 'rincian' => $rincian], JSON_UNESCAPED_UNICODE),
            ]);

            return ['terbit' => count($rincian), 'dilewati' => count($idSantri) - count($rincian), 'rincian' => $rincian];
        });
    }
}
