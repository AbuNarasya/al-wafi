<?php

namespace App\Services\Modules;

use App\Models\HakAksesModul;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\ReminderSetting;
use App\Models\TagihanSantri;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;

/**
 * Reminder tagihan mendekati jatuh tempo. Sumber: tagihan santri (SPP,
 * registrasi, uang pangkal tanpa rencana angsuran, tagihan lain), invoice
 * vendor, dan termin angsuran uang pangkal. Titik pengingat H-n dari
 * pengaturan; tiap titik terkirim SEKALI per item per penerima (dedup lewat
 * ref_id "{sumber}:{id}:h{n}" di tabel notifications).
 */
class ReminderTagihanService
{
    private const ID = 1;

    public const JENIS_NOTIF = 'reminder_tagihan';

    /** Modul yang penerimanya diikutkan bila penerima_akses_modul aktif, per sumber. */
    private const MODUL_SUMBER = [
        'tagihan_santri' => ['pembayaran-kesantrian', 'pembayaran-ppsb'],
        'angsuran_uang_pangkal' => ['angsuran-uang-pangkal'],
        'invoice_vendor' => ['invoices'],
    ];

    public function __construct(private AngsuranUangPangkalService $angsuran = new AngsuranUangPangkalService)
    {
    }

    /** Pengaturan singleton; baris dibuat saat pertama disimpan. */
    public function pengaturan(): ReminderSetting
    {
        return ReminderSetting::find(self::ID)
            ?? new ReminderSetting([
                'id' => self::ID,
                'aktif' => true,
                'hari_sebelum' => '7,3,1',
                'sumber_tagihan_santri' => true,
                'sumber_invoice_vendor' => true,
                'sumber_angsuran_uang_pangkal' => true,
                'penerima_admin' => true,
                'penerima_tim_keuangan' => true,
                'penerima_akses_modul' => false,
                'jam_kirim' => '07:00',
            ]);
    }

    public function simpan(array $data): ReminderSetting
    {
        $data['hari_sebelum'] = implode(',', self::parseHari($data['hari_sebelum'] ?? ''));

        return ReminderSetting::updateOrCreate(['id' => self::ID], $data);
    }

    /** @return list<int> titik pengingat terurut menurun (mis. [7,3,1]; 0 = hari-H). */
    public static function parseHari(string $csv): array
    {
        $hari = array_filter(
            array_map(fn ($x) => trim($x), explode(',', $csv)),
            fn ($x) => $x !== '' && ctype_digit($x) && (int) $x <= 90,
        );
        $hari = array_values(array_unique(array_map('intval', $hari)));
        rsort($hari);

        return $hari === [] ? [7, 3, 1] : $hari;
    }

    /**
     * Daftar tagihan belum lunas yang jatuh temponya sudah lewat atau berada
     * dalam jendela pengingat terjauh. hari_tersisa < 0 = terlambat.
     *
     * @return list<array{sumber:string,ref_jenis:string,id:string,label:string,pihak:?string,jatuh_tempo:SupportCarbon,sisa:string,hari_tersisa:int}>
     */
    public function daftarMendekati(?ReminderSetting $s = null): array
    {
        $s ??= $this->pengaturan();
        $hari = self::parseHari($s->hari_sebelum);
        $maxHari = $hari[0];
        $kini = Carbon::now()->startOfDay();
        $batas = $kini->copy()->addDays($maxHari);
        $hasil = [];

        if ($s->sumber_tagihan_santri) {
            $rows = TagihanSantri::query()
                ->whereIn('status', ['belum_bayar', 'sebagian'])
                ->where('sisa', '>', 0)
                ->whereNotNull('jatuh_tempo')
                ->whereDate('jatuh_tempo', '<=', $batas)
                ->whereDoesntHave('rencanaAngsuran', fn ($q) => $q->where('status', 'aktif'))
                ->with(['santri', 'jenis'])
                ->orderBy('jatuh_tempo')
                ->get();
            foreach ($rows as $t) {
                $label = trim(($t->jenis?->nama ?? $t->kode_jenis).($t->periode ? " {$t->periode}" : ''));
                $hasil[] = [
                    'sumber' => 'tagihan_santri',
                    // Tipe dipakai penanda tugas untuk menunjuk modul yang benar:
                    // registrasi & uang pangkal ditangani PPSB, spp & lain-lain
                    // ditangani Kesantrian.
                    'tipe' => \App\Models\TipeBiaya::perilakuDari($t->jenis?->tipe) ?? 'lain',
                    'ref_jenis' => 'TagihanSantri',
                    'id' => (string) $t->id,
                    'label' => "{$label} — ".($t->santri?->nama ?? "santri #{$t->id_santri}"),
                    'pihak' => $t->santri?->nama,
                    'jatuh_tempo' => $t->jatuh_tempo,
                    'sisa' => (string) $t->sisa,
                    'hari_tersisa' => (int) $kini->diffInDays(Carbon::parse($t->jatuh_tempo)->startOfDay(), false),
                ];
            }
        }

        if ($s->sumber_invoice_vendor) {
            $rows = Invoice::query()
                ->whereIn('status', ['belum_bayar', 'sebagian'])
                ->where('sisa_hutang', '>', 0)
                ->whereNotNull('tanggal_jatuh_tempo')
                ->whereDate('tanggal_jatuh_tempo', '<=', $batas)
                ->with('vendor')
                ->orderBy('tanggal_jatuh_tempo')
                ->get();
            foreach ($rows as $inv) {
                $hasil[] = [
                    'sumber' => 'invoice_vendor',
                    'ref_jenis' => 'Invoice',
                    'id' => (string) $inv->id_invoice,
                    'label' => "Invoice {$inv->nomor_invoice} — ".($inv->vendor?->nama_vendor ?? $inv->kode_vendor),
                    'pihak' => $inv->vendor?->nama_vendor,
                    'jatuh_tempo' => $inv->tanggal_jatuh_tempo,
                    'sisa' => (string) $inv->sisa_hutang,
                    'hari_tersisa' => (int) $kini->diffInDays(Carbon::parse($inv->tanggal_jatuh_tempo)->startOfDay(), false),
                ];
            }
        }

        if ($s->sumber_angsuran_uang_pangkal) {
            foreach ($this->angsuran->jatuhTempo($maxHari) as $t) {
                $hasil[] = [
                    'sumber' => 'angsuran_uang_pangkal',
                    'ref_jenis' => 'TerminUangPangkal',
                    'id' => (string) $t['id_termin'],
                    'label' => "Angsuran uang pangkal termin {$t['urutan']} — {$t['nama']}",
                    'pihak' => $t['nama'],
                    'jatuh_tempo' => SupportCarbon::parse($t['jatuh_tempo']),
                    'sisa' => (string) $t['sisa_termin'],
                    'hari_tersisa' => -1 * (int) $t['hari_lewat'],
                ];
            }
        }

        usort($hasil, fn ($a, $b) => $a['hari_tersisa'] <=> $b['hari_tersisa']);

        return $hasil;
    }

    /**
     * Penerima notifikasi per sumber (admin / tim keuangan / pemegang akses
     * "lihat" modul terkait), hanya pengguna aktif.
     *
     * @return array<string,list<int>> sumber => daftar id_pengguna
     */
    public function penerima(?ReminderSetting $s = null): array
    {
        $s ??= $this->pengaturan();

        $dasar = [];
        if ($s->penerima_admin) {
            $dasar = User::where('is_admin', true)->where('status', 'aktif')->pluck('id_pengguna')->all();
        }
        if ($s->penerima_tim_keuangan) {
            $dasar = array_merge($dasar, User::where('tim_keuangan', true)->where('status', 'aktif')->pluck('id_pengguna')->all());
        }

        $hasil = [];
        foreach (array_keys(self::MODUL_SUMBER) as $sumber) {
            $ids = $dasar;
            if ($s->penerima_akses_modul) {
                $ids = array_merge($ids, HakAksesModul::query()
                    ->whereIn('kode_modul', self::MODUL_SUMBER[$sumber])
                    ->where('lihat', true)
                    ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))
                    ->pluck('id_pengguna')
                    ->all());
            }
            $hasil[$sumber] = array_values(array_unique($ids));
        }

        return $hasil;
    }

    /**
     * Kirim reminder untuk semua item yang sudah melewati salah satu titik
     * pengingat. Aman dipanggil berulang (harian / tombol manual): titik yang
     * sudah terkirim tidak dikirim ulang.
     *
     * @return array{terkirim:int,kandidat:int}
     */
    public function kirim(): array
    {
        $s = $this->pengaturan();
        if (! $s->aktif) {
            return ['terkirim' => 0, 'kandidat' => 0];
        }

        $hari = self::parseHari($s->hari_sebelum);
        $penerima = $this->penerima($s);
        $items = [];

        foreach ($this->daftarMendekati($s) as $item) {
            // Titik pengingat terdekat yang sudah tercapai (H-n terkecil).
            $cocok = array_values(array_filter($hari, fn ($h) => $item['hari_tersisa'] <= $h));
            if ($cocok === [] || ($penerima[$item['sumber']] ?? []) === []) {
                continue;
            }
            $titik = min($cocok);
            $item['ref_id'] = "{$item['sumber']}:{$item['id']}:h{$titik}";

            $status = match (true) {
                $item['hari_tersisa'] < 0 => 'TERLAMBAT '.abs($item['hari_tersisa']).' hari',
                $item['hari_tersisa'] === 0 => 'jatuh tempo HARI INI',
                default => "H-{$item['hari_tersisa']}",
            };
            $item['judul'] = 'Reminder tagihan: '.($item['hari_tersisa'] < 0 ? 'lewat jatuh tempo' : $status);
            $item['pesan'] = "{$item['label']} ({$status}, jatuh tempo "
                .$item['jatuh_tempo']->format('d/m/Y').'). Sisa Rp '
                .number_format((float) $item['sisa'], 0, ',', '.').'.';
            $items[] = $item;
        }

        if ($items === []) {
            return ['terkirim' => 0, 'kandidat' => 0];
        }

        // Dedup terhadap DB: pasangan (id_pengguna, ref_id) yang sudah pernah dikirim.
        $sudah = Notification::where('jenis', self::JENIS_NOTIF)
            ->whereIn('ref_id', array_column($items, 'ref_id'))
            ->get(['id_pengguna', 'ref_id'])
            ->map(fn ($n) => "{$n->id_pengguna}|{$n->ref_id}")
            ->flip();

        $kirim = [];
        foreach ($items as $item) {
            foreach ($penerima[$item['sumber']] as $idPengguna) {
                if (isset($sudah["{$idPengguna}|{$item['ref_id']}"])) {
                    continue;
                }
                $kirim[] = [
                    'id_pengguna' => $idPengguna,
                    'judul' => $item['judul'],
                    'pesan' => $item['pesan'],
                    'jenis' => self::JENIS_NOTIF,
                    'ref_jenis' => $item['ref_jenis'],
                    'ref_id' => $item['ref_id'],
                ];
            }
        }

        $hasil = (new NotificationService)->kirim($kirim);

        return ['terkirim' => $hasil['terkirim'], 'kandidat' => count($items)];
    }
}
