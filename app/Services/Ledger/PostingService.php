<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\UnitDefault;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Inti posting jurnal double-entry. Balance (Σdebet = Σkredit) divalidasi di
 * sini (bukan di skema), lalu entry + baris dipersist secara atomik.
 */
final class PostingService
{
    /**
     * Validasi aturan double-entry:
     *  - minimal 2 baris
     *  - tiap baris: debet & kredit >= 0, tidak keduanya > 0, salah satu > 0
     *  - total debet HARUS sama dengan total kredit (balance)
     * Melempar AppException(422) bila tidak valid. Fungsi murni (tanpa DB).
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array{totalDebet:string,totalKredit:string}
     */
    public static function validateBalanced(array $lines): array
    {
        if (count($lines) < 2) {
            throw new AppException(422, 'Jurnal harus memiliki minimal 2 baris.');
        }

        $totalDebet = '0';
        $totalKredit = '0';

        foreach ($lines as $i => $l) {
            $d = Money::of($l['debet'] ?? 0);
            $k = Money::of($l['kredit'] ?? 0);
            $n = $i + 1;

            if (Money::isNegative($d) || Money::isNegative($k)) {
                throw new AppException(422, "Baris {$n}: debet/kredit tidak boleh negatif.");
            }
            if (Money::gtZero($d) && Money::gtZero($k)) {
                throw new AppException(422, "Baris {$n}: satu baris tidak boleh memiliki debet dan kredit sekaligus.");
            }
            if (Money::isZero($d) && Money::isZero($k)) {
                throw new AppException(422, "Baris {$n}: setiap baris harus memiliki debet atau kredit > 0.");
            }

            $totalDebet = Money::add($totalDebet, $d);
            $totalKredit = Money::add($totalKredit, $k);
        }

        if (! Money::eq($totalDebet, $totalKredit)) {
            throw new AppException(
                422,
                "Jurnal tidak balance: total debet ({$totalDebet}) tidak sama dengan total kredit ({$totalKredit})."
            );
        }

        return ['totalDebet' => $totalDebet, 'totalKredit' => $totalKredit];
    }

    /**
     * Setiap baris akun Beban wajib membawa dimensi Bagian (penggerak realisasi
     * anggaran). Baris neraca & pendapatan tidak wajib; jurnal non-kas dikecualikan.
     *
     * @param  array<int,array<string,mixed>>  $lines
     */
    public static function validateBagian(array $lines, string $sumberModul): void
    {
        foreach ($lines as $i => $l) {
            if (! BagianPolicy::wajibBagian($sumberModul, $l['kode_coa'])) {
                continue;
            }
            if (empty($l['kode_bagian'])) {
                throw new AppException(422, 'Baris '.($i + 1).': Bagian wajib diisi untuk akun Beban.');
            }
        }
    }

    /**
     * Unit bisnis default untuk sebuah modul asal — dipakai HANYA bila pemanggil
     * tidak menentukan unit. null bila modul tidak dipetakan (aman/aditif).
     */
    protected static function resolveDefaultUnit(string $sumberModul): ?string
    {
        return UnitDefault::where('sumber_modul', $sumberModul)->value('kode_unit');
    }

    /**
     * Akun-akun NERACA yang unitnya dipaksa ke unit penampung: seluruh
     * liabilitas (awalan "2") dan setiap rekening kas/bank.
     *
     * Dihitung sekali per proses — daftar rekening kas jarang berubah, dan
     * postJournal dipanggil berkali-kali dalam satu permintaan.
     *
     * @return array{unit:?string, kas:array<string,true>}
     */
    /** @var array{unit:?string, kas:array<string,true>}|null */
    private static ?array $konteksNeraca = null;

    protected static function konteksNeraca(): array
    {
        return self::$konteksNeraca ??= [
            'unit' => CompanySettings::query()->value('kode_unit_neraca'),
            'kas' => array_fill_keys(BankAccount::pluck('kode_coa')->all(), true),
        ];
    }

    /**
     * Buang cache konteks. WAJIB dipanggil sesudah pengaturan unit penampung
     * atau daftar rekening kas berubah — termasuk di test, yang membuat
     * keduanya sesudah kelas ini mungkin sudah sempat membacanya.
     */
    public static function lupakanKonteksNeraca(): void
    {
        self::$konteksNeraca = null;
    }

    /** Baris ini termasuk neraca yang unitnya dipusatkan? */
    private static function barisNeraca(string $kodeCoa, array $ctx): bool
    {
        return str_starts_with($kodeCoa, '2') || isset($ctx['kas'][$kodeCoa]);
    }

    /**
     * Memvalidasi balance lalu mem-persist SATU journal entry + baris-barisnya
     * secara atomik. Unit diresolusi PER BARIS, tiga tingkat (spesifik menang):
     *   1. unit baris → dokumen multi-unit
     *   2. unit dokumen → modul satu-unit (semua baris mewarisi)
     *   3. default modul → pengisi kekosongan terakhir
     *
     * KECUALI baris NERACA. Baris berakun liabilitas atau rekening kas/bank
     * selalu memakai UNIT PENAMPUNG (company_settings.kode_unit_neraca),
     * mengabaikan ketiga tingkat di atas — unit adalah pusat laba, dan neraca
     * hanya bermakna di tingkat yayasan. Bila pengaturannya belum diisi,
     * perilakunya persis seperti dulu.
     *
     * @param  array<string,mixed>  $input
     */
    public static function postJournal(array $input): JournalEntry
    {
        self::validateBalanced($input['lines']);
        self::validateBagian($input['lines'], $input['sumber_modul']);
        PeriodService::assertPeriodPostable($input['tanggal']);

        $unitDokumen = $input['kode_unit'] ?? self::resolveDefaultUnit($input['sumber_modul']);
        $neraca = self::konteksNeraca();

        return DB::transaction(function () use ($input, $unitDokumen, $neraca) {
            $entry = JournalEntry::create([
                'referensi' => $input['referensi'],
                'tanggal' => $input['tanggal'],
                'keterangan' => $input['keterangan'] ?? null,
                'sumber_modul' => $input['sumber_modul'],
                'id_sumber' => $input['id_sumber'] ?? null,
                'id_pengguna' => $input['id_pengguna'] ?? null,
            ]);

            foreach ($input['lines'] as $l) {
                $entry->lines()->create([
                    'kode_coa' => $l['kode_coa'],
                    'nama_coa' => $l['nama_coa'] ?? null,
                    'debet' => Money::of($l['debet'] ?? 0),
                    'kredit' => Money::of($l['kredit'] ?? 0),
                    'keterangan' => $l['keterangan'] ?? null,
                    'kode_persediaan' => $l['kode_persediaan'] ?? null,
                    'kuantiti' => (array_key_exists('kuantiti', $l) && $l['kuantiti'] !== null)
                        ? Money::of($l['kuantiti'], 4)
                        : null,
                    'kode_bagian' => $l['kode_bagian'] ?? null,
                    'kode_unit' => ($neraca['unit'] !== null && self::barisNeraca($l['kode_coa'], $neraca))
                        ? $neraca['unit']
                        : ($l['kode_unit'] ?? $unitDokumen),
                ]);
            }

            // refresh() agar atribut default DB (status='aktif', created_at) termuat.
            $entry->refresh();

            return $entry->load('lines');
        });
    }

    /**
     * Mem-posting BEBERAPA entry secara atomik dalam satu transaksi DB.
     *
     * @param  array<int,array<string,mixed>>  $inputs
     * @return array<int,JournalEntry>
     */
    public static function postJournals(array $inputs): array
    {
        return DB::transaction(function () use ($inputs) {
            $results = [];
            foreach ($inputs as $input) {
                $results[] = self::postJournal($input);
            }

            return $results;
        });
    }
}
