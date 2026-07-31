<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SumberInformasiService;
use App\Services\Modules\TipeBiayaService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Master Tipe Biaya & Sumber Informasi.
 *
 * Inti yang dikunci: tipe biaya bukan sekadar label — kode boleh apa saja, tetapi
 * PERILAKU-nya yang dibaca program. Tipe buatan sendiri karenanya langsung
 * tertangani modul yang benar, sementara empat tipe bawaan dilindungi dari
 * perubahan yang bisa merusak alur.
 */
class MasterTipeBiayaSumberTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZMT';
    private const PEND = '4.ZZMT.PEND';
    private const UNIT = 'ZZMTU';
    private const TA = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'MT']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
        TipeBiaya::lupakan();

        // Registrasi bawaan: pendaftaran calon santri butuh ini untuk menagih.
        $this->buatBiaya([
            'kode' => 'REG27', 'nama' => 'Registrasi 2027', 'tipe' => 'registrasi', 'nominal' => '750000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    public function test_tipe_bawaan_terisi_dari_migrasi(): void
    {
        // Perilaku ke-5 "perlengkapan" & ke-6 "daftar_ulang" menyusul lewat
        // migrasi tersendiri; keduanya hanya disisipkan bila belum ada tipe
        // berperilaku itu, sehingga master yang sudah diisi manual tak ditambahi.
        $this->assertSame(6, TipeBiaya::count());
        foreach (['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp', 'lain'] as $kode) {
            $t = TipeBiaya::findOrFail($kode);
            $this->assertTrue($t->bawaan);
            $this->assertSame($kode, $t->perilaku, 'Tipe bawaan berperilaku sama dengan namanya.');
        }
        $this->assertSame(5, SumberInformasi::count());
        $this->assertTrue(SumberInformasi::findOrFail('lainnya')->butuh_keterangan);
    }

    /** Tipe buatan sendiri langsung tertangani modul yang sesuai perilakunya. */
    public function test_tipe_baru_mengikuti_perilaku_yang_dipilih(): void
    {
        (new TipeBiayaService)->create([
            'kode' => 'seragam', 'nama' => 'Seragam', 'perilaku' => 'lain', 'status' => 'aktif',
        ]);
        TipeBiaya::lupakan();

        $this->assertContains('seragam', TipeBiaya::kodeBerperilaku('lain'));
        $this->assertNotContains('seragam', TipeBiaya::kodeBerperilaku('registrasi'));
        $this->assertSame('lain', TipeBiaya::perilakuDari('seragam'));

        // Jenis biaya bertipe baru itu bisa dibuat & muncul di modul tagihan lain.
        $this->buatBiaya([
            'kode' => 'SRG27', 'nama' => 'Seragam 2027', 'tipe' => 'seragam', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
        // Halaman tagihan lain butuh minimal satu santri aktif untuk menampilkan
        // formulirnya — tanpa itu ia hanya memberi pesan kosong.
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08126']);
        (new \App\Services\Modules\SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Santri Aktif', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ])->update(['status' => 'aktif']);

        $this->actingAs($this->admin)->get(route('tagihan_lain.create'))->assertOk()->assertSee('SRG27');
    }

    /** Tipe berperilaku registrasi ikut menagih otomatis saat calon mendaftar. */
    public function test_perilaku_registrasi_tetap_menagih_otomatis(): void
    {
        (new TipeBiayaService)->create([
            'kode' => 'reg_khusus', 'nama' => 'Registrasi Khusus', 'perilaku' => 'registrasi', 'status' => 'aktif',
        ]);
        TipeBiaya::lupakan();

        // Bercakupan jenjang SMP → lebih khusus daripada REG27 yang umum, jadi
        // inilah yang dipakai. Sekaligus membuktikan penjaga cakupan tunggal
        // membandingkan PERILAKU: dua tipe berbeda tetap tak boleh bercakupan sama.
        $this->buatBiaya([
            'kode' => 'REGK27', 'nama' => 'Registrasi Khusus 2027', 'tipe' => 'reg_khusus', 'nominal' => '900000',
            'kode_jenjang' => 'SMP', 'kode_coa_pendapatan' => self::PEND,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08123']);
        $santri = (new \App\Services\Modules\SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);

        $tagihan = $santri->tagihan()->first();
        $this->assertNotNull($tagihan, 'Tipe berperilaku registrasi tetap menerbitkan tagihan otomatis.');
        $this->assertSame('REGK27', $tagihan->kode_jenis);
        $this->assertSame(900000.0, (float) $tagihan->nominal);
    }

    public function test_tipe_bawaan_dilindungi(): void
    {
        $svc = new TipeBiayaService;

        // Perilaku tak boleh diubah.
        try {
            $svc->update('registrasi', ['kode' => 'registrasi', 'nama' => 'Registrasi', 'perilaku' => 'lain', 'status' => 'aktif']);
            $this->fail('Perilaku tipe bawaan seharusnya terkunci.');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
        }

        // Tak boleh dinonaktifkan maupun dihapus.
        try {
            $svc->update('spp', ['kode' => 'spp', 'nama' => 'SPP', 'perilaku' => 'spp', 'status' => 'nonaktif']);
            $this->fail('Tipe bawaan seharusnya tak bisa dinonaktifkan.');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
        }
        try {
            $svc->remove('lain');
            $this->fail('Tipe bawaan seharusnya tak bisa dihapus.');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
        }

        // Namanya tetap bebas diubah.
        $svc->update('spp', ['kode' => 'spp', 'nama' => 'SPP Bulanan', 'perilaku' => 'spp', 'status' => 'aktif']);
        $this->assertSame('SPP Bulanan', TipeBiaya::findOrFail('spp')->nama);
    }

    public function test_tipe_yang_dipakai_tak_bisa_dihapus(): void
    {
        (new TipeBiayaService)->create(['kode' => 'seragam', 'nama' => 'Seragam', 'perilaku' => 'lain', 'status' => 'aktif']);
        TipeBiaya::lupakan();
        $this->buatBiaya([
            'kode' => 'SRG27', 'nama' => 'Seragam 2027', 'tipe' => 'seragam', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);

        $this->expectException(AppException::class);
        (new TipeBiayaService)->remove('seragam');
    }

    public function test_sumber_informasi_bisa_ditambah_dan_dipakai_formulir(): void
    {
        (new SumberInformasiService)->create([
            'kode' => 'brosur', 'nama' => 'Brosur / Spanduk', 'urutan' => 6, 'status' => 'aktif',
        ]);

        $this->actingAs($this->admin)->get(route('santri.create'))->assertOk()
            ->assertSee('Brosur / Spanduk');

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08124']);
        $santri = (new \App\Services\Modules\SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'sumber_informasi' => 'brosur',
        ]);
        $this->assertSame('brosur', $santri->sumber_informasi);
    }

    public function test_sumber_yang_dipakai_santri_tak_bisa_dihapus(): void
    {
        (new SumberInformasiService)->create(['kode' => 'brosur', 'nama' => 'Brosur', 'status' => 'aktif']);
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08125']);
        (new \App\Services\Modules\SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'sumber_informasi' => 'brosur',
        ]);

        try {
            (new SumberInformasiService)->remove('brosur');
            $this->fail('Sumber yang sudah dipakai seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Nonaktifkan', $e->getMessage());
            $this->assertNotNull(SumberInformasi::find('brosur'));
        }
    }

    /**
     * Form Tambah memakai objek TipeBiaya yang BELUM tersimpan. Dulu halamannya
     * gagal dibuka (500) karena model punya method statis bernama `kode` —
     * sama dengan nama kolomnya — sehingga pada objek baru Eloquent menyangka
     * `$row->kode` sebuah relasi. Methodnya kini `kodeBerperilaku()`.
     */
    public function test_form_tambah_tipe_biaya_bisa_dibuka(): void
    {
        $this->actingAs($this->admin)->get(route('tipe_biaya.create'))->assertOk()
            ->assertSee('Tambah Tipe Biaya')
            ->assertSee('Perilaku');

        // Membaca atribut pada objek baru tidak boleh melempar apa pun.
        $this->assertNull((new \App\Models\TipeBiaya)->kode);

        // Ubah (objek tersimpan) tetap jalan.
        $this->actingAs($this->admin)->get(route('tipe_biaya.edit', 'registrasi'))->assertOk();
    }

    /**
     * Jenjang berlaku untuk SEMUA perilaku, termasuk lain-lain. Dulu isian
     * jenjang ikut tersembunyi bersama Nominal & Jalur begitu perilakunya
     * "lain", padahal jenjang dipakai memilah laporan — sama seperti unit.
     */
    public function test_jenis_biaya_berperilaku_lain_bisa_berjenjang(): void
    {
        TipeBiaya::create(['kode' => 'seragam', 'nama' => 'Seragam', 'perilaku' => 'lain', 'urutan' => 9, 'status' => 'aktif']);
        TipeBiaya::lupakan();

        $this->actingAs($this->admin)->post(route('jenis_biaya.store'), [
            'kode' => 'SRG-SMP', 'nama' => 'Seragam', 'tipe' => 'seragam',
            'tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'status' => 'aktif',
        ])->assertRedirect();

        $jb = \App\Models\JenisBiaya::findOrFail('SRG-SMP');
        $this->assertSame('SMP', $jb->kode_jenjang);
        $this->assertSame(self::UNIT, $jb->kode_unit);

        // Terbaca di daftar. Kolom cakupan kini cuma jenjang: jalur & tahun ajaran
        // sudah pindah ke grid Tarif.
        $this->actingAs($this->admin)->get(route('jenis_biaya.index'))->assertOk()
            ->assertSee('SMP', false);

        // …dan jenjangnya ikut tampil saat memilih jenis di Tagihan Lain-lain,
        // supaya dua baris bernama mirip tak tertukar. Halaman itu hanya
        // memunculkan formulirnya bila ada santri aktif.
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08127']);
        (new \App\Services\Modules\SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Santri Aktif', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ])->update(['status' => 'aktif']);

        $halaman = $this->actingAs($this->admin)->get(route('tagihan_lain.create'))->assertOk()->getContent();
        $this->assertStringContainsString('SRG-SMP', $halaman, 'jenis berperilaku lain harus muncul sebagai pilihan');
        $this->assertStringContainsString('(SMP)', $halaman, 'jenjangnya harus ikut tampil di label pilihan');

        // Form suntingnya menampilkan isian jenjang.
        $this->actingAs($this->admin)->get(route('jenis_biaya.edit', 'SRG-SMP'))->assertOk()
            ->assertSee('name="kode_jenjang"', false);
    }

    public function test_halaman_master_dan_letak_menunya(): void
    {
        $this->actingAs($this->admin)->get('/tipe-biaya')->assertOk()->assertSee('Registrasi')->assertSee('Perilaku');
        $this->actingAs($this->admin)->get('/ppsb/sumber-informasi')->assertOk()->assertSee('Media Sosial');

        // Jenis Biaya kini di SETTING AWAL → Setting Biaya, tak lagi di sub PPSB.
        $item = collect(\App\Support\Navigation::ITEMS)->firstWhere('url', '/ppsb/jenis-biaya');
        $this->assertSame('SETTING AWAL', $item['group']);
        $this->assertSame('Setting Biaya', $item['sub']);

        $modul = collect(\App\Support\ModulRegistry::MODUL)->firstWhere('kode', 'jenis-biaya');
        $this->assertSame('SETTING AWAL', $modul['grup']);
        $this->assertSame('Setting Biaya', $modul['sub']);
    }

    /** Data lama tetap terbaca walau masternya kosong (kode = nama perilaku). */
    public function test_kode_selalu_memuat_nama_perilakunya_sendiri(): void
    {
        JenisBiaya::query()->delete(); // lepas FK-nya dulu
        TipeBiaya::query()->delete();
        TipeBiaya::lupakan();

        $this->assertSame(['registrasi'], TipeBiaya::kodeBerperilaku('registrasi'));
        $this->assertSame('spp', TipeBiaya::perilakuDari('spp'));
    }
}
