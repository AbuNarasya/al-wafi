<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\Level;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Modules\CashInService;
use App\Services\Modules\CashOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUKTI KAS MASUK (RV) & BUKTI KAS KELUAR (PV) — lembar siap tanda tangan.
 *
 * Satu view melayani keduanya, jadi yang dijaga di sini terutama hal-hal yang
 * BERBEDA di antara keduanya — arah uang, lawan transaksi, kolom rincian —
 * karena di situlah satu cabang yang salah akan mencetak bukti yang keliru
 * tanpa ada yang menyadarinya sampai dokumennya beredar.
 *
 * Yang paling penting: voucher yang sudah DIBATALKAN harus tercetak dengan cap
 * VOID. Lembar batal yang tampak sah adalah satu-satunya kekeliruan di modul ini
 * yang bisa dipakai orang untuk menagih dua kali.
 */
class CetakBuktiKasTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZCT';

    private const BANK = 'ZZCT.BANK';

    private const PEND = 'ZZCT.PEND';

    private const BEBAN = 'ZZCT.BEBAN';

    private const UNIT = 'ZZCTU';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Cetak Test']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Kas Besar', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan Kantin', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Listrik', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Usaha']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        CompanySettings::create(['id' => 1, 'nama_perusahaan' => 'Pesantren Al Wafi', 'alamat' => 'Jl. Pesantren No. 1',
            'periode_awal_pembukuan' => '2026-07-01']);
        CustomerType::create(['kode_jenis_customer' => 'JC', 'nama' => 'Umum']);
        Customer::create(['kode_customer' => 'CUS1', 'nama_customer' => 'Koperasi Santri', 'kode_jenis_customer' => 'JC']);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'VEN1', 'nama_vendor' => 'PT Listrik Jaya', 'kode_jenis_vendor' => 'JV']);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Ustadz Bendahara', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
    }

    private function kasMasuk(array $ganti = [])
    {
        return (new CashInService)->create(array_merge([
            'tanggal' => '2026-08-01',
            'kode_unit' => self::UNIT,
            'kode_rekening' => self::BANK,
            'kode_customer' => 'CUS1',
            'keterangan' => 'Setoran kantin Juli',
            'details' => [['kode_coa' => self::PEND, 'nominal' => '1250000', 'jenis_kas_masuk' => 'pelunasan', 'keterangan' => 'Penjualan kantin']],
        ], $ganti), $this->admin->id_pengguna);
    }

    private function kasKeluar(array $ganti = [])
    {
        return (new CashOutService)->create(array_merge([
            'tanggal' => '2026-08-02',
            'kode_unit' => self::UNIT,
            'kode_rekening' => self::BANK,
            'kode_vendor' => 'VEN1',
            'keterangan' => 'Bayar listrik Juli',
            'details' => [['kode_coa' => self::BEBAN, 'nominal' => '875000', 'keterangan' => 'Tagihan PLN']],
        ], $ganti), $this->admin->id_pengguna);
    }

    // ---- Kas masuk ----

    public function test_bukti_kas_masuk_memuat_isi_voucher_dan_kop(): void
    {
        $rec = $this->kasMasuk();

        $html = $this->actingAs($this->admin)->get(route('cash_in.print', $rec->kode_transaksi))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Bukti Kas Masuk', $html);
        $this->assertStringContainsString('Receivable Voucher (RV)', $html);
        $this->assertStringContainsString($rec->nomor_transaksi, $html);
        // Kop dari master Company Settings, bukan teks yang dipaku di view.
        $this->assertStringContainsString('Pesantren Al Wafi', $html);
        $this->assertStringContainsString('Jl. Pesantren No. 1', $html);
        // Lawan transaksi kas MASUK adalah customer, dan arahnya "diterima dari".
        $this->assertStringContainsString('Diterima dari', $html);
        $this->assertStringContainsString('Koperasi Santri', $html);
        $this->assertStringContainsString('Pendapatan Kantin', $html);
        $this->assertStringContainsString('Penjualan kantin', $html);
    }

    /** Terbilang adalah yang dibaca pemeriksa saat angkanya diragukan. */
    public function test_bukti_memuat_terbilang_yang_cocok_dengan_nominalnya(): void
    {
        $rec = $this->kasMasuk();

        $this->actingAs($this->admin)->get(route('cash_in.print', $rec->kode_transaksi))
            ->assertOk()
            ->assertSee('Terbilang')
            ->assertSee('satu juta dua ratus lima puluh ribu rupiah');
    }

    /**
     * Label jenis rincian dulu memetakan apa pun selain `uang_muka` menjadi
     * "Pendapatan" — termasuk `pelunasan`, yang berarti bukti resmi menyebut
     * pelunasan piutang sebagai pendapatan baru.
     */
    public function test_jenis_rincian_disebut_apa_adanya(): void
    {
        $rec = $this->kasMasuk();

        foreach ([route('cash_in.print', $rec->kode_transaksi), route('cash_in.show', $rec->kode_transaksi)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Pelunasan', $html);
        }
    }

    // ---- Kas keluar ----

    public function test_bukti_kas_keluar_memakai_vendor_dan_arah_bayar(): void
    {
        $rec = $this->kasKeluar();

        $html = $this->actingAs($this->admin)->get(route('cash_out.print', $rec->kode_transaksi))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Bukti Kas Keluar', $html);
        $this->assertStringContainsString('Payment Voucher (PV)', $html);
        $this->assertStringContainsString('Dibayarkan kepada', $html);
        $this->assertStringContainsString('PT Listrik Jaya', $html);
        $this->assertStringContainsString('Beban Listrik', $html);
        // Kolom "Jenis" hanya milik kas masuk — kas keluar tak punya padanannya.
        $this->assertStringNotContainsString('Diterima dari', $html);
    }

    public function test_kolom_tanda_tangan_menyesuaikan_arah_uang(): void
    {
        $masuk = $this->actingAs($this->admin)->get(route('cash_in.print', $this->kasMasuk()->kode_transaksi))
            ->assertOk()->getContent();
        $keluar = $this->actingAs($this->admin)->get(route('cash_out.print', $this->kasKeluar()->kode_transaksi))
            ->assertOk()->getContent();

        // Yang menyerahkan uang pada kas masuk = penyetor; pada kas keluar = penerima.
        $this->assertStringContainsString('Penyetor', $masuk);
        $this->assertStringNotContainsString('Penerima', $masuk);
        $this->assertStringContainsString('Penerima', $keluar);

        // Pembuatnya tercetak bernama, bukan garis kosong.
        $this->assertStringContainsString('Ustadz Bendahara', $masuk);
        foreach (['Dibuat oleh', 'Diperiksa', 'Disetujui'] as $peran) {
            $this->assertStringContainsString($peran, $masuk);
        }
    }

    // ---- Voucher yang dibatalkan ----

    /**
     * Yang paling berbahaya di modul ini: lembar batal yang tampak sah masih
     * bisa dipakai menagih ulang. Cap VOID berikut alasannya wajib ikut.
     */
    public function test_voucher_void_tercetak_dengan_cap_void_dan_alasannya(): void
    {
        $rec = $this->kasMasuk();
        (new CashInService)->void(
            $rec->kode_transaksi,
            ['tanggal' => '2026-08-03', 'alasan' => 'Salah rekening tujuan'],
            $this->admin->id_pengguna,
            $this->admin->nama,
        );

        $html = $this->actingAs($this->admin)->get(route('cash_in.print', $rec->kode_transaksi))
            ->assertOk()->getContent();

        $this->assertStringContainsString('VOID', $html);
        $this->assertStringContainsString('telah dibatalkan', $html);
        $this->assertStringContainsString('Salah rekening tujuan', $html);
        $this->assertStringContainsString('bukan bukti transaksi yang sah', $html);
    }

    public function test_voucher_aktif_tidak_membawa_cap_void(): void
    {
        $html = $this->actingAs($this->admin)->get(route('cash_in.print', $this->kasMasuk()->kode_transaksi))
            ->assertOk()->getContent();

        // Dicari markup capnya, bukan kata "cap-void" — definisi CSS-nya memang
        // selalu ada di <style>, jadi mencari namanya saja akan selalu cocok.
        $this->assertStringNotContainsString('class="cap-void"', $html);
        $this->assertStringNotContainsString('telah dibatalkan', $html);
    }

    // ---- Akses ----

    public function test_tombol_cetak_muncul_di_halaman_rincian(): void
    {
        $masuk = $this->kasMasuk();
        $keluar = $this->kasKeluar();

        $this->actingAs($this->admin)->get(route('cash_in.show', $masuk->kode_transaksi))
            ->assertOk()->assertSee('Cetak Bukti')
            ->assertSee(route('cash_in.print', $masuk->kode_transaksi), false);
        $this->actingAs($this->admin)->get(route('cash_out.show', $keluar->kode_transaksi))
            ->assertOk()->assertSee('Cetak Bukti')
            ->assertSee(route('cash_out.print', $keluar->kode_transaksi), false);
    }

    public function test_cetak_butuh_login(): void
    {
        $rec = $this->kasMasuk();

        $this->get(route('cash_in.print', $rec->kode_transaksi))->assertRedirect(route('login'));
    }
}
