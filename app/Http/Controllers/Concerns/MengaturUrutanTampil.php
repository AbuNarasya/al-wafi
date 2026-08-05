<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Modules\UrutanTampilService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Aksi "simpan urutan tampil" untuk master ber-kolom `urutan`. Dipakai keempat
 * halaman daftar yang barisnya bisa diseret (jenjang, tipe biaya, sumber
 * informasi, jalur pendaftaran).
 *
 * Menjawab JSON, bukan redirect: pemanggilnya `fetch` dari halaman daftar yang
 * tetap diam di tempat setelah baris dilepas. `AppException` dari service
 * dirender sendiri menjadi JSON `{message}` beserta status HTTP-nya.
 */
trait MengaturUrutanTampil
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function kelasUrutan(): string;

    public function urutan(Request $request, UrutanTampilService $service): JsonResponse
    {
        // Divalidasi manual, BUKAN lewat $request->validate(): aplikasi ini
        // hanya merender galat sebagai JSON untuk alamat api/* (lihat
        // bootstrap/app.php), sehingga validate() akan menjawab redirect 302 —
        // dan `fetch` di layar akan mengiranya berhasil.
        $v = Validator::make($request->all(), [
            'kode' => ['required', 'array', 'min:1'],
            'kode.*' => ['required', 'string'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $berubah = $service->simpan($this->kelasUrutan(), $v->validated()['kode']);

        return response()->json([
            'berubah' => $berubah,
            'pesan' => $berubah > 0 ? 'Urutan tersimpan.' : 'Urutan tidak berubah.',
        ]);
    }
}
