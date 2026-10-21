<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Santri;
use Illuminate\Support\Facades\DB;

/**
 * KELAS / ROMBEL (keputusan user 21 Okt 2026) — master & penempatan santri.
 *
 *  • Kelas milik satu jenjang & satu tingkat; peruntukan Putra/Putri/Campur dijaga saat penempatan.
 *  • Santri ditempatkan hanya bila AKTIF dan jenjang + tingkatnya sama dengan kelasnya.
 *  • Kelas dikosongkan otomatis saat tingkat/jenjang/tahun berjalan berganti (Santri::booted).
 */
class KelasService
{
    /**
     * Pecah isian kelas dari data sekolah: "7A - ABU BAKAR…", "9 CHECHNYA", "1 (ABU BAKAR…)", "12 SCIENCE".
     * Angka di depan = tingkat; seluruh teks (spasi dirapikan) = nama kelas. null bila tak diawali angka.
     *
     * @return array{tingkat:int, nama:string}|null
     */
    public static function pecah(?string $teks): ?array
    {
        $teks = trim(preg_replace('/\s+/u', ' ', (string) $teks));
        if (! preg_match('/^(\d{1,2})(?!\d)/', $teks, $m)) {
            return null;
        }

        return ['tingkat' => (int) $m[1], 'nama' => mb_strtoupper($teks)];
    }

    public function simpan(array $data, ?Kelas $kelas = null): Kelas
    {
        $jenjang = Jenjang::find($data['kode_jenjang']) ?? throw new AppException(422, 'Jenjang tidak ditemukan.');
        $tingkat = (int) $data['tingkat'];
        (new SantriService)->pastikanTingkatSah($jenjang->kode, $tingkat);
        $nama = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $data['nama'])));
        if ($nama === '') {
            throw new AppException(422, 'Nama kelas wajib diisi.');
        }
        $kembar = Kelas::where('kode_jenjang', $jenjang->kode)->where('tingkat', $tingkat)->where('nama', $nama)
            ->when($kelas, fn ($q) => $q->whereKeyNot($kelas->id))->exists();
        if ($kembar) {
            throw new AppException(422, "Kelas \"{$nama}\" sudah ada di {$jenjang->nama} tingkat {$tingkat}.");
        }
        $isi = ['kode_jenjang' => $jenjang->kode, 'tingkat' => $tingkat, 'nama' => $nama,
            'peruntukan' => $data['peruntukan'] ?? 'C', 'status' => $data['status'] ?? 'aktif',
            // Wali kelas memverifikasi pengajuan pengunduran diri santri kelasnya (21 Okt 2026).
            'id_wali_kelas' => array_key_exists('id_wali_kelas', $data) ? ($data['id_wali_kelas'] ?: null) : $kelas?->id_wali_kelas];

        if ($kelas) {
            $berpenghuni = $kelas->santri()->exists();
            if ($berpenghuni && ($kelas->kode_jenjang !== $isi['kode_jenjang'] || (int) $kelas->tingkat !== $tingkat)) {
                throw new AppException(422, 'Jenjang/tingkat kelas yang sudah berisi santri tidak bisa diubah. Kosongkan dulu kelasnya.');
            }
            if ($berpenghuni && $isi['peruntukan'] !== 'C'
                && $kelas->santri()->where('jenis_kelamin', '!=', $isi['peruntukan'])->exists()) {
                throw new AppException(422, 'Kelas ini masih berisi santri berjenis kelamin lain — peruntukannya tak bisa diubah.');
            }
            $kelas->update($isi);

            return $kelas;
        }

        return Kelas::create($isi);
    }

    public function hapus(Kelas $kelas): void
    {
        if ($kelas->santri()->exists()) {
            throw new AppException(422, 'Kelas ini masih berisi santri. Pindahkan/kosongkan santrinya dulu, atau nonaktifkan kelasnya.');
        }
        $kelas->delete();
    }

    /**
     * Tempatkan beberapa santri ke satu kelas (null = kosongkan). Semua atau tidak sama sekali.
     *
     * @param  int[]  $idSantri
     */
    public function tempatkan(array $idSantri, ?int $idKelas): int
    {
        $kelas = $idKelas ? (Kelas::find($idKelas) ?? throw new AppException(404, 'Kelas tidak ditemukan.')) : null;
        if ($kelas && $kelas->status !== 'aktif') {
            throw new AppException(422, "Kelas {$kelas->nama} nonaktif.");
        }
        $santri = Santri::whereIn('id', $idSantri)->get();
        if ($santri->isEmpty()) {
            throw new AppException(422, 'Pilih minimal satu santri.');
        }
        foreach ($santri as $s) {
            if ($s->status !== 'aktif') {
                throw new AppException(422, "{$s->nama} bukan santri aktif — kelas diisi sesudah santri dinyatakan aktif.");
            }
            if ($kelas && ($s->kode_jenjang !== $kelas->kode_jenjang || (int) $s->tingkat !== (int) $kelas->tingkat)) {
                throw new AppException(422, "{$s->nama} berada di tingkat {$s->tingkat} — tidak cocok dengan kelas {$kelas->nama}.");
            }
            if ($kelas && ! $kelas->menerima($s->jenis_kelamin)) {
                throw new AppException(422, "{$s->nama} tidak bisa masuk kelas {$kelas->labelPeruntukan()} {$kelas->nama}.");
            }
        }

        return DB::transaction(fn () => Santri::whereIn('id', $santri->pluck('id'))->update(['id_kelas' => $kelas?->id]));
    }

    /**
     * Kelas untuk impor santri lama: dicari, atau dibuat bila belum ada. Peruntukan kelas baru mengikuti
     * jenis kelamin santri pertama; santri berjenis kelamin lain di kelas yang sama menjadikannya Campur.
     */
    public static function untukImpor(string $kodeJenjang, int $tingkat, string $nama, string $jenisKelamin): Kelas
    {
        $kelas = Kelas::firstOrCreate(['kode_jenjang' => $kodeJenjang, 'tingkat' => $tingkat, 'nama' => $nama],
            ['peruntukan' => strtoupper($jenisKelamin), 'status' => 'aktif']);
        if (! $kelas->menerima($jenisKelamin)) {
            $kelas->update(['peruntukan' => 'C']);
        }

        return $kelas;
    }
}
