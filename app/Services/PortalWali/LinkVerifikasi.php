<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\VerifikasiWali;
use App\Models\Wali;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LINK VERIFIKASI DATA WALI (keputusan user 14 Okt 2026).
 *
 * Staf membuat link unik per keluarga dan mengirimnya lewat WhatsApp ke nomor
 * yang tercatat; wali membuka link → memeriksa & membetulkan data keluarga dan
 * anak (layar Data Wali & Santri) → membuktikan email dengan kode → selesai, dan
 * email itu menjadi akun Portal Wali. EMAIL WAJIB (keputusan user).
 *
 * Link = kunci masuk TERBATAS ("mode verifikasi", lihat middleware PortalWali):
 * hanya data keluarga yang terbuka; dompet, pembayaran & transaksi tetap tertutup
 * sampai email terbukti. Berlaku 30 hari sejak tanggal MULAI pilihan staf.
 *
 * Jalur cadangan untuk yang kehilangan link: NIS anak + tanggal lahir anak +
 * 4 angka akhir HP tercatat — hanya bila keluarga itu SUDAH punya link yang
 * sedang berlaku (staf tetap memegang kendali masa verifikasinya).
 */
class LinkVerifikasi
{
    public const HARI = 30;

    /** Saklar `VERIFIKASI_WALI_AKTIF` — mati = link, jalur cadangan & tombol staf tak berfungsi. */
    public static function aktif(): bool
    {
        return (bool) config('app.verifikasi_wali_aktif');
    }

    /**
     * Akhiri kampanye: semua link yang belum selesai dicabut sekaligus — link yang
     * sudah tersebar di WhatsApp mati saat itu juga, tak menunggu kedaluwarsa.
     * Link yang SELESAI dibiarkan: ia catatan siapa memverifikasi & kapan.
     *
     * @return int jumlah link yang dicabut
     */
    public function akhiriSemua(): int
    {
        return VerifikasiWali::whereNull('dicabut_pada')->whereNull('selesai_pada')->update(['dicabut_pada' => now()]);
    }

    public static function sidik(string $kode): string
    {
        return hash('sha256', $kode);
    }

    /**
     * Keluarga yang PERLU diverifikasi: punya anak berstatus belum final (aktif atau
     * masih dalam proses PSB) — sama dengan hitungan Kesiapan Portal.
     *
     * @return Collection<int,int>
     */
    public static function keluargaPerlu(): Collection
    {
        return Santri::whereNotNull('id_wali')->whereNotIn('status', Tahap::STATUS_FINAL)
            ->distinct()->pluck('id_wali')->map(fn ($id) => (int) $id);
    }

    /** Buat link baru untuk satu keluarga; link lama (bila ada) dicabut. */
    public function buat(int $idWali, Carbon $mulai, ?int $idPengguna): VerifikasiWali
    {
        $kode = Str::random(32);
        $mulai = $mulai->copy()->startOfDay();

        return DB::transaction(function () use ($idWali, $kode, $mulai, $idPengguna) {
            VerifikasiWali::where('id_wali', $idWali)->whereNull('dicabut_pada')->update(['dicabut_pada' => now()]);

            return VerifikasiWali::create([
                'id_wali' => $idWali, 'token_hash' => self::sidik($kode), 'token_enkripsi' => Crypt::encryptString($kode),
                'berlaku_mulai' => $mulai, 'berlaku_sampai' => $mulai->copy()->addDays(self::HARI),
                'dibuat_oleh' => $idPengguna,
            ]);
        });
    }

    /**
     * Link untuk SEMUA keluarga yang perlu dan belum punya link aktif (yang belum
     * selesai & belum kedaluwarsa). Yang sudah punya tidak disentuh — link yang
     * sudah terkirim tak boleh mati diam-diam karena staf menekan tombol massal.
     *
     * @return int jumlah link yang dibuat
     */
    public function buatMassal(Carbon $mulai, ?int $idPengguna): int
    {
        $sudah = VerifikasiWali::whereNull('dicabut_pada')
            ->where(fn ($q) => $q->whereNotNull('selesai_pada')->orWhere('berlaku_sampai', '>', now()))
            ->pluck('id_wali')->map(fn ($id) => (int) $id);
        $sasaran = self::keluargaPerlu()->diff($sudah)
            ->intersect(Wali::where('status', 'aktif')->pluck('id')->map(fn ($id) => (int) $id));

        foreach ($sasaran as $idWali) {
            $this->buat($idWali, $mulai, $idPengguna);
        }

        return $sasaran->count();
    }

    public function cabut(VerifikasiWali $v): void
    {
        $v->update(['dicabut_pada' => now()]);
    }

    /** Link dari kode di alamat — null bila tak dikenal atau sudah dicabut. */
    public function kenali(string $kode): ?VerifikasiWali
    {
        if (strlen($kode) !== 32) {
            return null;
        }

        return VerifikasiWali::with('wali')->where('token_hash', self::sidik($kode))->whereNull('dicabut_pada')->first();
    }

    public static function kode(VerifikasiWali $v): string
    {
        return Crypt::decryptString($v->token_enkripsi);
    }

    public static function url(VerifikasiWali $v): string
    {
        return route('portal.verifikasi.buka', self::kode($v));
    }

    /** Nomor kontak utama dalam format WhatsApp (62…), atau null. */
    public static function nomorWa(Wali $wali): ?string
    {
        $telepon = $wali->{"telepon_{$wali->kontak_utama}"} ?: $wali->telepon;
        $angka = PendaftaranPortal::normalTelepon((string) $telepon);
        if (strlen($angka) < 9) {
            return null;
        }

        return str_starts_with($angka, '0') ? '62'.substr($angka, 1) : $angka;
    }

    public static function pesanWa(VerifikasiWali $v): string
    {
        $wali = $v->wali;
        $nama = $wali->{"nama_{$wali->kontak_utama}"} ?: $wali->nama;
        $anak = $wali->santri()->whereNotIn('status', Tahap::STATUS_FINAL)->orderBy('nama')->pluck('nama')->implode(', ');

        return "Assalamu'alaikum Bapak/Ibu {$nama}.\n\n"
            ."Mohon periksa dan perbarui data keluarga serta data santri ({$anak}) di Portal Wali AL Wafi "
            ."melalui tautan berikut, berlaku sampai {$v->berlaku_sampai->copy()->subDay()->locale('id')->translatedFormat('j F Y')}:\n\n"
            .self::url($v)."\n\n"
            ."Tautan ini khusus untuk keluarga Bapak/Ibu — mohon tidak dibagikan. Jazakumullah khairan.";
    }

    public function tandaiDikirim(VerifikasiWali $v): void
    {
        $v->update(['dikirim_pada' => $v->dikirim_pada ?? now()]);
    }

    public function tandaiDibuka(VerifikasiWali $v): void
    {
        $v->update(['dibuka_pertama' => $v->dibuka_pertama ?? now(), 'dibuka_terakhir' => now()]);
    }

    /**
     * Selesai: dipanggil SESUDAH email peran ini terbukti dengan kode.
     *
     * @throws AppException
     */
    public function selesai(VerifikasiWali $v, string $peran, string $email): void
    {
        if (! $v->bisaDipakai()) {
            throw new AppException(422, 'Link verifikasi ini sudah tidak berlaku.');
        }
        $v->update(['selesai_pada' => now(), 'selesai_peran' => $peran, 'selesai_email' => MasukWali::normalEmail($email)]);
    }

    /**
     * Jalur cadangan. Pesan galat SELALU sama, apa pun isian yang salah — tak ada
     * yang bisa dipakai menebak NIS mana yang terdaftar.
     *
     * @throws AppException
     */
    public function cariCadangan(string $nis, string $tanggalLahir, string $empatAngka): VerifikasiWali
    {
        $tolak = new AppException(422, 'Data tidak cocok, atau keluarga ini belum dikirimi link verifikasi. '
            .'Periksa lagi isiannya, atau hubungi bagian kesantrian.');

        $empatAngka = preg_replace('/\D/', '', $empatAngka);
        $santri = Santri::where('nis', trim($nis))->whereDate('tanggal_lahir', $tanggalLahir)->first();
        if (! $santri || strlen($empatAngka) !== 4 || ! $santri->id_wali) {
            throw $tolak;
        }
        $wali = Wali::find($santri->id_wali);
        $cocok = collect(['telepon', 'telepon_ayah', 'telepon_ibu', 'telepon_wali'])
            ->contains(fn ($kol) => str_ends_with(PendaftaranPortal::normalTelepon((string) $wali?->{$kol}), $empatAngka)
                && strlen(PendaftaranPortal::normalTelepon((string) $wali?->{$kol})) >= 8);
        $v = $cocok ? VerifikasiWali::where('id_wali', $wali->id)->whereNull('dicabut_pada')->first() : null;
        if (! $v || ! $v->bisaDipakai()) {
            throw $tolak;
        }

        return $v;
    }
}
