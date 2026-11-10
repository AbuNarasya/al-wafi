<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\MenuMitra;
use App\Models\Mitra;
use App\Models\PenjualanMitra;
use App\Services\Penghubung\KlienDompetToko;
use App\Services\Penghubung\PenghubungTakPasti;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KASIR MITRA KANTIN — layar HP mitra di instans Wafi Mart (keputusan user 9 Nov 2026).
 *
 * Pola pengamannya SAMA dengan kasir Wafi Mart lewat penghubung (Fase 3c-1):
 *  • Penjualan disimpan dulu berstatus `menunggu` — nomornya = nomor rujukan potongan di ERP Al Wafi.
 *  • Penghubung menolak (saldo, PIN, kartu, belanja kantin dimatikan wali) → `gagal`, tak ada yang terpotong.
 *  • Jawaban hilang → nomornya DIPASTIKAN ke ERP Al Wafi (jawaban di sana final); tetap tak terjangkau →
 *    `belum_pasti`: mitra menekan "Cek lagi" dan JANGAN menerima tunai dulu. Sisanya dicocokkan rekonsiliasi malam.
 *  • Kunci klien: kiriman ulang keranjang yang sama tak pernah memotong dua kali.
 *  • Batal hanya di hari yang sama, dengan alasan; nomor pengembaliannya tetap (`<nomor>-B`) sehingga aman diulang.
 */
class KasirMitraService
{
    public const PESAN_BELUM_PASTI = 'Status pembayaran kartu BELUM PASTI — JANGAN terima tunai dulu. Tekan "Cek lagi" sebentar lagi.';

    public const PESAN_TIDAK_TERJADI = 'Pembayaran kartu TIDAK terjadi (penghubung tak menjawab tepat waktu) — aman: ulangi bayar kartu, atau terima tunai.';

    public const PESAN_BATAL_BELUM_PASTI = 'Status pengembalian ke dompet santri BELUM PASTI (penghubung tak menjawab) — JANGAN beri uang tunai. Ulangi sebentar lagi: aman, tidak akan dikembalikan dua kali.';

    public const MAKS_JUMLAH = 99;

    public function __construct(private readonly KlienDompetToko $klien) {}

    /** Info kartu sebelum bayar (nama, perlu PIN) — batas KANTIN & pilihan wali diperiksa ERP Al Wafi. */
    public function cekKartu(Mitra $mitra, string $uid, string|int|float $total): array
    {
        $this->pastikanAktif($mitra);
        $uid = KartuSantriService::bersihkanUid($uid) ?? throw new AppException(422, 'Tempelkan kartu santri dulu.');

        return $this->klien->cekKartu($uid, Money::of($total), 'kantin');
    }

    /**
     * @param  list<array{id_menu:int|string, jumlah:int|string}>  $baris
     */
    public function bayar(Mitra $mitra, int $idKasir, array $baris, ?string $uid, ?string $pin, ?string $kunci = null): PenjualanMitra
    {
        $this->pastikanAktif($mitra);
        $kunci = ($kunci = trim((string) $kunci)) !== '' ? mb_substr($kunci, 0, 64) : null;
        if ($kunci && ($ada = PenjualanMitra::where('id_kasir', $idKasir)->where('kunci_klien', $kunci)->first())) {
            if ($ada->status !== 'gagal') {
                // Kiriman ulang: hasil yang sama; yang nasibnya belum jelas dipastikan dulu.
                return in_array($ada->status, ['menunggu', 'belum_pasti'], true) ? $this->pastikan($ada) : $ada->load('rincian');
            }
            // Percobaan sebelumnya ditolak (mis. minta PIN) — kunci dilepas, coba lagi dengan nomor baru.
            $ada->update(['kunci_klien' => null]);
        }
        $uid = KartuSantriService::bersihkanUid($uid) ?? throw new AppException(422, 'Tempelkan kartu santri dulu.');
        [$rincian, $total] = $this->rincian($mitra, $baris);

        $jual = DB::transaction(function () use ($mitra, $idKasir, $rincian, $total, $uid, $kunci) {
            $j = PenjualanMitra::create([
                'nomor' => 'KM-'.now()->format('ymd').'-'.strtoupper(Str::random(10)), 'id_mitra' => $mitra->id, 'id_kasir' => $idKasir,
                'waktu' => now(), 'total' => $total, 'uid_kartu' => $uid, 'status' => 'menunggu', 'kunci_klien' => $kunci,
            ]);
            foreach ($rincian as $r) {
                $j->rincian()->create($r);
            }

            return $j;
        });

        try {
            $h = $this->klien->potong($jual->nomor, $uid, $total, $pin, $mitra->kode, $mitra->nama, $this->rincianPortal($jual, $mitra), 'kantin');
        } catch (PenghubungTakPasti) {
            $jual = $this->pastikan($jual);
            if ($jual->status === 'gagal') {
                throw new AppException(503, self::PESAN_TIDAK_TERJADI);
            }

            return $jual;
        } catch (AppException $e) {
            // Penolakan pasti dari ERP Al Wafi: tak ada yang terpotong.
            $jual->update(['status' => 'gagal', 'pesan' => mb_substr($e->getMessage(), 0, 250)]);
            throw $e;
        }
        $jual->update(['status' => 'berhasil', 'nama_santri' => $h['nama'] ?? null, 'sisa_saldo' => $h['sisa_saldo'] ?? null]);

        return $jual->load('rincian');
    }

    /** Tombol "Cek lagi" untuk pembayaran yang belum pasti. */
    public function cekLagi(Mitra $mitra, int $id): PenjualanMitra
    {
        $j = PenjualanMitra::where('id_mitra', $mitra->id)->findOrFail($id);

        return in_array($j->status, ['menunggu', 'belum_pasti'], true) ? $this->pastikan($j) : $j->load('rincian');
    }

    /**
     * Tanyakan nasib nomornya ke ERP Al Wafi (beberapa kali, berjeda). Jawaban Al Wafi FINAL; tak terjangkau
     * sama sekali → `belum_pasti`. Dipakai juga oleh rekonsiliasi malam.
     */
    public function pastikan(PenjualanMitra $j): PenjualanMitra
    {
        if (! in_array($j->status, ['menunggu', 'belum_pasti'], true)) {
            return $j;
        }
        $jeda = (int) config('penghubung.jeda_pastikan_ms', 1000);
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0 && $jeda > 0) {
                usleep($jeda * 1000);
            }
            try {
                $h = $this->klien->pastikan($j->nomor, 'potong', Money::of($j->total));
            } catch (AppException) {
                continue;
            }
            // Ditulis BERSYARAT (audit 10 Nov 2026): rekonsiliasi malam bisa mengklaim baris yang sama selagi ERP
            // ditanya (jadi `gagal` + uang santri dikembalikan). Tulisan tanpa syarat dulu menimpanya jadi `berhasil`
            // → masuk saldo mitra padahal santri sudah menerima uangnya kembali.
            $this->tulisJikaMasihTerbuka($j, ($h['status'] ?? '') === 'berhasil'
                ? ['status' => 'berhasil', 'nama_santri' => $h['nama'] ?? null, 'sisa_saldo' => $h['sisa_saldo'] ?? null, 'pesan' => null]
                : ['status' => 'gagal', 'pesan' => 'Dipastikan tidak terjadi di ERP Al Wafi.']);

            return $j->load('rincian');
        }
        $this->tulisJikaMasihTerbuka($j, ['status' => 'belum_pasti', 'pesan' => self::PESAN_BELUM_PASTI]);

        return $j->load('rincian');
    }

    /** Ubah hanya bila statusnya masih menunggu/belum pasti; selalu dimuat ulang supaya layar melihat keadaan sebenarnya. */
    private function tulisJikaMasihTerbuka(PenjualanMitra $j, array $isi): void
    {
        PenjualanMitra::whereKey($j->id)->whereIn('status', ['menunggu', 'belum_pasti'])->update($isi + ['updated_at' => now()]);
        $j->refresh();
    }

    /** Batal (hari yang sama, dengan alasan): seluruh nilainya kembali ke dompet santri. */
    public function batal(Mitra $mitra, int $id, string $alasan, int $idPengguna): PenjualanMitra
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new AppException(422, 'Tulis alasan pembatalan.');
        }

        return DB::transaction(function () use ($mitra, $id, $alasan, $idPengguna) {
            $j = PenjualanMitra::where('id_mitra', $mitra->id)->lockForUpdate()->findOrFail($id);
            if ($j->status === 'batal') {
                return $j;
            }
            if ($j->status !== 'berhasil') {
                throw new AppException(422, 'Hanya pembayaran yang berhasil yang bisa dibatalkan.');
            }
            if (! $j->waktu->isSameDay(now())) {
                throw new AppException(422, 'Pembatalan hanya bisa di hari yang sama. Hubungi petugas Wafi Mart.');
            }
            $nomor = $j->nomor.'-B';
            try {
                // Keterangan dibatasi 255 karakter oleh API penghubung.
                $this->klien->kembalikan($nomor, $j->nomor, Money::of($j->total), mb_substr("Batal {$mitra->nama} {$j->nomor}: {$alasan}", 0, 250));
            } catch (PenghubungTakPasti) {
                throw new AppException(503, self::PESAN_BATAL_BELUM_PASTI);
            }
            $j->update(['status' => 'batal', 'ref_dompet_batal' => $nomor, 'alasan_batal' => mb_substr($alasan, 0, 255),
                'dibatalkan_pada' => now(), 'dibatalkan_oleh' => $idPengguna]);

            return $j;
        });
    }

    /** @return array{0:list<array<string,mixed>>, 1:string} */
    private function rincian(Mitra $mitra, array $baris): array
    {
        $jumlah = [];
        foreach ($baris as $b) {
            $id = (int) ($b['id_menu'] ?? 0);
            $n = (int) ($b['jumlah'] ?? 0);
            if ($id <= 0 || $n <= 0) {
                continue;
            }
            $jumlah[$id] = ($jumlah[$id] ?? 0) + $n;
        }
        if (! $jumlah) {
            throw new AppException(422, 'Pilih menu dulu.');
        }
        $menu = MenuMitra::where('id_mitra', $mitra->id)->where('aktif', true)->whereKey(array_keys($jumlah))->get()->keyBy('id');
        $rincian = [];
        $total = '0';
        foreach ($jumlah as $id => $n) {
            $m = $menu[$id] ?? throw new AppException(422, 'Ada menu yang tidak tersedia lagi — muat ulang halaman.');
            if ($n > self::MAKS_JUMLAH) {
                throw new AppException(422, "Jumlah {$m->nama} terlalu banyak.");
            }
            $subtotal = Money::of(bcmul(Money::of($m->harga), (string) $n, 2));
            $rincian[] = ['id_menu' => $m->id, 'nama' => $m->nama, 'harga' => Money::of($m->harga), 'jumlah' => $n, 'subtotal' => $subtotal];
            $total = Money::add($total, $subtotal);
        }

        return [$rincian, Money::of($total)];
    }

    /** Daftar menu untuk riwayat belanja di portal wali — bentuk [[RincianBelanja]]. */
    private function rincianPortal(PenjualanMitra $j, Mitra $mitra): array
    {
        return [
            'struk' => $j->nomor, 'toko' => $mitra->nama, 'waktu' => $j->waktu?->toIso8601String(), 'jenis' => 'belanja',
            'barang' => $j->rincian()->get()->map(fn ($r) => [
                'nama' => $r->nama, 'jumlah' => Money::of($r->jumlah, 4), 'satuan' => 'porsi',
                'harga' => Money::of($r->harga), 'diskon' => '0.00', 'subtotal' => Money::of($r->subtotal),
            ])->all(),
            'diskon_struk' => '0.00', 'total' => Money::of($j->total),
        ];
    }

    private function pastikanAktif(Mitra $mitra): void
    {
        if (! $mitra->aktif()) {
            throw new AppException(403, 'Mitra ini sedang nonaktif — hubungi petugas Wafi Mart.');
        }
    }
}
