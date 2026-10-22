<?php

namespace App\Services\Ppsb;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\DokumenSantri;
use App\Models\JadwalUjian;
use App\Models\KehadiranUjian;
use App\Models\Santri;
use Illuminate\Support\Facades\URL;

/**
 * UJIAN SELEKSI PSB — kartu peserta & absensi lewat QR (keputusan user 21 Okt 2026).
 *
 *  • Kartu tersedia sejak registrasi lunas (Registrasi Terbayar) sampai seleksi selesai.
 *  • Tanggal ujian: tanggal khusus peserta → jadwal jenjang+gelombang → gelombang → jenjang → T.A saja.
 *  • QR berisi tautan BERTANDA TANGAN ke halaman panitia; membukanya (login, hak absensi-ujian)
 *    mencatat hadir sekali per santri per T.A.
 */
class UjianSeleksi
{
    /** Tahap yang boleh mencetak kartu: sudah bayar registrasi, belum ada keputusan seleksi. */
    public const STATUS_KARTU = ['terbayar', 'terverifikasi', 'diseleksi'];

    public const PERHATIAN_BAWAAN = "Cetak kartu peserta dan kupon makan dengan kertas tebal warna putih\n"
        ."Gunting kartu dan kupon sesuai garis yang terlihat\n"
        ."Harap datang saat ujian dengan membawa:\n"
        ."# Kartu peserta ujian seleksi\n"
        ."# Tempat co-card (plastik bening) untuk kartu peserta beserta tali gantungannya\n"
        ."# Kupon makan\n"
        ."# Smartphone untuk mengikuti tes\n"
        ."# Mushaf Al Qur'an\n"
        .'# Alat tulis dan papan alas';

    public static function bolehKartu(Santri $s): bool
    {
        return in_array($s->status, self::STATUS_KARTU, true);
    }

    /**
     * @return array{tanggal:\Illuminate\Support\Carbon, jam:?string, tempat:?string, asal:string}|null
     */
    public static function jadwal(Santri $s): ?array
    {
        $jadwal = JadwalUjian::where('tahun_ajaran', $s->tahun_ajaran)
            ->where(fn ($q) => $q->whereNull('kode_jenjang')->orWhere('kode_jenjang', $s->kode_jenjang))
            ->where(fn ($q) => $q->whereNull('kode_gelombang')->orWhere('kode_gelombang', (string) $s->gelombang))
            ->get()
            // Paling spesifik dulu: jenjang+gelombang (3) → gelombang (2) → jenjang (1) → T.A saja (0).
            ->sortByDesc(fn ($j) => ($j->kode_gelombang ? 2 : 0) + ($j->kode_jenjang ? 1 : 0))
            ->first();

        if ($s->tanggal_ujian) {
            return ['tanggal' => $s->tanggal_ujian, 'jam' => $jadwal?->jam, 'tempat' => $jadwal?->tempat, 'asal' => 'peserta'];
        }

        return $jadwal ? ['tanggal' => $jadwal->tanggal, 'jam' => $jadwal->jam, 'tempat' => $jadwal->tempat, 'asal' => 'jadwal'] : null;
    }

    /** Tautan bertanda tangan yang dikodekan ke QR kartu. */
    public static function urlHadir(Santri $s): string
    {
        // Selalu beralamat ERP staf (APP_URL), bukan alamat permintaan saat ini: kartu yang dicetak wali
        // dari parents.al-wafi.sch.id dulu ber-QR ke domain portal → 404 saat dipindai panitia (audit 22 Okt 2026).
        // Tanda tangannya mencakup host, jadi harus dibuat dengan root yang sama dengan tempat ia dipindai.
        URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
        try {
            return URL::signedRoute('ujian.hadir', ['santri' => $s->id]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /** @return array{0:KehadiranUjian, 1:bool} kehadiran + apakah baru tercatat sekarang */
    public static function catatHadir(Santri $s, ?int $idPengguna): array
    {
        if (! self::bolehKartu($s)) {
            throw new AppException(422, "{$s->nama} tidak sedang dalam tahap ujian seleksi (status: ".Tahap::labelStatus((string) $s->status).').');
        }
        $k = KehadiranUjian::firstOrCreate(['id_santri' => $s->id, 'tahun_ajaran' => $s->tahun_ajaran],
            ['hadir_pada' => now(), 'dicatat_oleh' => $idPengguna]);

        return [$k, $k->wasRecentlyCreated];
    }

    /** Data lengkap untuk menggambar kartu (dipakai layar staf & portal wali). */
    public static function dataKartu(Santri $s): array
    {
        $s->loadMissing(['jenjang:kode,nama', 'jalurPendaftaran:kode,nama']);
        $c = CompanySettings::find(1);
        // Pas foto dari berkas "Foto" — disisipkan sebagai data URI supaya kartu (portal & staf) tak butuh
        // rute berkas tersendiri. PDF tak bisa dipakai sebagai foto.
        $berkas = DokumenSantri::where('id_santri', $s->id)->where('jenis', 'foto')->where('mime', 'like', 'image/%')->latest('id')->first();
        $foto = $berkas && \Illuminate\Support\Facades\Storage::disk('local')->exists($berkas->path)
            ? 'data:'.$berkas->mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($berkas->path))
            : null;

        return [
            'santri' => $s,
            'putri' => $s->jenis_kelamin === 'P',
            'lembaga' => $c?->nama_perusahaan ?: config('app.name'),
            'jalur' => $s->jalurPendaftaran?->nama ?? (string) $s->jalur,
            'jenjang' => $s->jenjang?->nama ?? (string) $s->kode_jenjang,
            'jadwal' => self::jadwal($s),
            'foto' => $foto,
            'qr' => self::urlHadir($s),
            'kupon' => max(1, (int) ($c?->kupon_makan_orang ?? 2)),
            'perhatian' => trim((string) ($c?->perhatian_kartu_ujian ?: self::PERHATIAN_BAWAAN)),
        ];
    }
}
