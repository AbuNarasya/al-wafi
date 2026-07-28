<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\Modules\ReminderTagihanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Setting reminder tagihan mendekati jatuh tempo — singleton (pola
 * company-settings) + pratinjau tagihan dalam jendela pengingat + kirim manual.
 * Pengiriman otomatis: command `reminder:tagihan` terjadwal harian.
 */
class ReminderTagihanController extends Controller
{
    public function __construct(private ReminderTagihanService $service)
    {
    }

    public function index(): View
    {
        $s = $this->service->pengaturan();

        return view('reminder-tagihan.index', [
            's' => $s,
            'daftar' => $this->service->daftarMendekati($s),
            'hari' => ReminderTagihanService::parseHari($s->hari_sebelum),
            'terakhir' => Notification::where('jenis', ReminderTagihanService::JENIS_NOTIF)
                ->with('user')
                ->orderByDesc('created_at')
                ->limit(15)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hari_sebelum' => ['required', 'string', 'max:100', 'regex:/^\s*\d{1,2}\s*(,\s*\d{1,2}\s*)*$/'],
            'jam_kirim' => ['required', 'date_format:H:i'],
        ], [
            'hari_sebelum.regex' => 'Isi daftar hari dipisah koma, contoh: 7,3,1 (0 = tepat hari jatuh tempo).',
        ]);

        foreach ([
            'aktif', 'sumber_tagihan_santri', 'sumber_invoice_vendor', 'sumber_angsuran_uang_pangkal',
            'penerima_admin', 'penerima_tim_keuangan', 'penerima_akses_modul',
        ] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $this->service->simpan($data);

        return redirect()->route('reminder_tagihan.index')->with('status', 'Pengaturan reminder berhasil disimpan.');
    }

    public function kirim(): RedirectResponse
    {
        $hasil = $this->service->kirim();
        $pesan = $hasil['terkirim'] > 0
            ? "Reminder terkirim: {$hasil['terkirim']} notifikasi baru untuk {$hasil['kandidat']} tagihan."
            : ($hasil['kandidat'] > 0
                ? "Tidak ada notifikasi baru — {$hasil['kandidat']} tagihan dalam jendela pengingat sudah pernah diingatkan."
                : 'Tidak ada tagihan yang melewati titik pengingat saat ini.');

        return redirect()->route('reminder_tagihan.index')->with('status', $pesan);
    }
}
