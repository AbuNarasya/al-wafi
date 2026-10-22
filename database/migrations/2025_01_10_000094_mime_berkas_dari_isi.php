<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AUDIT 22 Okt 2026 — kolom `mime` berkas dulu diisi header Content-Type kiriman PERAMBAN, sehingga berkas
 * berkedok PDF/JPG bisa tercatat (dan dulu disajikan) sebagai text/html. Penyajian kini selalu mendeteksi
 * ulang dari isi berkas (Unggahan::sajikan); migrasi ini menyamakan catatan lama dengan isi berkasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ([['dokumen_santri', fn () => 'local'], ['lampiran_dokumen', fn ($r) => $r->disk ?: 'local']] as [$tabel, $disk]) {
            if (! DB::getSchemaBuilder()->hasTable($tabel)) {
                continue;
            }
            DB::table($tabel)->orderBy('id')->chunkById(500, function ($baris) use ($tabel, $disk, $finfo) {
                foreach ($baris as $r) {
                    try {
                        $s = Storage::disk($disk($r));
                        if (! $s->exists($r->path)) {
                            continue;
                        }
                        $mime = (string) ($finfo->file($s->path($r->path)) ?: 'application/octet-stream');
                    } catch (Throwable) {
                        continue;
                    }
                    if ($mime !== $r->mime) {
                        DB::table($tabel)->where('id', $r->id)->update(['mime' => $mime]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Tak ada jalan pulang yang berarti: nilai lama tak bisa dipercaya.
    }
};
