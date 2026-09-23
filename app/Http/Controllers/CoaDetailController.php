<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoaDetailRequest;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * CRUD Chart of Account (akun detail / level 4). jenis_saldo menentukan sisi
 * normal akun. Dirujuk journal_lines, opening_balances, bank_accounts.
 */
class CoaDetailController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $akun = CoaDetail::query()
            ->with('grup')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_coa', 'ilike', "%{$q}%")->orWhere('nama_coa', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_coa')
            ->get();

        return view('coa-detail.index', compact('akun', 'q'));
    }

    public function create(): View
    {
        return view('coa-detail.form', ['akun' => new CoaDetail(['status' => 'aktif']), 'grupOptions' => $this->grupOptions()]);
    }

    public function store(CoaDetailRequest $request): RedirectResponse
    {
        CoaDetail::create($request->tersimpan());

        return redirect()->route('coa_detail.index')->with('status', 'Akun berhasil ditambahkan.');
    }

    public function edit(CoaDetail $coa_detail): View
    {
        return view('coa-detail.form', ['akun' => $coa_detail, 'grupOptions' => $this->grupOptions()]);
    }

    public function update(CoaDetailRequest $request, CoaDetail $coa_detail): RedirectResponse
    {
        $coa_detail->update($request->tersimpan());

        return redirect()->route('coa_detail.index')->with('status', 'Akun berhasil diperbarui.');
    }

    /**
     * Nama terbaca untuk tabel perujuk. Yang tak disebut di sini jatuh ke nama
     * tabelnya sendiri — cukup untuk admin, dan jauh lebih baik daripada pesan
     * "tidak bisa dihapus" tanpa keterangan apa pun.
     */
    private const LABEL_PERUJUK = [
        'journal_lines' => 'baris jurnal',
        'opening_balances' => 'saldo awal',
        'bank_accounts' => 'rekening kas',
        'dana_akun' => 'akun dana',
        'akun_pengurang_dana_bebas' => 'akun pengurang dana bebas',
        'jenis_biaya' => 'jenis biaya',
        'budgets' => 'anggaran',
        'budget_pengajuan_detail' => 'rincian pengajuan anggaran',
        'assets' => 'aset tetap',
        'pelepasan_aset' => 'pelepasan aset',
        'inventory' => 'barang persediaan',
        'invoices' => 'tagihan pembelian',
        'invoice_details' => 'rincian tagihan pembelian',
        'cash_in_details' => 'rincian kas masuk',
        'cash_out_details' => 'rincian kas keluar',
        'purchase_order_details' => 'rincian purchase order',
        'pengajuan_pembayaran' => 'pengajuan pembayaran',
        'pengajuan_pembayaran_detail' => 'rincian pengajuan pembayaran',
        'operational_advances' => 'uang muka operasional',
        'advance_settlements' => 'penyelesaian uang muka',
        'bank_loans' => 'pinjaman bank',
        'pinjaman_karyawan' => 'pinjaman karyawan',
        'pembayaran_pinjaman_karyawan' => 'pembayaran pinjaman karyawan',
        'customers' => 'customer',
        'accrues' => 'akrual',
        'bank_reconciliations' => 'rekonsiliasi bank',
        'approval_instances' => 'dokumen persetujuan',
    ];

    /**
     * Siapa saja yang masih memakai sebuah akun — SELURUH tabel, bukan hanya
     * yang berkunci asing.
     *
     * Hanya LIMA tabel yang punya kunci asing ke `coa_detail`; sekitar tiga
     * puluh tabel lain menyimpan `kode_coa` tanpa penjaga apa pun. Menyandarkan
     * penghapusan pada galat kunci asing karena itu meloloskan yang paling
     * berbahaya: menghapus akun yang dipakai `jenis_biaya` BERHASIL tanpa
     * peringatan, dan penagihan berikutnya patah dengan galat yang tak
     * menyebut sebabnya sama sekali.
     *
     * Daftar kolomnya dibaca dari information_schema, bukan ditulis tangan:
     * aplikasi ini masih bertambah tabelnya, dan daftar tangan pasti tertinggal.
     * Nama tabel & kolom berasal dari katalog basis data (tepercaya); kode
     * akunnya tetap diikat sebagai parameter.
     *
     * @return array<string,int> label perujuk => jumlah baris
     */
    private function pemakaiAkun(string $kodeCoa): array
    {
        $kolom = DB::select("select table_name, column_name from information_schema.columns
            where table_schema = 'public' and column_name like 'kode_coa%'
              and table_name <> 'coa_detail' order by table_name, column_name");

        if ($kolom === []) {
            return [];
        }

        // Satu kueri untuk seluruh tabel; tiga puluh kueri berurutan ke Neon
        // Singapura akan membuat satu klik Hapus berbiaya lebih dari sedetik.
        $bagian = [];
        $ikat = [];
        foreach ($kolom as $k) {
            $bagian[] = sprintf('select %s as tabel, count(*) as jml from "%s" where "%s" = ?',
                DB::getPdo()->quote($k->table_name), $k->table_name, $k->column_name);
            $ikat[] = $kodeCoa;
        }

        $pakai = [];
        foreach (DB::select(implode(' union all ', $bagian), $ikat) as $r) {
            if ((int) $r->jml === 0) {
                continue;
            }
            $label = self::LABEL_PERUJUK[$r->tabel] ?? str_replace('_', ' ', $r->tabel);
            $pakai[$label] = ($pakai[$label] ?? 0) + (int) $r->jml;
        }

        return $pakai;
    }

    public function destroy(CoaDetail $coa_detail): RedirectResponse
    {
        $pakai = $this->pemakaiAkun($coa_detail->kode_coa);
        if ($pakai !== []) {
            $rincian = implode(', ', array_map(fn ($n, $l) => "{$n} {$l}", $pakai, array_keys($pakai)));

            return redirect()->route('coa_detail.index')->with('error',
                "Akun {$coa_detail->kode_coa} — {$coa_detail->nama_coa} masih dipakai ({$rincian}). "
                .'Nonaktifkan saja bila sudah tidak terpakai; menghapusnya akan membuat data itu '
                .'menunjuk akun yang tak ada.');
        }

        try {
            $coa_detail->delete();
        } catch (QueryException $e) {
            // Lapis terakhir: kolom perujuk yang namanya tak berawalan `kode_coa`
            // tak terjaring pemeriksaan di atas, tetapi kunci asingnya tetap menahan.
            return redirect()->route('coa_detail.index')
                ->with('error', 'Akun tidak bisa dihapus karena masih dipakai data lain. Nonaktifkan saja.');
        }

        return redirect()->route('coa_detail.index')
            ->with('status', "Akun {$coa_detail->kode_coa} — {$coa_detail->nama_coa} dihapus.");
    }

    /** Hanya grup daun (level 3) yang lazim menampung akun detail. */
    private function grupOptions(): array
    {
        return CoaGroup::query()->orderBy('kode_grup')->get()
            ->mapWithKeys(fn ($g) => [$g->kode_grup => "{$g->kode_grup} — {$g->nama_grup} (L{$g->level})"])->all();
    }
}
