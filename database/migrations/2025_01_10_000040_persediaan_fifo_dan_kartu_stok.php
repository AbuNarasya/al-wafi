<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERSEDIAAN: dari rata-rata tertimbang → FIFO, dan dari "dua angka akumulatif"
 * → kartu stok yang utuh.
 *
 * Sebelum ini `inventory` hanya menyimpan `stok_masuk` & `stok_keluar`. Tak ada
 * satu baris pun riwayat, sehingga pertanyaan "stok 40 ini dari mana" tak bisa
 * dijawab, pembatalan transaksi merusak harga rata-rata secara permanen, dan
 * logika rata-ratanya tersalin di TIGA tempat yang sudah saling menyimpang.
 *
 * Dua tabel baru:
 *  - `lapisan_persediaan` — lot FIFO. Tiap pemasukan melahirkan satu lapisan
 *    berharga sendiri; pengeluaran menggerus lapisan tertua lebih dulu.
 *  - `mutasi_persediaan`  — kartu stok. SETIAP pergerakan, dari modul mana pun,
 *    meninggalkan satu baris di sini beserta saldo berjalannya.
 *
 * Kolom lama di `inventory` DIPERTAHANKAN tetapi berubah sifat menjadi TURUNAN:
 * dihitung ulang dari lapisan tiap kali stok bergerak. Itu menjaga seluruh
 * laporan & unduhan yang sudah ada tetap hidup tanpa disentuh, sambil
 * memindahkan sumber kebenarannya ke lapisan.
 *
 * Aman dijalankan: saat migrasi ini dibuat, `inventory` masih kosong dan belum
 * ada satu transaksi pun — jadi tak ada saldo lama yang perlu dipindahkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lapisan_persediaan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_persediaan');
            $table->date('tanggal');
            $table->decimal('kuantiti_awal', 18, 4);
            $table->decimal('kuantiti_sisa', 18, 4);
            $table->decimal('harga_satuan', 18, 2);
            $table->unsignedInteger('mutasi_id')->nullable();
            $table->string('sumber_modul');
            $table->string('sumber_ref')->nullable();
            $table->timestamps();

            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory')->cascadeOnDelete();
            // Urutan FIFO: tanggal dulu, lalu id sebagai pemutus seri. Dua
            // pemasukan bertanggal sama harus tetap punya urutan yang pasti —
            // tanpa pemutus itu, harga pokok berubah-ubah antar pemanggilan.
            $table->index(['kode_persediaan', 'tanggal', 'id'], 'lapisan_urutan_fifo');
        });

        Schema::create('mutasi_persediaan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_persediaan');
            $table->date('tanggal');
            $table->enum('arah', ['masuk', 'keluar']);
            // pembelian | pemakaian | opname | pembatalan — alasan yang menentukan
            // akun lawan jurnalnya, bukan sekadar catatan.
            $table->string('alasan')->default('pembelian');
            $table->decimal('kuantiti', 18, 4);
            $table->decimal('nilai', 18, 2);
            $table->decimal('saldo_kuantiti', 18, 4);
            $table->decimal('saldo_nilai', 18, 2);
            $table->string('sumber_modul');
            $table->string('sumber_ref')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            // Lapisan mana saja yang tergerus oleh pengeluaran ini, berikut qty &
            // harganya. Inilah yang membuat pembatalan bisa MENGEMBALIKAN lapisan
            // yang benar — dulu pembatalan hanya mengurangi qty dan membiarkan
            // harga rata-ratanya salah selamanya.
            $table->jsonb('rincian_lapisan')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->timestamps();

            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory')->cascadeOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['kode_persediaan', 'tanggal', 'id'], 'kartu_stok_urutan');
            $table->index(['sumber_modul', 'sumber_ref']);
        });

        Schema::table('inventory', function (Blueprint $table) {
            // AKUN — dan hanya akun — ditentukan BAGIAN KEUANGAN di master ini,
            // sekali saja. Petugas gudang kelak tak pernah memilih akun; ia
            // menyebut barang, tanggal, jumlah, dan BAGIAN yang memakainya
            // (bagian dipilih saat mencatat karena barang yang sama bisa dipakai
            // bagian mana saja — memakunya di master justru memaksa akuntansi
            // yang salah setiap kali peminjamnya berbeda).
            $table->string('kode_coa_beban')->nullable()->after('kode_coa');
            $table->string('kode_coa_selisih')->nullable()->after('kode_coa_beban');
            // Nilai persediaan TURUNAN, dijumlahkan persis dari lapisan tersisa.
            // Disimpan supaya laporan tak perlu mengalikan stok × harga rata-rata
            // yang sudah dibulatkan — perkalian itu meleset beberapa rupiah dan
            // membuat rekonsiliasi berteriak tanpa sebab.
            $table->decimal('nilai_persediaan', 18, 2)->default(0)->after('stok_keluar');

            $table->index('kode_coa_beban');
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropIndex(['kode_coa_beban']);
            $table->dropColumn(['kode_coa_beban', 'kode_coa_selisih', 'nilai_persediaan']);
        });
        Schema::dropIfExists('mutasi_persediaan');
        Schema::dropIfExists('lapisan_persediaan');
    }
};
