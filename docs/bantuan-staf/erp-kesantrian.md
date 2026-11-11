Panduan ini menjawab pertanyaan staf ERP Al Wafi tentang pekerjaan harian kesantrian dan keuangan santri: data santri dan wali, PPSB, pengaturan biaya, SPP dan tagihan lain, pembayaran, tunggakan, dompet dan kartu santri, tarik tunai kegiatan, laundry, perizinan, kehadiran asrama, klinik, serta pengaturan portal wali. Jalur menu ditulis persis seperti di sidebar dengan tanda › (grup › sub-grup › menu), dan hak akses disebut dengan nama modulnya seperti di layar Hak Akses Modul. Bila hak Anda kurang, mintalah kepada admin.

## Apa saja daftar santri yang ada dan apa bedanya?
kata kunci: daftar santri, santri aktif, calon santri, alumni, santri keluar, siap aktivasi, mengundurkan diri, data santri, cari santri, list santri

Semua daftar ada di grup **DATA SISWA/SANTRI**. Orangnya satu data yang sama; yang berbeda hanya tahapnya:

1. **Calon Santri** — pendaftar yang masih diproses PPSB.
2. **Calon Santri Siap Aktivasi** — berkas tuntas, menunggu tahun ajarannya dimulai.
3. **Calon Mengundurkan Diri** — calon yang mundur (arsip).
4. **Santri Aktif** — seluruh jenjang dalam satu daftar; saring jenjang, tingkat, jalur, status, dan status bayar di kepala tabel.
5. **Alumni** — yang sudah diluluskan.
6. **Santri Keluar** — yang keluar lewat alur pengunduran diri.

Kotak cari menerima nama, nomor pendaftaran, NISN, atau NIS. Butuh hak Calon Santri & Santri (Lihat), atau hak Ubah Data Santri (Lihat).

## Bagaimana cara mendaftarkan calon santri baru?
kata kunci: daftar calon, tambah calon santri, pendaftaran baru, input pendaftar, registrasi santri baru, daftarkan calon, psb, ppsb

1. Buka **DATA SISWA/SANTRI › Calon Santri**, tekan **+ Daftarkan Calon**.
2. **Cari wali** yang sudah ada (kakak-adik satu keluarga), atau isi data keluarga baru (kontak utama, nama, telepon, email, pekerjaan, alamat).
3. Isi data calon: nama, jenis kelamin, tempat dan tanggal lahir, NISN, jenjang, asal sekolah.
4. Pilih **Tahun Ajaran**, **Jalur**, dan **Gelombang** (wajib). Pilih "Tanpa Gelombang" untuk pindahan dan kasus khusus — tidak mendapat potongan.
5. Simpan dengan tombol **Daftarkan**.

Tagihan registrasi terbit otomatis sesuai tarif tahun ajaran, jenjang, dan jalur yang dipilih. Butuh hak Calon Santri & Santri (Buat). Pesan "NISN ... sudah terdaftar atas nama ..." berarti anak itu kemungkinan sudah pernah didaftarkan; lanjutkan dari data lamanya.

## Kenapa pilihan jalur atau gelombang kosong saat mendaftarkan calon?
kata kunci: jalur tidak muncul, gelombang kosong, tanpa gelombang, jalur disembunyikan, belum ada jalur aktif, tidak bisa pilih jalur

- **Jalur**: sebagian jalur disembunyikan bila tidak berlaku untuk jenjang dan tahun ajaran yang dipilih. Bila tertulis "belum ada jalur aktif", tambahkan dulu di **PPSB › Setting Awal › Jalur Pendaftaran**.
- **Gelombang**: hanya gelombang yang periodenya sedang berjalan untuk tahun ajaran itu yang ditawarkan. Bila belum ada, tertulis bahwa hanya "Tanpa Gelombang" yang bisa dipilih — atur periodenya di **PPSB › Setting Awal › Gelombang**.

Pilih dulu tahun ajaran dan jenjang, karena keduanya menentukan isi kedua pilihan itu.

## Bagaimana membebaskan biaya registrasi seorang calon?
kata kunci: bebas registrasi, gratis pendaftaran, pembebasan registrasi, tidak bayar registrasi, biaya daftar gratis

Di form **+ Daftarkan Calon** ada kotak pembebasan biaya registrasi beserta isian **Alasan pembebasan**. Kotak itu hanya muncul bagi pemegang hak Pembebasan Biaya Registrasi (Buat); tanpa hak itu centangnya tidak tampil sama sekali. Ini pembebasan per anak. Untuk membebaskan seluruh pendaftar satu jalur, yang dipakai adalah tanda **Bebas** pada sel tarif registrasi di menu Tarif.

## Bagaimana alur proses penerimaan calon santri dari daftar sampai diterima?
kata kunci: alur ppsb, tahap penerimaan, proses penerimaan, verifikasi berkas, seleksi, pengumuman, med check, status calon

Buka halaman detail calon (tekan namanya), lalu ikuti kotak **Proses Penerimaan**. Tahapnya berurutan dan tidak bisa dilompati:

1. **Calon** → **Registrasi Terbayar**: maju sendiri begitu tagihan registrasi lunas dan pembayarannya diverifikasi keuangan.
2. **Verifikasi Berkas** → status Berkas Terverifikasi.
3. Isi nilai baca Qur'an, nilai akademik, wawancara wali, dan wawancara calon, lalu **Selesai Seleksi**.
4. **Umumkan: Diterima** atau **Umumkan: Tidak Lulus**.
5. **Med Check: Lolos** atau **Med Check: Gagal** (gagal membatalkan penerimaan).
6. Terbitkan tagihan uang pangkal & perlengkapan, lalu **Siap di Aktifkan**.

Semua tombol tahap butuh hak Calon Santri & Santri (Ubah).

## Kenapa calon masih berstatus "Calon" padahal wali sudah membayar registrasi?
kata kunci: status tidak berubah, masih calon, registrasi sudah bayar, belum terbayar, verifikasi berkas tidak muncul, tahap macet

Status naik ke **Registrasi Terbayar** hanya bila tagihan registrasi **lunas** dan pembayarannya sudah **diverifikasi** keuangan. Periksa di **PPSB › Transaksi › Pembayaran Tagihan PPSB**: bila pembayarannya masih berstatus menunggu verifikasi, minta tim keuangan memverifikasinya. Bila yang dibayar baru sebagian, statusnya tetap Calon sampai lunas.

## Bagaimana mengelola berkas calon atau santri (KTP, akta, KK, med check)?
kata kunci: berkas santri, dokumen santri, upload berkas, akta, kartu keluarga, ktp, kelengkapan berkas, berkas fisik, perlu diganti

1. Di halaman detail santri, tekan **📎 Berkas Santri**.
2. Bagian **Kelengkapan Berkas** menampilkan status tiap jenis berkas. Tekan **Terima** (atau **Terima (fisik)** bila diserahkan dalam bentuk kertas tanpa diunggah), atau **Perlu diganti** dengan alasan — wali dikabari.
3. Untuk mengunggah, pilih **Jenis Berkas**, pilih berkasnya, isi keterangan (wajib bila jenisnya "Lainnya").

Untuk calon pindahan ada isian **Wali Kelas Sekolah Asal** sebagai kontak konfirmasi. Butuh hak Berkas Santri (Lihat untuk membuka, Buat untuk mengunggah/memutus, Hapus untuk membuang).

## Bagaimana menerbitkan tagihan uang pangkal dan perlengkapan untuk calon yang diterima?
kata kunci: tagih uang pangkal, terbitkan uang pangkal, biaya perlengkapan, tagihan up, uang gedung, uang masuk, potongan gelombang

Tagihan ini hanya bisa diterbitkan setelah calon dinyatakan **Diterima**.

1. Buka detail calon, cari kotak **Tagihkan Uang Pangkal & Perlengkapan**.
2. Isi **Nominal Normal** uang pangkal (angka usulan dari tarif sudah terisi dan boleh diubah). Bila gelombangnya berpotongan, tagihan terbit sebesar nominal normal dikurangi potongan.
3. Isi **Biaya Perlengkapan** — tidak dipotong potongan gelombang. Kosongkan bila tidak dipungut; 0 = gratis.
4. Isi **Jatuh Tempo** dan **Keterangan** (wajib bila nominal 0).
5. Periksa blok **SPP Bulanan**: bila calon ini punya SPP khusus, tekan ubah dan isi nominalnya. SPP tidak ikut terbit sekarang.
6. Tekan **Terbitkan Tagihan**.

Tahap ini belum menerbitkan jurnal; jurnalnya terbit saat santri diaktifkan. Butuh hak Calon Santri & Santri (Ubah).

## Muncul pesan "Tarif ... belum diisi" saat menagih, apa yang harus dilakukan?
kata kunci: tarif belum diisi, tarif kosong, isi selnya, tidak bisa menagih, gagal terbit, tarif tidak ditemukan, belum ada tarif

Artinya sel tarif untuk tahun ajaran, jenjang, jalur (dan tingkat, bila tarifnya bertingkat) itu masih kosong. Aplikasi sengaja berhenti, bukan menagih nol.

1. Catat tahun ajaran, jenjang, dan jalur yang disebut di pesan.
2. Buka **SETTING AWAL › Setting Biaya › Tarif**, pilih tahun ajaran dan jenjangnya.
3. Isi angkanya, atau centang **Bebas** bila memang tidak dipungut, lalu simpan.
4. Ulangi penagihan.

Mengisi tarif butuh hak Tarif (Ubah). Pesan serupa: "Tahun ajaran santri belum terisi" atau "tingkat santri ini belum terisi" berarti data santrinya yang perlu dilengkapi dulu.

## Bagaimana mengoreksi nominal uang pangkal atau perlengkapan yang salah ketik?
kata kunci: koreksi uang pangkal, salah nominal, salah ketik tagihan, ubah nominal perlengkapan, koreksi tagihan ppsb, ralat nominal

Di detail santri, pada baris **Uang pangkal tertagih** (atau perlengkapan), tekan tombol koreksi. Isi **Nominal Normal yang Benar**, jatuh tempo, dan **Alasan Koreksi**, lalu **Simpan Koreksi**. Sisa tagihan dihitung ulang dan potongan gelombang tetap berlaku.

Koreksi ditolak bila:
- masih ada pembayaran yang **menunggu verifikasi** — selesaikan dulu;
- tagihannya **sudah diakrualkan** saat aktivasi — koreksinya harus lewat jurnal penyesuaian oleh keuangan;
- nominal baru lebih kecil dari yang sudah dibayar — kelebihannya diselesaikan keuangan dulu.

Bila santri punya rencana angsuran aktif, rencana itu dinonaktifkan dan terminnya perlu disusun ulang. Butuh hak Calon Santri & Santri (Ubah).

## Bagaimana menandai calon siap diaktifkan dan mengaktifkan santri?
kata kunci: aktivasi santri, aktifkan santri, siap diaktifkan, daftar ulang calon, jadi santri aktif, aktifkan sekarang, santri masuk tengah tahun

1. Setelah calon **Lolos Kesehatan**, tekan **Siap di Aktifkan** di detail calon. Santri **belum** aktif saat itu; ia pindah ke daftar **Calon Santri Siap Aktivasi**.
2. Aktivasinya menyala sendiri saat tahun ajaran masuknya dimulai, beserta jurnal akrual uang pangkal dan perlengkapan. Sampai saat itu ia belum ditagih SPP dan belum ikut kenaikan tingkat.
3. Untuk santri yang masuk di tengah tahun ajaran, tekan **Aktifkan Sekarang** di detailnya.

Pesan "Santri ini tidak punya jadwal aktivasi yang menunggu" berarti tombol **Siap di Aktifkan** belum ditekan. Butuh hak Calon Santri & Santri (Ubah).

## Bagaimana mengaktifkan banyak calon sekaligus?
kata kunci: aktivasi massal, aktifkan banyak, aktifkan semua, centang santri, aktifkan yang dicentang, satu angkatan

1. Buka **DATA SISWA/SANTRI › Calon Santri Siap Aktivasi**.
2. Centang calon yang ingin diaktifkan sekarang.
3. Tekan **Aktifkan yang Dicentang** dan konfirmasi.

Jurnal akrual uang pangkal dan perlengkapan terbit untuk masing-masing. Satu santri yang gagal tidak menahan yang lain; namanya dan sebabnya disebut di pesan. Butuh hak Calon Santri & Santri (Ubah).

## Bagaimana mengubah biodata santri?
kata kunci: ubah data santri, edit santri, ganti nama santri, salah nama, perbaiki biodata, ubah nis, koreksi data santri

1. Buka detail santri, tekan **✏️ Ubah Data**.
2. Perbaiki isiannya, lalu simpan.

Yang **tidak** bisa diubah di sini: status (punya tahapnya sendiri), jalur, gelombang, tahun ajaran masuk, dan nomor pendaftaran — karena ketiganya menentukan tarif tagihan yang sudah terbit. Nominal SPP khusus diatur di menu SPP. **Nama** hanya bisa diubah oleh admin atau pemegang hak Ubah Data Santri (Ubah), dan perubahannya tercatat di Jejak Audit. NIS boleh dikoreksi bila salah ketik, asal tidak sama dengan santri lain. Butuh hak Calon Santri & Santri (Ubah) atau Ubah Data Santri (Ubah).

## Bagaimana mencatat calon yang mengundurkan diri?
kata kunci: calon mundur, batal daftar, mengundurkan diri calon, tidak jadi masuk, hapus calon, calon batal

Di detail calon, tekan **Mengundurkan Diri**, tulis alasannya, lalu **Konfirmasi**. Calon pindah ke daftar **Calon Mengundurkan Diri** dan riwayatnya tetap tersimpan. Calon yang sudah melewati tahap awal tidak bisa dihapus — pesan "Santri berstatus ... tidak boleh dihapus" meminta Anda memakai pengunduran diri ini. Untuk santri yang **sudah aktif**, tombol ini tidak berlaku; pakai alur Pengunduran Diri Santri Aktif. Butuh hak Calon Santri & Santri (Ubah).

## Bagaimana mengajukan pengunduran diri santri aktif?
kata kunci: santri keluar, pengunduran diri, berhenti sekolah, pindah sekolah, keluar pesantren, resign santri, undur diri santri aktif

Santri aktif hanya bisa keluar lewat alur Pengunduran Diri. Wali bisa mengajukan dari portal, atau staf mengajukan atas namanya:

1. Buka detail santri, cari kotak **Pengunduran Diri Santri Aktif**.
2. Isi **Alasan**, **Rencana tanggal keluar**, dan unggah **Surat pengajuan dari wali** (wajib).
3. Tekan **Kirim ke Wali Kelas**. Wali dikabari lewat portal.

Seluruh tagihan (SPP, sisa uang pangkal dan perlengkapan, tagihan lain) **tetap ditagih**. Butuh hak Pengunduran Diri Santri (Buat).

## Bagaimana memproses pengajuan pengunduran diri sampai santri dikeluarkan?
kata kunci: proses pengunduran diri, clearance, wali kelas, kepala sekolah, keluarkan santri, dapodik, verifikasi pengunduran

Buka **DATA SISWA/SANTRI › Pengunduran Diri**. Tab **Perlu tindakan saya** berisi pengajuan yang menunggu Anda. Urutannya:

1. **Verifikasi wali kelas** — **Verifikasi & teruskan**, atau **Kembalikan ke wali** dengan catatan.
2. **Persetujuan kepala sekolah** jenjangnya — **Ya, setujui** atau **Tidak** (catatan wajib).
3. **Clearance** keuangan, kesantrian, dan admin sekolah — tiap pihak menekan **Beri clearance ✓** (bisa mengunggah dokumen).
4. **Pengeluaran & Dapodik** — di tab **Siap dikeluarkan**, centang lalu tekan **Keluarkan santri (proses akhir bulan)**.

Wali kelas diatur di master Kelas, kepala sekolah di master Jenjang. Clearance butuh hak Clearance Pengunduran Diri sesuai pihaknya; mengeluarkan butuh hak Clearance Pengunduran Diri — Admin Sekolah (Ubah). Setelah dikeluarkan, sisa tagihan PPSB dibatalkan, tetapi SPP dan tagihan lain tetap tertagih.

## Bagaimana menerbitkan NIS santri?
kata kunci: nis, nomor induk, generate nis, terbitkan nis, buat nis, format nis, nomor induk santri

1. Buka **KEPENDIDIKAN › Administrasi › Generate NIS**.
2. Centang santri yang akan diberi NIS, lalu terbitkan dan konfirmasi.

NIS diterbitkan massal dan manual karena nomornya berurut menurut **abjad nama** dalam satu angkatan jenjang. Nomor yang sudah terbit tidak bisa ditarik kembali. Formatnya bisa diatur di layar yang sama (misalnya kode tahun ajaran dan tingkat masuk); mengubah format tidak mengubah NIS yang sudah ada. Menerbitkan butuh hak NIS (Buat); mengubah format butuh hak NIS (Ubah).

## Bagaimana menaikkan tingkat atau meluluskan santri di akhir tahun ajaran?
kata kunci: naik kelas, kenaikan tingkat, naik tingkat, kelulusan, luluskan, mengulang, tinggal kelas, alumni, akhir tahun

1. Buka **KEPENDIDIKAN › Administrasi › Kenaikan Tingkat & Kelulusan**.
2. Pilih tahun ajaran tujuan dan jenjang.
3. Tiap baris punya keputusan: **Naik tingkat**, **Mengulang**, **Melanjutkan (daftar ke jenjang berikutnya)**, **Lulus (alumni)**, atau **Lewati**. Isi tanggal lulus untuk yang diluluskan.
4. Tetapkan dan konfirmasi.

Keputusan ini **dijadwalkan**: data santri baru berubah saat tahun ajaran tujuan dimulai. Jalankan kenaikan tingkat **sebelum** Penagihan Daftar Ulang, karena tarif daftar ulang mengikuti tingkat yang baru. Menyaring hanya menyembunyikan baris; keputusan baris tersembunyi tetap ikut terkirim. Tunggakan tidak dihapus — alumni tetap bisa ditagih. Butuh hak Kenaikan Tingkat & Kelulusan (Lihat untuk pratinjau, Buat untuk menetapkan).

## Bagaimana santri naik ke jenjang berikutnya (misalnya SMP ke SMA)?
kata kunci: naik jenjang, pendaftaran lanjutan, jenjang lanjutan, lanjut sma, lanjut smp, daftar jenjang berikutnya, santri internal

Santri internal melewati PPSB jenjang berikutnya lewat **pendaftaran lanjutan**:

1. Pilih **Melanjutkan** di Kenaikan Tingkat, atau buka kotak **Jenjang Lanjutan** di detail santri lalu pilih **Tahun Ajaran Tujuan**.
2. Tagihan registrasi jenjang lanjutan terbit bila tarifnya diisi. Tahapnya maju sendiri begitu tagihan itu lunas dan diverifikasi.
3. Tahap berkas dilewati; lanjutkan **Simpan Seleksi**, **Diterima**, dan **Med Check: Lolos**.
4. Terakhir isi tingkat baru, uang pangkal, perlengkapan, dan jatuh tempo, lalu eksekusi kenaikan.

Selama prosesnya, santri tetap aktif di jenjang lama dan tetap ditagih SPP. Bila jenjangnya tidak punya jenjang lanjutan, santrinya menjadi alumni. Butuh hak Calon Santri & Santri (Ubah).

## Apakah alumni dan santri keluar masih bisa ditagih?
kata kunci: alumni nunggak, tagihan alumni, santri keluar bayar, tunggakan lulusan, piutang alumni, bayar setelah lulus

Ya. Alumni dan santri keluar tetap muncul di pemilih santri pembayaran, karena keduanya bisa meninggalkan tunggakan yang masih sah ditagih. Catat pembayarannya seperti biasa di **KEPENDIDIKAN › Transaksi › Pembayaran SPP & Tagihan Lain**. Yang tidak lagi muncul di pemilih adalah calon yang mengundurkan diri. Untuk memeriksa sisa kewajiban dua arah (hutang santri dan titipan dompet) sebelum santri pergi, pakai **KEPENDIDIKAN › Kontrol › Bebas Tanggungan**.

## Bagaimana memasukkan santri lama yang sudah bersekolah tanpa lewat PPSB?
kata kunci: input manual santri, santri lama, santri susulan, tanpa ppsb, data lama, migrasi santri, tunggakan awal

Buka **SETTING AWAL › Setting Umum › Input Manual Santri Aktif**. Layar ini untuk satu-dua santri yang sudah bersekolah sebelum aplikasi dipakai; untuk banyak santri sekaligus pakai **Impor Data Awal**.

1. Isi identitas: **NIS** asli, nama lengkap, jenis kelamin, jenjang, **Tingkat**, **Jalur**, dan **T.A Masuk (angkatan)** — tahun ia masuk, bukan tahun berjalan.
2. Bila ada tunggakan lama, tambahkan barisnya beserta perlakuan posting.

Santri langsung berstatus Aktif tanpa tagihan registrasi dan tanpa potongan gelombang. Jangan dipakai untuk pendaftar baru. Perlakuan "Saldo awal" dan "Kas basis" (tanpa jurnal) khusus admin; staf lain hanya bisa memakai "Akrual sekarang". Butuh hak Input Manual Santri Aktif (Buat).

## Bagaimana mengatur kelas dan menempatkan santri ke kelas?
kata kunci: kelas, rombel, penempatan kelas, bagi kelas, wali kelas, pindah kelas, tambah kelas

1. Buat kelas di **DATA SISWA/SANTRI › Kelas** (pilih jenjang, tekan **Tambah kelas**). Wali kelas ditetapkan di sini.
2. Buka **DATA SISWA/SANTRI › Penempatan Kelas**, centang santri, pilih kelasnya, tekan **Tempatkan**.

Daftar penempatan hanya berisi santri aktif yang belum berkelas; centang **Tampilkan juga yang sudah berkelas** untuk memindahkan. Kenaikan tingkat mengosongkan kelas, jadi santrinya muncul lagi untuk ditempatkan ke kelas baru. Butuh hak Kelas (rombel) & Penempatan Kelas (Buat untuk kelas baru, Ubah untuk menempatkan).

## Bagaimana menambah atau mengubah data wali/keluarga?
kata kunci: data wali, tambah wali, ubah wali, keluarga santri, orang tua, nomor hp wali, kontak utama, ganti telepon wali

1. Buka **DATA SISWA/SANTRI › Wali / Keluarga**. Cari lewat nama atau telepon.
2. Tekan **+ Tambah Wali** untuk keluarga baru, atau ubah pada barisnya.
3. Lengkapi kontak utama (nama dan telepon wajib), data ayah, ibu, atau wali, beserta email.

Pesan "Nomor ... sudah dipakai wali ..." berarti keluarga itu sudah ada — bila kakak-adik, pakai data wali itu. Wali yang masih menaungi santri tidak bisa dihapus; nonaktifkan saja. Email dipakai wali untuk masuk portal. Butuh hak Wali / Keluarga Santri (Buat atau Ubah).

## Bagaimana mengirim link verifikasi data wali lewat WhatsApp?
kata kunci: verifikasi data wali, link verifikasi, kirim link wa, whatsapp wali, salin pesan, buat link, verifikasi keluarga

1. Buka **DATA SISWA/SANTRI › Wali / Keluarga**, tekan **Verifikasi Data Wali**.
2. Pastikan saklar di atas berbunyi **Verifikasi data: AKTIF**.
3. Pilih tanggal **Mulai berlaku**, lalu tekan **Buat link untuk semua yang belum punya**, atau **Buat link** per keluarga.
4. Pada baris keluarga, tekan tombol kirim WhatsApp, atau **Salin pesan** lalu tempel di WhatsApp.

Wali membuka link, memeriksa dan membetulkan data keluarga dan santri, lalu membuktikan email aktifnya dengan kode — email itu langsung menjadi akun portal. Link berlaku 30 hari sejak tanggal mulai. Butuh hak Wali / Keluarga Santri (Lihat untuk membuka, Ubah untuk membuat dan mengirim link).

## Apa arti status link verifikasi dan bagaimana mencabut atau mengulang?
kata kunci: status verifikasi, belum dikirim, terkirim, sudah dibuka, selesai, kedaluwarsa, cabut link, verifikasi ulang, akhiri verifikasi

Status di layar **Verifikasi Data Wali**: **Belum ada link**, **Belum mulai**, **Belum dikirim**, **Terkirim**, **Sudah dibuka**, **Selesai**, dan **Kedaluwarsa**.

- **Cabut** — link keluarga itu tidak bisa dibuka lagi.
- **Buat link baru** — untuk link yang kedaluwarsa atau dicabut.
- **Verifikasi ulang** — untuk keluarga yang sudah selesai; akun portalnya tetap berlaku.
- **Akhiri verifikasi** — mencabut sekaligus semua link yang belum diselesaikan.
- Saklar **AKTIF/NONAKTIF** — menahan sementara semua link tanpa mencabutnya; menyalakannya lagi menghidupkan link lama.

Butuh hak Wali / Keluarga Santri (Ubah).

## Kenapa ada wali yang belum bisa masuk portal?
kata kunci: wali tidak bisa login portal, kesiapan portal, email wali kosong, email kembar, akun portal wali, portal wali belum bisa

Buka **DATA SISWA/SANTRI › Wali / Keluarga**, tekan **Kesiapan Portal Wali**. Halaman ini menghitung keluarga dengan anak aktif atau masih dalam proses PSB, berapa yang sudah bisa masuk dan yang belum, beserta sebabnya — misalnya email belum diisi atau satu email dipakai dua keluarga. Wali masuk portal dengan email, jadi perbaikannya cukup lewat tombol **Ubah** di tiap baris: isi email ayah, ibu, atau wali (siapa pun yang akan memakai portal). Butuh hak Wali / Keluarga Santri (Lihat, dan Ubah untuk memperbaiki).

## Bagaimana menambah tahun ajaran baru?
kata kunci: tahun ajaran, ta baru, tambah tahun ajaran, tanggal mulai, periode tahun ajaran, ganti tahun ajaran

1. Buka **PPSB › Setting Awal › Tahun Ajaran**, tambah baru.
2. Isi **Tahun Ajaran** berformat YYYY/YYYY, **Tanggal Mulai**, **Tanggal Selesai**, dan status.

Kode tahun ajaran tidak bisa diubah setelah disimpan karena menjadi rujukan data lain. Tanggal mulai dan selesai wajib benar: penerbitan SPP mencari tahun ajaran dari rentang tanggal ini. Pesan "Belum ada tahun ajaran yang memuat periode ..." saat menerbitkan SPP berarti tahun ajarannya belum dibuat atau tanggalnya belum mencakup bulan itu. Butuh hak Tahun Ajaran (Buat).

## Bagaimana mengatur jalur pendaftaran?
kata kunci: jalur pendaftaran, jalur reguler, jalur beasiswa, jalur pindahan, tambah jalur, jalur setelah naik jenjang, bebas uang pangkal

Buka **PPSB › Setting Awal › Jalur Pendaftaran**. Isi **Kode**, **Nama Jalur**, **Jalur Setelah Naik Jenjang** (jalur yang berlaku sesudah santri naik ke jenjang berikutnya — menentukan tarif uang pangkal lanjutannya), keterangan, dan status. Jalur berlaku untuk semua tahun ajaran; besaran biayanya per jalur diisi di menu **Tarif**. Jalur bertanda bebas uang pangkal tidak ditagih uang pangkal sama sekali, apa pun isi sel tarifnya. Butuh hak Jalur Pendaftaran (Buat/Ubah).

## Bagaimana mengatur gelombang pendaftaran dan potongannya?
kata kunci: gelombang, potongan gelombang, diskon gelombang, gelombang 1, early bird, masa berlaku potongan, potongan uang pangkal

1. **PPSB › Setting Awal › Gelombang**: pilih tahun ajaran, tambah gelombang dengan **Kode Gelombang**, **Nama Gelombang**, periode **Mulai**–**Sampai**, dan **Masa Berlaku (hari)**.
2. **PPSB › Setting Awal › Potongan Gelombang**: isi besaran potongan per gelombang × jenjang, lalu **Simpan Potongan**.

Di luar periodenya gelombang tidak ditawarkan saat registrasi; memperpanjang tanggal selesai menghidupkannya lagi. **Masa Berlaku** adalah tenggat tiap calon memenuhi syarat bayar minimal agar potongannya tidak hangus, dihitung sejak uang pangkal ditagihkan. Potongan hanya berlaku untuk uang pangkal, tidak untuk perlengkapan. Butuh hak Gelombang & Potongan Uang Pangkal.

## Bagaimana mengatur jadwal ujian seleksi, mencetak kartu peserta, dan mencatat kehadiran?
kata kunci: jadwal ujian, kartu peserta, kartu ujian, daftar hadir ujian, absen ujian, scan qr, seleksi masuk

1. **PPSB › Setting Awal › Jadwal Ujian & Kartu Peserta**: tambah jadwal per tahun ajaran (**Tambah jadwal**) dan atur isi kartu peserta (termasuk daftar PERHATIAN).
2. Kartu peserta bisa dicetak atau disimpan sebagai PDF (**Cetak / Simpan PDF**).
3. Saat ujian, panitia memindai QR di kartu peserta dengan kamera HP (masuk ERP). Kehadirannya muncul di **PPSB › Transaksi › Daftar Hadir Ujian**.

Tanggal ujian peserta dicari dari yang paling khusus: tanggal khusus peserta (diisi di detail santri), lalu jadwal umum. Butuh hak Jadwal Ujian Seleksi & Kartu Peserta, dan Absensi Ujian Seleksi untuk memindai.

## Bagaimana membuat rencana angsuran uang pangkal?
kata kunci: angsuran uang pangkal, cicilan, termin, kredit uang pangkal, jadwal bayar, cicil uang masuk, rencana angsuran

1. Buka **PPSB › Transaksi › Angsuran Uang Pangkal**, tekan **+ Buat Rencana**.
2. Ketik nomor pendaftaran, NIS, atau nama santri.
3. Isi **Disepakati Pada** dan susun termin uang pangkal serta perlengkapan (masing-masing tabel sendiri). Total termin harus sama dengan total tagihan.
4. Simpan.

Uang pangkal harus sudah ditagihkan (calon berstatus Diterima). Bila potongan gelombang masih berlaku, termin pertama sudah diisikan mengikuti syarat potongan; setoran yang telat mencapai ambang membuat potongan hangus. Rencana angsuran tidak menerbitkan jurnal — hanya pembayaran nyata yang berjurnal. Butuh hak Angsuran Uang Pangkal (Buat).

## Bagaimana mengubah jadwal angsuran yang sudah disepakati (renegosiasi)?
kata kunci: renegosiasi, ubah termin, jadwal ulang angsuran, wali minta diperingan, versi baru termin, geser cicilan

Buka detail rencana angsuran santri dari **PPSB › Transaksi › Angsuran Uang Pangkal**. Pada komponen yang ingin diubah, susun termin baru, isi **Disepakati pada** dan **Alasan** (mis. wali minta diperingan), lalu **Simpan Versi Baru**. Totalnya tetap sama — renegosiasi hanya menata ulang jadwal, dan versi lama tetap tersimpan di riwayat kesepakatan. Butuh hak Angsuran Uang Pangkal (Ubah).

## Bagaimana memantau termin angsuran yang jatuh tempo dan potongan yang hangus?
kata kunci: termin jatuh tempo, ingatkan wali, sudah diingatkan, evaluasi potongan, potongan hangus, tagih angsuran

Di **PPSB › Transaksi › Angsuran Uang Pangkal**, bagian **Termin jatuh tempo — perlu ditagih** berisi termin yang mendekati atau lewat jatuh tempo. Setelah menghubungi wali, tekan **Sudah diingatkan**. Tombol **Evaluasi Potongan** memeriksa semua tagihan berpotongan: bila syarat bayar minimal gelombangnya belum terpenuhi sampai tenggat, potongan hangus dan tagihan naik ke nominal normal. Rekap dan detail bisa dicetak. Butuh hak Angsuran Uang Pangkal (Ubah).

## Apa urutan mengisi pengaturan biaya sebelum menagih?
kata kunci: setting biaya, urutan setting, persiapan tagihan, awal tahun ajaran, jenjang tipe jenis tarif, setup biaya

Isi berurutan di **SETTING AWAL › Setting Biaya**:

1. **Jenjang Pendidikan** — jenjang dan jumlah tingkatnya.
2. **Tipe Biaya** — perilaku tiap biaya (registrasi, uang pangkal, perlengkapan, daftar ulang, SPP, lain-lain).
3. **Jenis Biaya** — identitas akuntansi tiap biaya: akun, unit bisnis, dan jenjangnya. Tanpa nominal.
4. **Tarif** — besaran per tahun ajaran × jenjang × jalur.

Sebelumnya pastikan **Tahun Ajaran** dan **Jalur Pendaftaran** (grup PPSB) sudah ada. Tiap tahun ajaran baru, cukup isi ulang sel Tarif-nya.

## Bagaimana mengatur jenjang pendidikan?
kata kunci: jenjang, jenjang pendidikan, sdtq, smp, sma, jumlah tingkat, jenjang lanjutan, kepala sekolah, tingkat dimulai

Buka **SETTING AWAL › Setting Biaya › Jenjang Pendidikan**. Isi **Kode Jenjang**, **Nama Jenjang**, **Jumlah Tingkat**, **Tingkat Dimulai Dari**, **Jenjang Lanjutan** (tujuan bila santri naik jenjang), dan **Kepala sekolah** (yang memutus pengunduran diri). Jumlah tingkat wajib diisi: tanpa itu, tarif daftar ulang per tingkat tidak bisa diisi dan muncul pesan "Jumlah tingkat jenjang ... belum diisi". Butuh hak Jenjang Pendidikan.

## Apa itu Tipe Biaya dan perilakunya?
kata kunci: tipe biaya, perilaku biaya, jenis tagihan, registrasi, uang pangkal, daftar ulang, spp, lain-lain, tipe baru

Tipe biaya menentukan **alur** sebuah biaya. Tipe buatan sendiri wajib memilih salah satu perilaku bawaan:

- **Registrasi** — terbit otomatis saat calon mendaftar.
- **Uang Pangkal** — dengan potongan gelombang dan angsuran termin.
- **Perlengkapan** — terbit bersama uang pangkal, tanpa potongan gelombang, termin sendiri.
- **Daftar Ulang** — tagihan tahunan santri aktif, diterbitkan massal.
- **SPP** — terbit berkala per periode.
- **Lain-lain** — ditagihkan manual per santri.

Atur di **SETTING AWAL › Setting Biaya › Tipe Biaya**. Butuh hak Tipe Biaya.

## Bagaimana mengisi Jenis Biaya?
kata kunci: jenis biaya, akun biaya, akun piutang, akun pendapatan, unit bisnis biaya, cara menagih, ditagih tiap bulan, biaya baru

Buka **SETTING AWAL › Setting Biaya › Jenis Biaya**. Jenis biaya adalah identitas akuntansi: **Kode**, **Nama**, **Jenjang**, tipe, **Unit Bisnis**, **Pengakuan**, **Akun Pendapatan**, **Akun Piutang**, **Akun Pendapatan Diterima di Muka**, dan status. **Besarannya tidak diisi di sini**, melainkan di menu Tarif (atau Matriks Tarif Kegiatan untuk kegiatan berpeserta).

Untuk tipe lain-lain ada **Cara Menagih** (Langsung, atau Menurut kepesertaan) dan centang **Ditagih tiap bulan**. Akun piutang wajib untuk biaya yang diakui akrual; bila kosong, penerbitan berhenti dengan pesan "Jenis biaya ... belum punya akun piutang". Butuh hak Jenis Biaya.

## Bagaimana mengisi tarif biaya?
kata kunci: tarif, isi tarif, besaran biaya, nominal spp, tarif daftar ulang, tarif uang pangkal, tarif registrasi, harga biaya

1. Buka **SETTING AWAL › Setting Biaya › Tarif**, pilih **Tahun Ajaran** dan **Jenjang**.
2. Bagian **Biaya santri aktif** berisi SPP per bulan (satu angka untuk seluruh jalur) dan daftar ulang per tingkat tujuan (ditagih setelah naik tingkat).
3. Bagian **Biaya masuk** berisi registrasi, uang pangkal, dan perlengkapan per jalur pendaftaran.
4. Isi angka, centang **Bebas**, atau biarkan kosong, lalu tekan **Simpan Tarif**.

Tiap jalur diisi sendiri-sendiri; jalur yang selnya kosong tidak mengikuti jalur lain. Akun dan unit bisnisnya diatur di Jenis Biaya. Butuh hak Tarif (Ubah).

## Apa arti tiga keadaan sel tarif (angka, Bebas, kosong)?
kata kunci: tarif bebas, tarif kosong, tarif nol, sel kosong, belum diisi, tidak dipungut, gratis, arti bebas

Setiap sel tarif punya tiga keadaan yang berbeda artinya:

- **Angka** (termasuk **0**) — tarif berlaku. Nol adalah angka sah: tagihan tetap terbit sebesar nol.
- **Bebas** dicentang — sengaja tidak dipungut; tagihannya **tidak terbit**.
- **Kosong** — belum diisi; penagihan **berhenti** dan meminta sel itu dilengkapi.

Jangan mengosongkan sel untuk maksud "gratis" — pakai Bebas atau 0. Untuk keringanan satu santri, jangan mengubah sel tarif; pakai Kebijakan Khusus atau nominal SPP khusus.

## Bagaimana menerbitkan tagihan SPP bulanan?
kata kunci: terbitkan spp, tagihan spp, generate spp, spp bulan ini, buat tagihan spp, penerbitan spp, spp bulanan

1. Buka **KEPENDIDIKAN › Administrasi › Penagihan SPP**.
2. Pilih **Periode (YYYY-MM)**, tekan **Pratinjau**.
3. Periksa **Total Tagihan per Jenjang**: berapa santri siap terbit, tanpa tarif, dan sudah ada. Saring kolom Status untuk menemukan yang bermasalah.
4. Isi **Tanggal Terbit**, tekan **Terbitkan ... Tagihan**, dan konfirmasi.

Santri yang tagihan periodenya sudah ada dilewati, jadi aman diulang. Nominal mengikuti tarif jenjang, kecuali ada nominal SPP khusus atau Kebijakan Khusus yang berlaku. Saldo prabayar dan Dompet Wali (bila keluarga mengizinkan auto-debet) dapat langsung memotong tagihan saat terbit. Besaran SPP diisi di SETTING AWAL › Setting Biaya › Tarif. Butuh hak Penagihan SPP (Ubah).

## Kenapa sebagian santri berstatus "tanpa tarif" di pratinjau SPP?
kata kunci: tanpa tarif, spp tidak terbit, santri tertahan, tarif spp kosong, belum punya jenjang, spp bebas, gagal spp

Santri "tanpa tarif" tidak ikut terbit. Saring kolom Status untuk menemukan siapa saja, lalu perhatikan jenjang dan tingkatnya. Sebab yang umum:

- Sel SPP jenjang itu di menu **Tarif** belum diisi untuk tahun ajaran periodenya — isi selnya.
- Santri belum punya jenjang — lengkapi datanya.
- Tarif SPP jenjang/jalur itu bertanda **Bebas**.

Setelah diperbaiki, ulangi **Pratinjau** lalu terbitkan lagi; santri yang sudah terbit tidak tertagih dua kali.

## Muncul peringatan "Lintas tahun ajaran" saat menerbitkan SPP, apa artinya?
kata kunci: lintas tahun ajaran, spp bulan lalu, spp terlewat, alasan lintas, beda tahun ajaran, menyusul spp

Peringatan ini muncul bila periode yang dipilih termasuk tahun ajaran yang **berbeda** dari tahun ajaran yang sedang berjalan, misalnya menyusulkan SPP bulan yang terlewat. Penerbitan tetap boleh, tetapi **Alasan menerbitkan di luar T.A berjalan** wajib diisi dan tercatat di log aktivitas. Bila Anda tidak bermaksud menerbitkan bulan itu, periksa lagi pilihan periodenya. Tahun ajaran ditentukan oleh periode, bukan oleh angkatan santri.

## Bagaimana memberi nominal SPP khusus untuk seorang santri?
kata kunci: spp khusus, nominal khusus, beasiswa spp, keringanan spp, spp beda, spp tahfizh, spp gratis

1. Di **KEPENDIDIKAN › Administrasi › Penagihan SPP**, tekan **Nominal Khusus Santri**.
2. Pilih santri, isi **Nominal SPP** dan **Alasan** (mis. beasiswa 50%), lalu **Simpan**.

Nominal **0** = beasiswa penuh (tagihan tetap terbit senilai nol). **Kosongkan** nominal untuk kembali ke tarif jenjang. Berlaku untuk penerbitan berikutnya, bukan tagihan yang sudah terbit. Nominal ini juga bisa diisi saat menagih uang pangkal calon. Untuk keringanan dengan alasan, persetujuan, dan masa berlaku tertulis, lebih baik memakai **Kebijakan Khusus Santri**. Butuh hak Penagihan SPP (Ubah).

## Bagaimana mencatat setoran SPP di muka (prabayar)?
kata kunci: prabayar, bayar di muka, setoran spp, bayar spp beberapa bulan, deposit spp, spp dimuka, titip spp

1. Di **KEPENDIDIKAN › Administrasi › Penagihan SPP**, tekan **Setoran Prabayar**.
2. Pilih santri, isi **Nominal**, pilih **Sumber Dana** (Setoran tunai / transfer, atau Dompet Wali).
3. Untuk tunai/transfer, pilih **Kas/Rekening** penerima.
4. Tekan **Terima Setoran**.

Setoran melunasi **tunggakan tertua** lebih dulu; kelebihannya menjadi saldo prabayar yang otomatis dipakai saat tagihan SPP berikutnya terbit. Hanya untuk santri aktif. Pesan "Saldo Dompet Wali tidak cukup" atau "Keluarga ini belum punya Dompet Wali" muncul bila sumbernya Dompet Wali. Butuh hak Penagihan SPP (Buat).

## Bagaimana mengunduh daftar nominal SPP per periode?
kata kunci: unduh spp, download spp, excel spp, daftar nominal spp, rekap spp periode, export spp

Di **KEPENDIDIKAN › Administrasi › Penagihan SPP**, pilih periode lalu **Pratinjau**. Di bagian ringkasan ada tombol **Unduh: CSV / Excel / PDF**. Isinya nama santri dan nominal SPP periode itu persis seperti pratinjau (tarif jenjang, nominal khusus, dan Kebijakan Khusus sudah dihitung), dengan status Siap terbit, Sudah terbit, atau Tarif belum diisi. Santri yang tagihannya sudah terbit membawa nominal tagihan itu. Butuh hak Penagihan SPP (Lihat).

## Bagaimana menerbitkan tagihan daftar ulang tahunan?
kata kunci: daftar ulang, tagihan daftar ulang, her registrasi, daftar ulang tahunan, penagihan daftar ulang, biaya tahunan

1. Pastikan **Kenaikan Tingkat** sudah dijalankan dan berlaku, karena tarifnya mengikuti tingkat BARU.
2. Buka **KEPENDIDIKAN › Administrasi › Penagihan Daftar Ulang**.
3. Pilih T.A tagihan, jenjang, dan saringan angkatan bila perlu.
4. Periksa usulan nominal tiap baris; ubah angkanya atau lepas centang santri yang tidak ditagih.
5. Terbitkan dan konfirmasi.

Otomatis dilewati: santri tingkat 1 dan santri pada tahun masuknya (termasuk pindahan). Penerbitan berjalan dalam satu kali proses: bila satu baris gagal, seluruhnya dibatalkan. Menyaring hanya menyembunyikan — untuk mengeluarkan santri, lepas centangnya. Butuh hak Penagihan Daftar Ulang (Buat).

## Bagaimana memakai Batch Tagihan untuk menerbitkan tagihan terjadwal?
kata kunci: batch tagihan, tagihan terjadwal, rilis otomatis, jadwal terbit, otorisasi tagihan, draft tagihan, terbit nanti

Batch dipakai bila hasil tagihan perlu diperiksa dulu, atau rilisnya harus tetap jalan saat petugas libur.

1. Buka **KEPENDIDIKAN › Administrasi › Batch Tagihan**, susun batch baru.
2. Pilih **Modul** (SPP, daftar ulang, atau tagihan lain) dan isiannya, simpan sebagai draft. Belum ada tagihan yang terbit.
3. Buka batchnya, periksa baris per baris, lalu otorisasi. Isi **Waktu rilis otomatis** bila ingin terbit sendiri pada tanggal/jam tertentu; kosongkan bila rilis hanya lewat tombol.
4. Rilis sekarang dengan tombol rilis, atau tunggu jadwalnya.

Angka dikunci saat otorisasi. Baris bermasalah saat rilis dilewati dan dilaporkan. Batch yang belum dirilis bisa dibatalkan. Butuh hak Batch Tagihan, **ditambah** hak modulnya: Penagihan SPP (Ubah), Penagihan Daftar Ulang (Buat), atau Tagihan Lain-lain (Buat).

## Bagaimana mengajukan dan menyetujui Kebijakan Khusus (keringanan, potongan, beasiswa)?
kata kunci: kebijakan khusus, keringanan, potongan, beasiswa, diskon santri, surat permohonan, persetujuan yayasan, keringanan spp

1. Buka **KEPENDIDIKAN › Kontrol › Kebijakan Khusus Santri**.
2. Isi **Santri**, **Jenis** (Keringanan, Potongan, Beasiswa, Lainnya), **Biaya yang Terdampak**, **Cara** (Nominal khusus, Potong sejumlah rupiah, Potong sekian persen), **Besaran**, **Tahun Ajaran** (kosong = semua), masa berlaku, dan **Alasan**. Tekan **Ajukan**.
3. Pada barisnya, unggah dua surat lewat tautan **Permohonan wali** dan **Persetujuan yayasan**.
4. Tekan **Putuskan** lalu **Setujui** (atau **Tolak** dengan alasan). Tombol tertulis "Surat belum lengkap" selama surat belum terlampir.

Tagihan yang sudah terbit tidak berubah; yang berubah penerbitan berikutnya. Kebijakan berlaku bisa diakhiri dengan **Akhiri**. Pesan "Santri ini sudah punya kebijakan yang BERLAKU ..." berarti akhiri dulu yang lama. Butuh hak Kebijakan Khusus Santri (Buat untuk mengajukan, Ubah untuk memutus).

## Bagaimana mengoreksi nominal tagihan yang sudah terbit?
kata kunci: koreksi tagihan, ubah nominal tagihan, salah nominal spp, edit tagihan, ralat tagihan, bebaskan tagihan terbit

- **SPP**: buka **KEPENDIDIKAN › Kontrol › Daftar Outstanding SPP**, tekan **Edit tagihan** pada barisnya, isi **Nominal yang Benar**, jatuh tempo, dan **Alasan Koreksi**, lalu **Simpan Koreksi**. Selisihnya diterbitkan sebagai jurnal penyesuaian. Nominal 0 sah untuk membebaskan santri yang telanjur tertagih. Butuh hak Outstanding SPP (Ubah).
- **Tagihan lain-lain dan daftar ulang**: lewat **Daftar Outstanding Tagihan Lain**, butuh hak Outstanding Tagihan Lain (Ubah).
- **Tagihan lain di detail santri**: tombol **Koreksi Tagihan**, butuh hak Koreksi Nominal Tagihan (Ubah) — hak khusus kepala keuangan.

Koreksi ditolak selama masih ada pembayaran yang menunggu verifikasi, atau bila nominalnya sama dengan yang sekarang.

## Bagaimana menerbitkan tagihan lain-lain (seragam, kegiatan, denda)?
kata kunci: tagihan lain, tagihan lain-lain, seragam, denda, biaya kegiatan, tagihan manual, terbitkan tagihan lain

1. Buka **TAGIHAN LAIN-LAIN › Transaksi › Daftar & Terbitkan**, tekan **+ Terbitkan Tagihan**.
2. Pilih jenis biayanya dulu — daftar santri menyesuaikan.
3. Isi **Nominal per Santri**, **Tanggal**, **Jatuh Tempo** (agar masuk Reminder), dan **Keterangan** (tampil di tagihan dan kuitansi wali).
4. Untuk jenis yang ditagih tiap bulan, pilih bulannya; santri yang sudah ditagih bulan itu dilewati.
5. Centang santri (ada **Pilih semua yang tampil**), lalu **Terbitkan**.

Butuh hak Tagihan Lain-lain (Buat).

## Bagaimana menagih kegiatan yang hanya untuk peserta (ekskul, umroh)?
kata kunci: peserta kegiatan, ekskul, umroh, kegiatan berpeserta, kepesertaan, matriks tarif kegiatan, daftar peserta

1. Pastikan jenis biayanya bertipe lain-lain dengan **Cara Menagih: Menurut kepesertaan**.
2. Isi besaran per jenjang di **TAGIHAN LAIN-LAIN › Setting Tagihan Lain Lain › Matriks Tarif Kegiatan**, lalu **Simpan Tarif**. Sel kosong berarti jenjang itu **tidak ikut** (berbeda dari menu Tarif); 0 tetap menerbitkan tagihan Rp 0.
3. Di **Peserta Kegiatan**, pilih kegiatannya, **Tambah peserta** (nominal khusus opsional).
4. Tekan **Terbitkan** untuk menagih seluruh peserta berstatus Ikut.

Santri hanya bisa didaftarkan pada jenjang yang selnya terisi. Butuh hak Matriks Tarif Kegiatan (Ubah) dan Tagihan Lain-lain (Ubah untuk peserta, Buat untuk menerbitkan).

## Kenapa tagihan lain-lain tidak bisa dibayar sebagian?
kata kunci: tidak bisa cicil, harus lunas, bayar sebagian ditolak, lunasi sekaligus, cicil tagihan lain

Tagihan lain-lain harus dilunasi sekaligus. Bila nominal yang dicatat kurang dari sisanya, muncul pesan "Tagihan lain-lain harus dilunasi sekaligus, tidak bisa dicicil. Yang harus dibayar ..., bukan ...". Catat ulang dengan nominal sebesar sisa tagihan. SPP, uang pangkal, dan perlengkapan tetap boleh dibayar sebagian.

## Bagaimana membatalkan tagihan lain-lain yang salah terbit?
kata kunci: batalkan tagihan, hapus tagihan lain, salah terbit, tagihan dobel, cancel tagihan

Di **TAGIHAN LAIN-LAIN › Transaksi › Daftar & Terbitkan**, tekan **Batalkan** pada baris tagihannya dan konfirmasi. Butuh hak Tagihan Lain-lain (Hapus). Tagihan yang sudah punya pembayaran, atau sudah diakrualkan ke buku besar, ditolak saat dibatalkan — pakai koreksi nominal di Daftar Outstanding Tagihan Lain; kelebihan bayarnya dipindahkan ke Dompet Wali sebagai titipan.

## Bagaimana mencatat pembayaran santri yang datang langsung (tunai atau transfer)?
kata kunci: catat pembayaran, bayar spp, terima pembayaran, setor tunai, input pembayaran, bayar tagihan, kuitansi, transfer manual

1. Untuk SPP, daftar ulang, dan tagihan lain: **KEPENDIDIKAN › Transaksi › Pembayaran SPP & Tagihan Lain**. Untuk registrasi, uang pangkal, dan perlengkapan: **PPSB › Transaksi › Pembayaran Tagihan PPSB**.
2. Tekan **+ Catat Pembayaran**.
3. Ketik nomor pendaftaran, NIS, atau nama santri, lalu pilih **Tagihan**.
4. Isi **Tanggal**, **Nominal**, **Kas/Rekening Penerima**, **Metode** (Tunai / Transfer ...), unggah **Bukti Transfer** (JPG, PNG, WebP, PDF), dan catatan.
5. Tekan **Catat Pembayaran**.

Pembayaran berstatus menunggu verifikasi dan **belum berjurnal** sampai tim keuangan memverifikasinya. Nominal tidak boleh melebihi sisa tagihan yang belum tercatat. Butuh hak Pembayaran SPP & Tagihan Lain (Buat) atau Pembayaran Tagihan PPSB (Buat).

## Bagaimana memverifikasi pembayaran dan bukti transfer dari wali?
kata kunci: verifikasi pembayaran, cek bukti transfer, konfirmasi bayar, menunggu verifikasi, verifikasi kelompok, bukti tf wali, approve pembayaran

1. Buka **KEPENDIDIKAN › Transaksi › Pembayaran SPP & Tagihan Lain** atau **PPSB › Transaksi › Pembayaran Tagihan PPSB**. Angka merah menunjukkan jumlah yang menunggu.
2. Buka bukti transfer pada barisnya, cocokkan dengan mutasi rekening.
3. Tekan **Verifikasi** dan konfirmasi. Jurnal terbit saat itu.
4. Bila wali membayar beberapa tagihan dengan satu transfer, barisnya bertanda **Kelompok ... tagihan**: cocokkan **total**nya dengan mutasi bank, lalu tekan **Verifikasi kelompok** di baris kepalanya.

Hanya **Anggota Tim Keuangan** (atau admin) yang boleh memverifikasi, ditambah hak Ubah pada modul pembayarannya. Pesan "Pembayaran ini bagian dari transfer berkelompok" berarti gunakan Verifikasi kelompok. Kuitansi bisa dicetak per pembayaran atau **🖨 Gabungan** untuk kelompok.

## Bagaimana menolak pembayaran yang buktinya tidak cocok?
kata kunci: tolak pembayaran, bukti palsu, transfer tidak masuk, salah transfer, tolak kelompok, reject pembayaran

Pada baris pembayaran yang menunggu verifikasi, tekan **Tolak**, tulis alasannya, lalu tekan tombol tolak. Untuk transfer berkelompok, pakai **Tolak kelompok**. Pembayaran yang ditolak tidak berjurnal dan sisa tagihannya kembali seperti semula, sehingga wali bisa membayar ulang. Butuh hak Hapus pada modul pembayarannya. Bila tagihannya sudah dibatalkan atau dihapus, pembayarannya harus ditolak.

## Bagaimana membayar tagihan santri dari saldo Dompet Wali?
kata kunci: bayar dari dompet, potong dompet wali, saldo wali, bayar pakai saldo, dompet wali bayar tagihan

1. Di layar pembayaran (Kependidikan atau PPSB), tekan **Bayar dari Dompet Wali**.
2. Pilih santri dan **Tagihan**, isi **Tanggal** dan **Nominal**, lalu simpan.

Pembayaran ini **langsung berlaku** tanpa menunggu verifikasi, karena dananya sudah diperiksa saat top-up. Bila saldo kurang, bayar sebagian lebih dulu (kecuali tagihan lain-lain yang harus lunas sekaligus). Pesan "Keluarga ini belum punya Dompet Wali" berarti lakukan top-up dulu. Butuh hak Buat pada modul pembayarannya.

## Apakah pembayaran wali lewat Virtual Account perlu diverifikasi?
kata kunci: virtual account, va, midtrans, bayar online, pembayaran otomatis, va lunas, va belum masuk

Tidak. Pembayaran lewat Virtual Account (Midtrans) dicatat **langsung terverifikasi** beserta jurnalnya begitu bank melaporkan lunas. Biaya VA ditanggung wali; tagihan berkurang sebesar nominal tagihannya. Bila staf mencatat pembayaran manual untuk tagihan yang masih punya VA belum dibayar, layar memperingatkan bahwa VA itu dapat dibatalkan bila nominalnya tak lagi muat di sisa tagihan. Yang perlu diverifikasi manual hanya transfer dengan unggahan bukti.

## Muncul pesan "Tagihan berjenis ... bukan urusan modul ...", apa artinya?
kata kunci: bukan urusan modul, salah modul pembayaran, tagihan tidak muncul, pakai modul ppsb, pakai modul kependidikan

Setiap layar pembayaran hanya melayani perilakunya sendiri:

- **Pembayaran Tagihan PPSB**: registrasi, uang pangkal, perlengkapan.
- **Pembayaran SPP & Tagihan Lain**: SPP, daftar ulang, tagihan lain-lain.

Pindah ke layar yang disebut di pesan, lalu catat pembayarannya di sana.

## Bagaimana melihat riwayat tagihan dan pembayaran seorang santri?
kata kunci: rekap pembayaran, riwayat bayar, kartu pembayaran, cetak rekap, histori tagihan, lihat tagihan santri

Buka **KEPENDIDIKAN › Transaksi › Rekap Pembayaran Santri**, cari santrinya, lalu buka detailnya untuk melihat seluruh tagihan dan riwayat pembayaran (registrasi, uang pangkal, SPP, tagihan lain). Rekap bisa dicetak. Menu dengan nama yang sama di **PPSB › Transaksi** hanya memuat santri yang masih punya kewajiban uang pangkal atau perlengkapan. Dari detail santri juga bisa lewat tombol **🧾 Rekap Pembayaran**. Butuh hak Rekap Pembayaran Santri (Lihat).

## Bagaimana melihat daftar tunggakan SPP?
kata kunci: outstanding spp, tunggakan spp, belum bayar spp, daftar nunggak, piutang spp, spp belum lunas

Buka **KEPENDIDIKAN › Kontrol › Daftar Outstanding SPP**. Isinya tagihan SPP yang sudah terbit tetapi belum lunas; baris hilang sendiri begitu lunas. Saring menurut **Tahun Ajaran**, **Periode**, **Jenjang**, atau cari NIS/nama, lalu tekan **Saring**. Tagihan yang langsung terpotong saldo prabayar atau Dompet Wali saat terbit tidak muncul di sini. Butuh hak Outstanding SPP (Lihat).

## Bagaimana melihat tunggakan tagihan lain-lain dan daftar ulang?
kata kunci: outstanding lain, tunggakan daftar ulang, tunggakan laundry, tagihan lain belum lunas, piutang lain

Buka **KEPENDIDIKAN › Kontrol › Daftar Outstanding Tagihan Lain**. Isinya tagihan lain-lain dan daftar ulang yang sudah terbit tetapi belum lunas, dengan yang lewat jatuh tempo di depan. Baris hilang sendiri begitu lunas. SPP punya layarnya sendiri di Daftar Outstanding SPP. Butuh hak Outstanding Tagihan Lain (Lihat).

## Bagaimana mencatat penagihan tunggakan ke wali dan janji bayarnya?
kata kunci: penagihan tunggakan, tagih wali, janji bayar, catatan penagihan, follow up tunggakan, telepon wali, nunggak lewat jatuh tempo

1. Buka **KEPENDIDIKAN › Kontrol › Penagihan Tunggakan**. Isinya santri dengan tagihan lewat jatuh tempo, semua jenis dan jenjang. Saring menurut jenjang, kelas, lama menunggak, atau status penagihan.
2. Buka santrinya, centang tagihan yang ditagihkan.
3. Isi **Tanggal ditagih**, **Cara menagih** (Telepon, WhatsApp, Tatap muka, Surat, Lainnya), **Tanggapan wali**, **Janji bayar** (tanggal dan nominal, bila ada), dan **Langkah berikutnya**.
4. Tekan **Simpan catatan penagihan**.

Catatan tidak bisa diubah atau dihapus; bila keliru, tulis catatan baru. Janji bayar yang lewat muncul di Tugas Saya. Butuh hak Penagihan Tunggakan (Lihat, dan Buat untuk mencatat).

## Bagaimana mengatur reminder tagihan dan tanggal jatuh tempo SPP?
kata kunci: reminder, pengingat tagihan, jatuh tempo, jatuh tempo spp, notifikasi tagihan, h-7, pengingat wali

Buka **SETTING AWAL › Setting Biaya › Reminder Tagihan Jatuh Tempo**.

1. Centang **Aktifkan reminder otomatis**.
2. Isi **Titik Pengingat** (hari sebelum jatuh tempo, dipisah koma, mis. 7,3,1; 0 = tepat hari jatuh tempo) dan **Jam Kirim Harian**.
3. Isi **Tanggal Jatuh Tempo SPP Bawaan** (1–31). Dipakai saat SPP terbit tanpa jatuh tempo; saat disimpan, SPP belum lunas yang jatuh temponya kosong ikut terisi.
4. Pilih sumber tagihan yang dipantau dan penerimanya, lalu **Simpan**.

Bila sumber **Tagihan santri** aktif, wali ikut diingatkan lewat portal dan notifikasi HP, plus satu kali sesudah lewat jatuh tempo. Tombol kirim sekarang menjalankan pengiriman tanpa menunggu jadwal; titik yang sudah terkirim tidak dikirim ulang. Butuh hak Reminder Tagihan Jatuh Tempo (Ubah).

## Bagaimana melihat penerimaan uang santri per periode?
kata kunci: laporan penerimaan, uang masuk, penerimaan per bulan, setoran terverifikasi, rekap penerimaan, pemasukan santri

Buka **KEPENDIDIKAN › Kontrol › Laporan Penerimaan per Periode**. Isinya setoran yang **sudah diverifikasi**, dikelompokkan per jenis biaya dan per bulan — uang yang masuk, bukan tagihan yang terbit. Tanpa saringan, laporan membandingkan jumlahnya dengan buku besar dan menyebut "Cocok" atau besar selisihnya. Saat disaring per jenjang/perilaku, perbandingan itu dimatikan. Bisa diunduh CSV/Excel/PDF. Butuh hak Rekap Pembayaran Santri (Lihat).

## Bagaimana memeriksa santri sudah bebas tanggungan?
kata kunci: bebas tanggungan, clearance, cek tunggakan, santri lulus, sisa saldo santri, surat bebas tanggungan

Buka **KEPENDIDIKAN › Kontrol › Bebas Tanggungan**. Pemeriksaannya dua arah: tagihan santri yang masih bersisa, dan titipan pesantren yang masih ada (saldo Dompet Santri dan Tabungan). Centang tampilkan yang sudah bersih bila ingin melihat semua. Dompet Wali sengaja tidak ikut dihitung karena milik keluarga. Butuh hak Rekap Pembayaran Santri (Lihat).

## Bagaimana mencatat top-up Dompet Wali dan memverifikasinya?
kata kunci: top up dompet, isi saldo wali, setor dompet wali, topup, verifikasi top up, saldo wali bertambah

1. Buka **KEPENDIDIKAN › Transaksi › Dompet & Tabungan**, cari walinya lalu tekan **Lihat**.
2. Di kotak top-up, isi nominal dan **Kas/Rekening** penerima, tekan **Catat Top-up**.
3. Top-up masuk **Buku Mutasi Dompet** berstatus menunggu verifikasi. Setelah dana dipastikan masuk, tim keuangan menekan **Verifikasi** (atau **Tolak**).

Top-up dari portal (transfer dengan bukti) juga muncul di sini untuk diverifikasi; top-up lewat VA langsung berlaku. Mencatat butuh hak Dompet & Tabungan Santri (Buat); verifikasi butuh hak (Ubah) dan Anggota Tim Keuangan; menolak butuh (Hapus).

## Bagaimana menyetor tunai langsung ke Dompet Santri?
kata kunci: setor tunai santri, isi uang saku, dompet santri, titip uang saku, top up santri

Di **KEPENDIDIKAN › Transaksi › Dompet & Tabungan**, tekan **Setor Tunai Santri**. Pilih santri, nominal, kas/rekening, dan keterangan, lalu **Simpan & Kirim ke Keuangan**. Saldo baru bertambah setelah diverifikasi keuangan. Jalur ini bisa dimatikan admin di Pengaturan Perusahaan; bila mati, muncul pesan agar mengisi lewat Dompet Wali lalu dipindahkan. Dompet hanya untuk santri aktif. Butuh hak Dompet & Tabungan Santri (Buat).

## Bagaimana memindahkan saldo antar dompet (wali, santri, tabungan)?
kata kunci: pindah saldo, transfer dompet, uang saku, kirim ke santri, ke tabungan, pindah dompet, distribusi saldo

Di **KEPENDIDIKAN › Transaksi › Dompet & Tabungan**, buka keluarganya, isi kotak pemindahan: **dari** (Dompet Wali atau Dompet Santri), **ke**, santri, dan nominal, lalu **Pindahkan**.

Arah yang sah: Wali → Santri, Wali → Tabungan, Santri → Tabungan, dan Santri → Wali. Tabungan → Wali hanya lewat pengajuan wali yang disetujui keuangan. Pemindahan tidak menggerakkan kas — hanya menggeser antar akun titipan. Butuh hak Dompet & Tabungan Santri (Ubah).

## Bagaimana menyerahkan uang titipan secara tunai (tarik titipan)?
kata kunci: tarik titipan, ambil saldo, cairkan dompet, tarik tunai dompet, uang dikembalikan, kembalikan saldo alumni

Di **KEPENDIDIKAN › Transaksi › Dompet & Tabungan**, pada kotak tarik titipan pilih pemilik (wali, santri, atau tabungan) dan dompetnya, kas/rekening sumber, nominal, nama penerima, dan keterangan, lalu **Tarik Titipan**. Jurnalnya terbit seketika dan uangnya dianggap sudah diserahkan. Ditolak bila saldo tidak cukup, bila Dompet Santri sedang **Terkunci**, atau bila sebagian saldo santri sedang dicadangkan untuk tarik tunai kegiatan. Butuh hak Dompet & Tabungan Santri (Ubah).

## Apa fungsi Kunci Tarik pada Dompet Santri?
kata kunci: kunci tarik, dompet terkunci, buka kunci dompet, kunci dompet santri, gembok dompet

Di tabel **Dompet & Tabungan Santri** pada keluarga yang dibuka, kolom **Kunci Tarik** menampilkan **🔓 Terbuka** atau **🔒 Terkunci**. Tekan tombolnya untuk mengganti. Saat terkunci, saldo Dompet Santri tidak bisa ditarik tunai (pesan "Dompet santri ini sedang dikunci dari penarikan") dan tidak bisa dipakai untuk penarikan tunai kegiatan. Pemindahan antar dompet tidak terhalang kunci ini. Butuh hak Dompet & Tabungan Santri (Ubah).

## Bagaimana menyetujui permintaan wali menarik tabungan ke Dompet Wali?
kata kunci: tarik tabungan, tabungan ke dompet wali, persetujuan tabungan, pencairan tabungan, setujui tabungan

Pengajuan wali dari portal muncul di **KEPENDIDIKAN › Transaksi › Dompet & Tabungan**. Tekan setujui untuk memindahkan saldo Tabungan ke Dompet Wali — jurnal reklasifikasinya terbit seketika — atau **Tolak** dengan alasan lalu **Kirim**. Butuh hak Dompet & Tabungan Santri (Ubah untuk menyetujui, Hapus untuk menolak).

## Apa itu auto-debet dan kapan dijalankan?
kata kunci: auto debet, autodebet, potong otomatis, bayar otomatis, dompet wali otomatis, lunasi otomatis

Tombol **Jalankan Auto-Debet** di **KEPENDIDIKAN › Transaksi › Dompet & Tabungan** memotong saldo Dompet Wali untuk melunasi tagihan, **hanya** bagi keluarga yang mengizinkannya, dimulai dari tunggakan tertua. Konfirmasi muncul sebelum dijalankan. Selain lewat tombol ini, auto-debet juga berjalan otomatis setiap kali SPP diterbitkan — juga hanya untuk keluarga yang mengizinkannya; tagihan yang terpotong begitu tidak muncul di Daftar Outstanding SPP. Butuh hak Dompet & Tabungan Santri (Ubah).

## Bagaimana mencocokkan saldo dompet dengan buku besar?
kata kunci: saldo dompet, daftar saldo, cek saldo titipan, selisih dompet, rekonsiliasi dompet, total titipan

Buka **KEPENDIDIKAN › Kontrol › Daftar Saldo Dompet**. Layar ini menampilkan saldo titipan seluruh wali dan santri (Dompet Wali, Dompet Santri, Tabungan). Totalnya harus sama dengan saldo akun titipan di buku besar; bila berselisih, daftar ini menunjukkan selisihnya pada siapa. Centang **Hanya yang bersaldo** untuk meringkas. Bisa diunduh. Butuh hak Dompet & Tabungan Santri (Lihat).

## Bagaimana memasang, mengganti, atau memblokir kartu santri (RFID)?
kata kunci: kartu santri, rfid, pasang kartu, ganti kartu, kartu hilang, blokir kartu, buka blokir, kartu belanja

1. Buka **DATA SISWA/SANTRI › Kartu Santri (RFID)**, cari santri (centang **Hanya yang belum berkartu** bila perlu).
2. Tekan **Pasang kartu** atau **Ganti kartu**, tempelkan kartu ke pembaca, lalu **Simpan**.
3. Kartu hilang: tekan **Blokir**. Bila ditemukan, buka blokir dari daftar kartu terblokir.
4. **Hapus pendaftaran** melepas kartu agar bisa dipasang ke santri lain; riwayat transaksinya tetap atas nama santri lama.

Banyak kartu sekaligus lewat **Impor Data Awal → Kartu Santri (RFID)**. Batas harian dan PIN belanja diatur wali dari portal. Butuh hak Kartu Santri (Buat untuk pasang/ganti, Ubah untuk blokir/buka, Hapus untuk hapus pendaftaran).

## Bagaimana mengatur belanja kartu santri di kantin mitra?
kata kunci: belanja kantin, kantin mitra, batas kantin, gabung pisah, batas jajan, kantin basah, saklar wali kantin

Buka **DATA SISWA/SANTRI › Belanja Kartu di Kantin**.

1. **Batas belanja harian**: **Gabung** (satu batas untuk Wafi Mart dan semua kantin) atau **Pisah** (batas Wafi Mart dan batas kantin dihitung sendiri; semua kantin berbagi satu batas).
2. Saklar pilihan wali: bila nyala, wali bisa mematikan belanja kantin per anak dari portal; bila mati, semua santri boleh belanja di kantin dan pilihan wali tetap tersimpan.
3. Tekan **Simpan**.

Uangnya tetap dari Dompet Santri, dengan PIN belanja anak bila melewati batas harian. Batas bawaan Wafi Mart diubah di Pengaturan Perusahaan. Butuh hak Belanja Kartu di Kantin (Ubah).

## Bagaimana keuangan menjalankan sesi Penarikan Tunai Kegiatan (outbound, rihlah)?
kata kunci: tarik tunai kegiatan, uang saku kegiatan, outbound, rihlah, sesi tarik tunai, distribusi uang saku, ambil dana bank

1. Buka **KEUANGAN › Transaksi › Penarikan Tunai Kegiatan**, buka **+ Buat sesi baru**, pilih peserta (jenjang, tingkat), batas nominal, dan rekening, lalu **Buat sesi**.
2. Di halaman sesi, tekan **Buka untuk wali** agar wali bisa mengajukan dari portal. Peserta bisa ditambah lewat NIS atau dikeluarkan.
3. Setelah pengajuan disetujui kesantrian, catat dana sudah diambil dari bank (jurnal terbit).
4. Tekan **Buka layar distribusi**: santri menempelkan kartu, petugas menekan **Serahkan**. Tanpa kartu, pakai **Tanpa kartu** dengan alasan.
5. Hitung uang fisik sisa, lalu **Selesaikan sesi** — pengajuan yang belum diambil dilepas dan jurnal penutup dibukukan.

Rekap per sesi bisa dicetak. Butuh hak Sesi Penarikan Tunai Kegiatan (Buat untuk membuat, Ubah untuk langkah berikutnya, Hapus untuk membatalkan sesi atau penyerahan).

## Bagaimana menyetujui pengajuan wali untuk uang saku tunai kegiatan?
kata kunci: persetujuan tarik tunai, setujui uang saku, tolak pengajuan tarik, pengajuan wali kegiatan, uang saku outbound

Buka **KEPENDIDIKAN › Transaksi › Persetujuan Tarik Tunai Kegiatan**. Wali mengajukan dari Dompet Santri dengan kelipatan Rp 50.000, dan saldonya langsung dicadangkan. Tekan **Setujui** sesuai pengajuan, atau isi nominal yang **lebih kecil** (tetap kelipatan Rp 50.000). Untuk menolak, tekan **Tolak**, tulis alasan (dikirim ke wali), lalu **Tolak pengajuan**. Pengajuan yang belum diputus saat batas persetujuan lewat akan gugur dan cadangannya dilepas. Butuh hak Persetujuan Penarikan Tunai Kegiatan (Ubah).

## Bagaimana menerima cucian santri di laundry?
kata kunci: terima cucian, laundry masuk, setor laundry, timbang cucian, tap kartu laundry, cucian kotor, kantong laundry

1. Buka **LAUNDRY › Terima Cucian (tap kartu)**.
2. Santri menempelkan kartu. Bila kartu tertinggal, cari lewat nama atau NIS (tercatat sebagai pencarian manual).
3. Timbang dan isi beratnya. Jenis dan jumlah pakaian belum perlu dicatat — itu dikerjakan di meja Pemeriksaan.
4. Tekan **Simpan & terima**. Tekan **Cetak label** bila ingin mencetak label kantong.

Santri harus aktif dan jenis kelaminnya terisi (menentukan laundry ikhwan atau akhwat). Berat harus lebih dari 0 dan dalam batas wajar. Santri yang cuciannya tertahan karena tunggakan tetap boleh menyetor cucian kotor. Sesudah disimpan, tekan Cetak label untuk mencetak label kantong. Butuh hak Laundry Ikhwan atau Laundry Akhwat (Buat).

## Bagaimana memeriksa dan menimbang ulang cucian?
kata kunci: pemeriksaan cucian, timbang ulang, daftar pakaian, cek cucian, hitung pakaian, meja pemeriksaan, berat final

1. Buka **LAUNDRY › Pemeriksaan Cucian (timbang ulang)**.
2. Ketik nomor kantong atau cari nama santri, tekan **Buka**.
3. Isi **Berat timbang ulang (kg)** — selisih dari penerimaan ditampilkan — dan catat jenis serta jumlah tiap pakaian (tombol − / + atau ketik angkanya, 1–200).
4. Tekan **Simpan pemeriksaan**, lalu **Cetak label + daftar cucian** bila perlu untuk cek saat packing.

Berat pemeriksaan inilah yang menjadi berat final, dipakai kuota, dan dilihat wali di portal. Daftar pakaian wajib diisi. Hanya kantong berstatus Diterima yang diperiksa. Butuh hak Laundry Ikhwan atau Laundry Akhwat (Ubah).

## Bagaimana memajukan proses cucian di papan laundry?
kata kunci: papan laundry, proses cucian, cucian selesai, status laundry, diproses, ikhwan, akhwat, majukan status

Buka **LAUNDRY › Papan Laundry Ikhwan** atau **Papan Laundry Akhwat**. Kolomnya: Diterima → (meja Pemeriksaan) → Diperiksa → Diproses → Selesai. Centang kantong di sebuah kolom, lalu tekan tombol **... kantong → Diproses** (atau **→ Selesai**). Kantong yang Selesai diambil lewat menu Serahkan Cucian. Membuka papan butuh hak Laundry Ikhwan/Akhwat (Lihat); memajukan butuh (Ubah). Petugas hanya melihat cucian bagiannya.

## Bagaimana menyerahkan cucian bersih ke santri?
kata kunci: serahkan cucian, ambil laundry, cucian bersih, cocokkan kantong, pakaian kurang, tap kartu serahkan

1. Buka **LAUNDRY › Serahkan Cucian (tap kartu)**. Santri menempelkan kartu (atau cari manual).
2. Muncul kantong santri yang berstatus Selesai. Bila ingin mencocokkan, ketik nomor di label lalu Enter atau tekan **Cocok** — tidak wajib.
3. Periksa jumlah pakaian. Bila ada yang kurang, isi catatan (dicari dulu / diganti / sudah dikonfirmasi santri).
4. Tekan **Serahkan**.

Peringatan "... BUKAN kantong santri ini — jangan diserahkan" muncul bila nomor yang diketik milik santri lain. Butuh hak Laundry Ikhwan atau Laundry Akhwat (Ubah).

## Kenapa cucian santri "TERTAHAN" dan bagaimana memberi izin mendesak?
kata kunci: cucian tertahan, laundry ditahan, tunggakan laundry, izin mendesak, tidak bisa ambil cucian, tahan cucian

Bila tagihan kelebihan laundry santri sudah lewat jatuh tempo dan belum lunas sebanyak jumlah periode yang diatur, layar Serahkan menampilkan "Cucian TERTAHAN" dan tombolnya terkunci. Santri tetap boleh menyetor cucian kotor. Untuk keadaan mendesak, pemegang hak Laundry — Izin mengambil cucian tertahan (Ubah) mengisi **Alasan izin mendesak** lalu menekan **Izinkan & serahkan (mendesak)**. Alasannya tercatat di Jejak Audit. Cara terbaik tetap melunasi tunggakannya. Jumlah periode penahanan diatur di Pengaturan Laundry (0 = penahanan mati).

## Bagaimana mengubah data kantong laundry yang salah input atau membatalkannya?
kata kunci: ubah laundry, salah berat, koreksi berat laundry, batalkan kantong, salah input laundry, edit kantong

Buka kantongnya (dari papan atau **LAUNDRY › Riwayat Laundry**). Tekan **Ubah data (salah input berat / pakaian / catatan)**, perbaiki, tulis alasan, lalu **Simpan perubahan**. Bila kelebihan bulan itu sudah ditagih, berat terkunci (pakaian dan catatan masih bisa diubah); koreksi nilainya lewat Koreksi Nominal Tagihan. Kantong yang belum dicuci (Diterima atau Diperiksa) bisa dibatalkan dengan tombol **Batalkan** beserta alasannya. Mengubah butuh hak Laundry Ikhwan/Akhwat (Ubah); membatalkan butuh (Hapus). Label kantong bisa dicetak ulang lewat **Cetak label kantong**.

## Bagaimana menyusun dan menerbitkan tagihan kelebihan kuota laundry?
kata kunci: tagihan laundry, kelebihan kuota, kuota laundry, tagih kelebihan, laundry bulanan, kecualikan santri

1. Buka **LAUNDRY › Kuota & Tagihan**, pilih bulan, tekan **Tampilkan**.
2. Tanggal 1 tiap bulan draf tagihan bulan lalu disusun otomatis. Bila perlu, tekan **Susun ulang draf**.
3. Periksa daftarnya; **kecualikan** santri bila perlu (dengan alasan), atau **tarik pengecualian**.
4. Tekan **Terbitkan sekarang**, atau biarkan terbit otomatis pada tanggal yang diatur bila saklar terbit otomatis **NYALA**.

Selama bulan berjalan, tagihan belum bisa disusun. Santri yang masih punya kantong belum diperiksa ditunda tagihannya. Pesan "Tarif kelebihan per kg belum diatur" berarti isi dulu Pengaturan Laundry. Butuh hak Laundry — Kuota & Tagihan Kelebihan (Buat; Ubah untuk mengecualikan).

## Bagaimana mengatur tarif kelebihan, kuota, dan penahanan laundry?
kata kunci: pengaturan laundry, kuota per bulan, tarif per kg, kuota ikhwan, kuota akhwat, penahanan laundry, terbit otomatis

Buka **LAUNDRY › Pengaturan Laundry (tarif & kuota)**:

1. **Tarif kelebihan per kg** dan **Jenis biaya penagihan (bawaan)**.
2. **Matriks kuota per bulan (kg)** per jenjang untuk ikhwan dan akhwat. Baris **Bawaan** berlaku untuk jenjang yang selnya dikosongkan.
3. **Penerbitan tagihan kelebihan**: tanggal terbit dan centang **Terbitkan otomatis pada tanggal itu**.
4. **Tahan bila menunggak (periode)**: 0 = tidak ada penahanan.

Kuota dihitung per bulan kalender dari tanggal cucian diterima; sisa kuota tidak terbawa. Jurnal tagihan yang terbit otomatis dicatat atas nama orang yang terakhir menyimpan pengaturan. Butuh hak Laundry — Jenis Pakaian & Pengaturan (Ubah).

## Bagaimana memantau pemakaian dan tunggakan laundry?
kata kunci: monitor laundry, dashboard laundry, pemakaian laundry, tunggakan laundry, grafik laundry, kelebihan kuota

- **LAUNDRY › Monitor Kelebihan Laundry**: pemakaian dibanding kuota, tagihan kelebihan yang sudah terbit, dan daftar tunggakan yang lewat jatuh tempo. Butuh hak Laundry — Monitor Kelebihan (Lihat).
- **LAUNDRY › Dashboard Laundry**: grafik dan angka 12 bulan (pemakaian, penyerahan per santri, lama proses, jenis pakaian terbanyak, nilai tagihan). Tanda ≈ berarti perkiraan karena tagihan bulan itu belum disusun. Butuh hak Laundry — Dashboard (Lihat).
- **LAUNDRY › Riwayat Laundry**: riwayat kantong; centang **hanya yang kurang** untuk penyerahan dengan pakaian kurang.

## Bagaimana menambah jenis pakaian laundry?
kata kunci: jenis pakaian, tambah pakaian, daftar pakaian, master pakaian, nonaktifkan pakaian

Buka **LAUNDRY › Jenis Pakaian**. Ketik nama di kotak **Jenis pakaian baru**, tekan **Tambah**. Nama dan status bisa diubah per baris lalu **Simpan**. Jenis yang nonaktif tidak muncul di pemeriksaan baru; riwayat lama tetap memakai nama saat dicatat. Butuh hak Laundry — Jenis Pakaian & Pengaturan (Buat/Ubah).

## Bagaimana memproses pengajuan izin insidental dari wali?
kata kunci: perizinan, izin pulang, izin sakit, setujui izin, tolak izin, izin keluar, persetujuan izin, izin santri

1. Buka **KEPENDIDIKAN › Absensi dan Perizinan › Perizinan Insidental**. Saring menurut status bila perlu.
2. Buka pengajuannya untuk melihat jenis izin, waktu keluar dan kembali, keperluan, penjemput, dan lampiran.
3. Tekan **Setujui**, atau **Tolak** dengan alasan.

Bila dua tingkat persetujuan aktif, tingkat 1 memutus lebih dulu lalu tingkat 2. Jenis izin bertanda persetujuan otomatis langsung disetujui. Pesan "Anda tidak berhak menyetujui pengajuan pada tingkat ini" berarti tingkat yang sedang menunggu bukan tingkat Anda. Butuh hak Perizinan — persetujuan tingkat 1 atau tingkat 2 (Ubah). Santri yang terlambat kembali ditandai di atas daftar.

## Bagaimana mencatat izin santri atas nama wali?
kata kunci: izin atas nama wali, catat izin, wali telepon, santri sakit mendadak, buat izin, input izin staf

1. Di **KEPENDIDIKAN › Absensi dan Perizinan › Perizinan Insidental**, tekan **+ Atas nama wali**.
2. Pilih santri, jenis izin, waktu keluar dan kembali, keperluan (wajib untuk jenis tertentu), dan penjemput (dijemput wali atau diwakilkan).
3. Tekan **Catat izin**.

Batas waktu pengajuan tidak berlaku bagi staf; persetujuan tetap mengikuti pengaturan jenis izinnya, dan wali dikabari. Izin keluar lingkungan ma'had harus kembali di hari yang sama. Penjengukan dan kepulangan rutin tidak dicatat di sini, melainkan lewat Perizinan Rutin. Butuh hak Perizinan Insidental (Buat).

## Bagaimana mencatat santri keluar dan kembali di pos?
kata kunci: pos keluar masuk, catat keluar, catat kembali, satpam, tap kartu pos, santri pulang, gerbang

1. Buka **KEPENDIDIKAN › Absensi dan Perizinan › Pos Keluar/Masuk Santri**.
2. Santri menempelkan kartu (atau cari nama/NIS bila kartu tertinggal).
3. Cocokkan data penjemput yang tampil (termasuk foto identitas bila diwakilkan).
4. Tekan **Catat KELUAR** atau **Catat KEMBALI**.

Bila tertulis "Tidak ada izin yang disetujui untuk santri ini — tidak boleh keluar", santri belum punya izin yang disetujui. Butuh hak Perizinan Insidental (Ubah).

## Santri tertahan di pos karena tunggakan, apa yang bisa dilakukan?
kata kunci: tertahan tunggakan, tidak boleh keluar, izin tunggakan, santri nunggak izin, tahan di pos, izinkan keluar

Bila jenis izinnya memakai kebijakan tunggakan, santri dengan tagihan lewat jatuh tempo yang belum lunas bisa tertahan: "Izin tidak diberikan" (tak bisa diajukan dan tertahan di pos keluar), atau "Boleh keluar, tidak boleh kembali" (tertahan di pos masuk). Di pos, tombolnya berubah menjadi **Izinkan keluar** / **Izinkan masuk** dan alasan wajib diisi; keputusannya tercatat di Jejak Audit atas nama petugas. Hanya pemegang hak Perizinan — izinkan santri yang tertahan tunggakan di pos (Ubah) yang bisa melakukannya. Lebih baik arahkan wali melunasi tunggakannya.

## Bagaimana mengatur tingkat persetujuan dan jenis izin?
kata kunci: pengaturan perizinan, tingkat persetujuan, batas pengajuan, h-14, persetujuan otomatis, penjemput diwakilkan, jenis izin

Buka **KEPENDIDIKAN › Absensi dan Perizinan › Pengaturan Perizinan**:

1. **Tingkat persetujuan** — aktifkan tingkat 1, tingkat 2, atau keduanya.
2. **Jenis izin** — per jenis atur **Batas** (wali mengajukan paling lambat H-n; kosong = tanpa batas; 0 = hari itu juga), **Persetujuan otomatis**, dan **Kebijakan tunggakan**.
3. **Penjemput diwakilkan** — centang isian yang wajib diisi wali bila penjemputnya bukan wali.

Penyetuju tiap tingkat adalah pemegang hak Perizinan — persetujuan tingkat 1/2 (Ubah). Butuh hak Perizinan — pengaturan (Ubah).

## Bagaimana membuat jadwal penjengukan dan kepulangan rutin?
kata kunci: perizinan rutin, jadwal penjengukan, jadwal kepulangan, jenguk santri, pulang rutin, matriks jadwal, tiadakan jadwal

1. Buka **KEPENDIDIKAN › Absensi dan Perizinan › Jadwal Perizinan Rutin**.
2. Atur matriks tiap jenis rutin (per jenjang dan ikhwan/akhwat).
3. Tekan **Buat jadwal** di kotak **Buat jadwal dari matriks**. Aman ditekan berulang — jadwal yang sudah ada tidak dibuat ulang.
4. Untuk tanggal di luar pola, pakai **Tambah jadwal khusus** dengan catatan untuk wali.

Di daftar **Jadwal mendatang**, jadwal bisa diubah waktunya (pengajuan yang belum keluar ikut bergeser), **Tiadakan** dengan alasan (pengajuan dibatalkan dan wali dikabari), **Aktifkan lagi**, atau **Hapus**. Bila jadwal bulan depan belum dibuat, muncul tugas di Beranda. Butuh hak Perizinan Rutin — matriks & jadwal (Ubah).

## Bagaimana menyetujui pengajuan penjengukan atau kepulangan rutin?
kata kunci: setujui penjengukan, setujui kepulangan, perizinan rutin, setujui semua, pengajuan rutin wali

Buka **KEPENDIDIKAN › Absensi dan Perizinan › Perizinan Rutin**, lalu tekan jadwalnya. Di halaman jadwal terlihat santri, pengajuan wali, dan statusnya. Tekan **Setujui semua (n)** untuk menyetujui seluruh pengajuan yang menunggu keputusan Anda sekaligus, atau buka pengajuan satu per satu. Pencatatan keluar dan kembalinya tetap lewat Pos Keluar/Masuk Santri. Butuh hak Perizinan — persetujuan tingkat 1 atau 2 (Ubah).

## Di mana melihat kehadiran asrama dari ISE?
kata kunci: absensi asrama, kehadiran asrama, ise, absen asrama, data absen, santri tidak cocok

Buka **KEPENDIDIKAN › Absensi dan Perizinan › Absensi Asrama (ISE)**. Isinya catatan absen asrama yang dikirim aplikasi ISE ke ERP, yang juga tampil di portal wali. Ada daftar santri dari kiriman ISE yang tidak cocok dengan santri ERP — betulkan NIS-nya di ISE atau ERP, dan kiriman berikutnya otomatis tercocok — serta log kiriman. Bila tertulis pintu penerima ISE belum aktif, hubungi admin aplikasi. Butuh hak Absensi Asrama dari ISE (Lihat).

## Bagaimana mencatat kunjungan santri ke klinik?
kata kunci: klinik, catat kunjungan, santri sakit, berobat, uks, tap kartu klinik, keluhan, rujuk rumah sakit

1. Buka **KLINIK › Catat Kunjungan (tap kartu)**. Santri menempelkan kartu, atau cari manual.
2. Isi keluhan, pemeriksaan (suhu, tensi, nadi, berat), tindakan, obat (tekan **+ obat** untuk menambah baris), dan tindak lanjut: Kembali ke kelas, Istirahat di asrama, Dirawat di klinik, Dirujuk ke rumah sakit (sebutkan fasilitasnya), atau Dijemput wali.
3. Tekan **Simpan kunjungan**.

Wali langsung dikabari lewat notifikasi. Daftar hari ini ada di **KLINIK › Klinik Hari Ini**, ringkasan di **KLINIK › Rekap Klinik**. Butuh hak Klinik (Buat).

## Bagaimana menyelesaikan rawat klinik dan menerbitkan tagihan klinik?
kata kunci: selesai rawat, pulang ke asrama, tagihan klinik, biaya klinik, biaya obat, tagih wali klinik

Buka kunjungannya dari **KLINIK › Klinik Hari Ini**.
- **Selesai**: isi catatan kondisi, tekan **Selesai / pulang ke asrama**. Butuh hak Klinik (Ubah).
- **Tagihan**: pilih jenis biaya, isi nominal, tekan **Terbitkan tagihan**. Butuh hak Klinik (Buat) dan juga Tagihan Lain-lain (Buat).

Jenis biaya harus bertipe Lain-lain dan aktif (misalnya "Biaya Klinik"); bila belum ada, layar memberi tahu agar dibuat di master Jenis Biaya. Satu kunjungan hanya bisa punya satu tagihan.

## Bagaimana mengatur metode pembayaran di portal wali?
kata kunci: metode pembayaran, cara bayar portal, matikan va, transfer bukti, pengumuman wali, va gangguan, midtrans mati

Buka **SETTING AWAL › Setting Umum › Metode Pembayaran Portal**. Nyalakan atau matikan **Virtual Account** (lewat Midtrans) dan **Transfer + bukti**. **Dompet Wali selalu aktif**, dan minimal satu metode lain harus tetap nyala. Isi **Pengumuman untuk wali** bila perlu (mis. "VA sedang gangguan, silakan gunakan Transfer + bukti"), lalu **Simpan**. Mematikan metode hanya menahan pesanan baru: VA yang sudah terbit tetap bisa dibayar dan bukti transfer yang menunggu tetap diverifikasi. Butuh hak Metode Pembayaran Portal (Ubah).

## Bagaimana mengatur Asisten Bantuan dan artikel panduannya?
kata kunci: asisten bantuan, artikel panduan, ai portal, bantuan wali, bantuan staf, riwayat pertanyaan, uji ai

Buka **SETTING AWAL › Setting Umum › Asisten Bantuan Portal**:

1. Nyalakan **Tampilkan menu Bantuan di portal wali** dan/atau **Tampilkan tombol bantuan untuk staf**.
2. Pilih **Penyedia AI** dan isi batas harian (**Batas / keluarga / hari**, **Batas / staf / hari**), lalu **Simpan**.
3. Kelola artikel panduan untuk wali dan staf: tambah, ubah, nonaktifkan, atau hapus. Judul ditulis sebagai pertanyaan, isi berupa langkah bernomor, ditambah kata kunci.
4. Pakai **Uji** untuk mencoba pertanyaan, dan **Riwayat pertanyaan** untuk melihat pertanyaan yang belum punya artikel cocok.

AI hanya menjawab dari artikel dan tidak melihat data. Jangan memuat tarif, nomor rekening, kata sandi, atau nama orang di artikel. Bila AI tidak bisa dipakai, penanya tetap mendapat hasil pencarian artikel. Butuh hak Asisten Bantuan Portal (Lihat; Buat/Ubah/Hapus untuk artikel; Ubah untuk pengaturan).
