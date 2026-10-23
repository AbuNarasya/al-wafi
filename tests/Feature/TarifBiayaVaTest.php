<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use App\Services\Midtrans\PembayaranMidtrans;
use App\Services\Midtrans\TarifVa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Biaya VA Midtrans diatur dari Pengaturan Perusahaan, bertanggal berlaku & berriwayat (24 Okt 2026). */
class TarifBiayaVaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.midtrans.biaya_va' => 4440]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
    }

    public function test_tanpa_pengaturan_memakai_angka_server(): void
    {
        $this->assertSame('4440.00', PembayaranMidtrans::biaya());
    }

    public function test_tarif_hari_ini_berlaku_seketika_tarif_terjadwal_menunggu_harinya(): void
    {
        $this->actingAs($this->admin)->post(route('company_settings.biaya_va'), ['biaya_va' => '4995', 'berlaku_mulai' => today()->toDateString(), 'catatan_va' => 'Surat Midtrans'])
            ->assertSessionHas('status');
        $this->assertSame('4995.00', PembayaranMidtrans::biaya());

        $this->actingAs($this->admin)->post(route('company_settings.biaya_va'), ['biaya_va' => '5550', 'berlaku_mulai' => today()->addDays(10)->toDateString()]);
        $this->assertSame('4995.00', PembayaranMidtrans::biaya(), 'belum harinya');
        $this->assertSame('5550.00', TarifVa::berlaku(Carbon::today()->addDays(10)));

        $this->actingAs($this->admin)->get(route('company_settings.edit'))->assertOk()
            ->assertSee('Biaya Virtual Account (Midtrans)')->assertSee('Terjadwal')->assertSee('Admin Keuangan')->assertSee('Surat Midtrans');

        // Terjadwal boleh dibatalkan; yang sudah berlaku tidak.
        $jadwal = DB::table('tarif_biaya_va')->where('biaya', 5550)->value('id');
        $berlaku = DB::table('tarif_biaya_va')->where('biaya', 4995)->value('id');
        $this->actingAs($this->admin)->delete(route('company_settings.biaya_va.batal', $berlaku))->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('company_settings.biaya_va.batal', $jadwal))->assertSessionHas('status');
        $this->assertSame(1, DB::table('tarif_biaya_va')->count());
        $this->assertTrue(DB::table('activity_log')->where('aksi', 'ubah_tarif_biaya_va')->exists());
    }

    public function test_tanggal_lampau_dan_angka_tak_wajar_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('company_settings.biaya_va'), ['biaya_va' => '4440', 'berlaku_mulai' => today()->subDay()->toDateString()])
            ->assertSessionHasErrors('berlaku_mulai');
        $this->actingAs($this->admin)->post(route('company_settings.biaya_va'), ['biaya_va' => '200000', 'berlaku_mulai' => today()->toDateString()])
            ->assertSessionHasErrors('biaya_va');
        $this->assertSame(0, DB::table('tarif_biaya_va')->count());
    }

    public function test_tanpa_hak_ubah_ditolak(): void
    {
        $staf = User::create(['username' => 'stf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        $this->actingAs($staf)->post(route('company_settings.biaya_va'), ['biaya_va' => '1', 'berlaku_mulai' => today()->toDateString()])->assertForbidden();
    }
}
