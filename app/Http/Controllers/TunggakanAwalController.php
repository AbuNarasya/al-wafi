<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\TunggakanAwalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * TUNGGAKAN AWAL — pintu manual saldo awal tunggakan, dari halaman detail santri.
 *
 * Wewenangnya MENUMPANG `impor-data-awal`, bukan modul tersendiri: yang dilakukan
 * di sini persis sama dengan yang dilakukan Impor Data Awal — memasukkan keadaan
 * pindahan tanpa menjurnal — dan orangnya pun sama. Memberinya baris hak sendiri
 * hanya menambah satu kotak yang harus dicentangi di dua database, dan hak yang
 * lupa dicentang sudah beberapa kali muncul sebagai keluhan "tombolnya tak ada".
 *
 * `buat` untuk ketiganya, termasuk hapus: yang dihapus di sini bukan dokumen
 * keuangan yang sudah berlaku, melainkan barisnya sendiri yang belum tersentuh —
 * pembatalan atas pekerjaannya sendiri, sama seperti membatalkan batch impor.
 */
class TunggakanAwalController extends Controller
{
    public function __construct(private readonly TunggakanAwalService $service) {}

    public function store(Request $request, int $idSantri): RedirectResponse
    {
        // Kewajiban isian ditegakkan DI SINI, bukan di service: service dipakai
        // juga dari test & perintah, dan menaruh "wajib" di sana sudah pernah
        // memecahkan puluhan test sekaligus.
        $data = $request->validate([
            'kode_jenis' => ['required', 'string', 'exists:jenis_biaya,kode'],
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            // `nominal_tunggakan`, bukan `nominal`: di halaman santri
            // `name="nominal"` sudah berarti isian uang pangkal, dan KETIADAANNYA
            // dipakai sebagai bukti bahwa jalur bebas uang pangkal memang tak
            // menawarkannya (JalurBebasUangPangkalTest). Memakai nama yang sama
            // di sini membuat bukti itu palsu.
            'nominal_tunggakan' => ['required', 'numeric', 'gt:0'],
            // Wajib, dan itu disengaja. Baris ini menerbitkan piutang tanpa
            // jurnal; setahun lagi tak seorang pun ingat dari mana angkanya —
            // kecuali ditulis sekarang.
            'keterangan' => ['required', 'string', 'max:255'],
            'jatuh_tempo' => ['nullable', 'date'],
        ]);

        try {
            $tagihan = $this->service->tambah($idSantri, $this->keService($data));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tunggakan awal '.Money::of($tagihan->nominal).' dicatat. '
            .$this->ingatkanJurnalPembuka());
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'nominal_tunggakan' => ['required', 'numeric', 'gt:0'],
            'keterangan' => ['required', 'string', 'max:255'],
            'jatuh_tempo' => ['nullable', 'date'],
        ]);

        try {
            $tagihan = $this->service->ubah($id, $this->keService($data));
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tunggakan awal diubah menjadi '.Money::of($tagihan->nominal).'. '
            .$this->ingatkanJurnalPembuka());
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->hapus($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tunggakan awal dihapus. '.$this->ingatkanJurnalPembuka());
    }

    /**
     * Nama isian layar → nama yang dimengerti service.
     *
     * Hanya `nominal_tunggakan` yang berganti nama; sisanya lewat apa adanya.
     * Pemetaannya di satu tempat supaya kedua jalur (tambah & ubah) tak bisa
     * berbeda pendapat tentang nama yang sama.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function keService(array $data): array
    {
        $data['nominal'] = $data['nominal_tunggakan'];
        unset($data['nominal_tunggakan']);

        return $data;
    }

    /**
     * Ke mana angkanya pergi — disebutkan tiap kali, bukan sekali di halaman
     * bantuan. Dulu kalimat ini berbunyi "sesuaikan sendiri jurnal pembukanya";
     * sejak menu Saldo Awal punya baris turunan, penyesuaian itu tak lagi
     * pekerjaan tangan, dan menyuruh orang mengerjakannya justru membuat
     * angkanya terhitung dua kali.
     */
    private function ingatkanJurnalPembuka(): string
    {
        return 'Tidak ada jurnal yang terbit: angkanya masuk buku besar lewat baris turunan '
            .'di menu Saldo Awal, yang menghitung ulang sendiri.';
    }
}
