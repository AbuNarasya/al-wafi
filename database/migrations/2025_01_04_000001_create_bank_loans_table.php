<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_loans — Master pembiayaan/pinjaman bank (syariah). Sisa pokok =
 * pokok_awal - pokok_terbayar. Angsuran dicatat lewat cash_out.id_bank_loan.
 * jenis_akad: murabahah | ijarah | musyarakah_mutanaqishah | qardh | istishna | lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_loans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_bank');
            $table->string('nomor_kontrak')->nullable();
            $table->string('jenis_akad')->default('murabahah');
            $table->decimal('pokok_awal', 18, 2);
            $table->decimal('margin', 18, 2)->nullable();
            $table->integer('tenor_bulan')->nullable();
            $table->date('tanggal_mulai');
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->string('kode_coa_hutang');
            $table->string('kode_coa_beban_bunga')->nullable();
            $table->string('kode_rekening');
            $table->decimal('pokok_terbayar', 18, 2)->default(0);
            $table->string('status')->default('aktif'); // aktif | lunas | void
            $table->text('keterangan')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('nama_bank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_loans');
    }
};
