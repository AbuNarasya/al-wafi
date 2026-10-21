<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\NotifikasiWali;
use App\Models\PreferensiNotifikasiWali;
use App\Models\PushLanggananWali;
use App\Models\PushLangganan;
use App\Services\Modules\PushService;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;

/**
 * PUSH NOTIFICATION UNTUK WALI (keputusan user 21 Okt 2026) — mendorong kabar portal (`notifikasi_wali`) ke perangkat
 * orang yang menerimanya, hanya untuk KATEGORI yang tidak ia matikan (NotifikasiWali::KATEGORI).
 *
 * Mesin & penjaganya sama dengan push staf ([[PushService]]): lewat cron `push:kirim` tiap menit; kabar lebih tua
 * dari `push.umur_maksimal_menit` dikeluarkan dari antrean tanpa dikirim; ditandai sudah didorong SEBELUM hasil
 * dibaca (tak pernah dobel); langganan yang ditolak layanan push dibuang. Tanpa kunci VAPID, semuanya diam.
 */
class PushWaliService
{
    public function __construct(private readonly PushService $push) {}

    // ---- Langganan & pilihan kategori (milik orang yang sedang masuk) ----

    public function simpanLangganan(int $idWali, string $peran, array $data, ?string $userAgent = null): PushLanggananWali
    {
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new AppException(422, 'Data langganan dari peramban tidak lengkap.');
        }
        if (mb_strlen($endpoint) > 500) {
            throw new AppException(422, 'Alamat langganan terlalu panjang untuk disimpan.');
        }

        // Dikunci pada endpoint: perangkat yang sama mendaftar ulang (atau berpindah tangan) MEMPERBARUI barisnya.
        return PushLanggananWali::updateOrCreate(['endpoint' => $endpoint], [
            'id_wali' => $idWali, 'peran' => $peran, 'p256dh' => $p256dh, 'auth' => $auth,
            'perangkat' => $this->push->ringkasPerangkat($userAgent), 'gagal_beruntun' => 0,
        ]);
    }

    public function hapusLangganan(int $idWali, string $peran, string $endpoint): int
    {
        return PushLanggananWali::where('id_wali', $idWali)->where('peran', $peran)->where('endpoint', $endpoint)->delete();
    }

    /** @return list<string> kategori yang NYALA untuk orang ini. */
    public function kategoriNyala(int $idWali, string $peran): array
    {
        $mati = PreferensiNotifikasiWali::where('id_wali', $idWali)->where('peran', $peran)->pluck('kategori')->all();

        return array_values(array_diff(array_keys(NotifikasiWali::KATEGORI), $mati));
    }

    /** @param  list<string>  $nyala */
    public function simpanKategori(int $idWali, string $peran, array $nyala): void
    {
        $nyala = array_intersect($nyala, array_keys(NotifikasiWali::KATEGORI));
        PreferensiNotifikasiWali::where('id_wali', $idWali)->where('peran', $peran)->delete();
        foreach (array_diff(array_keys(NotifikasiWali::KATEGORI), $nyala) as $k) {
            PreferensiNotifikasiWali::create(['id_wali' => $idWali, 'peran' => $peran, 'kategori' => $k]);
        }
    }

    // ---- Pengiriman ----

    /** @return array{disapu:int, terkirim:int, gagal:int, langganan_dibuang:int} */
    public function kirimYangBelum(): array
    {
        $ringkas = ['disapu' => 0, 'terkirim' => 0, 'gagal' => 0, 'langganan_dibuang' => 0];
        $antre = NotifikasiWali::whereNull('didorong_pada')->orderBy('id')->limit((int) config('push.batas_sapuan', 200))->get();
        if ($antre->isEmpty()) {
            return $ringkas;
        }
        $ringkas['disapu'] = $antre->count();
        $tandai = fn ($daftar) => NotifikasiWali::whereIn('id', $daftar->pluck('id'))->update(['didorong_pada' => now()]);

        $batas = now()->subMinutes((int) config('push.umur_maksimal_menit', 120));
        [$segar, $basi] = $antre->partition(fn ($n) => $n->created_at && $n->created_at->gte($batas));
        if ($basi->isNotEmpty()) {
            $tandai($basi);
        }
        if ($segar->isEmpty() || ! $this->push->aktif()) {
            if ($segar->isNotEmpty()) {
                $tandai($segar);
            }

            return $ringkas;
        }

        $antreanKirim = [];
        $perEndpoint = [];
        foreach ($this->sasaran($segar) as [$l, $n]) {
            $perEndpoint[$l->endpoint] = $l;
            $antreanKirim[] = [$l, json_encode($this->muatan($n), JSON_UNESCAPED_UNICODE)];
        }
        $tandai($segar);
        if ($antreanKirim === []) {
            return $ringkas;
        }

        $webPush = $this->push->webPush();
        foreach ($antreanKirim as [$l, $muatan]) {
            $webPush->queueNotification(new Subscription($l->endpoint, $l->p256dh, $l->auth), $muatan, ['TTL' => (int) config('push.ttl', 43200)]);
        }
        foreach ($webPush->flush() as $laporan) {
            $l = $perEndpoint[$laporan->getEndpoint()] ?? null;
            if ($laporan->isSuccess()) {
                $ringkas['terkirim']++;
                $l?->update(['gagal_beruntun' => 0, 'terakhir_berhasil' => now()]);

                continue;
            }
            $ringkas['gagal']++;
            if ($laporan->isSubscriptionExpired()) {
                $l?->delete();
                $ringkas['langganan_dibuang']++;

                continue;
            }
            if ($l) {
                $l->increment('gagal_beruntun');
                if ($l->gagal_beruntun >= PushLangganan::BATAS_GAGAL) {
                    $l->delete();
                    $ringkas['langganan_dibuang']++;
                }
            }
            Log::warning('Push wali gagal', ['endpoint' => substr($laporan->getEndpoint(), 0, 60).'…', 'alasan' => $laporan->getReason()]);
        }

        return $ringkas;
    }

    /**
     * Pasangan (perangkat, kabar) yang akan didorong: perangkat milik orang PENERIMA kabar itu (keluarga + peran),
     * kecuali kategorinya ia matikan — kabarnya tetap ada di menu Kabar portal, hanya tak berbunyi.
     *
     * @param  \Illuminate\Support\Collection<int,NotifikasiWali>  $kabar
     * @return list<array{0:PushLanggananWali, 1:NotifikasiWali}>
     */
    public function sasaran($kabar): array
    {
        $kunci = fn ($idWali, $peran) => $idWali.'|'.$peran;
        $idWali = $kabar->pluck('id_wali')->unique();
        $mati = PreferensiNotifikasiWali::whereIn('id_wali', $idWali)->get()
            ->mapWithKeys(fn ($p) => [$kunci($p->id_wali, $p->peran).'|'.$p->kategori => true]);
        $langganan = PushLanggananWali::whereIn('id_wali', $idWali)->get()->groupBy(fn ($l) => $kunci($l->id_wali, $l->peran));

        $hasil = [];
        foreach ($kabar as $n) {
            if (isset($mati[$kunci($n->id_wali, $n->peran).'|'.$n->kategori])) {
                continue;
            }
            foreach ($langganan->get($kunci($n->id_wali, $n->peran), collect()) as $l) {
                $hasil[] = [$l, $n];
            }
        }

        return $hasil;
    }

    /** Alamat RELATIF: dibuka pekerja layanan di asal (domain) tempat wali berlangganan. */
    private function muatan(NotifikasiWali $n): array
    {
        return [
            'judul' => (string) $n->judul,
            'pesan' => mb_strimwidth((string) $n->pesan, 0, 180, '…'),
            'tautan' => route('portal.kabar', [], false),
            'tag' => 'wali:'.$n->jenis.':'.($n->ref_id ?? $n->id),
        ];
    }
}
