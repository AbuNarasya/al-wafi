<?php

/**
 * ISI KAMUS DATA — penjelasan kuratif tiap tabel & kolom penting.
 *
 * STRUKTURNYA (kolom, tipe, kunci) TIDAK ditulis di sini: ia dibaca langsung
 * dari database oleh docs/render-kamus-data.php, supaya dokumennya tak pernah
 * bisa berbeda dari keadaan sebenarnya. Berkas ini hanya memuat yang tak bisa
 * dibaca mesin: untuk apa sebuah tabel ada, dan apa arti kolom yang namanya
 * tidak menjelaskan dirinya sendiri.
 *
 * Menambah tabel baru → tambahkan di sini juga; render akan MENOLAK berjalan
 * bila ada tabel di database yang tak disebut (atau sebaliknya).
 */
return [

    // =====================================================================
    // Keterangan kolom yang berulang di banyak tabel. Dipakai bila tabelnya
    // tak memberi keterangan sendiri untuk kolom itu.
    // =====================================================================
    'glosarium' => [
        'id' => 'Nomor baris, terbit otomatis.',
        'created_at' => 'Waktu baris dibuat.',
        'updated_at' => 'Waktu baris terakhir diubah.',
        'keterangan' => 'Catatan bebas.',
        'catatan' => 'Catatan bebas.',
        'status' => 'Status baris.',
        'nominal' => 'Nilai rupiah.',
        'tanggal' => 'Tanggal dokumen.',
        'kode_coa' => 'Akun COA yang dibebani/dikredit.',
        'nama_coa' => 'Salinan nama akun saat transaksi dibuat (agar cetakan lama tak ikut berubah bila akunnya di-rename).',
        'kode_unit' => 'Unit bisnis pembebanan — dimensi pelaporan per unit.',
        'kode_rekening' => 'Kas/rekening bank yang dipakai.',
        'kode_bagian' => 'Bagian/struktur organisasi pemilik.',
        'id_pengguna' => 'Pengguna yang mencatat.',
        'journal_entry_id' => 'Jurnal yang lahir dari dokumen ini.',
        'void_reason' => 'Alasan pembatalan.',
        'void_by' => 'Yang membatalkan.',
        'void_at' => 'Waktu pembatalan.',
        'id_santri' => 'Santri yang bersangkutan.',
        'id_wali' => 'Wali/keluarga yang bersangkutan.',
        'id_tagihan' => 'Tagihan yang bersangkutan.',
        'tahun_ajaran' => 'Tahun ajaran yang berlaku.',
        'kode_jenjang' => 'Jenjang pendidikan.',
        'kode_jenis' => 'Jenis biaya yang dirujuk.',
        'urutan' => 'Urutan tampil/proses.',
        'nomor' => 'Nomor dokumen, terbit otomatis & berurut.',
        'periode' => 'Periode akuntansi (YYYY-MM).',

        // Identitas & kontak
        'nama' => 'Nama.',
        'kode' => 'Kode — kunci utama.',
        'alamat' => 'Alamat.',
        'telepon' => 'Nomor telepon.',
        'email' => 'Alamat surel.',
        'jabatan' => 'Jabatan.',
        'judul' => 'Judul.',
        'aktif' => 'Sedang berlaku atau tidak.',

        // Waktu
        'tahun' => 'Tahun.',
        'bulan' => 'Bulan (1–12).',
        'waktu' => 'Waktu kejadian.',
        'jatuh_tempo' => 'Tanggal jatuh tempo.',
        'tanggal_mulai' => 'Tanggal mulai berlaku.',
        'tanggal_selesai' => 'Tanggal berakhir.',
        'tanggal_jatuh_tempo' => 'Tanggal jatuh tempo.',

        // Nilai & barang
        'total' => 'Jumlah seluruh baris.',
        'saldo' => 'Saldo saat ini.',
        'debet' => 'Nilai sisi debet.',
        'kredit' => 'Nilai sisi kredit.',
        'kuantiti' => 'Jumlah satuan.',
        'harga_satuan' => 'Harga per satuan.',
        'harga_perolehan' => 'Harga perolehan.',
        'satuan' => 'Satuan barang.',
        'kode_persediaan' => 'Barang persediaan yang bergerak.',

        // Rujukan antar-dokumen
        'referensi' => 'Rujukan dokumen luar.',
        'nomor_referensi' => 'Nomor rujukan dokumen.',
        'nomor_dokumen' => 'Nomor dokumen asal.',
        'ref' => 'Rujukan dokumen yang dimaksud.',
        'modul' => 'Modul asal dokumen.',
        'jenis_dokumen' => 'Jenis dokumen yang dimaksud.',
        'id_record' => 'Baris yang dimaksud pada modul tersebut.',
        'payload' => 'Isi perubahan yang diusulkan, dalam JSON.',
        'ringkasan' => 'Ringkasan untuk dibaca penyetuju.',
        'alasan' => 'Alasan — wajib diisi.',
        'alasan_tolak' => 'Alasan penolakan.',
        'aksi' => 'Tindakan yang dilakukan.',
        'metode' => 'Cara/metode yang dipakai.',
        'entry_id' => 'Kepala jurnal pemilik baris ini.',

        // Rekening & akun (salinan nama)
        'bank' => 'Nama bank.',
        'nama_bank' => 'Nama bank.',
        'no_rekening' => 'Nomor rekening.',
        'atas_nama' => 'Nama pemilik rekening.',
        'nama_rekening' => 'Nama rekening sebagaimana disebut di layar.',
        'jenis_saldo' => 'Sisi yang menambah saldo: debet atau kredit.',
        'nama_coa_debet' => 'Salinan nama akun yang didebet.',
        'nama_coa_kredit' => 'Salinan nama akun yang dikredit.',
        'nama_coa_uang_muka' => 'Salinan nama akun uang muka.',
        'nama_coa_realisasi' => 'Salinan nama akun realisasi.',
        'kode_coa_uang_muka' => 'Akun uang muka (aset).',

        // Pelaku & jejak
        'nama_pengguna' => 'Salinan nama pelakunya, agar jejaknya tetap terbaca walau akunnya dihapus.',
        'nama_pemohon' => 'Salinan nama pemohon.',
        'nama_penyetuju' => 'Salinan nama penyetuju.',
        'decided_by' => 'Yang memutuskan.',
        'decided_at' => 'Waktu keputusan.',
        'diunggah_oleh' => 'Pengguna yang mengunggah.',
        'diverifikasi_pada' => 'Waktu diverifikasi.',
        'ip_address' => 'Alamat IP.',
        'user_agent' => 'Perangkat/peramban yang dipakai.',
        'locked_at' => 'Waktu dikunci.',
        'closed_at' => 'Waktu ditutup.',
        'disusun_oleh' => 'Penyusun dokumen.',
        'dibuat_oleh' => 'Pembuat baris.',
        'ditetapkan_pada' => 'Waktu ditetapkan.',
        'disetujui_pada' => 'Waktu disetujui.',
        'diterbitkan_oleh' => 'Yang menerbitkan.',
        'dikoreksi_oleh' => 'Yang mengoreksi.',
        'dinilai_oleh' => 'Yang menilai.',
        'dibatalkan_oleh' => 'Yang membatalkan.',
        'mime' => 'Jenis isi berkas.',
        'ukuran' => 'Ukuran berkas dalam byte.',

        // "Nama X" — salinan nama supaya cetakan lama tak ikut berubah.
        'kode_grup' => 'Kode kelompok akun.',
        'nama_grup' => 'Nama kelompok akun.',
        'nama_unit' => 'Nama unit bisnis.',
        'nama_vendor' => 'Nama vendor.',
        'nama_customer' => 'Nama customer.',
        'nama_aset' => 'Nama aset.',
        'nama_persediaan' => 'Nama barang.',
        'nama_level' => 'Nama level.',
        'nama_bagian' => 'Nama bagian.',
        'nama_flow' => 'Nama rantai persetujuan.',
        'nama_perusahaan' => 'Nama lembaga sebagaimana tercetak di dokumen.',
        'kode_vendor' => 'Vendor yang bersangkutan.',
        'kode_customer' => 'Customer yang bersangkutan.',
        'kode_jenis_vendor' => 'Jenis vendor.',
        'kode_jenis_customer' => 'Jenis customer.',
        'kode_flow' => 'Rantai persetujuan yang dipakai.',
        'kode_transaksi' => 'Kunci utama voucher.',
        'nomor_transaksi' => 'Nomor cetak voucher.',
        'kode_jalur' => 'Jalur pendaftaran.',
        'gelombang' => 'Gelombang pendaftaran.',
        'tingkat' => 'Tingkat/kelas.',
        'id_pengajuan' => 'Pengajuan induk.',
        'id_invoice' => 'Invoice yang dirujuk.',
        'id_po' => 'Purchase order induk.',
        'nomor_po' => 'Nomor PO.',
        'tanggal_po' => 'Tanggal PO diterbitkan.',
        'tanggal_invoice' => 'Tanggal invoice diterbitkan.',
        'id_pinjaman' => 'Pinjaman induk.',
        'id_accrue' => 'Kunci utama dokumen accrue.',
        'id_rekonsiliasi' => 'Sesi rekonsiliasi induk.',
        'id_instance' => 'Dokumen berjalan yang dicatat jejaknya.',
        'id_rencana' => 'Rencana angsuran induk.',
        'id_term_template' => 'Versi naskah S&K yang dipakai.',
        'nomor_kontrak' => 'Nomor kontrak dari bank.',
        'kode_kategori' => 'Kode kategori — kunci utama.',
        'kategori_aset' => 'Kategori aset.',
        'kode_aset' => 'Aset yang dimaksud.',
        'tanggal_perolehan' => 'Tanggal aset diperoleh.',
        'kode_level' => 'Kode level — kunci utama.',
        'pesan' => 'Isi pemberitahuan.',
        'format' => 'Pola penomoran yang dipakai.',
        'tanggal_lulus' => 'Tanggal kelulusan.',
        'diotorisasi_pada' => 'Waktu diotorisasi.',
        'catatan_otorisasi' => 'Catatan pejabat saat mengotorisasi.',
        'ditutup_pada' => 'Waktu dinyatakan selesai.',
        'alasan_tutup' => 'Alasan penutupan.',
        'dijalankan_oleh' => 'Yang menjalankan.',
        'dijalankan_pada' => 'Waktu dijalankan.',
        'no_rekening_lama' => 'Nomor rekening sebelum diubah.',
        'atas_nama_lama' => 'Pemilik rekening sebelum diubah.',
        'no_rekening_baru' => 'Nomor rekening sesudah diubah.',
        'atas_nama_baru' => 'Pemilik rekening sesudah diubah.',
        'penanda_tangan_telepon' => 'Telepon penanda tangan.',
        'pilihan_hari' => 'Daftar rentang hari yang tersedia di penyaring.',
        'default_hari' => 'Rentang hari yang terpilih otomatis.',
    ],

    // =====================================================================
    // Bagian dokumen. Urutannya = urutan di PDF.
    // =====================================================================
    'bagian' => [

        // -------------------------------------------------------------
        [
            'judul' => 'Akuntansi Inti',
            'ringkas' => 'Kerangka pembukuan: bagan akun, jurnal double-entry, periode, dan dimensi unit bisnis. Seluruh modul lain bermuara ke sini.',
            'tabel' => [

                'coa_groups' => [
                    'judul' => 'Kelompok Akun (Chart of Accounts)',
                    'fungsi' => 'Hierarki kelompok akun berjenjang lewat `kode_induk` yang menunjuk dirinya sendiri. Kelompok tidak pernah menampung angka; ia hanya wadah bagi akun detail di bawahnya.',
                    'kolom' => [
                        'kode_induk' => 'Kelompok di atasnya. Kosong = kelompok puncak (Aset, Liabilitas, Modal, Pendapatan, Beban).',
                        'level' => 'Kedalaman kelompok, 1 = puncak.',
                    ],
                ],
                'coa_detail' => [
                    'judul' => 'Akun Detail',
                    'fungsi' => 'Akun tingkat terakhir — satu-satunya yang boleh dijurnal. Setiap baris jurnal wajib menunjuk ke sini.',
                    'catatan' => 'PK-nya `kode_coa` (string), bukan `id`. `jenis_saldo` menentukan sisi mana yang menambah saldo, dan salah mengisinya membuat laporan terbalik tanpa pesan galat.',
                    'kolom' => [
                        'kode_coa' => 'Kode akun — kunci utama, dipakai seluruh aplikasi.',
                        'jenis_saldo' => '`debet` atau `kredit` — sisi yang MENAMBAH saldo akun ini.',
                        'kode_grup' => 'Kelompok induk akun.',
                    ],
                ],
                'journal_entries' => [
                    'judul' => 'Jurnal — Kepala',
                    'fungsi' => 'Kepala jurnal double-entry. Setiap dokumen keuangan yang berdampak akuntansi melahirkan satu baris di sini beserta baris-baris detailnya, dan totalnya wajib seimbang (Σdebet = Σkredit).',
                    'catatan' => 'Jurnal TIDAK PERNAH dihapus. Pembatalan dilakukan dengan menerbitkan jurnal balik yang menunjuk aslinya lewat `reversal_of`.',
                    'kolom' => [
                        'referensi' => 'Nomor jurnal.',
                        'sumber_modul' => 'Modul asal (kas keluar, invoice, pembayaran santri, …) — dipakai menelusuri balik ke dokumennya.',
                        'id_sumber' => 'Nomor dokumen asal di modul tersebut.',
                        'reversal_of' => 'Jurnal yang dibalik oleh baris ini. Terisi = baris ini adalah jurnal pembatalan.',
                    ],
                ],
                'journal_lines' => [
                    'judul' => 'Jurnal — Baris',
                    'fungsi' => 'Baris debet/kredit sebuah jurnal. Di sinilah angka sesungguhnya tersimpan, lengkap dengan dimensi bagian & unit bisnis untuk pelaporan tersegmen.',
                    'kolom' => [
                        'entry_id' => 'Kepala jurnal pemilik baris ini.',
                        'debet' => 'Nilai sisi debet (0 bila baris ini kredit).',
                        'kredit' => 'Nilai sisi kredit (0 bila baris ini debet).',
                        'kode_persediaan' => 'Barang persediaan, bila baris ini menggerakkan stok.',
                        'kuantiti' => 'Jumlah satuan barang yang bergerak.',
                    ],
                ],
                'accrues' => [
                    'judul' => 'Accrue & Prepaid',
                    'fungsi' => 'Jurnal penyesuaian yang dibuat manual: biaya yang sudah terjadi tapi belum dibayar (accrue), dan biaya dibayar di muka yang mulai diakui (prepaid). Akun debet & kreditnya dipilih sendiri oleh penginput.',
                    'kolom' => [
                        'kode_coa_debet' => 'Akun yang didebet.',
                        'kode_coa_kredit' => 'Akun yang dikredit.',
                        'nomor_referensi' => 'Nomor dokumen accrue.',
                        'saldo_awal' => 'Dokumen pindahan sistem: dicatat TANPA jurnal. Nilainya masuk buku besar lewat baris turunan di menu Saldo Awal — dan hanya sisi neracanya, karena bebannya milik periode lalu.',
                    ],
                ],
                'accounting_periods' => [
                    'judul' => 'Periode Akuntansi (Tutup Buku)',
                    'fungsi' => 'Menandai bulan mana yang sudah ditutup. Periode tertutup menolak jurnal baru — pengaman agar laporan yang sudah dilaporkan tak berubah diam-diam di belakang.',
                    'kolom' => [
                        'status' => 'Terbuka atau tertutup.',
                        'closed_by' => 'Pengguna yang menutup periode.',
                        'nama_closed_by' => 'Salinan namanya, agar tetap terbaca walau akunnya kelak dihapus.',
                        'reopened_at' => 'Waktu periode dibuka kembali, bila pernah.',
                    ],
                ],
                'opening_balances' => [
                    'judul' => 'Saldo Awal',
                    'fungsi' => 'Saldo pembuka tiap akun saat aplikasi mulai dipakai — jembatan dari pembukuan lama. Sekali diposting, ia menjadi jurnal biasa.',
                    'kolom' => [
                        'posted' => 'Sudah dijurnal atau masih rancangan.',
                        'saldo' => 'Nilai saldo awal akun.',
                    ],
                ],
                'business_units' => [
                    'judul' => 'Unit Bisnis',
                    'fungsi' => 'Dimensi pelaporan: satu pesantren bisa memisahkan hasil usaha per unit (SD, SMP, koperasi, dsb.). Hampir semua transaksi membawa kode unit.',
                    'catatan' => 'PK-nya `kode_unit` (string).',
                ],
                'unit_defaults' => [
                    'judul' => 'Unit Bisnis Bawaan per Modul',
                    'fungsi' => 'Unit yang dipakai bila sebuah modul menerbitkan jurnal tanpa unit eksplisit. Mencegah jurnal lahir tanpa dimensi — yang membuat laporan per unit tak pernah menjumlah utuh.',
                    'kolom' => ['sumber_modul' => 'Modul asal jurnal — sekaligus kunci utama tabel ini.'],
                ],
                'company_settings' => [
                    'judul' => 'Pengaturan Lembaga',
                    'fungsi' => 'Baris tunggal (id = 1) berisi identitas pesantren dan setelan pembukuan yang berlaku menyeluruh.',
                    'kolom' => [
                        'periode_awal_pembukuan' => 'Bulan pertama pembukuan; jurnal sebelum tanggal ini ditolak.',
                        'bulan_awal_anggaran' => 'Bulan pertama tahun anggaran (bisa berbeda dari Januari).',
                        'topup_tunai_dompet_santri' => 'Boleh/tidaknya dompet santri diisi tunai di loket.',
                        'kode_unit_neraca' => 'Unit penampung pos neraca (liabilitas & rekening kas) yang tak melekat pada unit mana pun.',
                        'jenis_perusahaan' => 'Bentuk badan hukum lembaga.',
                        'npwp' => 'Nomor pokok wajib pajak.',
                        'mata_uang' => 'Mata uang pembukuan.',
                    ],
                ],
                'akun_pengurang_dana_bebas' => [
                    'judul' => 'Akun Pengurang Dana Bebas',
                    'fungsi' => 'Daftar akun yang saldonya TIDAK boleh ikut dihitung sebagai dana yang bebas dipakai — terutama titipan santri & wali. Dipakai Perintah Pembayaran untuk menolak pembayaran yang melampaui dana bebas.',
                    'catatan' => 'Tabel kecil tapi berdampak besar: bila kosong, dana bebas terhitung terlalu besar karena uang titipan ikut dianggap boleh dipakai.',
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Kas & Bank',
            'ringkas' => 'Penerimaan dan pengeluaran uang yang sesungguhnya, beserta pencocokannya dengan rekening koran.',
            'tabel' => [

                'bank_accounts' => [
                    'judul' => 'Kas & Rekening',
                    'fungsi' => 'Daftar tempat uang disimpan: kas tunai maupun rekening bank. Tiap baris MELEKAT pada satu akun COA — kunci utamanya memang `kode_coa` itu sendiri.',
                    'catatan' => 'Tabel inilah yang menentukan sebuah akun dianggap "kas", bukan awalan kodenya. Perhitungan arus kas & dana bebas membacanya dari sini.',
                    'kolom' => [
                        'kode_coa' => 'Akun kas/bank — kunci utama sekaligus kunci asing ke akun detail.',
                        'jenis_rekening' => 'Tunai atau bank.',
                    ],
                ],
                'cash_in' => [
                    'judul' => 'Kas Masuk — Kepala',
                    'fungsi' => 'Voucher penerimaan uang, boleh berisi beberapa baris sekaligus. Menerbitkan jurnal saat diposting.',
                    'kolom' => [
                        'kode_transaksi' => 'Kunci utama voucher.',
                        'nomor_transaksi' => 'Nomor cetak voucher.',
                        'referensi' => 'Rujukan dokumen luar (nomor kuitansi, bukti transfer).',
                    ],
                ],
                'cash_in_details' => [
                    'judul' => 'Kas Masuk — Baris',
                    'fungsi' => 'Rincian penerimaan per akun. Satu voucher bisa memuat beberapa sumber penerimaan yang berbeda sifatnya.',
                    'kolom' => [
                        'jenis_kas_masuk' => 'Sifat penerimaan (uang muka customer, pelunasan piutang, pendapatan langsung, …) — menentukan akun lawan jurnalnya.',
                        'status_pengakuan' => 'Sudah diakui sebagai pendapatan atau masih tertahan sebagai titipan.',
                        'kode_persediaan' => 'Barang yang keluar, bila penerimaan ini penjualan barang.',
                    ],
                ],
                'cash_out' => [
                    'judul' => 'Kas Keluar — Kepala',
                    'fungsi' => 'Voucher pengeluaran uang. Inilah dokumen yang benar-benar mengurangi kas — pengajuan dan perintah pembayaran sebelumnya hanyalah rencana.',
                    'kolom' => [
                        'kode_transaksi' => 'Kunci utama voucher.',
                        'id_bank_loan' => 'Pembiayaan bank yang diangsur, bila pengeluaran ini angsuran.',
                        'id_perintah' => 'Perintah Pembayaran yang direalisasikan voucher ini.',
                        'metode' => 'Cara bayar (tunai/transfer).',
                    ],
                ],
                'cash_out_details' => [
                    'judul' => 'Kas Keluar — Baris',
                    'fungsi' => 'Rincian pengeluaran per akun, sekaligus penghubung ke dokumen yang dilunasinya.',
                    'kolom' => [
                        'tipe' => 'Sifat baris: pelunasan invoice, pelunasan pengajuan, atau pengeluaran langsung.',
                        'id_invoice' => 'Invoice vendor yang dilunasi baris ini.',
                        'id_pengajuan' => 'Pengajuan pembayaran yang dilunasi baris ini.',
                        'id_perintah_detail' => 'Baris Perintah Pembayaran yang direalisasikan.',
                    ],
                ],
                'bank_reconciliations' => [
                    'judul' => 'Rekonsiliasi Bank — Kepala',
                    'fungsi' => 'Satu sesi pencocokan saldo buku dengan saldo rekening koran pada tanggal tertentu.',
                    'kolom' => [
                        'saldo_bank' => 'Saldo menurut rekening koran.',
                        'saldo_buku' => 'Saldo menurut pembukuan.',
                    ],
                ],
                'bank_reconciliation_items' => [
                    'judul' => 'Rekonsiliasi Bank — Baris',
                    'fungsi' => 'Baris jurnal akun bank yang dicocokkan, ditandai sudah cair (cleared) atau belum. Selisih yang tersisa menjelaskan beda saldo buku dan bank.',
                    'kolom' => [
                        'cleared' => 'Sudah muncul di rekening koran.',
                        'is_adjustment' => 'Baris penyesuaian yang dibuat saat rekonsiliasi, bukan berasal dari jurnal.',
                        'journal_line_id' => 'Baris jurnal yang dicocokkan.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Pembiayaan & Pinjaman',
            'ringkas' => 'Hutang lembaga kepada bank, dan piutang lembaga kepada karyawan.',
            'tabel' => [

                'bank_loans' => [
                    'judul' => 'Pembiayaan Bank',
                    'fungsi' => 'Pokok pembiayaan/pinjaman bank berakad syariah beserta marginnya. Sisa pokok dihitung dari pokok awal dikurangi yang sudah terbayar lewat Kas Keluar.',
                    'kolom' => [
                        'jenis_akad' => 'Akad pembiayaan yang dipakai.',
                        'pokok_awal' => 'Pokok pembiayaan saat akad.',
                        'margin' => 'Margin/bagi hasil yang disepakati.',
                        'tenor_bulan' => 'Jangka waktu dalam bulan.',
                        'pokok_terbayar' => 'Akumulasi pokok yang sudah diangsur.',
                        'kode_coa_hutang' => 'Akun liabilitas pembiayaan.',
                        'kode_coa_beban_bunga' => 'Akun beban margin.',
                        'saldo_awal' => 'Pembiayaan yang uangnya cair SEBELUM pindah sistem — dicatat tanpa jurnal pencairan. Hutangnya masuk buku besar lewat baris turunan di menu Saldo Awal.',
                    ],
                ],
                'pinjaman_karyawan' => [
                    'judul' => 'Pinjaman Karyawan',
                    'fungsi' => 'Piutang lembaga kepada pegawai. Pokok dicairkan sekali, lalu dicicil menurut termin yang disepakati.',
                    'kolom' => [
                        'kode_karyawan' => 'Pegawai peminjam.',
                        'pokok' => 'Nilai pinjaman.',
                        'terbayar' => 'Akumulasi yang sudah dikembalikan.',
                        'kode_coa_piutang' => 'Akun piutang karyawan.',
                        'saldo_awal' => 'Pinjaman yang uangnya diserahkan SEBELUM pindah sistem — dicatat tanpa jurnal pencairan. Piutangnya masuk buku besar lewat baris turunan di menu Saldo Awal.',
                    ],
                ],
                'termin_pinjaman_karyawan' => [
                    'judul' => 'Termin Pinjaman Karyawan',
                    'fungsi' => 'Jadwal angsuran sebuah pinjaman: berapa dan kapan jatuh temponya.',
                    'kolom' => ['jatuh_tempo' => 'Tanggal angsuran ini jatuh tempo.'],
                ],
                'pembayaran_pinjaman_karyawan' => [
                    'judul' => 'Pembayaran Pinjaman Karyawan',
                    'fungsi' => 'Setoran pengembalian pinjaman, baik tunai maupun potong gaji.',
                    'kolom' => [
                        'cara' => 'Tunai/transfer atau potong gaji — menentukan akun lawan jurnalnya.',
                        'kode_coa_lawan' => 'Akun lawan bila pembayaran bukan lewat kas.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Pembelian, Hutang & Uang Muka',
            'ringkas' => 'Rantai belanja: vendor → pesanan → invoice → perintah bayar → kas keluar. Termasuk uang muka belanja operasional dan penyelesaiannya.',
            'tabel' => [

                'vendor_types' => [
                    'judul' => 'Jenis Vendor',
                    'fungsi' => 'Pengelompokan vendor (pemasok barang, jasa, kontraktor, …).',
                ],
                'vendors' => [
                    'judul' => 'Vendor',
                    'fungsi' => 'Master pemasok beserta cara & tempo pembayarannya, dan rekening tujuan transfernya.',
                    'kolom' => [
                        'metode_pembayaran' => 'Tunai atau termin (berjangka).',
                        'termin_hari' => 'Tempo hutang dalam hari, bila termin.',
                        'atas_nama' => 'Nama pemilik rekening tujuan.',
                    ],
                ],
                'purchase_orders' => [
                    'judul' => 'Purchase Order — Kepala',
                    'fungsi' => 'Pesanan pembelian kepada vendor. TIDAK menerbitkan jurnal apa pun — ia baru komitmen, bukan kewajiban.',
                    'kolom' => ['total_po' => 'Nilai seluruh baris pesanan.'],
                ],
                'purchase_order_details' => [
                    'judul' => 'Purchase Order — Baris',
                    'fungsi' => 'Rincian barang/jasa yang dipesan. `qty_invoiced` menjaga agar satu baris pesanan tak ditagih vendor melebihi yang dipesan.',
                    'kolom' => [
                        'kuantiti' => 'Jumlah yang dipesan.',
                        'qty_invoiced' => 'Jumlah yang sudah tertagih lewat invoice.',
                    ],
                ],
                'invoices' => [
                    'judul' => 'Invoice Vendor — Kepala',
                    'fungsi' => 'Tagihan dari vendor — inilah yang MENGAKUI HUTANG dan menerbitkan jurnal. Boleh merujuk PO, boleh berdiri sendiri.',
                    'kolom' => [
                        'nomor_invoice' => 'Nomor menurut vendor.',
                        'nomor_ref_internal' => 'Nomor internal lembaga.',
                        'sisa_hutang' => 'Yang masih harus dibayar; berkurang tiap kali dilunasi Kas Keluar.',
                        'kode_coa_hutang' => 'Akun liabilitas yang dikredit.',
                    ],
                ],
                'invoice_details' => [
                    'judul' => 'Invoice Vendor — Baris',
                    'fungsi' => 'Rincian tagihan per akun beban atau per barang persediaan.',
                ],
                'operational_advances' => [
                    'judul' => 'Uang Muka Operasional',
                    'fungsi' => 'Uang yang diserahkan lebih dulu kepada seseorang untuk berbelanja. Tercatat sebagai ASET (piutang) sampai dipertanggungjawabkan.',
                    'kolom' => [
                        'nomor_ref' => 'Nomor dokumen uang muka.',
                        'penerima' => 'Orang yang memegang uangnya.',
                        'nominal_diselesaikan' => 'Bagian yang sudah dipertanggungjawabkan; sisanya = nominal − ini.',
                        'id_pengajuan_sumber' => 'Pengajuan uang muka yang melahirkannya, bila lewat jalur pengajuan.',
                        'saldo_awal' => 'Uang muka yang diserahkan SEBELUM pindah sistem — didaftarkan ke pool tanpa jurnal, karena kas keluarnya sudah terjadi di pembukuan lama. Masuk buku besar lewat baris turunan di menu Saldo Awal.',
                    ],
                ],
                'advance_settlements' => [
                    'judul' => 'Penyelesaian Uang Muka',
                    'fungsi' => 'Pertanggungjawaban belanja atas uang muka: realisasi dibandingkan dengan uang muka yang dipegang, selisihnya dikembalikan atau ditambah.',
                    'kolom' => [
                        'nominal_uang_muka' => 'Nilai uang muka yang diselesaikan.',
                        'nominal_realisasi' => 'Nilai belanja yang benar-benar terjadi.',
                        'kode_coa_realisasi' => 'Akun beban tujuan realisasi.',
                        'kode_rekening' => 'Kas penampung selisih.',
                        'id_uang_muka' => 'Uang muka yang diselesaikan.',
                    ],
                ],
                'perintah_pembayaran' => [
                    'judul' => 'Perintah Pembayaran — Kepala',
                    'fungsi' => 'Dokumen KAS, bukan dokumen akuntansi: daftar kewajiban yang diperintahkan untuk dibayar pada satu tanggal. Tidak menerbitkan jurnal — Kas Keluar yang melakukannya.',
                    'catatan' => 'Otorisasinya boleh sebagian: pejabat dapat menyetujui sebagian baris dan menunda sisanya. Penutupan dokumen tak pernah otomatis.',
                    'kolom' => [
                        'tanggal_usulan' => 'Tanggal dokumen diusulkan.',
                        'tanggal_bayar' => 'Tanggal pembayaran direncanakan.',
                        'kode_rekening_rencana' => 'Kas/rekening yang direncanakan dipakai.',
                        'total_diajukan' => 'Jumlah seluruh baris yang diusulkan.',
                        'total_diotorisasi' => 'Jumlah yang benar-benar disetujui pejabat.',
                        'disusun_oleh' => 'Penyusun dokumen.',
                        'diotorisasi_oleh' => 'Pejabat pengotorisasi.',
                        'ditutup_oleh' => 'Yang menyatakan perintah ini selesai.',
                    ],
                ],
                'perintah_pembayaran_detail' => [
                    'judul' => 'Perintah Pembayaran — Baris',
                    'fungsi' => 'Satu kewajiban yang diperintahkan dibayar, menunjuk dokumen asalnya (invoice, pengajuan, angsuran, …) lewat pasangan `sumber` + `id_dokumen`.',
                    'kolom' => [
                        'sumber' => 'Jenis dokumen asal kewajiban.',
                        'id_dokumen' => 'Nomor dokumen asal.',
                        'pihak' => 'Kepada siapa dibayarkan.',
                        'nominal_diajukan' => 'Nilai yang diusulkan penyusun.',
                        'nominal_diotorisasi' => 'Nilai yang disetujui pejabat — boleh lebih kecil.',
                        'terbayar' => 'Yang sudah direalisasikan lewat Kas Keluar.',
                        'sisa' => 'Sisa yang belum terbayar.',
                        'status_baris' => 'Keadaan baris ini sendiri, terpisah dari status dokumennya.',
                        'ditambahkan_pengotorisasi' => 'Baris yang disisipkan pejabat saat mengotorisasi, bukan oleh penyusun.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Customer & Piutang',
            'ringkas' => 'Pihak luar yang berhutang kepada lembaga (di luar santri, yang punya jalurnya sendiri).',
            'tabel' => [
                'customer_types' => [
                    'judul' => 'Jenis Customer',
                    'fungsi' => 'Pengelompokan customer.',
                ],
                'customers' => [
                    'judul' => 'Customer',
                    'fungsi' => 'Master pelanggan beserta akun pendapatan & piutang bawaannya, sehingga transaksi tak perlu memilih akun berulang kali.',
                    'kolom' => [
                        'kode_coa_pendapatan' => 'Akun pendapatan bawaan untuk customer ini.',
                        'kode_coa_piutang' => 'Akun piutang bawaan untuk customer ini.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Aset & Persediaan',
            'ringkas' => 'Barang milik lembaga: yang disusutkan (aset tetap) dan yang habis dipakai (persediaan).',
            'tabel' => [
                'asset_categories' => [
                    'judul' => 'Kategori Aset',
                    'fungsi' => 'Pengelompokan aset tetap (kendaraan, bangunan, peralatan, …).',
                ],
                'assets' => [
                    'judul' => 'Aset Tetap',
                    'fungsi' => 'Barang bernilai besar yang disusutkan selama umur manfaatnya.',
                    'kolom' => [
                        'kode_aset' => 'Kode aset — kunci utama.',
                        'umur_manfaat' => 'Umur ekonomis dalam bulan/tahun sesuai kebijakan.',
                        'metode_depresiasi' => 'Garis lurus atau saldo menurun.',
                        'nilai_residu' => 'Nilai sisa yang tak ikut disusutkan.',
                        'akumulasi_depresiasi' => 'Penyusutan yang sudah dibebankan sampai kini.',
                        'sumber_ref' => 'Dokumen asal perolehan.',
                        'saldo_awal' => 'Aset yang sudah dimiliki SEBELUM pindah sistem. Pencatatan aset memang tak pernah menjurnal — yang menjurnal hanya depresiasi bulanan — jadi kolom ini satu-satunya pembeda dari aset yang dibeli lewat Kas Keluar, yang nilainya sudah masuk buku besar dari sisi pembayarannya.',
                    ],
                ],
                'asset_movements' => [
                    'judul' => 'Penambahan Nilai Aset',
                    'fungsi' => 'Tambahan nilai perolehan pada aset yang sudah ada — perbaikan besar atau penambahan unit — agar dasar penyusutannya ikut naik.',
                    'kolom' => [
                        'sumber_modul' => 'Modul asal penambahan.',
                        'sumber_ref' => 'Nomor dokumen asal.',
                    ],
                ],
                'inventory' => [
                    'judul' => 'Persediaan',
                    'fungsi' => 'Barang yang keluar-masuk gudang. Stok berjalan = stok_masuk − stok_keluar; keduanya digerakkan oleh baris kas/invoice yang menyebut kode barang.',
                    'kolom' => [
                        'kode_persediaan' => 'Kode barang — kunci utama.',
                        'harga_perolehan' => 'Harga pokok satuan terakhir.',
                        'stok_masuk' => 'Akumulasi barang masuk.',
                        'stok_keluar' => 'Akumulasi barang keluar.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Anggaran',
            'ringkas' => 'Rencana belanja setahun per akun, per bulan, per bagian — dan usulan yang menyusunnya dari bawah.',
            'tabel' => [
                'budgets' => [
                    'judul' => 'Anggaran',
                    'fungsi' => 'Angka anggaran yang berlaku: satu baris per akun per bulan per bagian per unit. Pengajuan pembayaran diuji terhadap baris inilah untuk menentukan overbudget atau belum dianggarkan.',
                ],
                'anggaran_kunci' => [
                    'judul' => 'Kunci Anggaran',
                    'fungsi' => 'Menandai tahun anggaran yang sudah dikunci sehingga angkanya tak bisa diubah lagi. Kunci utamanya `tahun` — ada barisnya berarti terkunci.',
                    'kolom' => [
                        'tahun' => 'Tahun anggaran yang dikunci — sekaligus kunci utama.',
                        'locked_by' => 'Yang mengunci.',
                    ],
                ],
                'budget_pengajuan' => [
                    'judul' => 'Usulan Anggaran — Kepala',
                    'fungsi' => 'Usulan anggaran dari sebuah bagian untuk satu tahun, melewati rantai persetujuan sebelum menjadi angka yang berlaku.',
                    'kolom' => ['bulan_awal' => 'Bulan pertama yang diusulkan.'],
                ],
                'budget_pengajuan_detail' => [
                    'judul' => 'Usulan Anggaran — Baris',
                    'fungsi' => 'Rincian usulan per akun per bulan.',
                    'kolom' => ['bulan' => 'Bulan yang diusulkan.'],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Pengajuan & Rantai Persetujuan',
            'ringkas' => 'Jalur permohonan pembayaran dari staf sampai disetujui & diverifikasi keuangan, beserta mesin persetujuan yang dipakai bersama oleh beberapa modul.',
            'tabel' => [
                'pengajuan_pembayaran' => [
                    'judul' => 'Pengajuan Pembayaran',
                    'fungsi' => 'Permohonan pembayaran dari staf. Satu tabel melayani tiga jenis sekaligus lewat kolom `jenis`: pembayaran biasa, pengajuan uang muka, dan penyelesaian uang muka.',
                    'catatan' => 'Alur: dibuat staf → rantai persetujuan → verifikasi keuangan (menerbitkan jurnal hutang) → dibayar lewat Kas Keluar.',
                    'kolom' => [
                        'jenis' => '`pembayaran`, `uang_muka`, atau `penyelesaian_uang_muka`.',
                        'kode_coa_hutang' => 'Akun liabilitas, ditetapkan keuangan saat verifikasi.',
                        'sisa_hutang' => 'Sisa kewajiban dokumen ini. Pada penyelesaian uang muka kolom ini menyimpan nilai uang muka yang diselesaikan, bukan kekurangannya.',
                        'sisa_kurang_bayar' => 'Kekurangan yang masih harus dibayar pada dokumen penyelesaian uang muka.',
                        'id_uang_muka' => 'Uang muka yang diselesaikan, untuk jenis penyelesaian.',
                        'bank_tujuan' => 'Bank rekening tujuan transfer.',
                        'no_rekening_tujuan' => 'Nomor rekening tujuan.',
                        'atas_nama_tujuan' => 'Nama pemilik rekening tujuan.',
                        'referensi' => 'Rujukan dokumen pendukung.',
                        'saldo_awal' => 'Hutang yang sudah disetujui di pembukuan lama tetapi belum dicairkan saat pindah sistem. Lahir langsung berstatus `diposting` tanpa rantai persetujuan dan tanpa jurnal — keadaan yang di jalur normal mustahil, karena di sana status & `journal_entry_id` ditetapkan bersamaan.',
                    ],
                ],
                'pengajuan_pembayaran_detail' => [
                    'judul' => 'Pengajuan Pembayaran — Baris',
                    'fungsi' => 'Rincian permohonan per akun dan per unit bisnis. Pembagian unit di sinilah yang menentukan pembebanan anggarannya.',
                ],
                'pengajuan_rekening_riwayat' => [
                    'judul' => 'Riwayat Perubahan Rekening Tujuan',
                    'fungsi' => 'Jejak setiap penggantian rekening tujuan pembayaran, lengkap dengan alasan dan pelakunya.',
                    'catatan' => 'Ada karena penggantian rekening senyap sesudah dokumen disetujui adalah modus penipuan pembayaran yang paling umum.',
                    'kolom' => [
                        'bank_lama' => 'Bank sebelum diubah.',
                        'bank_baru' => 'Bank sesudah diubah.',
                        'alasan' => 'Alasan penggantian — wajib diisi.',
                    ],
                ],
                'rekening_tersimpan' => [
                    'judul' => 'Buku Rekening Pemohon',
                    'fungsi' => 'Rekening tujuan yang disimpan seorang pemohon untuk dipakai lagi di pengajuan berikutnya. Milik masing-masing pengguna, tidak pernah terlihat oleh pemohon lain.',
                ],
                'approval_flows' => [
                    'judul' => 'Rantai Persetujuan — Definisi',
                    'fungsi' => 'Satu rantai persetujuan untuk satu jenis dokumen. Mesin persetujuan ini dipakai bersama oleh pengajuan pembayaran maupun usulan anggaran.',
                    'kolom' => ['jenis_dokumen' => 'Jenis dokumen yang memakai rantai ini.'],
                ],
                'approval_steps' => [
                    'judul' => 'Rantai Persetujuan — Tahap',
                    'fungsi' => 'Satu tahap dalam rantai: siapa yang berwenang memutus, dan pada keadaan apa tahap ini muncul.',
                    'kolom' => [
                        'nama_tahap' => 'Nama tahap sebagaimana tampil di layar.',
                        'peringkat' => 'Peringkat pengguna yang berwenang.',
                        'fungsi' => 'Fungsi jabatan yang berwenang, sebagai alternatif peringkat.',
                        'scope' => 'Lingkup penyetuju: bagian sendiri, induknya, atau menyeluruh.',
                        'nominal_min' => 'Tahap ini hanya berlaku bila nominal dokumen mencapai angka ini.',
                        'syarat' => 'Syarat tambahan agar tahap ini diaktifkan.',
                    ],
                ],
                'approval_instances' => [
                    'judul' => 'Rantai Persetujuan — Dokumen Berjalan',
                    'fungsi' => 'Satu dokumen yang sedang atau sudah melewati rantai, beserta hasil pengujian anggarannya.',
                    'kolom' => [
                        'id_dokumen' => 'Nomor dokumen yang sedang diproses.',
                        'tahap_sekarang' => 'Tahap yang sedang menunggu keputusan.',
                        'overbudget' => 'Melebihi anggaran yang tersedia.',
                        'belum_dianggarkan' => 'Akun/bulan ini belum punya baris anggaran sama sekali.',
                        'id_pemohon' => 'Pengaju dokumen.',
                        'posted' => 'Sudah diverifikasi & dijurnal keuangan.',
                    ],
                ],
                'approval_logs' => [
                    'judul' => 'Rantai Persetujuan — Jejak Keputusan',
                    'fungsi' => 'Riwayat siapa memutus apa dan kapan. Nama penyetuju ikut disalin agar jejaknya tetap terbaca walau akunnya kelak dihapus.',
                ],
                'edit_approvals' => [
                    'judul' => 'Persetujuan Penyuntingan',
                    'fungsi' => 'Permohonan menyunting transaksi yang sudah terkunci. Perubahannya disimpan sebagai payload JSON dan baru diterapkan setelah disetujui.',
                    'catatan' => 'Service-nya sudah ada, tetapi pemicunya BELUM disambungkan ke controller mana pun — jadi tabel ini belum terisi di pemakaian normal.',
                    'kolom' => [
                        'modul' => 'Modul asal transaksi.',
                        'id_record' => 'Baris yang hendak disunting.',
                        'payload' => 'Isi perubahan yang diusulkan, dalam JSON.',
                        'ringkasan' => 'Ringkasan perubahan untuk dibaca penyetuju.',
                    ],
                ],
                'posting_approvals' => [
                    'judul' => 'Persetujuan Posting',
                    'fungsi' => 'Otorisasi atas input Accrue & Jurnal Umum yang melampaui wewenang penginputnya.',
                    'catatan' => 'Sama seperti persetujuan penyuntingan: belum tersambung ke alur berjalan.',
                ],
                'void_approvals' => [
                    'judul' => 'Persetujuan Pembatalan',
                    'fungsi' => 'Permohonan membatalkan transaksi yang melampaui wewenang pembatalnya.',
                    'catatan' => 'Menunya sudah dibuang dari sidebar pada 28 Juli 2026 karena fiturnya belum diport; tabel & service-nya sengaja dipertahankan untuk disambungkan kelak.',
                ],
                'lampiran_dokumen' => [
                    'judul' => 'Lampiran Dokumen Keuangan',
                    'fungsi' => 'Berkas pendukung dokumen keuangan: invoice, penawaran, nota, kwitansi, bukti transfer. Metadata di sini, isi berkasnya di penyimpanan.',
                    'catatan' => 'Polimorfik lewat pasangan `jenis_dokumen` + `id_dokumen`, sehingga satu tabel melayani pengajuan pembayaran, uang muka operasional, dan penyelesaian uang muka — serta modul mana pun berikutnya tanpa tabel baru. Konsekuensinya tak ada kunci asing ke tabel induk.',
                    'kolom' => [
                        'jenis_dokumen' => 'Jenis dokumen yang dilampiri.',
                        'id_dokumen' => 'Nomor dokumen yang dilampiri.',
                        'nama_asli' => 'Nama berkas menurut pengunggah.',
                        'path' => 'Letak berkas di penyimpanan.',
                        'disk' => 'Disk penyimpanan; disimpan per baris agar berkas lama tetap terbaca bila setelan disk kelak diubah.',
                        'mime' => 'Jenis isi berkas.',
                        'ukuran' => 'Ukuran berkas dalam byte.',
                        'hash_sha256' => 'Sidik jari isi berkas, untuk membuktikan berkas tak berubah.',
                        'diunggah_oleh' => 'Pengguna yang mengunggah.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Master Kesantrian & Biaya',
            'ringkas' => 'Kerangka yang harus terisi lebih dulu sebelum santri bisa ditagih: jenjang, tahun ajaran, jalur, gelombang, dan besaran biayanya.',
            'catatan' => 'Pemisahan terpenting di seluruh aplikasi ada di sini: `jenis_biaya` memegang IDENTITAS AKUNTANSI (nama, perilaku, akun, unit) TANPA nominal, sedangkan `tarif_biaya` memegang BESARANNYA per tahun ajaran, jenjang, dan jalur. Menyatukan keduanya dulu membuat tiap kenaikan tarif melahirkan akun baru.',
            'tabel' => [

                'jenjang' => [
                    'judul' => 'Jenjang Pendidikan',
                    'fungsi' => 'Master jenjang (SD, SMP, MI, MTs, …) beserta banyaknya tingkat di dalamnya dan jenjang lanjutannya.',
                    'catatan' => 'PK-nya `kode` (string). Kode berformat J001 dst.; layar & cetakan wajib menampilkan namanya, bukan kodenya.',
                    'kolom' => [
                        'kode' => 'Kode jenjang — kunci utama.',
                        'jumlah_tingkat' => 'Banyaknya tingkat/kelas dalam jenjang ini.',
                        'tingkat_mulai' => 'Tingkat pertama (mis. 1 untuk SD, 7 untuk SMP bila dinomori menerus).',
                        'kode_jenjang_lanjutan' => 'Jenjang tujuan saat santri lulus dari sini.',
                        'urutan' => 'Urutan tampil.',
                    ],
                ],
                'tahun_ajaran' => [
                    'judul' => 'Tahun Ajaran',
                    'fungsi' => 'Master tahun ajaran, mis. "2026/2027". Menjadi sumbu bagi tarif, tagihan, target, dan kenaikan tingkat.',
                    'catatan' => 'PK-nya `id` (integer), BUKAN kodenya — mencarinya harus lewat kolom `kode`. Ini jebakan yang paling sering menimbulkan galat tipe.',
                    'kolom' => [
                        'kode' => 'Label tahun ajaran, mis. 2026/2027.',
                        'default_pendaftaran' => 'Tahun ajaran yang dipilih otomatis pada form pendaftaran.',
                    ],
                ],
                'jalur_pendaftaran' => [
                    'judul' => 'Jalur Pendaftaran',
                    'fungsi' => 'Jalur masuk santri (reguler, tahfiz, beasiswa, …). Tarif dibedakan per jalur, jadi jalurnya harus ada sebelum tarif diisi.',
                    'kolom' => [
                        'kode' => 'Kode jalur — kunci utama.',
                        'kode_jalur_lanjutan' => 'Jalur yang berlaku setelah santri naik jenjang.',
                        'bebas_uang_pangkal' => 'Jalur ini tidak dipungut uang pangkal.',
                    ],
                ],
                'jalur_nonaktif' => [
                    'judul' => 'Jalur yang Ditutup',
                    'fungsi' => 'Menandai kombinasi tahun ajaran + jenjang + jalur yang sedang tidak dibuka, tanpa harus menonaktifkan jalurnya secara menyeluruh.',
                ],
                'gelombang' => [
                    'judul' => 'Gelombang Pendaftaran',
                    'fungsi' => 'Periode pendaftaran bertahap; makin awal mendaftar, makin besar potongan uang pangkalnya.',
                    'kolom' => [
                        'berlaku_mulai' => 'Awal masa gelombang.',
                        'berlaku_sampai' => 'Akhir masa gelombang.',
                        'masa_berlaku_hari' => 'Lama tenggat pemenuhan syarat potongan, dihitung dari pendaftaran.',
                    ],
                ],
                'potongan_gelombang' => [
                    'judul' => 'Matriks Potongan Gelombang',
                    'fungsi' => 'Besaran potongan uang pangkal per gelombang per jenjang.',
                    'kolom' => ['potongan' => 'Nilai potongan yang berlaku.'],
                ],
                'sumber_informasi' => [
                    'judul' => 'Sumber Informasi PPSB',
                    'fungsi' => 'Dari mana wali mengetahui pesantren — untuk menilai jalur promosi mana yang berhasil.',
                    'kolom' => [
                        'bawaan' => 'Baris bawaan sistem yang tak boleh dihapus.',
                        'butuh_keterangan' => 'Bila dipilih, wali wajib mengisi penjelasan tambahan.',
                    ],
                ],
                'tipe_biaya' => [
                    'judul' => 'Tipe Biaya',
                    'fungsi' => 'Master tipe biaya yang menentukan PERILAKU alur uangnya. Kode tipe dibuat sendiri tiap pesantren, karena itu program selalu menyaring lewat kolom `perilaku`, bukan kodenya.',
                    'kolom' => [
                        'kode' => 'Kode tipe — kunci utama, bebas ditentukan pesantren.',
                        'perilaku' => 'Perilaku baku yang dikenal program: registrasi, uang_pangkal, perlengkapan, daftar_ulang, spp, atau lain.',
                        'bawaan' => 'Baris bawaan sistem.',
                    ],
                ],
                'jenis_biaya' => [
                    'judul' => 'Jenis Biaya',
                    'fungsi' => 'IDENTITAS AKUNTANSI sebuah biaya: namanya, perilakunya, jenjangnya, akun pendapatan/piutang/diterima-di-muka, dan unit bisnisnya. Satu baris per pasangan (jenjang, perilaku).',
                    'catatan' => 'TANPA nominal, TANPA tahun ajaran, TANPA jalur — ketiganya ada di tarif. `tipe` berkunci asing ke tipe biaya, jadi baris tipenya harus ada lebih dulu.',
                    'kolom' => [
                        'kode' => 'Kode jenis biaya — kunci utama.',
                        'tipe' => 'Tipe biaya yang menentukan perilakunya.',
                        'kode_coa_pendapatan' => 'Akun pendapatan saat biaya diakui.',
                        'kode_coa_piutang' => 'Akun piutang saat tagihan terbit.',
                        'kode_coa_diterima_dimuka' => 'Akun titipan bila uang diterima sebelum diakui.',
                        'berulang' => 'Ditagihkan berulang (mis. SPP bulanan) atau sekali.',
                        'pengakuan' => 'Kapan pendapatannya diakui.',
                        'cara_tagih' => 'Cara tagihannya terbit: massal, menurut kepesertaan, atau menurut pemakaian.',
                    ],
                ],
                'tarif_biaya' => [
                    'judul' => 'Tarif Biaya',
                    'fungsi' => 'BESARAN biaya per tahun ajaran, jenjang, jalur, dan perilaku. Inilah yang dibaca saat tagihan diterbitkan.',
                    'catatan' => 'Tiga keadaan sel WAJIB tetap berbeda: nominal terisi = berlaku; `bebas` = sengaja tak dipungut sehingga tagihan tak terbit; tak ada barisnya = belum diisi, dan penagihan BERHENTI dengan pesan. Nol adalah angka yang sah, bukan "kosong".',
                    'kolom' => [
                        'perilaku' => 'Perilaku biaya yang ditarifkan.',
                        'kode_jalur' => 'Jalur pendaftaran; sebagian perilaku (daftar ulang, SPP) tak mengenal jalur.',
                        'bebas' => 'Sengaja tidak dipungut — berbeda maknanya dari nominal 0.',
                        'tingkat' => 'Tingkat/kelas, bila tarifnya dibedakan per tingkat.',
                    ],
                ],
                'tarif_tagihan_lain' => [
                    'judul' => 'Matriks Tarif Kegiatan',
                    'fungsi' => 'Besaran tagihan lain-lain yang dipungut menurut KEPESERTAAN (ekskul, kegiatan), per jenjang.',
                ],
                'tarif_pemakaian' => [
                    'judul' => 'Matriks Tarif Layanan',
                    'fungsi' => 'Besaran tagihan lain-lain yang dipungut menurut PEMAKAIAN (laundry per kilogram), lengkap dengan kuota gratisnya.',
                    'kolom' => [
                        'tarif_satuan' => 'Tarif per satuan pemakaian.',
                        'nama_satuan' => 'Nama satuannya (kg, lembar, …).',
                        'kuota_gratis' => 'Pemakaian yang tak ditagih lebih dulu.',
                    ],
                ],
                'target_santri' => [
                    'judul' => 'Target Penerimaan Santri',
                    'fungsi' => 'Target jumlah santri baru per tahun ajaran per jenjang, dipakai dashboard PPSB untuk mengukur capaian.',
                    'kolom' => [
                        'target' => 'Target keseluruhan.',
                        'target_l' => 'Target santri laki-laki.',
                        'target_p' => 'Target santri perempuan.',
                    ],
                ],
                'termin_filter_settings' => [
                    'judul' => 'Setelan Filter Termin Jatuh Tempo',
                    'fungsi' => 'Pilihan rentang hari yang tersedia pada penyaring "termin jatuh tempo", beserta pilihan bawaannya.',
                ],
                'reminder_settings' => [
                    'judul' => 'Setelan Reminder Tagihan',
                    'fungsi' => 'Mengatur pengingat tagihan yang mendekati jatuh tempo: berapa hari sebelumnya, sumber tagihan mana saja yang diikutkan, siapa penerimanya, dan jam pengirimannya.',
                    'kolom' => [
                        'hari_sebelum' => 'Berapa hari sebelum jatuh tempo pengingat dikirim.',
                        'sumber_tagihan_santri' => 'Ikutkan tagihan santri sebagai sumber pengingat.',
                        'sumber_invoice_vendor' => 'Ikutkan invoice vendor.',
                        'sumber_angsuran_uang_pangkal' => 'Ikutkan termin angsuran uang pangkal.',
                        'penerima_admin' => 'Kirim ke administrator.',
                        'penerima_tim_keuangan' => 'Kirim ke tim keuangan.',
                        'penerima_akses_modul' => 'Kirim juga ke pemegang hak akses modul terkait.',
                        'jam_kirim' => 'Jam pengiriman pengingat setiap harinya.',
                    ],
                ],
                'pengaturan_nis' => [
                    'judul' => 'Format NIS',
                    'fungsi' => 'Pola penomoran induk santri. Baris tunggal; mengubahnya mempengaruhi seluruh angkatan berikutnya.',
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Data Siswa/Santri',
            'ringkas' => 'Orangnya: calon santri sampai alumni, keluarganya, berkasnya, dan riwayat perpindahan tingkatnya.',
            'tabel' => [

                'santri' => [
                    'judul' => 'Santri',
                    'fungsi' => 'Satu baris untuk seorang anak, dari calon sampai alumni — statusnya yang membedakan tahap. Nomor pendaftaran terbit saat mendaftar, NIS menyusul saat diterima.',
                    'catatan' => 'Dua kolom tahun ajaran yang TIDAK BOLEH disamakan: `tahun_ajaran` = tahun masuk/angkatan yang tak pernah maju, `tahun_ajaran_berjalan` = tahun yang sedang dijalani dan ikut naik tiap kenaikan tingkat.',
                    'kolom' => [
                        'no_pendaftaran' => 'Nomor pendaftaran, terbit saat mendaftar.',
                        'nis' => 'Nomor induk santri, terbit setelah diterima.',
                        'nisn' => 'Nomor induk siswa nasional.',
                        'tahun_ajaran' => 'Tahun MASUK (angkatan) — tidak pernah berubah.',
                        'tahun_ajaran_berjalan' => 'Tahun ajaran yang sedang dijalani — maju tiap kenaikan tingkat.',
                        'tingkat' => 'Tingkat/kelas yang sedang dijalani.',
                        'jalur' => 'Jalur pendaftaran saat masuk.',
                        'gelombang' => 'Gelombang pendaftaran saat masuk.',
                        'nominal_spp' => 'SPP khusus bila berbeda dari tarif umum.',
                        'keterangan_spp' => 'Alasan SPP khusus.',
                        'status' => 'Tahap santri: calon, siap aktivasi, aktif, alumni, keluar, atau mengundurkan diri.',
                        'tanggal_lulus' => 'Tanggal kelulusan, bila sudah alumni.',
                        'id_batch' => 'Batch impor yang melahirkan baris ini, bila berasal dari impor data awal.',
                        'asal_sekolah' => 'Sekolah asal calon.',
                        'alamat_sekolah_asal' => 'Alamat sekolah asal.',
                        'kepala_sekolah_asal' => 'Nama kepala sekolah asal, untuk konfirmasi berkas pindahan.',
                        'cp_kepala_sekolah_asal' => 'Kontak kepala sekolah asal.',
                        'wali_kelas_asal' => 'Nama wali kelas di sekolah asal.',
                        'cp_wali_kelas_asal' => 'Kontak wali kelas asal.',
                        'jenis_kelamin' => 'Jenis kelamin.',
                        'tempat_lahir' => 'Tempat lahir.',
                        'tanggal_lahir' => 'Tanggal lahir.',
                        'sumber_informasi' => 'Dari mana wali mengetahui pesantren.',
                        'sumber_informasi_lain' => 'Penjelasan tambahan bila sumbernya perlu diperinci.',
                        'tanggal_lulus' => 'Tanggal kelulusan, bila sudah alumni.',
                    ],
                ],
                'wali' => [
                    'judul' => 'Wali / Keluarga',
                    'fungsi' => 'Satu baris = satu KELUARGA, bukan satu orang. Kakak-adik yang bersekolah di pesantren yang sama berbagi satu baris wali, dan karenanya berbagi satu dompet.',
                    'kolom' => [
                        'kontak_utama' => 'Siapa di antara ayah/ibu/wali yang menjadi kontak resmi.',
                        'nama' => 'Nama kontak utama, disalin agar mudah dicari.',
                        'telepon' => 'Telepon kontak utama.',
                        'nik' => 'NIK kontak utama.',
                        'auto_debet' => 'Izin memotong dompet otomatis saat tagihan terbit.',
                        'telepon_verified' => 'Nomor telepon sudah terverifikasi lewat OTP.',
                        'otp_hash' => 'Sidik kode OTP yang sedang berlaku.',
                        'otp_expires' => 'Batas waktu OTP.',
                        'nama_ayah' => 'Nama ayah.',
                        'telepon_ayah' => 'Telepon ayah.',
                        'email_ayah' => 'Surel ayah.',
                        'pekerjaan_ayah' => 'Pekerjaan ayah.',
                        'pendapatan_ayah' => 'Rentang penghasilan ayah, untuk pertimbangan keringanan.',
                        'nama_ibu' => 'Nama ibu.',
                        'telepon_ibu' => 'Telepon ibu.',
                        'email_ibu' => 'Surel ibu.',
                        'pekerjaan_ibu' => 'Pekerjaan ibu.',
                        'pendapatan_ibu' => 'Rentang penghasilan ibu.',
                        'nama_wali' => 'Nama wali, bila bukan ayah/ibu.',
                        'telepon_wali' => 'Telepon wali.',
                        'email_wali' => 'Surel wali.',
                        'pekerjaan_wali' => 'Pekerjaan wali.',
                        'pendapatan_wali' => 'Rentang penghasilan wali.',
                        'alamat' => 'Alamat keluarga.',
                        'id_batch' => 'Batch impor asal baris ini.',
                    ],
                ],
                'pendaftaran' => [
                    'judul' => 'Berkas Pendaftaran',
                    'fungsi' => 'Proses penerimaan seorang calon: hasil tes baca & akademik, wawancara wali dan santri, medical check, serta kelengkapan dokumennya.',
                    'kolom' => [
                        'verifikasi_ok' => 'Berkas awal sudah diverifikasi.',
                        'nilai_baca' => 'Hasil tes baca Al-Qur\'an.',
                        'nilai_akademik' => 'Hasil tes akademik.',
                        'wawancara_wali' => 'Catatan/hasil wawancara wali.',
                        'wawancara_santri' => 'Catatan/hasil wawancara calon santri.',
                        'medcheck_ok' => 'Pemeriksaan kesehatan lulus.',
                        'dokumen_lengkap' => 'Seluruh berkas wajib sudah masuk.',
                        'jenis' => 'Pendaftaran baru atau lanjutan (naik jenjang di dalam pesantren).',
                    ],
                ],
                'dokumen_santri' => [
                    'judul' => 'Berkas Santri',
                    'fungsi' => 'Metadata berkas unggahan santri (KTP orang tua, akta, kartu keluarga, rapor, hasil medical check). Isi berkasnya di penyimpanan, bukan di database.',
                    'kolom' => [
                        'jenis' => 'Jenis berkas.',
                        'tahap' => 'Tahap berkas ini diminta: registrasi atau pasca-lulus.',
                        'nama_asli' => 'Nama berkas menurut pengunggah.',
                        'path' => 'Letak berkas di penyimpanan.',
                        'hash_sha256' => 'Sidik jari isi berkas.',
                        'diunggah_wali' => 'Diunggah wali lewat portal, bukan oleh staf.',
                    ],
                ],
                'nis_santri' => [
                    'judul' => 'Riwayat NIS',
                    'fungsi' => 'NIS berformat dan BERIWAYAT: nomor diterbitkan ulang tiap kali santri berpindah jenjang, dan nomor lamanya tetap tersimpan.',
                    'kolom' => [
                        'nis' => 'Nomor induk yang diterbitkan.',
                        'urut' => 'Nomor urut dalam satu angkatan jenjang — disusun menurut abjad, bukan urutan kedatangan.',
                        'berlaku' => 'Nomor yang sedang berlaku saat ini.',
                        'diterbitkan_pada' => 'Waktu penerbitan.',
                    ],
                ],
                'riwayat_tingkat' => [
                    'judul' => 'Riwayat Tingkat',
                    'fungsi' => 'Jejak tingkat yang pernah dijalani seorang santri pada tiap tahun ajaran — dasar untuk menjawab "kelas berapa dia waktu itu".',
                ],
                'jadwal_perubahan_santri' => [
                    'judul' => 'Jadwal Perubahan Santri',
                    'fungsi' => 'Keputusan kenaikan tingkat, pengulangan, kelulusan, atau keluar yang DITETAPKAN lebih dulu lalu diterapkan serentak saat tahun ajaran berganti.',
                    'catatan' => 'Kenaikan tingkat harus dijalankan SEBELUM penagihan daftar ulang, karena tarif daftar ulang mengikuti tingkat yang baru.',
                    'kolom' => [
                        'keputusan' => 'Naik, mengulang, lulus, atau keluar.',
                        'kode_jenjang_tujuan' => 'Jenjang setelah perubahan.',
                        'tingkat_tujuan' => 'Tingkat setelah perubahan.',
                        'kode_jalur_tujuan' => 'Jalur setelah perubahan.',
                        'batch' => 'Kelompok penetapan, agar bisa dijalankan/dibatalkan bersama.',
                        'ditetapkan_oleh' => 'Yang menetapkan keputusan.',
                        'diterapkan_pada' => 'Waktu keputusan benar-benar dijalankan.',
                        'id_pendaftaran' => 'Berkas pendaftaran lanjutan yang menyertainya.',
                    ],
                ],
                'term_template' => [
                    'judul' => 'Template Syarat & Ketentuan',
                    'fungsi' => 'Naskah syarat & ketentuan berversi. Naskah yang sudah dipakai tidak pernah disunting — perubahan melahirkan versi baru.',
                    'kolom' => [
                        'versi' => 'Nomor versi naskah.',
                        'isi' => 'Naskah lengkapnya.',
                        'berlaku_mulai' => 'Tanggal versi ini mulai berlaku.',
                    ],
                ],
                'persetujuan_term' => [
                    'judul' => 'Persetujuan Wali atas S&K',
                    'fungsi' => 'Surat pernyataan wali: SALINAN teks yang benar-benar dibacanya beserta bukti persetujuannya. Naskah disalin, bukan dirujuk, agar tetap membuktikan apa yang disetujui walau template berubah.',
                    'kolom' => [
                        'isi_umum' => 'Salinan naskah umum saat disetujui.',
                        'isi_khusus' => 'Salinan kesepakatan khusus keluarga ini.',
                        'hash_sha256' => 'Sidik jari naskah, membuktikan isinya tak berubah.',
                        'metode_ttd' => 'Cara menandatangani (OTP, tanda tangan basah, …).',
                        'penanda_tangan_nama' => 'Nama penanda tangan.',
                        'ip_address' => 'Alamat IP saat menyetujui.',
                        'user_agent' => 'Perangkat/peramban saat menyetujui.',
                        'otp_terverifikasi_pada' => 'Waktu OTP terverifikasi.',
                        'bukti_provider_ref' => 'Rujukan bukti dari penyedia layanan tanda tangan.',
                        'pdf_path' => 'Berkas PDF surat pernyataan.',
                    ],
                ],
                'impor_batch' => [
                    'judul' => 'Batch Impor Data Awal',
                    'fungsi' => 'Satu nomor untuk seluruh baris yang lahir dari satu berkas impor, sehingga impor yang keliru bisa dibatalkan utuh selama belum ada transaksi yang menempel padanya.',
                    'kolom' => [
                        'kunci' => 'Jenis data yang diimpor.',
                        'nama_berkas' => 'Nama berkas sumber.',
                        'ringkasan' => 'Jumlah baris yang tercipta per tabel.',
                        'dibatalkan_pada' => 'Waktu batch dibatalkan.',
                        'alasan_batal' => 'Alasan pembatalan.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Tagihan & Pembayaran Santri',
            'ringkas' => 'Buku piutang santri: penerbitan tagihan, setoran pembayaran, koreksi, angsuran uang pangkal, dan tagihan lain-lain.',
            'tabel' => [

                'tagihan_santri' => [
                    'judul' => 'Tagihan Santri',
                    'fungsi' => 'Buku pembantu piutang per santri. Setiap kewajiban — registrasi, uang pangkal, perlengkapan, daftar ulang, SPP, tagihan lain — menjadi satu baris di sini.',
                    'catatan' => 'Perilaku, jenjang, dan tahun ajaran disimpan sebagai SALINAN agar tagihan lama tak ikut berubah saat masternya berubah. `tahun_ajaran` di sini adalah tahun TAGIHAN, berbeda dari tahun masuk santri. Indeks unik parsial menjaga satu perilaku tak terbit dua kali dalam satu tahun ajaran.',
                    'kolom' => [
                        'periode' => 'Periode tagihan (mis. bulan SPP).',
                        'sisa' => 'Yang belum terbayar; disimpan agar tak perlu dihitung ulang tiap kali dibaca.',
                        'sudah_akrual' => 'Piutangnya sudah diakui — menentukan sisi kredit saat dibayar: Piutang, bukan Pendapatan.',
                        'saldo_awal' => 'Tunggakan warisan yang masuk sebagai keadaan pindahan sistem: berakrual TANPA jurnal. Buku besarnya masuk terpisah lewat menu Saldo Awal. Pembeda dari tagihan akrual biasa, yang justru punya jurnal.',
                        'jatuh_tempo' => 'Tanggal jatuh tempo.',
                        'perilaku' => 'Salinan perilaku biaya saat tagihan terbit.',
                        'kode_jenjang' => 'Salinan jenjang saat tagihan terbit.',
                        'tahun_ajaran' => 'Tahun ajaran TAGIHAN — bukan tahun masuk santri.',
                        'id_batch' => 'Batch impor asal, bila tagihan ini hasil impor data awal.',
                    ],
                ],
                'pembayaran_santri' => [
                    'judul' => 'Pembayaran Santri',
                    'fungsi' => 'Satu setoran atas sebuah tagihan. Dicatat petugas, lalu DIVERIFIKASI tim keuangan sebelum menjadi jurnal — sehingga uang yang belum benar-benar diterima tak pernah masuk pembukuan.',
                    'kolom' => [
                        'sumber' => 'Dari mana setoran berasal (loket, transfer, dompet, auto-debet).',
                        'metode' => 'Cara pembayaran.',
                        'external_id' => 'Nomor rujukan dari sistem luar.',
                        'provider_ref' => 'Rujukan dari penyedia pembayaran.',
                        'bukti_path' => 'Berkas bukti transfer.',
                        'dicatat_oleh' => 'Petugas yang mencatat.',
                        'diverifikasi_oleh' => 'Petugas keuangan yang memverifikasi.',
                        'alasan_tolak' => 'Alasan bila setoran ditolak.',
                    ],
                ],
                'koreksi_tagihan' => [
                    'judul' => 'Koreksi Nominal Tagihan',
                    'fungsi' => 'Jejak pembetulan nominal tagihan yang salah, beserta jurnal penyesuaian yang menyertainya.',
                    'catatan' => 'Nominal boleh diturunkan di bawah yang sudah terbayar; kelebihannya dialihkan ke Dompet Wali. Jadwal angsuran yang terlanjur dibuat digugurkan, bukan dihitung ulang.',
                    'kolom' => [
                        'nominal_lama' => 'Nominal sebelum dikoreksi.',
                        'nominal_baru' => 'Nominal sesudah dikoreksi.',
                        'terbayar' => 'Yang sudah terbayar saat koreksi dilakukan.',
                        'kelebihan_ke_dompet' => 'Kelebihan bayar yang dipindahkan ke dompet wali.',
                        'alasan' => 'Alasan koreksi — wajib diisi.',
                    ],
                ],
                'prabayar_spp' => [
                    'judul' => 'Saldo SPP Dibayar di Muka',
                    'fungsi' => 'Titipan SPP yang dibayar mendahului terbitnya tagihan. Dipotong otomatis saat tagihan bulan berikutnya terbit.',
                ],
                'peserta_tagihan_lain' => [
                    'judul' => 'Peserta Kegiatan',
                    'fungsi' => 'Daftar santri yang mengikuti sebuah kegiatan berbayar — dasar penerbitan tagihan yang dipungut menurut KEPESERTAAN.',
                    'kolom' => ['nominal' => 'Nominal khusus peserta ini, bila berbeda dari matriks tarif.'],
                ],
                'setoran_pemakaian' => [
                    'judul' => 'Setoran Pemakaian (Laundry)',
                    'fungsi' => 'Catatan pemakaian harian yang kelak ditagihkan, mis. timbangan laundry. Dicatat petugas tiap hari; tagihannya diterbitkan sekali per periode.',
                    'kolom' => [
                        'kuantitas' => 'Jumlah pemakaian (mis. kilogram).',
                        'id_tagihan' => 'Tagihan yang akhirnya memuat pemakaian ini.',
                        'dicatat_oleh' => 'Petugas yang menimbang/mencatat.',
                    ],
                ],
                'potongan_uang_pangkal' => [
                    'judul' => 'Potongan Uang Pangkal',
                    'fungsi' => 'Salinan potongan gelombang yang melekat pada satu tagihan uang pangkal, beserta syarat dan tenggat pemenuhannya.',
                    'kolom' => [
                        'nominal_normal' => 'Nominal sebelum potongan.',
                        'potongan' => 'Nilai potongan yang dijanjikan.',
                        'syarat_persen' => 'Persentase yang harus dibayar agar potongan berlaku.',
                        'tenggat' => 'Batas waktu pemenuhan syarat.',
                        'dinilai_pada' => 'Waktu pemenuhan syarat dinilai.',
                    ],
                ],
                'rencana_angsuran_uang_pangkal' => [
                    'judul' => 'Rencana Angsuran Uang Pangkal',
                    'fungsi' => 'Kesepakatan mencicil uang pangkal. Berversi: kesepakatan baru tidak menimpa yang lama, sehingga riwayat negosiasinya tetap terbaca.',
                    'kolom' => [
                        'versi' => 'Versi kesepakatan.',
                        'disepakati_pada' => 'Waktu kesepakatan dibuat.',
                        'disepakati_oleh' => 'Petugas yang menyepakati.',
                    ],
                ],
                'termin_uang_pangkal' => [
                    'judul' => 'Termin Angsuran Uang Pangkal',
                    'fungsi' => 'Baris jadwal angsuran: berapa, kapan, dan riwayat penagihannya kepada wali.',
                    'kolom' => [
                        'diingatkan_pada' => 'Waktu wali terakhir diingatkan.',
                        'diingatkan_oleh' => 'Petugas yang mengingatkan.',
                        'catatan_reminder' => 'Catatan petugas saat menagih.',
                        'feedback' => 'Tanggapan wali atas penagihan.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Dompet & Tabungan',
            'ringkas' => 'Uang milik santri/wali yang DITITIPKAN kepada pesantren. Akadnya wadi\'ah — tercatat sebagai LIABILITAS, bukan pendapatan.',
            'catatan' => 'Karena titipan, saldonya tidak boleh ikut dihitung sebagai dana yang bebas dipakai lembaga (lihat Akun Pengurang Dana Bebas).',
            'tabel' => [
                'dompet_wali' => [
                    'judul' => 'Dompet Wali',
                    'fungsi' => 'Saldo titipan milik satu keluarga, dipakai membayar tagihan anak-anaknya. Satu keluarga satu dompet, walau anaknya beberapa.',
                    'kolom' => [
                        'saldo' => 'Saldo titipan saat ini.',
                        'va_number' => 'Nomor virtual account untuk setoran transfer.',
                    ],
                ],
                'dompet_santri' => [
                    'judul' => 'Dompet Santri',
                    'fungsi' => 'Uang saku santri yang dititipkan, dipakai belanja keperluan harian di dalam pesantren.',
                    'kolom' => ['kunci_tarik' => 'Penarikan dikunci — saldo hanya bisa dipakai, tak bisa ditarik tunai.'],
                ],
                'tabungan_santri' => [
                    'judul' => 'Tabungan Santri',
                    'fungsi' => 'Simpanan santri yang dipisahkan dari uang saku harian.',
                ],
                'mutasi_dompet' => [
                    'judul' => 'Mutasi Dompet',
                    'fungsi' => 'Buku besar seluruh dompet & tabungan: setiap setoran, penarikan, pemakaian, dan perpindahan antar-dompet tercatat di sini.',
                    'catatan' => 'Perpindahan antar-dompet menghasilkan DUA baris yang saling menunjuk lewat `id_pasangan` — satu keluar, satu masuk.',
                    'kolom' => [
                        'pemilik' => 'Jenis pemilik dompet (santri atau wali).',
                        'id_dompet' => 'Dompet yang bergerak.',
                        'jenis' => 'Jenis mutasi: setor, tarik, pakai, pindah.',
                        'saldo_setelah' => 'Saldo sesudah mutasi ini — agar riwayatnya bisa dibaca tanpa menjumlah ulang.',
                        'id_pasangan' => 'Baris pasangannya pada perpindahan antar-dompet.',
                        'bukti_path' => 'Berkas bukti setoran.',
                        'dicatat_oleh' => 'Petugas yang mencatat.',
                        'diverifikasi_oleh' => 'Petugas yang memverifikasi.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Pengguna, Wewenang & Jejak',
            'ringkas' => 'Siapa boleh melakukan apa. Empat sumbu wewenang yang saling lepas: hak modul, level nominal, peringkat persetujuan, dan keanggotaan tim keuangan.',
            'tabel' => [
                'users' => [
                    'judul' => 'Pengguna',
                    'fungsi' => 'Akun pemakai aplikasi beserta seluruh sumbu wewenangnya.',
                    'catatan' => 'PK-nya `id_pengguna`. Wewenang tidak berasal dari satu kolom "jabatan" tunggal: hak modul, batas nominal, peringkat persetujuan, dan status tim keuangan berdiri sendiri-sendiri.',
                    'kolom' => [
                        'id_pengguna' => 'Kunci utama pengguna.',
                        'is_admin' => 'Administrator — melewati seluruh pemeriksaan hak akses.',
                        'kode_level' => 'Level otorisasi keuangan (batas nominal).',
                        'peringkat_pengajuan' => 'Peringkat pada rantai persetujuan.',
                        'tim_keuangan' => 'Anggota tim keuangan — boleh memverifikasi & membatalkan dokumen yang sudah dijurnal.',
                        'password_hash' => 'Sidik kata sandi; kata sandi asli tak pernah disimpan.',
                        'remember_token' => 'Token "ingat saya".',
                        'username' => 'Nama masuk.',
                        'jabatan' => 'Jabatan, sebagai keterangan — bukan sumber wewenang.',
                    ],
                ],
                'levels' => [
                    'judul' => 'Level Otorisasi Keuangan',
                    'fungsi' => 'Batas nominal transaksi yang boleh dijalankan seorang pengguna.',
                    'kolom' => ['max_transaksi' => 'Batas nominal; kosong = tanpa batas.'],
                ],
                'level_pengajuan' => [
                    'judul' => 'Peringkat Pengajuan',
                    'fungsi' => 'Tingkatan pengguna dalam rantai persetujuan (staf, mudir bagian, direktorat, ketua yayasan). Kunci utamanya angka peringkat itu sendiri.',
                    'kolom' => ['peringkat' => 'Angka peringkat — makin kecil makin tinggi.'],
                ],
                'bagian' => [
                    'judul' => 'Bagian / Struktur Organisasi',
                    'fungsi' => 'Hierarki organisasi (Yayasan → Bidang → Bagian) lewat `kode_induk` yang menunjuk dirinya sendiri. Menentukan pembebanan anggaran sekaligus siapa yang berwenang menyetujui.',
                    'kolom' => [
                        'kode_induk' => 'Bagian di atasnya.',
                        'level' => 'Kedalaman dalam struktur.',
                    ],
                ],
                'karyawan' => [
                    'judul' => 'Karyawan',
                    'fungsi' => 'Master pegawai yang sengaja dibuat ringkas — hanya yang dibutuhkan untuk pinjaman karyawan dan penunjukan jabatan. Bukan sistem kepegawaian.',
                    'kolom' => [
                        'kode' => 'Kode karyawan — kunci utama.',
                        'id_pengguna' => 'Akun aplikasi milik karyawan ini, bila ada.',
                    ],
                ],
                'hak_akses_modul' => [
                    'judul' => 'Hak Akses Modul',
                    'fungsi' => 'Matriks hak per pengguna per modul. DENY BY DEFAULT: tanpa baris di sini, sebuah modul tertutup — kecuali bagi administrator.',
                    'catatan' => 'Kunci utamanya gabungan `id_pengguna` + `kode_modul`. Kode modul tersimpan sebagai teks, karena itu mengganti kode modul di program akan memutus hak yang sudah diberikan.',
                    'kolom' => [
                        'kode_modul' => 'Modul yang diatur.',
                        'lihat' => 'Boleh membuka & membaca.',
                        'buat' => 'Boleh menambah.',
                        'ubah' => 'Boleh menyunting.',
                        'hapus' => 'Boleh menghapus/membatalkan.',
                        'menu' => 'Modul ini tampil di sidebar pengguna tersebut.',
                    ],
                ],
                'activity_log' => [
                    'judul' => 'Jejak Aktivitas',
                    'fungsi' => 'Audit trail tindakan pengguna.',
                    'kolom' => [
                        'aksi' => 'Tindakan yang dilakukan.',
                        'detail' => 'Rincian tindakan.',
                    ],
                ],
                'notifications' => [
                    'judul' => 'Notifikasi',
                    'fungsi' => 'Pemberitahuan dalam aplikasi: dokumen menunggu persetujuan, tagihan jatuh tempo, dan sejenisnya.',
                    'kolom' => [
                        'jenis' => 'Jenis pemberitahuan.',
                        'ref_jenis' => 'Jenis dokumen yang dirujuk.',
                        'ref_id' => 'Nomor dokumen yang dirujuk.',
                        'dibaca' => 'Sudah dibaca penerimanya.',
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------
        [
            'judul' => 'Infrastruktur Laravel',
            'ringkas' => 'Tabel bawaan kerangka kerja. Tidak memuat data usaha dan tidak perlu diurus dalam pemakaian sehari-hari.',
            'tabel' => [
                'migrations' => [
                    'judul' => 'Riwayat Migrasi',
                    'fungsi' => 'Daftar perubahan skema database yang sudah dijalankan. Dipakai Laravel untuk tahu migrasi mana yang belum diterapkan.',
                    'kolom' => [
                        'migration' => 'Nama berkas migrasi.',
                        'batch' => 'Kelompok penjalanan, agar bisa dimundurkan bersama.',
                    ],
                ],
                'sessions' => [
                    'judul' => 'Sesi Login',
                    'fungsi' => 'Sesi pengguna yang sedang aktif.',
                    'kolom' => [
                        'id' => 'Kunci sesi.',
                        'user_id' => 'Pengguna pemilik sesi.',
                        'payload' => 'Isi sesi yang tersimpan.',
                        'last_activity' => 'Waktu aktivitas terakhir.',
                    ],
                ],
                'cache' => [
                    'judul' => 'Cache',
                    'fungsi' => 'Penyimpanan sementara hasil perhitungan agar tak dihitung ulang.',
                    'kolom' => [
                        'key' => 'Kunci penyimpanan.',
                        'value' => 'Isi yang disimpan.',
                        'expiration' => 'Waktu kedaluwarsa.',
                    ],
                ],
                'cache_locks' => [
                    'judul' => 'Kunci Cache',
                    'fungsi' => 'Penjaga agar dua proses tak mengisi cache yang sama bersamaan.',
                    'kolom' => [
                        'key' => 'Kunci yang dikunci.',
                        'owner' => 'Proses pemegang kunci.',
                        'expiration' => 'Waktu kunci kedaluwarsa.',
                    ],
                ],
                'jobs' => [
                    'judul' => 'Antrean Pekerjaan',
                    'fungsi' => 'Pekerjaan yang dijalankan di latar belakang.',
                    'kolom' => [
                        'queue' => 'Nama antrean.',
                        'payload' => 'Isi pekerjaan.',
                        'attempts' => 'Berapa kali sudah dicoba.',
                        'reserved_at' => 'Waktu pekerjaan diambil pekerja.',
                        'available_at' => 'Waktu pekerjaan boleh mulai dijalankan.',
                    ],
                ],
                'job_batches' => [
                    'judul' => 'Kelompok Pekerjaan',
                    'fungsi' => 'Sekumpulan pekerjaan latar yang dijalankan & dipantau bersama.',
                    'kolom' => [
                        'name' => 'Nama kelompok.',
                        'total_jobs' => 'Jumlah pekerjaan dalam kelompok.',
                        'pending_jobs' => 'Yang belum selesai.',
                        'failed_jobs' => 'Yang gagal.',
                        'failed_job_ids' => 'Daftar nomor pekerjaan yang gagal.',
                        'options' => 'Setelan kelompok.',
                        'cancelled_at' => 'Waktu dibatalkan.',
                        'finished_at' => 'Waktu selesai.',
                    ],
                ],
                'failed_jobs' => [
                    'judul' => 'Pekerjaan Gagal',
                    'fungsi' => 'Pekerjaan latar yang gagal, lengkap dengan pesan galatnya untuk ditelusuri.',
                    'kolom' => [
                        'uuid' => 'Penanda unik pekerjaan.',
                        'connection' => 'Koneksi antrean yang dipakai.',
                        'queue' => 'Nama antrean.',
                        'payload' => 'Isi pekerjaan saat gagal.',
                        'exception' => 'Pesan galat & jejaknya.',
                        'failed_at' => 'Waktu kegagalan.',
                    ],
                ],
            ],
        ],

    ],
];
