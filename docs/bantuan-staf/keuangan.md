Panduan ini menjawab pertanyaan bagian keuangan tentang pembukuan: pengaturan akuntansi (Chart of Account, rekening, unit bisnis, saldo awal, level otorisasi), transaksi harian (jurnal, kas masuk, kas keluar, pindah buku, invoice vendor, uang muka), pengajuan pembayaran beserta persetujuan berjenjangnya, perintah pembayaran, anggaran, aset tetap, pembiayaan, rekonsiliasi, tutup buku, dan laporan keuangan. Isinya berlaku untuk ERP Al Wafi maupun ERP Wafi Mart; fitur yang hanya ada di salah satunya diberi tanda di isi artikel. Nama menu ditulis persis seperti di sidebar dengan tanda › sebagai pemisah. Bila menu tidak terlihat, biasanya karena hak aksesnya belum diberikan — minta admin.

## Apa urutan menyiapkan pembukuan sebelum mulai mencatat transaksi?
kata kunci: mulai pembukuan, setting awal keuangan, persiapan akuntansi, urutan setting, setup coa, awal pakai aplikasi, mulai buku baru

Urutan yang disarankan:

1. **SETTING AWAL › Setting Umum › Pengaturan Perusahaan** — isi **Periode Awal Pembukuan** dan **Bulan Awal Anggaran**.
2. **KEUANGAN › Kontrol › Chart of Account** — susun grup dan akun.
3. **KEUANGAN › Kontrol › Kas & Rekening** — daftarkan akun kas dan bank sebagai rekening.
4. **KEUANGAN › Kontrol › Unit Bisnis** dan **Default Unit Bisnis**.
5. **SETTING AWAL › Setting Umum › Level Otorisasi Keuangan** dan **Level Pengajuan**, lalu **Bagian / Struktur Organisasi** dan **Pengguna**.
6. Masukkan dokumen lama lewat **Impor Data Awal** atau layar transaksinya (hutang vendor, uang muka, aset, pinjaman).
7. **KEUANGAN › Kontrol › Saldo Awal** — lengkapi sisanya lalu **Finalisasi Saldo Awal**.

Sesudah itu transaksi harian bisa dimulai.

## Bagaimana mengisi Pengaturan Perusahaan bagian akuntansi?
kata kunci: pengaturan perusahaan, periode awal pembukuan, bulan awal anggaran, unit penampung neraca, akun barang diterima belum ditagih, grni, setting perusahaan

Buka **SETTING AWAL › Setting Umum › Pengaturan Perusahaan**. Isian yang berhubungan dengan akuntansi:

- **Periode Awal Pembukuan** — tanggal mulai buku. Jurnal saldo awal otomatis bertanggal sehari sebelum tanggal ini, dan tanggal ini juga dasar Laba/Rugi Tahun Berjalan di laporan posisi keuangan.
- **Bulan Awal Anggaran** — bulan pertama tahun anggaran (tidak harus Januari).
- **Unit Penampung Neraca** — bila diisi, semua baris hutang dan kas/bank dibukukan ke unit ini; beban dan aset tetap tetap mengikuti unit yang mengajukan. Kosongkan bila tiap baris mengikuti unit dokumennya.
- **Akun Barang Diterima Belum Ditagih** — wajib diisi sebelum penerimaan barang pertama.
- **Akun Piutang ke Al Wafi (belanja kartu santri)** — hanya di ERP Wafi Mart, wajib sebelum menerima pembayaran kartu.

Tekan **Simpan**. Butuh hak Pengaturan Perusahaan (Ubah).

## Bagaimana menambah atau mengubah akun di Chart of Account?
kata kunci: chart of account, coa, tambah akun, akun baru, kode akun, perkiraan, daftar akun, ubah akun, saldo normal

1. Buka **KEUANGAN › Kontrol › Chart of Account**, pilih tab **Detail COA**, tekan **+ Tambah Akun**.
2. Isi **Kode Akun**, **Nama Akun**, pilih **Grup COA** dan **Saldo Normal** (Debet atau Kredit).
3. Untuk akun neraca, pilih **Klasifikasi Arus Kas**. Untuk akun Pendapatan dan Ekuitas/Aset Neto di ERP Al Wafi, isi juga **Sifat Pembatasan (Aset Neto)**.
4. Pilih **Status** lalu simpan.

Kode akun tidak bisa diubah setelah disimpan. Akun berstatus **Nonaktif** tidak bisa dipakai di transaksi baru — sistem menolak dengan pesan "Akun … berstatus nonaktif." Butuh hak Chart of Account (Buat untuk menambah, Ubah untuk mengubah).

## Kenapa akun tidak bisa dihapus dari Chart of Account?
kata kunci: hapus akun gagal, akun masih dipakai, tidak bisa hapus coa, nonaktifkan akun, akun salah buat, delete akun

Pesan "Akun … masih dipakai (…). Nonaktifkan saja bila sudah tidak terpakai" berarti akun itu sudah dirujuk jurnal, jenis biaya, rekening, atau data lain. Menghapusnya akan membuat data itu menunjuk akun yang tidak ada, jadi sistem menolak.

Caranya: buka **Ubah** pada akun itu, ganti **Status** menjadi **Nonaktif**, lalu simpan. Akun nonaktif tetap tampil di laporan lama, tetapi tidak bisa dipilih untuk transaksi baru. Hanya akun yang belum pernah dipakai yang bisa dihapus (butuh hak Chart of Account (Hapus)).

## Bagaimana mengatur Grup COA dan struktur pohon akun?
kata kunci: grup coa, struktur pohon, induk akun, level akun, kelompok akun, pohon coa, tambah grup

Di **KEUANGAN › Kontrol › Chart of Account** ada tiga tab: **Struktur Pohon** (gambaran seluruh akun), **Grup COA**, dan **Detail COA**.

1. Pilih tab **Grup COA**, tekan **+ Tambah Grup**.
2. Isi kode, nama grup, level, dan induknya.
3. Simpan, lalu akun detail bisa ditempatkan di grup itu.

Grup yang masih menaungi sub-grup atau akun detail tidak bisa dihapus. Mengelola grup butuh hak Grup COA (modul ini tidak punya menu sendiri; minta admin mencentangnya).

## Bagaimana mengisi klasifikasi arus kas setiap akun?
kata kunci: klasifikasi arus kas, belum diklasifikasikan, operasi investasi pendanaan, arus kas tidak rinci, setting arus kas, cash flow

Laporan Arus Kas mengelompokkan mutasi menurut klasifikasi tiap akun neraca. Bila ada akun yang belum diisi, di Chart of Account muncul tautan **Atur Klasifikasi Arus Kas →**.

1. Buka tautan itu dari **KEUANGAN › Kontrol › Chart of Account**.
2. Pilih kelompok tiap akun. Petunjuk di layar: piutang, uang muka, dan hutang jangka pendek → Operasi; aset tetap dan akumulasi depresiasinya → Investasi; pinjaman dan kewajiban jangka panjang → Pendanaan.
3. Tekan **Simpan Klasifikasi**.

Akun yang terdaftar sebagai rekening kas tidak perlu diklasifikasikan. Pendapatan dan beban selalu masuk Aktivitas Operasi. Akun yang dibiarkan kosong tetap terhitung, hanya terkumpul di kelompok "Belum Diklasifikasikan". Menyimpan butuh hak Chart of Account (Ubah).

## Bagaimana mendaftarkan rekening kas atau bank?
kata kunci: kas dan rekening, tambah rekening, rekening bank, kas kecil, akun kas, bank baru, daftar rekening, petty cash

1. Pastikan akun kas/bank sudah ada di Chart of Account.
2. Buka **KEUANGAN › Kontrol › Kas & Rekening**, tekan **+ Tambah Rekening**.
3. Pilih **Akun COA (Kas/Bank)**, isi **Nama Rekening**, pilih **Jenis** (Tunai (Kas) atau Bank), lalu isi **Nama Bank** dan **No. Rekening** bila bank.
4. Simpan.

Hanya rekening yang terdaftar di sini yang bisa dipilih sebagai sumber/tujuan di Kas Masuk, Kas Keluar, dan Pindah Buku, dan yang dikenali sebagai kas di Laporan Arus Kas. Rekening yang sudah dipakai transaksi tidak bisa dihapus — ubah statusnya menjadi Nonaktif. Butuh hak Kas & Rekening.

## Bagaimana memilih rekening bank yang tampil di Portal Wali?
kata kunci: rekening portal wali, rekening transfer wali, nomor rekening di portal, tujuan transfer wali, atas nama rekening, tampil di portal

(Hanya di ERP Al Wafi.)

1. Buka **KEUANGAN › Kontrol › Rekening Portal Wali**.
2. Centang **Tampil** pada rekening bank yang boleh dipakai wali untuk transfer (mengisi Dompet Wali dan membayar tagihan calon santri).
3. Isi **Atas nama (dilihat wali)** bila perlu; bila dikosongkan, wali melihat nama lembaga.
4. Tekan **Simpan**. Wali langsung melihat perubahannya.

Yang muncul hanya rekening bank yang aktif dan sudah punya nomor rekening; kas tunai tidak bisa dipilih. Pembayaran wali lewat transfer tetap menunggu verifikasi keuangan. Melihat butuh hak Kas & Rekening (Lihat), menyimpan butuh (Ubah).

## Apa itu Unit Bisnis dan bagaimana menambahnya?
kata kunci: unit bisnis, tambah unit, unit usaha, cost center, divisi, laporan per unit, business unit

Unit Bisnis adalah pembeda pembukuan per bidang usaha atau kegiatan, sehingga laba rugi bisa dilihat per unit.

1. Buka **KEUANGAN › Kontrol › Unit Bisnis**, tekan **+ Tambah Unit**.
2. Isi **Kode Unit** dan **Nama Unit**, pilih **Status**.
3. Simpan.

Hampir setiap transaksi meminta unit bisnis. Unit berstatus nonaktif tidak bisa dipakai di transaksi baru. Butuh hak Unit Bisnis.

## Kenapa unit bisnis tidak bisa dihapus?
kata kunci: hapus unit gagal, unit masih dipakai, gabung unit, unit tidak bisa dihapus, hapus unit bisnis

Pesan "Unit bisnis tidak bisa dihapus karena masih dipakai jurnal / default unit" muncul bila unit itu masih dirujuk data lain. Selain jurnal dan Default Unit Bisnis, perujuknya bisa juga data master seperti Jenis Biaya atau dokumen transaksi.

1. Periksa **Default Unit Bisnis** dan ganti unitnya bila menunjuk unit ini.
2. Periksa data master yang memakai unit itu (misalnya Jenis Biaya di ERP Al Wafi) dan arahkan ke unit lain.
3. Bila unit sudah punya jurnal, jangan dihapus — ubah statusnya menjadi **Nonaktif**.

Penghapusan ditolak selama unit masih dirujuk data apa pun — jurnal, Default Unit Bisnis, jenis biaya, rekening, aset, dan lain-lain. Pindahkan dulu rujukannya ke unit lain, atau cukup nonaktifkan unitnya.

## Apa fungsi Default Unit Bisnis?
kata kunci: default unit, unit bawaan, unit otomatis, jurnal tanpa unit, unit modul, default unit modul

**KEUANGAN › Kontrol › Default Unit Bisnis** menetapkan unit bawaan untuk tiap **Modul Asal** jurnal. Unit ini dipakai bila transaksi tidak menentukan unitnya sendiri, misalnya Pindah Buku tanpa unit atau jurnal otomatis dari modul tertentu.

1. Tekan **+ Tambah**, pilih **Modul Asal** dan **Unit Bisnis**, isi keterangan.
2. Simpan.

Bila sebuah modul tidak punya default dan transaksinya juga tidak menyebut unit, jurnalnya bisa tercatat tanpa unit — laporan per unit lalu memberi peringatan "belum berunit". Butuh hak Default Unit Bisnis.

## Bagaimana mengatur Level Otorisasi Keuangan (batas nominal transaksi)?
kata kunci: level otorisasi, batas transaksi, maksimal transaksi, limit nominal, wewenang nominal, plafon, level keuangan

1. Buka **SETTING AWAL › Setting Umum › Level Otorisasi Keuangan**.
2. Tambah level: isi **Kode Level**, **Nama Level**, dan **Maksimal Transaksi**. Kosongkan Maksimal Transaksi untuk level tanpa batas (level tertinggi).
3. Simpan, lalu pasang levelnya pada tiap pengguna di menu Pengguna.

Batas ini diperiksa saat memposting Kas Masuk, Kas Keluar, Invoice Vendor, Jurnal Umum, Pindah Buku, Uang Muka Operasional, dan Pelepasan Aset. Butuh hak Level Otorisasi Keuangan. Perbedaannya dengan Level Pengajuan dijelaskan di panduan umum.

## Muncul pesan "melebihi batas otorisasi level", apa yang harus dilakukan?
kata kunci: melebihi batas otorisasi, minta approval level lebih tinggi, ditolak nominal, limit transaksi, otorisasi gagal, level otorisasi tidak ditemukan

Pesan "Nominal … melebihi batas otorisasi level … (maks. …). Minta approval ke level lebih tinggi." berarti total transaksi lebih besar dari **Maksimal Transaksi** level Anda.

- Minta rekan yang levelnya lebih tinggi untuk memposting transaksi itu, atau
- Bila batas level memang perlu diubah, minta admin menyesuaikannya di Level Otorisasi Keuangan.

Jangan memecah satu transaksi menjadi beberapa voucher kecil hanya untuk menghindari batas. Pesan "Level otorisasi pengguna tidak ditemukan. Hubungi administrator." berarti akun Anda belum dipasangi level yang benar — minta admin memeriksa data Pengguna Anda.

## Bagaimana mengatur Level Pengajuan dan perannya?
kata kunci: level pengajuan, peringkat, peran pengajuan, boleh mengajukan, rantai persetujuan, mudir, penyetuju, approver

1. Buka **SETTING AWAL › Setting Umum › Level Pengajuan**, tekan **+ Tambah Level**.
2. Isi **Peringkat** (1 = tertinggi; tidak bisa diubah setelah disimpan), **Nama**, dan **Status**.
3. Centang **Peran** yang berlaku: **Boleh mengajukan pembayaran**, **Boleh mengajukan anggaran**, **Terikat pada satu Bagian**, **Melihat data seluruh yayasan**.
4. Simpan, lalu pasang peringkatnya pada pengguna di menu Pengguna.

Yang menentukan wewenang adalah **tanda peran**, bukan angka peringkat. Level tanpa centang adalah penyetuju biasa. Level nonaktif tidak memegang peran apa pun. Level yang masih dipakai pengguna atau tahap rantai tidak bisa dihapus. Butuh hak Level Pengajuan.

## Bagaimana mengisi Saldo Awal dan memfinalisasinya?
kata kunci: saldo awal, opening balance, jurnal pembuka, finalisasi saldo awal, saldo pembukaan, neraca awal, input saldo awal

1. Buka **KEUANGAN › Kontrol › Saldo Awal**.
2. Periksa baris **turunan** (terisi otomatis dari dokumen saldo awal).
3. Tambah baris manual: pilih **Akun**, **Sisi** (Debet/Kredit), isi **Saldo**, tekan **+ Tambah**. Lengkapi kas, bank, persediaan, dan ekuitas awal sebagai penyeimbang.
4. Bila total sudah **✓ Balance**, tekan **Finalisasi Saldo Awal**.

Finalisasi membuat satu jurnal pembuka bertanggal sehari sebelum Periode Awal Pembukuan, dan semua laporan membaca saldo awal dari jurnal itu. Setelah final, baris terkunci. Butuh minimal dua akun yang saling menyeimbangkan. Butuh hak Saldo Awal (Ubah).

## Apa arti baris "turunan" di Saldo Awal?
kata kunci: baris turunan, otomatis dari dokumen, saldo awal otomatis, dokumen saldo awal, terhitung dua kali, double saldo awal

Baris turunan adalah saldo yang dihitung otomatis dari dokumen saldo awal yang dicatat di modulnya masing-masing, misalnya uang muka operasional pindahan sistem, accrue saldo awal, aset tetap pindahan, hutang pengajuan belum dibayar, atau data dari Impor Data Awal. Tulisannya "otomatis dari … — … dokumen".

Baris ini tidak bisa diketik ulang. Bila Anda mencoba menambah akun yang sama secara manual, muncul pesan "Akun … sudah terisi otomatis … dari … dokumen saldo awal … Mengetiknya lagi membuat angkanya terhitung dua kali". Bila angkanya salah, betulkan di dokumen asalnya, bukan di layar Saldo Awal.

## Saldo awal perlu diubah padahal sudah difinalisasi, bagaimana?
kata kunci: revisi saldo awal, void finalisasi, ubah saldo awal, saldo awal salah, buka saldo awal, finalisasi ulang

1. Buka **KEUANGAN › Kontrol › Saldo Awal**.
2. Tekan **Void Finalisasi**. Jurnal pembuka dibalik dan baris bisa diubah lagi.
3. Perbaiki barisnya.
4. Tekan **Finalisasi Saldo Awal** lagi.

Bila muncul kotak "Ada dokumen saldo awal yang berubah setelah jurnal pembuka terbit", artinya ada dokumen pindahan yang ditambah atau diubah sesudah finalisasi. Tabelnya menunjukkan angka di jurnal, angka yang seharusnya, dan selisihnya. Lakukan Void Finalisasi lalu finalisasi ulang supaya baris turunan terhitung ulang. Pesan "Saldo awal sudah difinalisasi. Lakukan Void terlebih dahulu untuk mengubahnya." muncul bila Anda mengubah baris saat masih final. Butuh hak Saldo Awal (Ubah).

## Bagaimana memasukkan data keuangan dari sistem lama lewat Impor Data Awal?
kata kunci: impor data awal, pindahan sistem, migrasi data, upload csv, hutang vendor lama, aset lama, pinjaman lama, template csv

Buka **SETTING AWAL › Setting Umum › Impor Data Awal**. Jenis yang tersedia untuk keuangan antara lain Hutang Vendor, Uang Muka Operasional, Accrue & Prepaid, Aset Tetap, Pembiayaan Bank, Pinjaman Karyawan, dan Pengajuan Belum Dibayar.

1. Pilih jenisnya, tekan **Unduh Template CSV**, salin data ke template.
2. Unggah berkas, tekan **Periksa Berkas**. Baris bermasalah ditampilkan beserta sebabnya (nomor baris mengikuti Excel).
3. Bila sudah benar, tekan tombol **Impor**.

Kebanyakan jenis tidak menerbitkan jurnal; nilainya masuk lewat baris turunan di Saldo Awal. Jenis yang berjurnal (misalnya Hutang Vendor) membukukan ke akun perantara saldo awal, dan saldo akun perantara itulah yang diseimbangkan di Saldo Awal. Impor yang keliru bisa dibatalkan dari **Riwayat Impor** dengan alasan. Butuh hak Impor Data Awal (pindahan sistem).

## Bagaimana mencatat hutang lama yang sudah disetujui tetapi belum dibayar?
kata kunci: hutang belum dibayar, saldo awal pengajuan, pengajuan lama, hutang lama, belum dicairkan, pindah sistem hutang

1. Buka **SETTING AWAL › Setting Umum › Hutang Belum Dibayar (Saldo Awal)**.
2. Isi **Nomor dokumen lama**, **Tanggal**, **Nominal**, **Akun Hutang**, **Akun Beban / Lawannya**, **Bagian**, **Unit Bisnis**, dan **Keterangan**.
3. Tekan **Catat Hutang Saldo Awal**.

Dokumen ini langsung berstatus **Diposting** tanpa rantai persetujuan, sehingga bisa langsung dibayar lewat Kas Keluar. Pakai hanya untuk hutang yang memang sudah disetujui di sistem lama. Tidak ada jurnal yang terbit di sini; nilainya masuk lewat baris turunan di Saldo Awal. Baris yang belum dicairkan masih bisa dihapus. Butuh hak Impor Data Awal (pindahan sistem).

## Bagaimana membuat jurnal umum (jurnal manual)?
kata kunci: jurnal umum, jurnal manual, input jurnal, buat jurnal, jurnal memorial, posting jurnal, jurnal koreksi

1. Buka **KEUANGAN › Transaksi › Jurnal Umum**, tekan **+ Jurnal Manual**.
2. Isi **Tanggal**, **Unit Bisnis** (kosong = default modul), **Dana** (opsional), dan **Keterangan**.
3. Isi baris: **Akun**, **Debet** atau **Kredit**, **Bagian** (wajib untuk akun Beban), dan keterangan baris. Tekan **+ Tambah baris** bila perlu.
4. Pastikan tanda **✓ Balance** muncul, lalu tekan **Posting Jurnal**.

Satu baris hanya boleh berisi debet atau kredit, tidak keduanya, dan minimal dua baris. Daftar Jurnal Umum menampilkan seluruh jurnal, baik otomatis dari modul maupun manual; saring dengan tanggal atau kata kunci. Butuh hak Jurnal Umum (Buat).

## Muncul pesan "Jurnal tidak balance", apa artinya?
kata kunci: jurnal tidak balance, tidak seimbang, debet kredit beda, selisih jurnal, gagal posting jurnal, unbalance

Pesan "Jurnal tidak balance: total debet (…) tidak sama dengan total kredit (…)" berarti jumlah kolom Debet dan Kredit belum sama. Periksa angka tiap baris, atau tambah baris penyeimbang.

Pesan lain yang sering muncul saat menjurnal:

- "Jurnal harus memiliki minimal 2 baris."
- "Baris …: satu baris tidak boleh memiliki debet dan kredit sekaligus." — pecah menjadi dua baris.
- "Baris …: setiap baris harus memiliki debet atau kredit > 0." — hapus baris kosong.
- "Baris …: debet/kredit tidak boleh negatif." — tukar sisinya, jangan pakai minus.
- "Akun COA … berstatus nonaktif." — pilih akun lain atau aktifkan akunnya.

## Muncul pesan "Bagian wajib diisi untuk akun Beban", apa yang harus dilakukan?
kata kunci: bagian wajib, akun beban, dimensi bagian, kolom bagian, beban tanpa bagian, error bagian

Setiap baris yang memakai akun Beban wajib menyebut **Bagian** yang menanggung biaya itu, karena realisasi anggaran dihitung per bagian. Pesan "Baris …: Bagian wajib diisi untuk akun Beban." menunjuk nomor barisnya.

Pilih Bagian pada baris tersebut, lalu simpan lagi. Aturan ini berlaku di Jurnal Umum, Kas Keluar, Kas Masuk, Invoice Vendor, Accrue, dan layar lain yang menjurnal beban. Bila bagian yang dibutuhkan belum ada, minta admin menambahkannya di **SETTING AWAL › Setting Umum › Bagian / Struktur Organisasi**.

## Bagaimana membatalkan (void) jurnal dan kenapa tombol Void tidak muncul?
kata kunci: void jurnal, batalkan jurnal, hapus jurnal, jurnal pembalik, reversal, jurnal salah, koreksi jurnal

Jurnal tidak pernah dihapus; pembatalannya membuat **jurnal pembalik** sehingga riwayatnya tetap utuh.

1. Buka **KEUANGAN › Transaksi › Jurnal Umum**, cari jurnalnya.
2. Tekan **Void** (atau buka jurnalnya lalu **Void Jurnal**) dan konfirmasi.

Tombol Void hanya ada untuk jurnal manual. Jurnal yang dibuat modul lain (Kas Keluar, Invoice, pembayaran santri, dan sebagainya) bertuliskan "Jurnal dari modul … — void lewat modul asalnya"; batalkan dokumennya di modul itu. Pesan "Jurnal ini sudah di-void sebelumnya." berarti sudah dibatalkan. Void butuh hak Jurnal Umum (Hapus). Jurnal pembaliknya bertanggal hari pembatalan, jadi periode bulan berjalan harus masih terbuka.

## Muncul pesan tentang harga pokok FIFO saat jurnal memakai baris persediaan, apa artinya?
kata kunci: fifo, harga pokok, baris persediaan, stok keluar jurnal, kredit persediaan, qty persediaan, mutasi stok

Di Jurnal Umum, baris bisa diberi **Persediaan** dan **Qty**: debit berarti stok masuk, kredit berarti stok keluar. Untuk stok keluar, harga pokoknya dihitung otomatis dari lapisan FIFO, bukan diketik.

Pesan "Baris persediaan …: harga pokok FIFO untuk … unit adalah …, sedangkan kredit yang diisi …" berarti angka kredit Anda berbeda dari hitungan sistem. Samakan nominalnya dengan angka di pesan, atau catat pemakaian barang lewat tombol **Mutasi** di layar Persediaan supaya harga pokok terisi sendiri.

## Bagaimana membuat jurnal penyesuaian akrual atau prabayar (Accrue & Prepaid)?
kata kunci: accrue, akrual, prepaid, prabayar, jurnal penyesuaian, biaya masih harus dibayar, beban dibayar dimuka, adjusting

1. Buka **KEUANGAN › Transaksi › Accrue & Prepaid**, tekan **+ Accrue Baru**.
2. Isi **Tanggal**, **Periode (YYYY-MM)**, **Akun Debet**, **Akun Kredit**, **Nominal**, **Unit Bisnis**, **Bagian**, dan **Keterangan**.
3. Biarkan centang **Posting jurnal penyesuaian sekarang** menyala untuk transaksi biasa.
4. Tekan **Posting Accrue**.

Matikan centang itu hanya untuk saldo awal pindahan sistem: dokumennya tercatat tanpa jurnal dan nilainya ikut sebagai baris turunan di Saldo Awal. Accrue yang masih aktif bisa dipantau di **KEUANGAN › Kontrol › Accrue & Prepaid**. Butuh hak Accrue & Prepaid (Buat).

## Apa fungsi tombol Reversal Awal Bulan di Accrue & Prepaid?
kata kunci: reversal awal bulan, balik accrue, reverse akrual, pembalikan jurnal penyesuaian, accrue bulan lalu

Tombol **Reversal Awal Bulan** di **KEUANGAN › Transaksi › Accrue & Prepaid** membalik semua accrue yang masih aktif dari **Periode** sebelum bulan berjalan. Karena itu isian Periode pada accrue penting — itulah yang dipakai menentukan accrue mana yang dibalik.

Jalankan di awal bulan sesudah pembukuan bulan lalu selesai. Pesan "Tidak ada accrue periode lalu yang perlu di-reversal." berarti tidak ada accrue aktif dari periode lalu. Butuh hak Accrue & Prepaid (Buat).

## Bagaimana mencatat kas masuk (penerimaan uang)?
kata kunci: kas masuk, penerimaan kas, uang masuk, terima uang, cash in, rv, setoran, pemasukan, receipt voucher

1. Buka **KEUANGAN › Transaksi › Kas Masuk**, tekan **+ Kas Masuk Baru**.
2. Isi **Tanggal**, pilih **Kas/Rekening Tujuan**, **Unit Bisnis**, **Customer** (opsional), dan **Dana** bila penerimaan donasi/wakaf (Dana hanya di ERP Al Wafi).
3. Isi **Referensi** dan **Keterangan Voucher**.
4. Isi **Rincian Akun (dikredit)**: akun, **Jenis**, nominal, keterangan, dan **Bagian** bila perlu.
5. Tekan **Posting Kas Masuk**.

Nomor voucher dibuat otomatis saat posting. Bila uangnya hasil penjualan barang persediaan, tekan **+ Persediaan terjual** pada baris itu supaya stok ikut keluar. Pembayaran tagihan santri tidak dicatat di sini, tetapi di layar pembayaran santri. Butuh hak Kas Masuk (Buat).

## Apa arti pilihan Jenis pada baris Kas Masuk?
kata kunci: jenis kas masuk, pendapatan, pelunasan, pendapatan diterima dimuka, lain-lain, jenis penerimaan, uang muka customer

Setiap baris Kas Masuk punya **Jenis**:

- **Pendapatan** — penerimaan yang langsung menjadi pendapatan.
- **Pelunasan** — pelunasan piutang atau tagihan sebelumnya.
- **Pendapatan Diterima Dimuka** — uang muka dari customer yang belum menjadi pendapatan. Baris ini masuk daftar Uang Muka Customer dan diakui belakangan.
- **Lain-lain** — penerimaan lain di luar ketiganya.

Akun yang dikredit tetap akun yang Anda pilih; jenis menentukan cara baris itu dilacak di layar Kontrol.

## Bagaimana mencatat uang muka dari customer lalu mengakuinya sebagai pendapatan?
kata kunci: uang muka customer, pendapatan diterima dimuka, akui pendapatan, dp customer, down payment, pengakuan pendapatan, deposit

1. Catat penerimaannya di **KEUANGAN › Transaksi › Kas Masuk** dengan baris berjenis **Pendapatan Diterima Dimuka**, memakai akun uang muka/kewajiban.
2. Saat pekerjaan atau barangnya sudah diserahkan, buka **KEUANGAN › Kontrol › Uang Muka Customer**.
3. Pada baris voucher itu, tekan **Akui Pendapatan**, pilih **Akun Pendapatan**, isi **Nominal Diakui** (kosongkan untuk mengakui seluruh sisa), lalu **Proses Pengakuan**.

Pengakuan boleh bertahap. Pesan "Nominal melebihi sisa uang muka (…)" berarti nominal lebih besar dari sisa yang belum diakui; "Uang muka sudah diakui penuh." berarti sudah habis. Mengakui butuh hak Kas Masuk (Buat).

## Bagaimana mencatat kas keluar untuk beban biasa?
kata kunci: kas keluar, pengeluaran kas, bayar biaya, cash out, pv, payment voucher, uang keluar, belanja operasional

1. Buka **KEUANGAN › Transaksi › Kas Keluar**, tekan **+ Kas Keluar Baru**.
2. Isi **Tanggal**, **Metode Pembayaran**, **Unit Bisnis**, **Kas/Rekening Sumber**, **Vendor** (opsional), **Referensi Eksternal**, dan **Keterangan Voucher**. Isi **Dana** bila dibiayai dana terikat (hanya di ERP Al Wafi).
3. Di **Rincian Baris**, pilih jenis **Beban / Akun Lainnya**, isi **Akun (Debit)**, **Bagian**, dan **Nominal**.
4. Tekan **Posting Kas Keluar**.

Pengeluaran yang berasal dari permintaan bagian sebaiknya lewat Pengajuan Pembayaran dulu supaya melewati persetujuan dan pemeriksaan anggaran. Butuh hak Kas Keluar (Buat).

## Apa saja jenis baris di Kas Keluar?
kata kunci: jenis baris kas keluar, pembayaran invoice, pelunasan pengajuan, pembayaran uang muka, pembelian persediaan, rincian kas keluar

Satu voucher Kas Keluar bisa berisi beberapa baris dengan jenis berbeda:

- **Beban / Akun Lainnya** — pengeluaran langsung ke akun tertentu; bisa diberi **Perlakuan Aset (kapitalisasi)**.
- **Pembayaran Invoice** — melunasi hutang Invoice Vendor; nominal maksimal sebesar sisa hutang.
- **Pelunasan Pengajuan Pembayaran** — membayar pengajuan yang sudah diposting, juga kekurangan penyelesaian uang muka.
- **Pembayaran Pengajuan Uang Muka** — mencairkan pengajuan uang muka yang sudah diverifikasi; harus dibayar penuh.
- **Pembelian Persediaan** — membeli barang persediaan; isi item, Qty, dan Harga Satuan.

Satu invoice atau satu pengajuan tidak boleh dipilih dua kali dalam voucher yang sama.

## Bagaimana membayar invoice vendor?
kata kunci: bayar invoice, pelunasan hutang vendor, bayar supplier, bayar tagihan vendor, cicil invoice, hutang usaha

1. Buka **KEUANGAN › Transaksi › Kas Keluar**, tekan **+ Kas Keluar Baru**.
2. Isi kepala voucher dan pilih **Kas/Rekening Sumber**.
3. Di baris, pilih jenis **Pembayaran Invoice**, pilih **Invoice**, lalu isi **Nominal Bayar** (boleh sebagian, maksimal sisa hutang).
4. Tekan **Posting Kas Keluar**.

Status invoice berubah menjadi sebagian atau lunas. Pesan "Nominal melebihi sisa hutang invoice …" berarti nominal terlalu besar. Bila invoice sedang dimasukkan ke sebuah Perintah Pembayaran, sistem meminta pembayaran lewat perintah itu ("Kewajiban ini sedang berada di perintah pembayaran …"). Butuh hak Kas Keluar (Buat).

## Bagaimana membayar pengajuan pembayaran yang sudah disetujui?
kata kunci: bayar pengajuan, pelunasan pengajuan, cairkan pengajuan, transfer pengajuan, pengajuan diposting, realisasi pengajuan

Pengajuan baru bisa dibayar sesudah diverifikasi dan diposting oleh tim keuangan (statusnya **diposting**).

1. Buka **KEUANGAN › Transaksi › Kas Keluar**, tekan **+ Kas Keluar Baru**.
2. Pilih **Kas/Rekening Sumber**. Unit Bisnis tidak wajib bila semua baris adalah pelunasan pengajuan, karena tiap pengajuan membawa unitnya sendiri.
3. Di baris, pilih **Pelunasan Pengajuan Pembayaran**, pilih pengajuannya, nominal terisi sebesar sisa (boleh dibayar sebagian).
4. Tekan **Posting Kas Keluar**.

Rekening tujuan yang dicantumkan pemohon terlihat di halaman detail pengajuan. Pesan "Pengajuan … berstatus …; hanya yang sudah disetujui & diposting yang bisa dibayar" berarti verifikasi keuangan belum selesai. Butuh hak Kas Keluar (Buat).

## Bagaimana mencatat pembelian aset tetap lewat Kas Keluar atau Invoice Vendor?
kata kunci: beli aset, kapitalisasi, perlakuan aset, pembelian inventaris, aset dari kas keluar, tambah nilai aset, draft aset

Di baris **Beban / Akun Lainnya** pada Kas Keluar, atau di baris Invoice Vendor, ada pilihan **Perlakuan Aset (kapitalisasi)**.

1. Pilih akun aset tetap pada baris itu.
2. Di **Perlakuan Aset**, pilih membuat aset baru (draft) atau menambah nilai aset yang sudah ada.
3. Posting voucher atau invoice-nya.

Aset baru tercatat berstatus **Draft (perlu dilengkapi)**. Lengkapi datanya (kategori, umur manfaat, metode depresiasi) di **KEUANGAN › Aset & Persediaan › Aset Tetap** lalu ubah statusnya menjadi Aktif supaya ikut disusutkan. Jangan mencentang tanda "pindahan sistem" untuk aset yang dibeli dengan cara ini, karena nilainya akan terhitung dua kali.

## Bagaimana mencetak bukti atau membatalkan (void) kas masuk dan kas keluar?
kata kunci: cetak bukti kas, print voucher, void kas keluar, batal kas masuk, salah input kas, batalkan voucher, kwitansi

**Mencetak:** buka voucher dari daftar Kas Masuk atau Kas Keluar (tekan **Lihat**), lalu tekan **🖨 Cetak Bukti**. Cukup hak Lihat.

**Membatalkan:**

1. Buka vouchernya, tekan **Void**.
2. Isi **Alasan void** (misalnya salah input), tekan **Konfirmasi Void**.

Jurnalnya dibalik dan status menjadi void. Bila voucher itu membayar invoice, pengajuan, atau perintah pembayaran, sisa hutang dokumen-dokumen itu ikut dikembalikan. Void butuh hak Kas Masuk/Kas Keluar (Hapus). Jurnal pembaliknya bertanggal hari pembatalan, jadi periode bulan berjalan harus masih terbuka. Pesan "Kas Keluar sudah di-void." berarti sudah dibatalkan sebelumnya. Aturan tanggal yang sama berlaku untuk void Kas Masuk.

## Bagaimana memindahkan dana antar rekening (Pindah Buku)?
kata kunci: pindah buku, transfer antar rekening, mutasi bank, setor ke bank, tarik dari bank, isi kas kecil, book transfer

1. Buka **KEUANGAN › Transaksi › Pindah Buku**, tekan **+ Pindah Buku Baru**.
2. Isi **Tanggal**, pilih **Rekening Asal (dana keluar)** dan **Rekening Tujuan (dana masuk)**.
3. Isi **Unit Bisnis** (opsional; kosong = default unit modul), **Nominal**, dan **Keterangan**.
4. Tekan **Posting Pindah Buku**.

Jurnalnya: debit rekening tujuan, kredit rekening asal. Rekening asal otomatis tidak muncul di pilihan tujuan. Pindah buku yang salah dibatalkan dengan **Void** di daftarnya beserta alasan. Butuh hak Pindah Buku (Buat; Hapus untuk void).

## Apa arti pesan galat yang sering muncul saat memposting Kas Keluar?
kata kunci: error kas keluar, unit bisnis wajib dipilih, melebihi sisa hutang, harus dibayar penuh, belum punya akun hutang, gagal posting kas keluar

- "Unit bisnis wajib dipilih — ada baris yang tidak menentukan unitnya sendiri." — isi Unit Bisnis di kepala voucher.
- "Pilih Akun COA pada baris beban/lainnya." / "Nominal harus > 0." — lengkapi baris.
- "Nominal melebihi sisa hutang …" — kurangi nominal.
- "Uang muka … harus dibayar PENUH sebesar …" — pengajuan uang muka tidak boleh dicairkan sebagian.
- "Kekurangan penyelesaian … harus dilunasi PENUH sebesar …" — kekurangan penyelesaian uang muka dibayar sekaligus.
- "Pengajuan … belum punya akun hutang; tidak dapat dibayar." — tim keuangan belum memverifikasi.
- "Item … belum memiliki Akun COA." — lengkapi akun di master Persediaan.
- "Kewajiban ini sedang berada di perintah pembayaran …" — bayar lewat Perintah Pembayaran itu.

## Bagaimana menambah vendor dan jenis vendor?
kata kunci: tambah vendor, supplier baru, pemasok, jenis vendor, data vendor, termin vendor, rekening vendor

1. Bila jenisnya belum ada, buka **KEUANGAN › Vendor & Customer › Jenis Vendor** dan tambahkan.
2. Buka **KEUANGAN › Vendor & Customer › Vendor**, tekan **+ Tambah Vendor**.
3. Isi **Kode Vendor**, **Nama Vendor**, **Jenis Vendor**, alamat, telepon.
4. Pilih **Metode Pembayaran** (Tunai atau Termin) dan isi **Termin (hari)** bila termin — dipakai untuk mengusulkan jatuh tempo invoice.
5. Isi **Bank**, **No. Rekening**, dan **Atas Nama** bila pembayarannya transfer, lalu simpan.

Vendor nonaktif tidak bisa dipakai di transaksi baru. Butuh hak Vendor dan Jenis Vendor.

## Bagaimana menambah customer dan jenis customer?
kata kunci: tambah customer, pelanggan baru, data customer, jenis customer, akun piutang customer, client

1. Bila jenisnya belum ada, tambahkan di **KEUANGAN › Vendor & Customer › Jenis Customer**.
2. Buka **KEUANGAN › Vendor & Customer › Customer**, tekan **+ Tambah Customer**.
3. Isi **Kode Customer**, **Nama Customer**, **Jenis Customer**, lalu **Akun Pendapatan** dan **Akun Piutang** bila ada.
4. Isi alamat, telepon, email, status, lalu simpan.

Customer bisa dipilih di Kas Masuk dan muncul di daftar Uang Muka Customer. Butuh hak Customer dan Jenis Customer.

## Bagaimana mencatat invoice vendor untuk jasa atau beban (bukan barang)?
kata kunci: invoice vendor, tagihan jasa, hutang usaha, faktur vendor, catat hutang, invoice beban, tagihan supplier

1. Buka **KEUANGAN › Transaksi › Invoice Vendor**, tekan **+ Invoice Baru**.
2. Isi **Nomor Invoice (dari vendor)**, **Tanggal Invoice**, **Jatuh Tempo**, **Vendor**, **Unit Bisnis**, dan **Akun Hutang Usaha**.
3. Di **Rincian Item**, isi **Akun (Beban/Aset)**, Qty, Harga, keterangan, dan **Bagian** (wajib untuk akun Beban).
4. Tekan **Posting Invoice**.

Jurnalnya: debit akun beban/aset, kredit hutang usaha. Hutangnya muncul di Aging AP dan dibayar lewat Kas Keluar. Untuk barang persediaan dari Purchase Order atau Penerimaan Barang, pakai **Muat dari Penerimaan** (lihat panduan Wafi Mart tentang invoice pembelian). Butuh hak Invoice Vendor (Buat).

## Bagaimana mencatat hutang vendor lama dari sistem sebelumnya?
kata kunci: hutang vendor lama, saldo awal hutang, invoice lama, pindahan sistem hutang, akun perantara saldo awal, cut-off

Pakai layar **KEUANGAN › Transaksi › Invoice Vendor** dengan dua penyesuaian:

1. **Tanggal Invoice** diisi tanggal cut-off pembukuan, sedangkan **Jatuh Tempo** tetap tanggal aslinya supaya umur hutangnya benar.
2. Baris rinciannya diarahkan ke **akun perantara saldo awal**, bukan akun beban, karena bebannya milik periode lalu.

Invoice ini menerbitkan jurnal (debit perantara, kredit hutang), jadi hutangnya sudah masuk buku besar dan **tidak** ikut sebagai baris turunan di Saldo Awal. Yang perlu diseimbangkan di Saldo Awal adalah saldo akun perantaranya. Untuk banyak invoice sekaligus, lebih cepat lewat **Impor Data Awal** jenis Hutang Vendor.

## Bagaimana membatalkan invoice vendor yang salah?
kata kunci: void invoice, batalkan invoice, invoice salah, hapus tagihan vendor, invoice dobel, koreksi invoice

1. Buka invoicenya dari **KEUANGAN › Transaksi › Invoice Vendor** (tekan **Lihat**).
2. Tekan **Void**, isi **Alasan void**, lalu **Konfirmasi Void**.

Void hanya bisa dilakukan bila belum ada pembayaran. Pesan "Invoice sudah dibayar sebagian/lunas. Void pembayaran (Kas Keluar) terkait terlebih dahulu." berarti Anda harus membatalkan voucher Kas Keluar yang membayarnya dulu. Sesudah di-void, catat ulang invoice yang benar. Butuh hak Invoice Vendor (Hapus).

## Apakah ada menu invoice customer atau piutang customer?
kata kunci: invoice customer, piutang customer, tagihan ke customer, faktur penjualan, ar, account receivable, tagih customer

Saat ini tidak ada menu khusus untuk menerbitkan invoice ke customer. Penerimaan dari customer dicatat di **KEUANGAN › Transaksi › Kas Masuk** dengan memilih Customer, dan uang muka customer dilacak di **KEUANGAN › Kontrol › Uang Muka Customer**. Piutang santri (SPP, uang pangkal, tagihan lain) diterbitkan dari modul santri di ERP Al Wafi, bukan dari menu keuangan. Bila perlu mencatat piutang customer secara manual, gunakan Jurnal Umum dengan akun piutang customer, lalu pelunasannya lewat Kas Masuk berjenis Pelunasan. Tanyakan ke kepala keuangan bila ragu memilih caranya.

## Bagaimana memantau hutang vendor dan saldo outstanding lainnya?
kata kunci: aging ap, umur hutang, hutang jatuh tempo, ringkasan outstanding, hutang vendor belum lunas, outstanding

- **KEUANGAN › Kontrol › Ringkasan Outstanding** — kartu ringkas Hutang Vendor, Uang Muka Operasional, Uang Muka Customer, serta Accrue & Prepaid aktif, ditambah tabel umur hutang (Belum Jatuh Tempo, 1–30 hari, 31–60, 61–90, >90).
- **KEUANGAN › Kontrol › Aging AP** — daftar invoice belum lunas: vendor, unit, jatuh tempo, hari lewat, sisa hutang, plus total per vendor.
- **KEUANGAN › Kontrol › Uang Muka Operasional**, **Accrue & Prepaid**, **Rekap Pembiayaan** — rincian masing-masing.

Semua layar ini hanya membaca, tidak membuat jurnal, dan bisa diunduh. Butuh hak Kontrol Outstanding (Aging, Uang Muka, Rekap).

## Bagaimana alur pengajuan pembayaran dari diajukan sampai dibayar?
kata kunci: alur pengajuan, proses pengajuan pembayaran, tahapan persetujuan, approval pembayaran, pb, flow pengajuan

1. **Pemohon** (level yang boleh mengajukan) membuat pengajuan di **PENGAJUAN PEMBAYARAN › Buat Pengajuan**. Status **diajukan**.
2. **Rantai persetujuan** berjalan per tahap sesuai Level Pengajuan, misalnya pimpinan bagian lalu pimpinan di atasnya. Bila overbudget atau belum dianggarkan, ditambah tahap eskalasi ke pimpinan tertinggi.
3. Setelah seluruh tahap setuju, **tim keuangan** memverifikasi: menentukan akun hutang, bisa mengoreksi akun atau rekening tujuan, lalu **Verifikasi & Posting**. Status **diposting**.
4. Keuangan **membayar** lewat Kas Keluar (langsung atau lewat Perintah Pembayaran). Status **lunas** bila sudah dibayar penuh.

Bila ditolak di salah satu tahap, pengajuan kembali ke pemohon untuk diperbaiki. Nama tahap mengikuti pengaturan tiap instans.

## Bagaimana membuat pengajuan pembayaran?
kata kunci: buat pengajuan, ajukan pembayaran, form pengajuan, minta dana, request pembayaran, pengajuan biaya, reimburse

1. Buka **PENGAJUAN PEMBAYARAN › Buat Pengajuan › Buat Pengajuan Pembayaran**.
2. Bagian pemohon terisi otomatis dari profil Anda. Isi **Tanggal**, **Referensi**, dan **Keterangan**.
3. Isi **Rekening Tujuan Pembayaran** bila dibayar transfer: **Nama Bank**, **Nomor Rekening**, dan **Atas Nama Pemilik Rekening** — ketiganya wajib bila salah satu diisi. Kosongkan bila tunai. Centang **Simpan ke daftar rekening saya** agar bisa dipanggil lagi.
4. Isi rincian: **Akun (Beban/Biaya)**, **Unit Bisnis**, **Keterangan**, **Nominal**.
5. Lampirkan invoice, penawaran, atau nota bila ada (boleh menyusul).
6. Tekan **Ajukan**.

Nomor final ditetapkan saat diajukan. Satu akun untuk unit yang sama tidak boleh muncul dua kali — gabungkan barisnya. Butuh hak Pengajuan Pembayaran (Buat).

## Kenapa menu Buat Pengajuan tidak muncul atau muncul pesan "Level pengajuan Anda tidak berwenang"?
kata kunci: tidak bisa mengajukan, menu buat pengajuan hilang, level pengajuan tidak berwenang, tidak boleh mengajukan, staff only, peran pengajuan

Menu **Buat Pengajuan** hanya tampil bagi pengguna yang Level Pengajuannya bertanda peran **Boleh mengajukan pembayaran** (dan punya hak Pengajuan Pembayaran). Pesan "Level pengajuan Anda tidak berwenang membuat pengajuan pembayaran. Wewenang itu diatur di Setting Awal → Level Pengajuan." berarti peringkat pengajuan Anda belum punya peran itu.

Minta admin memeriksa **Peringkat Pengajuan** di data Pengguna Anda, atau mencentang peran tersebut di **SETTING AWAL › Setting Umum › Level Pengajuan**. Peringkat angka tidak menentukan wewenang — yang menentukan adalah tanda perannya.

## Muncul pesan "Profil Anda belum ditempatkan di bagian mana pun", apa artinya?
kata kunci: belum ada bagian, profil tanpa bagian, bagian kosong, tidak bisa ajukan anggaran, bagian pemohon, bagian nonaktif

Pengajuan pembayaran dan anggaran selalu dibebankan ke bagian pemohon, jadi akun Anda harus punya **Bagian**. Pesan ini (atau keterangan "Akun Anda belum ditempatkan di bagian mana pun" di layar pengajuan) berarti kolom Bagian pada data Pengguna Anda masih kosong.

Minta admin mengisi Bagian di **SETTING AWAL › Setting Umum › Pengguna**. Pesan "Bagian "…" berstatus nonaktif." berarti bagiannya sudah dinonaktifkan; minta admin mengaktifkannya atau memindahkan Anda ke bagian lain.

## Bagaimana menyetujui atau menolak pengajuan pembayaran?
kata kunci: setujui pengajuan, tolak pengajuan, approve, reject, persetujuan atasan, keputusan pengajuan, approval

1. Buka **SISTEM › Persetujuan Saya**, atau buka detail pengajuannya dari **PENGAJUAN PEMBAYARAN › Pengajuan › Status Pengajuan**.
2. Periksa rincian, lampiran, dan rantai persetujuan (ada penanda **Overbudget** atau **Belum dianggarkan** bila berlaku).
3. Tekan **Setujui** (catatan opsional) untuk meneruskan ke tahap berikutnya, atau **Tolak** dengan **Alasan penolakan** untuk mengembalikan ke pemohon.

Hanya penyetuju tahap yang sedang berjalan yang bisa memutus. Pesan "Tahap "…" hanya bisa disetujui oleh …" atau "Anda bukan penyetuju dari bagian pemohon." berarti tahap itu bukan wewenang Anda. Bila di kolom **Menunggu Di** tertulis "tak ada penyetuju", berarti belum ada pengguna aktif di posisi itu — pengajuan tertahan sampai admin mengisinya di menu Pengguna.

## Apa arti "Overbudget" dan "Belum dianggarkan" pada pengajuan?
kata kunci: overbudget, melebihi anggaran, belum dianggarkan, eskalasi, ketua yayasan, anggaran habis, sisa anggaran

Saat pengajuan pembayaran dibuat, sistem memeriksa anggaran akun tersebut untuk bagian pemohon secara kumulatif sejak awal tahun anggaran sampai bulan pengajuan: anggaran dibandingkan dengan realisasi jurnal ditambah pengajuan lain yang masih berjalan.

- **Overbudget** — pengajuan ini membuat pemakaian melewati anggaran.
- **Belum dianggarkan** — akun itu tidak punya anggaran untuk bagian tersebut.

Keduanya tidak memblokir pengajuan, tetapi rantainya ditambah **tahap eskalasi** ke pimpinan tertinggi (tampil sebagai peringatan di rantai persetujuan). Pengajuan uang muka tidak diperiksa anggarannya; pemeriksaan dilakukan saat penyelesaiannya.

## Bagaimana memperbaiki dan mengajukan ulang pengajuan yang ditolak?
kata kunci: pengajuan ditolak, perbaiki pengajuan, ajukan ulang, revisi pengajuan, dikembalikan, resubmit

1. Buka **PENGAJUAN PEMBAYARAN › Pengajuan › Status Pengajuan**.
2. Pada pengajuan berstatus ditolak milik Anda, tekan **Perbaiki**, ubah isinya, lalu **Simpan Perbaikan**.
3. Kembali ke daftar, tekan **Ajukan Ulang** dan konfirmasi.

Rantai persetujuan belum berjalan lagi sampai Anda menekan Ajukan Ulang. Nomor dokumen tetap dan riwayat penolakannya tersimpan. Hanya pemohon yang bisa memperbaiki dan mengajukan ulang. Butuh hak Pengajuan Pembayaran (Buat).

## Bagaimana tim keuangan memverifikasi dan memposting pengajuan?
kata kunci: verifikasi pengajuan, posting pengajuan, akun hutang pengajuan, verifikasi keuangan, tim keuangan, posting pb

1. Buka detail pengajuan yang rantainya sudah selesai (dari **Status Pengajuan**).
2. Di kotak **Verifikasi Keuangan**, isi **Akun Hutang Pengajuan (Liabilitas, kelompok 2)** untuk pengajuan pembayaran.
3. Bila perlu, isi **Koreksi Akun** per baris dan/atau ubah **Rekening Tujuan Pembayaran** beserta alasannya.
4. Isi catatan, tekan **Verifikasi & Posting**.

Jurnalnya: debit akun yang diajukan, kredit akun hutang; status menjadi diposting dan siap dibayar lewat Kas Keluar. Hanya **Anggota Tim Keuangan** dengan hak Pengajuan Pembayaran (Ubah) yang bisa memverifikasi. Pesan "Pengajuan ini belum disetujui seluruh approver; verifikasi keuangan menunggu rantai selesai." berarti masih ada tahap yang berjalan. Akun hutang harus kelompok 2 dan tidak boleh sama dengan akun yang diajukan.

## Bagaimana keuangan mengoreksi akun atau rekening tujuan pengajuan?
kata kunci: koreksi akun pengajuan, salah akun, ganti rekening tujuan, ubah rekening transfer, akun pengajuan salah, koreksi pb

Koreksi dilakukan di kotak **Verifikasi Keuangan** pada detail pengajuan, selama pengajuan belum diposting:

- **Koreksi Akun (opsional)** — pilih akun pengganti per baris; biarkan "— biarkan —" bila sudah benar. Nominal dan unit tidak berubah. Pemohon dan atasannya diberi tahu beserta status anggaran akun yang baru.
- **Rekening Tujuan Pembayaran** — ubah nama bank, nomor rekening, atau atas nama, dan wajib isi **Alasan perubahan rekening**. Perubahan dicatat permanen (nilai lama, nilai baru, alasan, nama Anda) dan diberitahukan ke pemohon.

Hanya Anggota Tim Keuangan yang bisa melakukannya. Pesan "Rekening tujuan harus lengkap: nama bank, nomor rekening, dan atas nama pemilik rekening." berarti ada isian yang kosong.

## Bagaimana membatalkan (void) pengajuan pembayaran?
kata kunci: void pengajuan, batalkan pengajuan, hapus pengajuan, pengajuan dobel, cancel pengajuan, tarik pengajuan

1. Buka **PENGAJUAN PEMBAYARAN › Pengajuan › Status Pengajuan**, tekan **Void** pada barisnya (atau **Batalkan** di halaman detail).
2. Isi alasan, lalu konfirmasi.

Pengajuan berstatus diajukan atau ditolak bisa dibatalkan oleh pemohonnya sendiri atau admin. Yang sudah diverifikasi atau diposting hanya bisa dibatalkan oleh tim keuangan atau admin; jurnal hutangnya dibalik. Pengajuan yang sudah dibayar (sebagian maupun penuh) tidak bisa di-void — batalkan dulu voucher Kas Keluar yang membayarnya. Butuh hak Pengajuan Pembayaran (Hapus).

## Bagaimana mengajukan uang muka untuk kegiatan?
kata kunci: uang muka, kasbon, panjar, advance, pengajuan uang muka, dana kegiatan, cash advance, dp kegiatan

1. Buka **PENGAJUAN PEMBAYARAN › Buat Pengajuan › Buat Pengajuan Uang Muka**.
2. Isi tanggal, referensi, keterangan, dan rekening tujuan bila transfer.
3. Di **Rincian Uang Muka (akun Aset)**, pilih **Akun Uang Muka** (harus akun kelompok Aset), unit, dan nominal.
4. Tekan **Ajukan**.

Rantai persetujuannya sama dengan pengajuan pembayaran. Setelah disetujui, tim keuangan menekan **Verifikasi** (status **diverifikasi**, siap dibayar); jurnalnya baru terbit saat dicairkan lewat Kas Keluar jenis **Pembayaran Pengajuan Uang Muka**, dan harus dibayar penuh. Pesan "Akun … bukan Aset. Uang muka harus akun kelompok 1 (Aset)." berarti akun yang dipilih salah.

## Bagaimana mempertanggungjawabkan (menyelesaikan) uang muka lewat pengajuan?
kata kunci: penyelesaian uang muka, pertanggungjawaban, lpj, realisasi uang muka, settlement, laporan kasbon, outstanding uang muka

1. Lihat uang muka Anda yang belum selesai di **PENGAJUAN PEMBAYARAN › Pengajuan › Outstanding Uang Muka**, lalu tekan **Selesaikan** (atau buka **Buat Pengajuan › Buat Penyelesaian Uang Muka**).
2. Pilih **Uang Muka (milik Anda)**.
3. Di **Rincian Realisasi (akun Beban)**, isi akun beban, unit, dan nominal sesuai nota.
4. Lampirkan nota/kwitansi, lalu tekan **Ajukan**.

Penyelesaian melewati rantai persetujuan, lalu tim keuangan menekan **Verifikasi & Posting Penyelesaian**. Anda hanya bisa menyelesaikan uang muka yang Anda ajukan sendiri. Pesan "Uang muka ini sudah selesai (tidak ada sisa)." berarti sudah tuntas.

## Apa yang terjadi bila realisasi lebih besar atau lebih kecil dari uang muka?
kata kunci: realisasi lebih, realisasi kurang, kelebihan uang muka, kekurangan uang muka, kembalikan sisa uang muka, selisih uang muka

Saat memverifikasi penyelesaian, tim keuangan melihat selisihnya:

- **Realisasi melampaui uang muka** — kekurangannya tidak langsung mengurangi kas, tetapi ditahan sebagai kewajiban. Keuangan memilih **Akun Hutang Penampung Kekurangan**, lalu kekurangan itu dibayar lewat Kas Keluar (Pelunasan Pengajuan Pembayaran) secara penuh, dan juga muncul di Perintah Pembayaran.
- **Uang muka melebihi realisasi** — kelebihannya dianggap sudah kembali, jadi keuangan memilih **Kas/Rekening Penerima Pengembalian** dan kasnya diakui saat posting. Pastikan uang sisanya benar-benar sudah disetor.
- **Persis sama** — tidak ada kas yang berpindah.

Pesan "Realisasi melampaui uang muka — tentukan akun hutang penampung kekurangannya." atau "Uang muka melebihi realisasi — tentukan kas/rekening penerima pengembaliannya." berarti isian itu belum dipilih.

## Bagaimana keuangan mencatat uang muka operasional secara langsung?
kata kunci: uang muka operasional, kasbon langsung, advance langsung, uang muka tanpa pengajuan, catat uang muka, um operasional

Uang muka dari pengajuan tercatat otomatis saat dicairkan. Untuk uang muka yang dicatat langsung oleh keuangan:

1. Buka **KEUANGAN › Transaksi › Uang Muka Operasional**, tekan **+ Uang Muka Baru**.
2. Isi **Tanggal**, **Penerima**, **Akun Uang Muka**, **Kas/Rekening Sumber**, **Nominal**, **Unit Bisnis**, **Bagian**, dan **Keterangan**.
3. Biarkan centang **Posting jurnal pengeluaran sekarang** menyala, lalu tekan **Posting Uang Muka**.

Matikan centang itu hanya untuk uang muka pindahan sistem yang sudah diserahkan sebelum aplikasi dipakai (tanpa jurnal; masuk baris turunan Saldo Awal). Uang muka yang masih outstanding dan belum diselesaikan bisa di-void dengan alasan. Butuh hak Uang Muka Operasional (Buat; Hapus untuk void).

## Bagaimana mencatat penyelesaian uang muka operasional secara langsung?
kata kunci: penyelesaian uang muka langsung, settlement keuangan, realisasi um, tutup uang muka, pertanggungjawaban keuangan

1. Buka **KEUANGAN › Transaksi › Penyelesaian Uang Muka**, tekan **+ Penyelesaian Baru**.
2. Pilih **Uang Muka** yang masih outstanding (sisanya ditampilkan).
3. Isi **Tanggal**, **Nominal Uang Muka Diselesaikan**, **Akun Realisasi (beban/aset)**, dan **Nominal Realisasi (aktual)**.
4. Pilih **Kas/Rekening (untuk selisih)**, **Unit Bisnis**, **Bagian**, isi keterangan.
5. Tekan **Posting Penyelesaian**.

Jurnalnya mengkredit akun uang muka dan mendebit akun realisasi; selisihnya lewat kas/rekening yang dipilih. Akun uang muka dan akun realisasi tidak boleh sama. Pesan "Nominal uang muka melebihi sisa outstanding (…)" berarti nominal yang diselesaikan terlalu besar. Lampiran nota bisa diunggah dari daftar. Butuh hak Penyelesaian Uang Muka (Buat).

## Apa itu Perintah Pembayaran dan kapan dipakai?
kata kunci: perintah pembayaran, pp, payment order, jadwal bayar, rencana pembayaran, otorisasi bayar, daftar bayar mingguan

**KEUANGAN › Transaksi › Perintah Pembayaran** dipakai untuk menyusun daftar kewajiban yang akan dibayar (pengajuan yang sudah diposting atau diverifikasi, invoice vendor, dan angsuran pembiayaan), lalu dimintakan otorisasi pejabat sebelum uangnya keluar.

Perintah Pembayaran **tidak menjurnal**; uang tetap tercatat sekali di Kas Keluar. Kegunaannya: pejabat menetapkan apa yang boleh dibayar, kapan, dan dengan metode apa, dan sistem menjaga agar total yang diotorisasi tidak melampaui dana bebas. Kewajiban tanpa dokumen (misalnya gaji atau pajak) harus dibuat sebagai Pengajuan Pembayaran dulu. Memakai Perintah Pembayaran tidak wajib — kewajiban tetap bisa dibayar langsung lewat Kas Keluar selama tidak sedang berada di sebuah perintah.

## Bagaimana menyusun Perintah Pembayaran?
kata kunci: buat perintah pembayaran, susun pp, pilih kewajiban, draf pp, ajukan otorisasi, rencana bayar

1. Buka **KEUANGAN › Transaksi › Perintah Pembayaran**, tekan **+ Perintah Baru**.
2. Isi **Tanggal**, **Usulan Tanggal Bayar**, dan **Keterangan**.
3. Di **Pilih kewajiban**, saring per sumber atau cari nomor dokumen, lalu centang kewajiban dan isi **Dibayar Kali Ini**.
4. Perhatikan **Saldo yang bisa digunakan**; bila total melebihi, muncul peringatan dan perintahnya tidak akan bisa diajukan.
5. Tekan **Simpan Draf**, lalu di halaman detail tekan **Ajukan Otorisasi**.

Kewajiban yang sedang berada di perintah lain tetap tampil dengan nomor perintahnya dan tidak bisa dimasukkan dua kali. Menyusun butuh hak Perintah Pembayaran (Buat); mengajukan otorisasi butuh (Ubah).

## Bagaimana pejabat mengotorisasi atau menolak Perintah Pembayaran?
kata kunci: otorisasi pembayaran, setujui pp, tolak pp, pejabat keuangan, approve payment order, tunda pembayaran

1. Buka perintah berstatus **Menunggu Otorisasi** dari **KEUANGAN › Transaksi › Perintah Pembayaran**.
2. Periksa **Dana yang bisa dipakai** dan **Saldo aktual per rekening**.
3. Per baris, ubah nominal bila perlu; isi **nol** untuk menunda baris itu (kewajibannya dilepas dan bisa diajukan lagi), dan tulis alasan bila menunda atau mengurangi.
4. Tetapkan **Tanggal Bayar**, **Metode**, dan **Rekening Sumber (rencana)**, isi catatan, tekan **Otorisasi Pembayaran**.

Untuk menolak semua, isi alasan lalu tekan **Tolak Seluruhnya**. Penyusun perintah tidak boleh mengotorisasi atau menolak perintahnya sendiri. Butuh hak Otorisasi Perintah Pembayaran (Ubah) — modul ini tidak punya menu sendiri; minta admin memberikannya hanya kepada pejabat yang berwenang.

## Bagaimana membayar dari Perintah Pembayaran dan menutupnya?
kata kunci: register kas keluar, bayar pp, realisasi perintah pembayaran, pp sudah selesai, tutup perintah, kepatuhan realisasi

1. Buka perintah yang sudah diotorisasi, tekan **Register Kas Keluar**.
2. Layar Kas Keluar terisi dari perintah itu — hanya baris yang diotorisasi dan masih bersisa yang muncul. Pilih rekening, sesuaikan bila perlu, lalu posting. Sisa yang belum dibayar boleh dibayar menyusul, termasuk dari rekening lain.
3. Bila sudah tuntas, tekan **PP Sudah Selesai**. Bila masih ada sisa, alasan wajib diisi; kewajiban yang belum dibayar dinyatakan batal dibayar dari perintah ini, sedangkan dokumen aslinya tetap utuh dan bisa diajukan lagi.

Menutup boleh dilakukan pengotorisasi atau staf yang punya hak Kas Keluar (Buat). Laporan **Kepatuhan Realisasi** (tombol di daftar perintah) membandingkan yang diotorisasi dengan yang benar-benar dibayar: tanggal, rekening, metode, dan sisa.

## Apa itu "Dana bebas dipakai" dan pesan "melebihi dana yang bisa dipakai"?
kata kunci: dana bebas, dana yang bisa dipakai, saldo tersedia, titipan, komitmen, melebihi dana, saldo tidak cukup

Saldo kas dan bank bukan seluruhnya milik lembaga — di dalamnya ada titipan orang lain, misalnya tabungan santri atau dompet wali. Karena itu Perintah Pembayaran memakai **Dana bebas dipakai** = saldo seluruh kas & bank − saldo akun titipan (akun pengurang) − perintah lain yang sudah diotorisasi tetapi belum dibayar.

Pesan "Total yang diajukan … melebihi dana yang bisa dipakai (…)" atau "Total otorisasi … melebihi dana yang bisa dipakai" berarti total perintah lebih besar dari angka itu. Kurangi nominal atau tunda sebagian baris, atau tunggu perintah lain selesai dibayar. Rinciannya tampil di atas daftar Perintah Pembayaran.

## Bagaimana mengatur akun pengurang dana bebas?
kata kunci: akun pengurang, titipan, dana bebas, akun titipan santri, pengurang saldo kas, setting dana bebas

1. Buka **KEUANGAN › Transaksi › Perintah Pembayaran**, tekan tautan **Atur akun pengurang →**.
2. Centang **Kurangi** pada akun yang saldonya merupakan uang titipan (misalnya tabungan atau dompet). Pilihan **Hanya akun kewajiban** membantu menyaring daftar.
3. Tekan **Simpan**.

Mengubah daftar ini mengubah batas seluruh perintah pembayaran, jadi hanya untuk pejabat keuangan. Butuh hak Akun Pengurang Dana Bebas (modul tanpa menu; Lihat untuk membuka, Ubah untuk menyimpan).

## Bagaimana admin mengisi anggaran (Input Anggaran)?
kata kunci: input anggaran, budget, isi anggaran, rencana anggaran, rkat, anggaran bulanan, anggaran per bagian

1. Buka **ANGGARAN › Input Anggaran**.
2. Pilih **Tahun Anggaran**, **Bagian**, dan **Unit**, tekan **Muat**.
3. Admin: pilih akun di **Tambah Akun ke Anggaran**, isi nominal per bulan, lalu tekan **Simpan Langsung**.

Anggaran hanya untuk akun Beban dan selalu dimiliki satu bagian, untuk 12 bulan tahun anggaran (mulai dari Bulan Awal Anggaran di Pengaturan Perusahaan). Pengguna bukan admin hanya melihat anggaran bagiannya sendiri; perubahan dari bagian lewat **Ajukan Anggaran**. Melihat butuh hak Anggaran (Input, Pengajuan & Realisasi) (Lihat); simpan langsung khusus administrator.

## Bagaimana bagian mengajukan anggaran?
kata kunci: ajukan anggaran, usulan anggaran, pengajuan anggaran, revisi anggaran, pa, status pengajuan anggaran

1. Buka **ANGGARAN › Ajukan Anggaran**.
2. Pilih **Tahun Anggaran** dan **Unit**, tekan **Muat** (bagian otomatis dari profil Anda).
3. Tabel sudah berisi anggaran yang berlaku sekarang — ubah seperlunya, tambah akun lewat **Tambah Akun ke Usulan**, isi keterangan.
4. Tekan **Ajukan untuk Disetujui**.

Usulan ini adalah **salinan penuh**: saat disetujui ia menggantikan seluruh anggaran bagian dan unit itu, dan akun yang tidak tercantum akan terhapus — jadi jangan dikosongkan. Anggaran baru berlaku setelah rantai persetujuan tuntas. Pantau di **ANGGARAN › Status Pengajuan Anggaran**; pemohon atau admin bisa **Batal** selama masih diajukan atau ditolak. Menu ini hanya tampil bagi level bertanda **Boleh mengajukan anggaran**. Butuh hak Anggaran (Buat).

## Bagaimana mengunci atau membuka kunci anggaran?
kata kunci: kunci anggaran, buka kunci anggaran, anggaran terkunci, lock budget, anggaran tidak bisa diubah

Di **ANGGARAN › Input Anggaran**, admin bisa menekan **🔒 Kunci Anggaran** untuk tahun anggaran yang dipilih. Penguncian membekukan anggaran seluruh bagian dan unit tahun itu: pengajuan anggaran baru ditolak dan simpan langsung pun ikut terkunci, termasuk bagi admin.

Untuk membukanya, admin menekan **🔓 Buka Kunci**. Pesan "Anggaran Tahun Anggaran … terkunci. Minta administrator membuka kuncinya sebelum mengubah anggaran." berarti tahun itu sedang dikunci. Mengunci dan membuka kunci khusus administrator.

## Bagaimana membaca Realisasi Anggaran?
kata kunci: realisasi anggaran, budget vs actual, varians, capaian anggaran, serapan anggaran, sisa anggaran, laporan anggaran

1. Buka **ANGGARAN › Realisasi Anggaran**.
2. Pilih **Tahun Anggaran**, **Unit**, dan **Bagian**, tekan **Tampilkan**.
3. Tabel menampilkan per akun: **Anggaran**, **Realisasi**, **Varians**, dan **Capaian**. Klik akun untuk melihat rincian per bulan.

Realisasi dihitung dari mutasi jurnal. Anda melihat bagian yang menjadi wewenang Anda; pilihan "Semua Bagian" (bagi yang berwenang melihat seluruhnya) ikut menjumlahkan jurnal lama yang belum berbagian. Menu ini tampil untuk admin, pengguna di bagian tingkat pimpinan, dan pengguna yang levelnya terikat pada satu bagian. Bila menunya tidak muncul padahal Anda perlu, minta admin memeriksa bagian dan level Anda.

## Bagaimana menambah aset tetap dan kategori aset?
kata kunci: tambah aset, aset tetap, inventaris, kategori aset, harga perolehan, umur manfaat, metode depresiasi, fixed asset

1. Bila kategorinya belum ada, tambahkan di **KEUANGAN › Aset & Persediaan › Kategori Aset**.
2. Buka **KEUANGAN › Aset & Persediaan › Aset Tetap**, tekan **+ Tambah Aset**.
3. Isi **Nama Aset**, **Kategori**, **Harga Perolehan**, **Tanggal Perolehan**, **Kuantiti**, **Umur Manfaat (bulan)**, **Metode Depresiasi** (Garis Lurus atau Saldo Menurun), **Nilai Residu**, dan **Akun COA Aset**.
4. Pilih **Status** (Draft, Aktif, atau Dilepas), lalu **Simpan**.

Centang tanda **pindahan sistem** (dan isi **Akumulasi Depresiasi Awal**) hanya untuk aset yang sudah dimiliki sebelum aplikasi dipakai — nilainya masuk baris turunan Saldo Awal. Jangan dicentang untuk aset yang dibeli lewat Kas Keluar atau Pengajuan Pembayaran. Butuh hak Aset Tetap dan Kategori Aset.

## Bagaimana menjalankan depresiasi (penyusutan) bulanan?
kata kunci: depresiasi, penyusutan, penyusutan aset, susut aset, aset tetap, jalankan depresiasi, akumulasi penyusutan, beban penyusutan, susut bulanan

1. Buka **KEUANGAN › Aset & Persediaan › Aset Tetap**, tekan **Jalankan Depresiasi**.
2. Pilih **Akun Beban Depresiasi**, **Akun Akumulasi Depresiasi**, dan **Unit Bisnis**.
3. Tekan **Posting Depresiasi**.

Sistem menjurnal satu bulan depresiasi untuk semua aset berstatus aktif (debit beban, kredit akumulasi) dan menambah akumulasi tiap aset. Tiap periode hanya boleh sekali — pesan "Depresiasi periode … sudah dijurnal (…)" berarti bulan itu sudah dijalankan. Pesan "Tidak ada nilai depresiasi yang perlu dijurnal" berarti semua aset aktif sudah mencapai nilai residu. Aset berstatus draft tidak ikut. Butuh hak Aset Tetap (Ubah).

## Bagaimana melepas aset yang dijual, dihibahkan, atau dihapuskan?
kata kunci: pelepasan aset, jual aset, hibah aset, aset rusak, hapus aset, disposal, write off aset, aset tidak bisa dihapus

1. Buka **KEUANGAN › Aset & Persediaan › Pelepasan Aset**.
2. Pilih **Aset**, **Tanggal**, dan **Perlakuan** (Dijual, Dihibahkan, atau Dihapuskan (rusak / usang)).
3. Bila dijual, isi **Harga Jual** dan **Rekening Penerima**.
4. Pilih **Akun Akumulasi Penyusutan**, **Akun Laba/Rugi Pelepasan**, **Unit Bisnis**, **Bagian**, dan isi **Alasan Pelepasan** (wajib).
5. Tekan **Lepaskan Aset**.

Penyusutannya berhenti sendiri. Tombol **Hapus** di daftar Aset Tetap ditolak untuk aset yang sudah disusutkan atau punya transaksi ("Pakai menu Pelepasan Aset agar ada jurnalnya"). Pelepasan yang salah bisa di-void dengan alasan; asetnya aktif kembali. Aset berstatus draft harus dilengkapi dulu. Butuh hak Aset Tetap (Hapus).

## Bagaimana mencatat pemakaian persediaan atau penyesuaian stok dari sisi keuangan?
kata kunci: persediaan, pemakaian barang, mutasi stok, kartu stok, penyesuaian opname, stok pesantren, atk, barang habis pakai

Di ERP Al Wafi, layar persediaan ada di **KEUANGAN › Aset & Persediaan › Persediaan** (di ERP Wafi Mart ada di grup WAFI MART).

1. Pada baris barang, tekan **Mutasi**.
2. Pilih **Jenis**: **Pemakaian Barang** (isi Jumlah dipakai dan **Bagian pemakai**) atau **Penyesuaian Opname** (isi Stok hasil hitung).
3. Pilih **Lokasi** dan **Tanggal**, isi keterangan, tekan **Simpan & Jurnalkan**.

Jurnalnya otomatis memakai harga pokok persediaan. Tombol **Kartu Stok** menampilkan riwayat keluar-masuk barang. Barang bertanda "akun belum lengkap" perlu dilengkapi akunnya dulu. Mencatat mutasi butuh hak Persediaan (Ubah). Laporan nilai stok ada di **KEUANGAN › Laporan › Persediaan**.

## Bagaimana mencatat pembiayaan bank dan membayar angsurannya?
kata kunci: pembiayaan bank, pinjaman bank, angsuran bank, kredit bank, margin, bagi hasil, akad, cicilan bank, hutang bank

**Mencatat pembiayaan:**

1. Buka **KEUANGAN › Transaksi › Pembiayaan**, tekan **+ Pembiayaan Baru**.
2. Isi **Nama Bank / Lembaga**, **Nomor Kontrak**, **Jenis Akad**, **Pokok Pembiayaan**, **Margin/Bagi Hasil**, **Tenor (bulan)**, **Tanggal Mulai**, **Jatuh Tempo**, **Akun Hutang Pembiayaan**, **Akun Beban Margin**, dan **Rekening Pencairan**.
3. Biarkan centang posting jurnal pencairan menyala (debit kas/bank, kredit hutang), lalu **Simpan Pembiayaan**.

**Membayar angsuran:** lewat **Kas Keluar**, pilih pembiayaannya di **Angsuran Pembiayaan (opsional)**, lalu buat baris pokok ke akun hutang pembiayaan dan baris margin ke akun beban margin. Voucher wajib memuat baris pokok, dan pokok tidak boleh melebihi sisa pokok. Riwayat angsuran tampil di detail pembiayaan; rekap per bank di **KEUANGAN › Kontrol › Rekap Pembiayaan**. Butuh hak Pembiayaan Bank.

## Bagaimana mencatat pinjaman karyawan dan cicilannya?
kata kunci: pinjaman karyawan, kasbon karyawan, piutang karyawan, potong gaji, cicilan karyawan, hutang pegawai

1. Pastikan karyawannya ada di **SETTING AWAL › Setting Umum › Karyawan**.
2. Buka **KEUANGAN › Transaksi › Pinjaman Karyawan**, tekan **+ Pinjaman Baru**.
3. Pilih **Karyawan**, isi **Tanggal Akad**, **Pokok Pinjaman**, **Akun Piutang Karyawan**, dan **Kas/Rekening Pencairan** (biarkan **Catat pencairan uangnya sekarang** menyala).
4. Isi **Jadwal Termin** bila ada — jumlah seluruh termin harus sama persis dengan pokok. Tekan **Simpan**.

Cicilan dicatat dari detail pinjaman lewat **Catat Cicilan**: pilih **Cara Bayar** tunai (pilih kas penerima) atau **potong gaji** (pilih **Akun Beban Gaji**; bagian karyawan wajib terisi). Untuk potong gaji, bayarkan gaji netto lewat Kas Keluar agar total bebannya utuh. Termin hanya jadwal dan tidak berjurnal. Butuh hak Pinjaman Karyawan (Buat; Ubah untuk cicilan dan termin).

## Bagaimana melakukan rekonsiliasi bank?
kata kunci: rekonsiliasi bank, cocokkan rekening koran, bank reconciliation, saldo bank beda, cleared, selisih bank, mutasi rekening koran

1. Buka **KEUANGAN › Transaksi › Rekonsiliasi Bank**, tekan **+ Rekonsiliasi Baru**.
2. Pilih **Rekening Bank**, isi **Tanggal (cut-off)** dan **Saldo Rekening Koran**, tekan **Buat Draft**.
3. Di halaman rekonsiliasi, centang **Cleared** pada transaksi yang ada di rekening koran.
4. Untuk transaksi yang hanya ada di bank (biaya admin, bunga/bagi hasil), isi **Tambah Penyesuaian**: **Akun Lawan**, **Arah**, **Nominal**, **Keterangan**, lalu **Posting Penyesuaian**.
5. Bila **Selisih** sudah nol, tekan **Selesaikan Rekonsiliasi**.

Item yang sudah cleared dikunci dan tidak dimuat lagi di rekonsiliasi berikutnya. Pesan "Belum balance — selisih …" berarti masih ada yang belum dicocokkan. Draft yang belum selesai bisa dihapus dengan **Hapus Draft**. Butuh hak Rekonsiliasi Bank.

## Apa itu Rekonsiliasi Buku Pembantu dan apa yang harus dilakukan bila ada selisih?
kata kunci: rekonsiliasi buku pembantu, buku besar beda, selisih buku pembantu, cek kesehatan buku, saldo modul beda, kontrol buku

**KEUANGAN › Kontrol › Rekonsiliasi Buku Pembantu** menaruh dua angka berdampingan untuk tiap pos: saldo **Buku pembantu** (rincian di modulnya, misalnya hutang vendor atau dompet) dan saldo **Buku besar** akunnya. Keduanya seharusnya sama.

1. Pilih **Posisi tanggal**, tekan **Tampilkan**.
2. Pos bertanda **cocok** tidak perlu tindakan. Pada pos **selisih**, baca dulu catatannya — sebagian selisih punya sebab yang sudah diketahui.
3. Tekan **Buka rinciannya →** untuk menelusuri.

Layar ini tidak memperbaiki apa pun. Saldo buku pembantu adalah keadaan saat ini, jadi memundurkan tanggal hanya memundurkan sisi buku besarnya. Butuh hak Kontrol Outstanding (Aging, Uang Muka, Rekap).

## Bagaimana mendaftarkan dana terikat (wakaf, donasi, beasiswa)?
kata kunci: dana terikat, wakaf, donasi, beasiswa, dana bantuan, peruntukan dana, restricted fund, dana abadi

(Hanya di ERP Al Wafi.)

1. Buka **KEUANGAN › Kontrol › Dana Terikat**, tekan **+ Tambah Dana**.
2. Isi **Kode Dana**, **Nama Dana**, pilih **Jenis**: Tidak Terikat (boleh dipakai untuk apa saja), Terikat Temporer (gugur bila peruntukan tercapai atau masanya lewat), atau Terikat Permanen (pokoknya tidak boleh dipakai).
3. Isi **Donatur / Pemberi**, **Peruntukan** (wajib untuk dana terikat), **Mulai**, **Selesai**, **Target Nominal**, dan **Status**.
4. Di **Peruntukan yang Ditegakkan**, centang akun beban yang boleh dibebani dana ini, lalu simpan.

Bila daftar akun dibiarkan kosong, peruntukannya hanya tertulis dan tidak dijaga sistem. Dana yang sudah dipakai di jurnal tidak bisa dihapus — ubah statusnya menjadi nonaktif. Butuh hak Dana Terikat (wakaf, donasi, beasiswa, bantuan).

## Bagaimana menandai transaksi dengan dana terikat dan arti pesan "di luar peruntukan dana"?
kata kunci: pakai dana terikat, tandai dana, kolom dana, di luar peruntukan, dana nonaktif, belanja wakaf, penerimaan donasi

(Hanya di ERP Al Wafi.)

Di Kas Masuk (kolom **Dana (donasi / wakaf)**), Kas Keluar, dan Jurnal Umum (kolom **Dana (opsional)**), pilih dana yang bersangkutan. Penerimaan dan pemakaian yang ditandai inilah yang dihitung di laporan pertanggungjawaban dana; transaksi tanpa tanda dana tidak terhitung.

Pesan "Baris …: akun "…" berada di luar peruntukan dana "…"" berarti akun beban itu tidak termasuk akun yang diizinkan untuk dana tersebut. Pakai dana lain, atau minta pengelola menambahkan akun itu ke peruntukan dananya. Pesan "dana … berstatus nonaktif" berarti dana itu sudah tidak boleh dipakai.

## Bagaimana membuat Laporan Pertanggungjawaban Dana untuk donatur?
kata kunci: laporan pertanggungjawaban dana, laporan donatur, dana diterima terpakai, sisa dana, lpj donasi, laporan wakaf

(Hanya di ERP Al Wafi.)

1. Buka **KEUANGAN › Kontrol › Laporan Pertanggungjawaban Dana**.
2. Isi **Dari** dan **Sampai** (atau sejak awal), tekan **Tampilkan**.

Untuk tiap dana tampil **Target**, **Diterima** (kredit bersih akun Pendapatan bertanda dana itu), **Terpakai** (debet bersih akun Beban bertanda dana itu), dan **Sisa**. Baris bertanda merah "melebihi penerimaan" berarti belanjanya ditombok dari uang lain — periksa transaksinya. Butuh hak Dana Terikat (Lihat).

## Bagaimana menutup buku bulanan?
kata kunci: tutup buku, tutup bulan, closing bulanan, kunci periode, period close, akhir bulan, tutup periode

1. Pastikan semua transaksi bulan itu sudah dicatat dan diverifikasi.
2. Buka **KEUANGAN › Kontrol › Tutup Buku Periode**, pilih **Tahun**, tekan **Tampilkan**.
3. Pada bulan yang berstatus **Terbuka**, tekan **Tutup bulan**.

Sesudah ditutup, periode itu menolak **semua** jurnal baru bertanggal bulan itu dari modul mana pun. Tanggal dan nama penutupnya tercatat. Membuka kembali hanya lewat permohonan. Butuh hak Tutup Buku Periode (Ubah).

## Muncul pesan "Belum bisa ditutup — masih ada …" saat menutup bulan, apa artinya?
kata kunci: belum bisa ditutup, gagal tutup buku, masih ada menunggu verifikasi, shift kasir terbuka, tutup bulan ditolak

Sistem menolak menutup bulan bila masih ada uang yang sudah diterima tetapi belum masuk buku besar, karena sesudah ditutup jurnalnya akan ditolak. Pesannya menyebut apa yang tertunda, misalnya:

- pembayaran santri menunggu verifikasi atau top-up dompet menunggu verifikasi (ERP Al Wafi) — verifikasi atau tolak dulu;
- transaksi kartu Wafi Mart belum dijurnal — tunggu atau jalankan jurnal malamnya;
- "Masih ada shift kasir terbuka (…) yang dijurnal di periode ini — tutup dulu shift-nya."

Selesaikan semua yang disebut, lalu tekan **Tutup bulan** lagi. Pesan "Periode sudah ditutup." berarti bulan itu sudah tertutup.

## Muncul pesan "Periode … sudah ditutup, jadi tak bisa dijurnal lagi", apa yang harus dilakukan?
kata kunci: periode sudah ditutup, periode tertutup, tidak bisa posting, tanggal terkunci, transaksi bulan lalu, closed period

Pesan ini berarti tanggal transaksi berada di bulan yang sudah ditutup buku. Tidak ada toleransi hari — semua jurnal ke periode itu ditolak.

- Bila transaksi sebenarnya milik bulan berjalan, ganti tanggalnya.
- Bila memang milik periode yang sudah ditutup, ajukan pembukaan periode lewat **KEUANGAN › Kontrol › Tutup Buku Periode** (lihat artikel pembukaan periode). Permohonan diajukan admin keuangan dan diputuskan direktur keuangan.

Jangan menyiasati dengan memindahkan transaksi ke tanggal lain bila itu membuat laporan bulan tersebut salah.

## Bagaimana mengajukan dan memutuskan pembukaan periode yang sudah ditutup?
kata kunci: buka periode, ajukan pembukaan, reopen periode, buka tutup buku, permohonan buka periode, direktur keuangan

**Mengajukan** (admin keuangan, hak Buka Periode (Buat)):

1. Buka **KEUANGAN › Kontrol › Tutup Buku Periode**.
2. Pada bulan yang **Ditutup**, tekan **Ajukan pembukaan**, isi alasan (wajib), tekan **Kirim Permohonan**.

**Memutuskan** (direktur keuangan, hak Buka Periode (Ubah)):

1. Di tabel **Permohonan Pembukaan Periode**, tekan **Putuskan**.
2. Tekan **Setujui & Buka** (catatan opsional) atau **Tolak** (alasan wajib).

Permohonan tidak boleh diputuskan oleh pemohonnya sendiri. Hanya boleh ada satu permohonan menunggu per periode. Setelah koreksi selesai, tutup lagi bulannya.

## Bagaimana melakukan tutup buku tahunan?
kata kunci: tutup buku tahunan, closing tahunan, laba ditahan, nol kan pendapatan beban, akhir tahun, tutup tahun

1. Buka **KEUANGAN › Kontrol › Tutup Buku Periode**, pilih tahunnya.
2. Di bagian **Tutup Buku Tahunan**, pilih **Akun Laba Ditahan (Ekuitas)**.
3. Tekan tombol **Tutup Buku** tahun itu.

Sistem membuat jurnal penutup bertanggal akhir tahun yang menolkan seluruh akun Pendapatan dan Beban tahun itu dan memindahkan laba/rugi bersihnya ke Laba Ditahan. Pesan "Tidak ada saldo Pendapatan/Beban untuk ditutup pada tahun ini." berarti tidak ada yang perlu ditutup. Tutup tahunan hanya bisa dibatalkan lewat permohonan admin keuangan (**Ajukan Pembukaan Tutup Buku**, alasan wajib). Butuh hak Tutup Buku Periode (Ubah).

## Laporan keuangan apa saja yang tersedia?
kata kunci: laporan keuangan, daftar laporan, neraca, laba rugi, financial report, laporan bulanan, semua laporan

Semua ada di **KEUANGAN › Laporan** (butuh hak Laporan (Neraca, Laba Rugi, … & Export Data)):

- **Laporan Posisi Keuangan** — dulu bernama Neraca, posisi pada satu tanggal.
- **Penghasilan Komprehensif** — dulu bernama Laba Rugi, pendapatan dan beban satu periode.
- **Perubahan Aset Neto** — format nirlaba (hanya di ERP Al Wafi).
- **Perubahan Modal (format perusahaan)** — mutasi ekuitas dan laba berjalan.
- **Arus Kas** — operasi, investasi, pendanaan.
- **Neraca Saldo** dan **Buku Besar** — untuk menguji dan menelusuri.
- **Aset & Depresiasi** — aset tetap, akumulasi, dan nilai buku.
- **Persediaan** — stok dan nilainya (di ERP Wafi Mart ada di grup WAFI MART).

Ada juga **Jurnal Mentah** (semua baris jurnal) lewat tautan **← Semua Laporan**. Semua laporan bisa diunduh ke CSV, Excel, atau PDF.

## Laporan Posisi Keuangan tertulis "Tidak seimbang", apa yang harus diperiksa?
kata kunci: neraca tidak seimbang, posisi keuangan tidak balance, aset tidak sama liabilitas ekuitas, neraca selisih, cek neraca

Laporan Posisi Keuangan (**KEUANGAN › Laporan › Laporan Posisi Keuangan**) dibuka per tanggal. Bila tertulis **✓ Neraca seimbang**, total aset sama dengan total liabilitas + ekuitas.

Bila tertulis "⚠ Tidak seimbang — periksa jurnal":

1. Buka **Neraca Saldo** untuk periode yang sama dengan **Semua unit** — lihat apakah selisihnya ada di saldo awal, mutasi, atau saldo akhir.
2. Periksa apakah Saldo Awal sudah difinalisasi dan seimbang.
3. Pastikan **Periode Awal Pembukuan** di Pengaturan Perusahaan benar, karena tanggal itu dasar Laba/Rugi Tahun Berjalan.

Klik **Lihat detail** pada akun untuk membuka buku besarnya. Bila ketiga hal di atas sudah diperiksa dan selisihnya tetap ada, laporkan ke admin aplikasi beserta tanggal laporannya.

## Bagaimana melihat laba rugi per unit bisnis?
kata kunci: laba rugi per unit, penghasilan komprehensif, laporan per unit, surplus defisit unit, rugi laba unit, profit per unit

1. Buka **KEUANGAN › Laporan › Penghasilan Komprehensif**.
2. Isi **Dari** dan **Sampai**, pilih **Unit Bisnis** (atau **Semua unit**), tekan **Tampilkan**.

Hasilnya menampilkan pendapatan, beban, dan **Laba/Rugi Bersih**. Bila ada peringatan mutasi pendapatan/beban yang **belum berunit**, nilai itu tidak masuk laporan unit mana pun, sehingga jumlah semua unit tidak akan sama dengan laba rugi keseluruhan — lengkapi unitnya lewat Jurnal. Saringan unit juga tersedia di Neraca Saldo, Arus Kas, dan Buku Besar, tetapi Laporan Posisi Keuangan hanya dibaca untuk seluruh lembaga.

## Laporan Arus Kas tertulis "TIDAK selaras", apa artinya?
kata kunci: arus kas tidak selaras, cash flow beda, saldo kas akhir beda, arus kas salah, laporan arus kas, belum diklasifikasikan

Di **KEUANGAN › Laporan › Arus Kas**, sistem menguji: saldo kas awal + arus kas bersih = saldo kas akhir menurut buku besar. Bila cocok tertulis **Selaras dengan Neraca**.

Bila "TIDAK selaras", telusuri lewat tautan **Neraca Saldo** di pesan itu. Penyebab yang perlu dicek: akun kas/bank yang belum didaftarkan di **Kas & Rekening** (laporan mengenali kas dari daftar itu), atau rekening yang didaftarkan belakangan.

Akun yang belum punya klasifikasi arus kas tetap terhitung, hanya muncul terpisah — lengkapi di Chart of Account. Bila menyaring satu unit, uji keselarasan dimatikan karena saldo kas tidak berdimensi unit. Bagian **Jembatan Laba → Kas** menjelaskan kenapa surplus bisa terjadi saat kas menipis, misalnya tagihan santri yang sudah diakui sebagai pendapatan tetapi uangnya belum masuk. Bila tetap tidak selaras tanpa sebab yang jelas, laporkan ke admin aplikasi beserta periodenya.

## Bagaimana memakai Neraca Saldo dan Buku Besar untuk mencari selisih?
kata kunci: neraca saldo, trial balance, buku besar, general ledger, mutasi akun, saldo akun, tidak seimbang, telusuri selisih

**Neraca Saldo** (**KEUANGAN › Laporan › Neraca Saldo**) menampilkan per akun: saldo awal, mutasi periode, dan saldo akhir (debet/kredit). Pilih **Semua unit** untuk menguji keseimbangan buku; bila tertulis **TIDAK SEIMBANG**, layar menyebut bagian mana yang berselisih. Neraca saldo per unit wajar bila tidak seimbang, karena saldo awal dan jurnal tanpa unit tidak ikut.

**Buku Besar** (**KEUANGAN › Laporan › Buku Besar**) menampilkan mutasi satu akun beserta saldo berjalan: pilih **Akun**, **Dari**, **Sampai**, dan unit bila perlu, tekan **Tampilkan**. Klik kode akun di Neraca Saldo untuk langsung membuka buku besarnya.

## Apa bedanya Perubahan Aset Neto dan Perubahan Modal?
kata kunci: perubahan aset neto, isak 35, perubahan modal, ekuitas, dengan pembatasan, tanpa pembatasan, laporan nirlaba

- **Perubahan Aset Neto** (hanya di ERP Al Wafi) — format entitas nirlaba (ISAK 35). Memisahkan aset neto **tanpa pembatasan** dan **dengan pembatasan**, serta memperlihatkan pelepasan pembatasan saat dana terikat terpakai sesuai peruntukannya. Kolom "Dengan Pembatasan" baru terisi bila akun Pendapatan dan Aset Neto yang terikat sudah ditandai **Sifat Pembatasan: Dengan Pembatasan** di Chart of Account.
- **Perubahan Modal (format perusahaan)** — mutasi akun ekuitas (saldo awal, mutasi, saldo akhir), ditambah laba/rugi yang belum ditutup buku dan laba/rugi tahun berjalan. Tersedia di kedua instans.

Keduanya dibuka di **KEUANGAN › Laporan** dengan rentang **Dari**–**Sampai**.

## Bagaimana mengunduh data keuangan lewat Export Data?
kata kunci: export data, unduh jurnal, download excel, ekspor laporan, data mentah jurnal, backup data, export csv

1. Buka **KEUANGAN › Kontrol › Export Data**.
2. Bagian **Export Khusus** berisi:
   - **Data Mentah Jurnal (per baris)** — saring unit dan tanggal, atau centang semua data.
   - **Buku Besar per Akun** — untuk hutang, piutang, uang muka, atau akun neraca lain.
   - **Aset Tetap — per Kategori**.
3. Di bawahnya ada daftar data master dan transaksi yang bisa diunduh seluruhnya.
4. Tekan **CSV**, **Excel**, atau **PDF**.

Kolom berlabel bahasa Indonesia dan nama vendor, customer, unit, serta akun sudah ditampilkan, bukan kodenya. Butuh hak Laporan (Neraca, Laba Rugi, … & Export Data) (Lihat).

## Bagaimana mencairkan belanja kartu santri ke Wafi Mart?
kata kunci: pencairan ke wafi mart, bayar wafi mart, hutang ke wafi mart, belanja kartu santri, cairkan kartu, transfer wafi mart

(Hanya di ERP Al Wafi.)

1. Buka **KEUANGAN › Transaksi › Pencairan ke Wafi Mart**.
2. Isi **Sampai tanggal**, tekan **Hitung**. Tampil jumlah transaksi yang menunggu dicairkan (belanja dikurangi pengembalian).
3. Pilih **Dibayar dari kas/bank**, tekan **Cairkan**, lalu transfer uangnya.

Belanja kartu menjadi hutang ke Wafi Mart lewat jurnal malam; transaksi hari ini baru dijurnal besok. Bila ada transaksi yang belum dijurnal, tersedia tombol **Jalankan jurnal malam sekarang**. Pencairan bisa dibatalkan dengan alasan selama Wafi Mart belum mencatat penerimaannya. Atur pengingat di Tugas Saya lewat isian jumlah hari (0 = tidak). Pesan "Akun "Hutang ke Wafi Mart" belum ditetapkan" berarti akunnya belum diisi di SETTING AWAL › Setting Umum › Pengaturan Perusahaan, isian "Akun Hutang ke Wafi Mart" (butuh hak Pengaturan Perusahaan). Butuh hak Pencairan Belanja Kartu ke Wafi Mart (antar-entitas).
