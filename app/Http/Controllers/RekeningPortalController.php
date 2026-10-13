<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Keuangan → Rekening Portal Wali: rekening mana yang muncul sebagai tujuan
 * transfer langsung di portal wali. Memakai hak modul Kas & Rekening — ini
 * atribut rekening, bukan modul baru yang harus diberikan terpisah.
 */
class RekeningPortalController extends Controller
{
    public function index(): View
    {
        return view('rekening-portal.index', [
            // Hanya rekening BANK aktif bernomor — kas tunai tak bisa ditransfer.
            'rows' => BankAccount::where('jenis_rekening', 'bank')->where('status', 'aktif')
                ->orderBy('nama_bank')->orderBy('kode_coa')->get(),
            'namaLembaga' => DB::table('company_settings')->value('nama_perusahaan'),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tampil' => ['array'],
            'tampil.*' => ['string', 'exists:bank_accounts,kode_coa'],
            'atas_nama' => ['array'],
            'atas_nama.*' => ['nullable', 'string', 'max:255'],
        ]);
        $tampil = $data['tampil'] ?? [];

        $tanpaNomor = BankAccount::whereIn('kode_coa', $tampil)->whereNull('no_rekening')->pluck('nama_rekening');
        if ($tanpaNomor->isNotEmpty()) {
            return back()->withInput()->with('error', 'Rekening berikut belum punya nomor, jadi tak bisa ditampilkan: '
                .$tanpaNomor->implode(', ').'. Lengkapi dulu di Kas & Rekening.');
        }

        DB::transaction(function () use ($tampil, $data) {
            foreach (BankAccount::where('jenis_rekening', 'bank')->where('status', 'aktif')->get() as $r) {
                $r->update([
                    'tampil_di_portal' => in_array($r->kode_coa, $tampil, true),
                    'atas_nama' => trim((string) ($data['atas_nama'][$r->kode_coa] ?? '')) ?: null,
                ]);
            }
        });

        return redirect()->route('rekening_portal.index')
            ->with('status', count($tampil).' rekening kini tampil sebagai tujuan transfer di Portal Wali.');
    }
}
