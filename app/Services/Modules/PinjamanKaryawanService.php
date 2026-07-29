<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\Karyawan;
use App\Models\PembayaranPinjamanKaryawan;
use App\Models\PinjamanKaryawan;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PINJAMAN KARYAWAN — piutang kepada pegawai, dicicil lewat dua jalur.
 *
 * JURNALNYA:
 *   Pencairan       D Piutang Karyawan   / K Kas-Rekening
 *   Cicilan tunai   D Kas-Rekening       / K Piutang Karyawan
 *   Potong gaji     D Beban Gaji         / K Piutang Karyawan     (TANPA kas)
 *
 * Kenapa potong gaji begitu: gaji bruto 10jt dengan potongan 1jt seharusnya
 * menghasilkan D Beban Gaji 10jt / K Kas 9jt / K Piutang 1jt. Gajinya sendiri
 * dibayar NETTO 9jt lewat Kas Keluar (D Beban Gaji 9jt / K Kas 9jt); jurnal
 * potongan di sini melengkapi sisanya. Digabung, hasilnya persis benar.
 *
 * Modul ini menerbitkan jurnalnya SENDIRI dan tidak lewat Kas Masuk/Kas Keluar
 * — pola yang sama dengan PembayaranSantriService. Karena itu register Kas
 * Masuk memang tidak memuat cicilan karyawan, sama seperti ia tak memuat
 * pembayaran santri.
 *
 * Termin adalah KESEPAKATAN JADWAL: tidak berjurnal, tidak berstatus per baris.
 * Yang mengurangi hutang hanyalah pembayaran. Σ termin wajib = pokok.
 */
class PinjamanKaryawanService
{
    public const SUMBER = 'PinjamanKaryawan';

    public const CARA = ['tunai' => 'Tunai / transfer', 'potong_gaji' => 'Potong gaji'];

    /**
     * Buat pinjaman. `posting_pencairan` sengaja OPT-IN (pola BankLoanService):
     * saat memindahkan saldo lama, uangnya cair bertahun lalu dan tak boleh
     * dicatat keluar hari ini.
     *
     * @param  array{kode_karyawan:string,tanggal:string,pokok:string,kode_coa_piutang:string,kode_rekening?:?string,keterangan?:?string,posting_pencairan?:bool,termin?:array<int,array{nominal:string,jatuh_tempo:string,keterangan?:?string}>}  $input
     */
    public function create(array $input, ?int $idPengguna): PinjamanKaryawan
    {
        $karyawan = Karyawan::find($input['kode_karyawan']);
        if (! $karyawan) {
            throw new AppException(400, 'Karyawan tidak ditemukan.');
        }
        if ($karyawan->status !== 'aktif') {
            throw new AppException(422, "Karyawan \"{$karyawan->nama}\" berstatus nonaktif.");
        }

        $piutang = CoaDetail::find($input['kode_coa_piutang']);
        if (! $piutang) {
            throw new AppException(400, 'Akun Piutang Karyawan tidak ditemukan.');
        }

        $pokok = Money::of($input['pokok']);
        if (! Money::gtZero($pokok)) {
            throw new AppException(422, 'Pokok pinjaman harus lebih dari nol.');
        }

        $rek = null;
        if (! empty($input['posting_pencairan'])) {
            $rek = BankAccount::with('coa')->find($input['kode_rekening'] ?? '');
            if (! $rek) {
                throw new AppException(400, 'Kas/Rekening pencairan tidak ditemukan.');
            }
        }

        $termin = $input['termin'] ?? [];
        if ($termin !== []) {
            $this->periksaJumlahTermin($termin, $pokok);
        }

        return DB::transaction(function () use ($input, $idPengguna, $karyawan, $piutang, $pokok, $rek, $termin) {
            $base = DocNumber::docBase('PKJ', $input['tanggal']);
            $last = PinjamanKaryawan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            $pinjaman = PinjamanKaryawan::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'kode_karyawan' => $karyawan->kode,
                'tanggal' => $input['tanggal'],
                'pokok' => $pokok,
                'terbayar' => '0',
                'kode_coa_piutang' => $piutang->kode_coa,
                'kode_rekening' => $input['kode_rekening'] ?? null,
                'status' => 'aktif',
                'keterangan' => $input['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ]);

            foreach ($this->barisTermin($termin) as $t) {
                $pinjaman->termin()->create($t);
            }

            if ($rek) {
                $ket = "Pencairan pinjaman karyawan {$karyawan->nama} ({$pinjaman->nomor})";
                $entry = PostingService::postJournal([
                    'referensi' => $pinjaman->nomor, 'tanggal' => $input['tanggal'],
                    'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $pinjaman->id,
                    'id_pengguna' => $idPengguna, 'keterangan' => $ket,
                    'lines' => [
                        ['kode_coa' => $piutang->kode_coa, 'nama_coa' => $piutang->nama_coa, 'debet' => $pokok, 'kredit' => '0', 'keterangan' => $ket],
                        ['kode_coa' => $rek->kode_coa, 'nama_coa' => $rek->coa?->nama_coa, 'debet' => '0', 'kredit' => $pokok, 'keterangan' => $ket],
                    ],
                ]);
                $pinjaman->update(['journal_entry_id' => $entry->id]);
            }

            return $pinjaman->refresh();
        });
    }

    /** Susun ulang jadwal termin (mengganti yang lama). Σ termin wajib = pokok. */
    public function aturTermin(int $id, array $termin): PinjamanKaryawan
    {
        $pinjaman = $this->ambil($id);
        $this->periksaJumlahTermin($termin, Money::of($pinjaman->pokok));

        return DB::transaction(function () use ($pinjaman, $termin) {
            $pinjaman->termin()->delete();
            foreach ($this->barisTermin($termin) as $t) {
                $pinjaman->termin()->create($t);
            }

            return $pinjaman->load('termin');
        });
    }

    /**
     * Catat satu cicilan. Jalur `tunai` menerima uang; `potong_gaji` tidak —
     * ia hanya memindahkan beban gaji menjadi pelunasan piutang.
     *
     * @param  array{tanggal:string,nominal:string,cara:string,kode_rekening?:?string,kode_coa_lawan?:?string,keterangan?:?string}  $data
     */
    public function bayar(int $id, array $data, ?int $idPengguna): PembayaranPinjamanKaryawan
    {
        $pinjaman = $this->ambil($id);
        if ($pinjaman->status !== 'aktif') {
            throw new AppException(422, "Pinjaman {$pinjaman->nomor} berstatus \"{$pinjaman->status}\"; tak bisa dibayar.");
        }

        $cara = $data['cara'] ?? '';
        if (! array_key_exists($cara, self::CARA)) {
            throw new AppException(422, 'Cara bayar harus tunai atau potong_gaji.');
        }

        $nominal = Money::of($data['nominal']);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal pembayaran harus lebih dari nol.');
        }
        $sisa = Money::of($pinjaman->sisa);
        if (Money::gt($nominal, $sisa)) {
            throw new AppException(422, "Nominal melebihi sisa pinjaman ({$sisa}).");
        }

        // Sisi lawan berbeda per jalur — inilah satu-satunya perbedaan jurnalnya.
        $kodeBagian = null;
        if ($cara === 'tunai') {
            $rek = BankAccount::with('coa')->find($data['kode_rekening'] ?? '');
            if (! $rek) {
                throw new AppException(400, 'Kas/Rekening penerima tidak ditemukan.');
            }
            $lawan = ['kode' => $rek->kode_coa, 'nama' => $rek->coa?->nama_coa];
        } else {
            $akun = CoaDetail::find($data['kode_coa_lawan'] ?? '');
            if (! $akun) {
                throw new AppException(400, 'Akun beban gaji tidak ditemukan. Potong gaji mendebit akun itu, bukan kas.');
            }
            $lawan = ['kode' => $akun->kode_coa, 'nama' => $akun->nama_coa];

            // Baris ke akun Beban WAJIB berbagian (BagianPolicy). Bagian yang
            // benar adalah bagian karyawannya — di situlah beban gajinya memang
            // dipikul. Boleh ditimpa lewat payload bila karyawan pindah bagian.
            $kodeBagian = $data['kode_bagian'] ?? $pinjaman->karyawan?->kode_bagian;
            if (! $kodeBagian) {
                throw new AppException(422, 'Potong gaji mendebit akun beban, jadi bagiannya wajib diketahui. Isi Bagian pada data karyawan lebih dulu.');
            }
        }

        $piutang = CoaDetail::find($pinjaman->kode_coa_piutang);

        return DB::transaction(function () use ($pinjaman, $data, $cara, $nominal, $lawan, $kodeBagian, $piutang, $idPengguna) {
            $base = DocNumber::docBase('BPK', $data['tanggal']);
            $last = PembayaranPinjamanKaryawan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $ket = $data['keterangan'] ?? ("Cicilan pinjaman {$pinjaman->nomor} — ".self::CARA[$cara]);
            $entry = PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => $data['tanggal'],
                'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $pinjaman->id,
                'id_pengguna' => $idPengguna, 'keterangan' => $ket,
                'lines' => [
                    ['kode_coa' => $lawan['kode'], 'nama_coa' => $lawan['nama'], 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket, 'kode_bagian' => $kodeBagian],
                    ['kode_coa' => $piutang->kode_coa, 'nama_coa' => $piutang->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => $ket],
                ],
            ]);

            $bayar = PembayaranPinjamanKaryawan::create([
                'nomor' => $nomor, 'id_pinjaman' => $pinjaman->id, 'tanggal' => $data['tanggal'],
                'nominal' => $nominal, 'cara' => $cara,
                'kode_rekening' => $cara === 'tunai' ? $lawan['kode'] : null,
                'kode_coa_lawan' => $cara === 'potong_gaji' ? $lawan['kode'] : null,
                'keterangan' => $data['keterangan'] ?? null,
                'journal_entry_id' => $entry->id, 'id_pengguna' => $idPengguna,
            ]);

            $terbayar = Money::add($pinjaman->terbayar, $nominal);
            $pinjaman->update([
                'terbayar' => $terbayar,
                'status' => Money::gte($terbayar, $pinjaman->pokok) ? 'lunas' : 'aktif',
            ]);

            return $bayar;
        });
    }

    /** Daftar pinjaman + sisa, untuk halaman index. */
    public function list(?string $status = null, ?string $cari = null)
    {
        return PinjamanKaryawan::query()->with('karyawan')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w
                ->where('nomor', 'ilike', "%{$cari}%")
                ->orWhereHas('karyawan', fn ($k) => $k->where('nama', 'ilike', "%{$cari}%")->orWhere('kode', 'ilike', "%{$cari}%"))))
            ->orderByDesc('id');
    }

    public function ambil(int $id): PinjamanKaryawan
    {
        $rec = PinjamanKaryawan::with(['karyawan', 'termin' => fn ($q) => $q->orderBy('urutan'), 'pembayaran' => fn ($q) => $q->orderBy('tanggal')->orderBy('id')])->find($id);
        if (! $rec) {
            throw new AppException(404, 'Pinjaman tidak ditemukan.');
        }

        return $rec;
    }

    /** Σ termin harus sama persis dengan pokok — jadwal tak boleh menyimpang dari hutangnya. */
    private function periksaJumlahTermin(array $termin, string $pokok): void
    {
        if ($termin === []) {
            throw new AppException(422, 'Jadwal termin kosong.');
        }
        $total = '0';
        foreach ($termin as $i => $t) {
            $n = Money::of($t['nominal'] ?? '0');
            if (! Money::gtZero($n)) {
                throw new AppException(422, 'Nominal termin ke-'.($i + 1).' harus lebih dari nol.');
            }
            if (empty($t['jatuh_tempo'])) {
                throw new AppException(422, 'Jatuh tempo termin ke-'.($i + 1).' wajib diisi.');
            }
            $total = Money::add($total, $n);
        }
        if (! Money::eq($total, $pokok)) {
            throw new AppException(422, "Jumlah termin ({$total}) harus sama dengan pokok pinjaman ({$pokok}).");
        }
    }

    /** @return list<array<string,mixed>> */
    private function barisTermin(array $termin): array
    {
        $hasil = [];
        foreach (array_values($termin) as $i => $t) {
            $hasil[] = [
                'urutan' => $i + 1,
                'nominal' => Money::of($t['nominal']),
                'jatuh_tempo' => $t['jatuh_tempo'],
                'keterangan' => $t['keterangan'] ?? null,
            ];
        }

        return $hasil;
    }
}
