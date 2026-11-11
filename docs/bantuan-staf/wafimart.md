Panduan ini untuk staf ERP Wafi Mart: kasir, pengawas kasir, petugas gudang, dan pengelola toko serta kantin mitra. Isinya pekerjaan toko sehari-hari: berjualan di kasir, buka dan tutup shift, retur, data barang, stok, pembelian barang, kantin mitra, dan laporan toko. Semua menu ada di grup WAFI MART pada sidebar. Kalau menu yang disebut tidak muncul, berarti Anda belum punya hak aksesnya. Mintalah admin membukanya di matriks hak akses dengan nama modul yang disebut di tiap artikel.

## Bagaimana cara membuka layar kasir?
kata kunci: kasir, pos, layar kasir, buka kasir, mulai jualan, jualan, point of sale, kasir toko, menu kasir

1. Buka menu WAFI MART › Kasir (POS).
2. Kalau Anda belum punya shift terbuka, yang muncul layar Buka Shift Kasir. Buka shift dulu (lihat artikel membuka shift).
3. Kalau shift Anda sudah terbuka, layar berjualan langsung tampil.
Di bagian atas layar kasir ada tombol Riwayat Struk, Retur, dan Tutup Shift. Untuk membuka layar dan riwayat struk, Anda butuh hak Kasir Toko / POS (Lihat). Untuk membuka shift dan berjualan, butuh hak Kasir Toko / POS (Buat). Mintalah admin bila belum ada.

## Bagaimana cara membuka shift kasir?
kata kunci: buka shift, mulai shift, shift baru, modal awal, modal laci, uang kembalian awal, open shift, sif, buka sip

1. Buka WAFI MART › Kasir (POS). Layar Buka Shift Kasir akan tampil.
2. Pilih Toko tempat Anda bertugas.
3. Isi Modal awal di laci, yaitu uang kembalian yang ada di laci sebelum mulai berjualan. Hitung dulu uangnya.
4. Kalau toko itu sudah pernah punya shift, di bawah isian muncul catatan "laci seharusnya berisi Rp …" menurut shift terakhir. Tekan "Pakai angka ini" bila uang di laci memang sejumlah itu.
5. Tekan Buka Shift.
Modal awal WAJIB sama dengan sisa laci shift terakhir. Bila berbeda, lihat artikel modal awal berbeda dari sisa laci. Satu kasir hanya boleh punya satu shift terbuka. Butuh hak Kasir Toko / POS (Buat).

## Modal awal saya berbeda dari sisa laci shift sebelumnya, apa yang harus dilakukan?
kata kunci: modal beda, modal tidak sama, sisa laci, uang laci kurang, uang laci lebih, pin pengawas buka shift, alasan beda modal, selisih modal

Sistem meminta modal awal sama dengan uang yang seharusnya masih ada di laci menurut shift terakhir toko itu. Bila uang di laci memang kurang atau lebih (mis. ada uang dipakai beli plastik), lakukan ini:
1. Ketik modal sesuai uang yang benar-benar ada di laci.
2. Akan muncul kotak kuning "Berbeda Rp … dari sisa laci shift terakhir".
3. Tulis alasannya, pilih nama pengawas, lalu minta pengawas mengetik PIN pengawasnya sendiri.
4. Tekan Buka Shift.
Bedanya dicatat sebagai selisih kas toko saat itu juga, sehingga selisih saat tutup shift nanti hanya milik shift Anda. Kalau alasan atau PIN belum diisi, muncul pesan "Modal … berbeda dari sisa laci shift … Pakai angka sisa laci, atau tulis alasannya dan minta PIN pengawas."

## Kenapa toko tidak bisa dipilih atau shift tidak bisa dibuka?
kata kunci: toko tidak muncul, belum siap, akun kasir belum lengkap, shift gagal dibuka, tidak bisa buka shift, lokasi toko, error buka shift

Pesan yang sering muncul saat membuka shift:
- "Belum ada lokasi berjenis toko" atau toko tertulis "belum siap (akun kasir belum lengkap)": akun kasir lokasi toko itu belum diisi. Pengelola perlu melengkapinya di WAFI MART › Toko & Gudang (Lokasi).
- "Shift kasir hanya bisa dibuka di lokasi berjenis toko yang aktif.": lokasi yang dipilih bukan toko atau sedang nonaktif.
- "Anda masih punya shift terbuka (…). Tutup dulu sebelum membuka yang baru.": tutup shift lama Anda dulu. Kalau Anda tidak bisa menutupnya, minta pengawas menutupnya dari WAFI MART › Shift Kasir & Setoran.
- "Anda sudah punya shift terbuka — muat ulang halaman.": tombol tertekan dua kali. Muat ulang halaman saja.

## Bagaimana cara mencari atau scan barang di kasir?
kata kunci: scan barang, barcode, cari barang, ketik nama barang, tambah ke keranjang, F2, scanner, pindai, scan barkode

1. Di layar kasir, kursor ada di kotak "Scan barcode atau ketik nama barang". Tekan F2 kapan saja untuk kembali ke kotak itu.
2. Pindai barcode dengan scanner. Barang langsung masuk ke keranjang dengan satuan sesuai barcode-nya (mis. barcode dus menjual 1 dus).
3. Atau ketik minimal 2 huruf nama atau kode barang, lalu pilih dari daftar. Di daftar terlihat sisa stok barang di toko Anda.
4. Ubah Jumlah atau Satuan di baris keranjang bila perlu. Tanda × menghapus baris.
Harga diambil dari data barang, jadi kasir tidak mengetik harga. Barang hanya muncul bila ditandai "Dijual di toko", berstatus aktif, dan punya harga jual. Harga promo yang sedang berlaku tampil dengan label promo dan harga normal dicoret.

## Kenapa barang tidak bisa dijual atau ditolak di kasir?
kata kunci: barang tidak muncul, barang ditolak, belum punya harga jual, barcode tidak dikenal, stok tidak cukup, satuan belum terdaftar, gagal scan, barang tidak ada

Pesan yang mungkin muncul:
- "Barcode … tidak dikenal.": barcode itu belum didaftarkan di data barang. Minta petugas menambahkannya di WAFI MART › Data Barang & Stok.
- "… belum ditandai dijual di toko atau belum punya harga jual." atau "… belum punya harga jual — isi harganya di master barang dulu.": harga jual masih kosong atau nol. Harga nol dianggap belum diisi, bukan gratis.
- "… belum punya Akun Persediaan.": data akun barang belum lengkap. Hubungi pengelola.
- "Satuan … belum terdaftar untuk …": satuan konversinya belum dibuat di data barang.
- "Stok … tidak cukup: diminta …, tersedia …": stok di toko kurang. Sistem tidak mengizinkan stok minus. Cek apakah barang belum diterima atau belum ditransfer ke toko.

## Bagaimana cara menerima pembayaran tunai di kasir?
kata kunci: bayar tunai, cash, uang diterima, kembalian, uang pas, F9, simpan struk, bayar, transaksi tunai

1. Setelah keranjang terisi, lihat Total belanja di sebelah kanan.
2. Ketik uang dari pembeli di kotak "Uang diterima". Bisa juga tekan "Uang pas" atau tombol nominal cepat (20.000, 50.000, 100.000).
3. Kembalian dihitung otomatis.
4. Tekan BAYAR atau F9 (bisa juga Enter di kotak uang).
5. Muncul kotak hijau "Tersimpan" berisi nomor struk dan kembalian. Struk tercetak otomatis, dan ada tombol "Cetak ulang struk".
Kalau uang kurang dari total, muncul pesan "Uang diterima kurang dari total." Kalau keranjang kosong: "Keranjang masih kosong."

## Bagaimana cara memberi diskon di kasir?
kata kunci: diskon, potongan harga, diskon barang, diskon struk, potongan, pin pengawas diskon, alasan diskon, discount, korting

Ada dua cara memberi diskon manual:
- Diskon per barang: tekan "+ diskon" di baris barang, lalu ketik nominal potongannya.
- Diskon struk: isi "Diskon struk" di bawah Total belanja.
Setiap diskon manual WAJIB disetujui pengawas. Saat Anda menekan BAYAR, muncul kotak "Persetujuan pengawas". Tulis alasan diskon, lalu pengawas memilih namanya dan mengetik PIN. Tekan "Setujui & Bayar". Nama penyetuju dicetak di struk. Diskon tidak boleh lebih besar dari harga barang atau total belanja. Promo terjadwal yang diatur di data barang tidak butuh persetujuan.

## Bagaimana cara membayar dengan kartu santri (RFID)?
kata kunci: kartu santri, rfid, bayar kartu, tap kartu, tempel kartu, uang saku, dompet santri, F8, kartu belanja, e-money santri

1. Setelah keranjang terisi, tekan "BAYAR KARTU SANTRI" atau F8.
2. Minta santri menempelkan kartunya ke pembaca. Nama dan NIS santri akan tampil.
3. Kalau belanja melewati batas harian, santri mengetik PIN belanjanya sendiri.
4. Selesaikan pembayaran. Struk mencetak nama santri dan sisa uang saku.
Kasir tidak bisa melihat saldo santri. Kalau saldo kurang, pembayaran akan ditolak. Kartu dibayar persis sebesar total, jadi tidak ada kembalian. Penjualan kartu tidak masuk ke laci, sehingga tidak ikut dihitung saat tutup shift. Kartu didaftarkan, diblokir, dan diberi batas oleh ERP Al Wafi, bukan dari Wafi Mart.

## Apa arti pesan galat saat bayar dengan kartu santri?
kata kunci: kartu ditolak, kartu tidak dikenal, saldo tidak cukup, pin belanja salah, batas harian, kartu diblokir, kartu error, uang saku kurang

Pesan yang sering muncul:
- "Kartu tidak dikenal, diblokir, atau bukan santri aktif.": kartu belum terdaftar atau sudah diblokir di ERP Al Wafi. Layani tunai.
- "Saldo uang saku tidak cukup untuk belanja ini.": kurangi belanja atau bayar tunai.
- "Melewati batas harian, dan wali belum memasang PIN belanja": santri tidak bisa belanja di atas batas. Kurangi belanja atau bayar tunai.
- "PIN belanja salah." / "PIN belanja terkunci sampai …": PIN salah 5 kali akan dikunci sementara.
- "Kartu belum bisa diproses (penghubung dompet tak menjawab) — silakan bayar tunai.": sambungan ke ERP Al Wafi sedang putus. Layani tunai.
- "Tempelkan kartu santri dulu.": kartu belum terbaca. Tempelkan lagi.

## Muncul pesan "BELUM PASTI" saat bayar kartu, apa yang harus dilakukan?
kata kunci: belum pasti, cek lagi, jangan terima tunai, kartu terpotong, koneksi putus, pembayaran kartu gantung, pending kartu, kartu loading

Pesan "Status pembayaran kartu BELUM PASTI — JANGAN terima tunai dulu" berarti sistem belum tahu apakah uang saku santri sudah terpotong.
1. Jangan terima uang tunai dulu, dan jangan biarkan santri membawa barang.
2. Tunggu sebentar, lalu tekan "Cek lagi".
3. Kalau hasilnya berhasil, struk tersimpan. Kalau hasilnya "Dipastikan TIDAK terpotong", aman untuk mengulang bayar kartu atau menerima tunai.
4. Kalau tetap belum pasti dan pembeli harus dilayani, tekan "Lepaskan & layani tunai". Potongan kartu yang sempat terjadi akan dikembalikan otomatis ke santri saat pencocokan malam.
Jangan mengubah isi keranjang selama status masih belum pasti.

## Bagaimana sistem mencegah struk tersimpan dua kali?
kata kunci: struk dobel, struk ganda, tersimpan dua kali, koneksi putus saat bayar, bayar lagi, double, kiriman ganda, klik dua kali

Setiap keranjang punya kunci sendiri. Kalau koneksi putus saat Anda menekan BAYAR, muncul pesan "Koneksi bermasalah — status struk BELUM PASTI. Tekan bayar lagi". Tekan BAYAR lagi tanpa mengubah keranjang. Aman: struk dan potongan kartu tidak akan terjadi dua kali, dan Anda akan mendapat struk yang sama.
Kalau isi keranjang sudah Anda ubah, muncul pesan "Kiriman sebelumnya SUDAH tersimpan sebagai struk …, tetapi isi keranjang kini berbeda." Artinya struk pertama sudah sah. Buka Riwayat Struk, kurangi barang yang sudah ada di struk itu dari keranjang, lalu bayar lagi untuk sisanya saja.

## Bagaimana cara mencetak ulang struk atau melihat riwayat struk?
kata kunci: cetak ulang struk, print struk, riwayat struk, struk hilang, daftar transaksi, struk kemarin, reprint, nota

1. Struk terakhir: tekan "Cetak ulang struk" di kotak hijau setelah transaksi.
2. Struk lain di shift ini: tekan Riwayat Struk di bagian atas layar kasir, lalu tekan "Cetak ulang" di baris yang dicari.
Riwayat Struk hanya memuat struk shift Anda yang sedang terbuka. Struk dicetak untuk printer kecil (kertas termal 58 mm). Struk kartu mencetak nama santri dan sisa uang saku. Struk yang dibatalkan bertanda DIBATALKAN. Pengawas bisa melihat struk semua shift di WAFI MART › Shift Kasir & Setoran.

## Saya salah input transaksi, bagaimana membatalkan struk?
kata kunci: batal struk, salah scan, salah transaksi, hapus transaksi, void struk, cancel struk, membatalkan penjualan, struk salah

Kasir tidak bisa membatalkan struknya sendiri. Minta pengawas yang melakukannya:
1. Pengawas membuka WAFI MART › Shift Kasir & Setoran, lalu memilih nomor shift Anda.
2. Di daftar Struk, pengawas menekan "Batalkan" pada struk itu, menulis alasan (mis. salah scan), lalu menekan "Batalkan struk".
Stok barang kembali ke toko. Kalau struk dibayar kartu, uangnya kembali ke uang saku santri, bukan dari laci. Pembatalan hanya bisa selama shift masih terbuka. Struk yang sudah pernah diretur tidak bisa dibatalkan utuh, gunakan retur. Pembatalan harus oleh orang lain, bukan kasir struk itu. Butuh hak Shift Kasir & Setoran (Ubah).

## Bagaimana cara melayani retur penjualan?
kata kunci: retur, pengembalian barang, tukar barang, barang dikembalikan, refund, uang kembali, retur struk, barang rusak dikembalikan, return

1. Pastikan Anda punya shift terbuka, karena uang retur keluar dari laci shift itu.
2. Di layar kasir tekan Retur.
3. Scan atau ketik nomor struk asal, lalu tampilkan.
4. Isi Jumlah retur tiap barang dan pilih Kondisi: "Layak jual — kembali ke rak" atau "Rusak — dibuang".
5. Tulis alasan retur, pilih pengawas, dan minta pengawas mengetik PIN.
6. Tekan Simpan Retur. Struk retur tercetak.
Uang kembali dihitung dari harga yang DIBAYAR di struk itu (sudah termasuk promo dan diskon), bukan harga hari ini. Kalau struk asal dibayar kartu santri, uangnya kembali ke uang saku santri. Barang layak kembali ke stok, barang rusak tidak.

## Kenapa retur ditolak?
kata kunci: retur ditolak, tidak bisa retur, lewat batas retur, struk tidak ditemukan, retur melebihi, retur gagal, beda toko

Pesan yang sering muncul:
- "Struk … sudah lewat batas retur … hari.": batas hari retur diatur per toko di WAFI MART › Toko & Gudang (Lokasi). Angka 0 berarti retur hanya di hari yang sama.
- "Retur dilayani di toko tempat barang dibeli (…).": retur harus di toko yang sama.
- "Struk … tidak ditemukan." atau "sudah dibatalkan — tak ada yang bisa diretur.": cek nomor struknya.
- "…: diretur melebihi yang dibeli (sisa …).": sebagian barang itu sudah pernah diretur.
- "Pilih kondisi …: layak jual atau rusak." dan "Tulis alasan retur.": lengkapi isian.
- "Buka shift dulu — uang retur keluar dari laci shift yang sedang buka.": buka shift sebelum retur.

## Bagaimana cara menutup shift kasir?
kata kunci: tutup shift, akhir shift, selesai jualan, hitung uang laci, pecahan uang, closing kasir, tutup kasir, close shift, setor

1. Di layar kasir tekan Tutup Shift.
2. Hitung SELURUH uang di laci, termasuk modal awal.
3. Isi jumlah lembar atau keping untuk tiap pecahan (100.000 sampai 100). Total uang dihitung otomatis.
4. Isi catatan bila perlu (mis. ada uang rusak).
5. Tekan Tutup Shift dan konfirmasi.
Sistem sengaja tidak menampilkan berapa uang yang seharusnya ada sebelum Anda menghitung. Hasilnya, termasuk selisih, baru tampil di laporan tutup shift. Laci kosong boleh ditutup dengan nol. Sesudah ditutup, Anda tidak bisa berjualan di shift itu lagi. Tutup shift setiap hari, karena pembukuan penjualan baru terbit saat shift ditutup.

## Bagaimana membaca laporan tutup shift dan selisih kas?
kata kunci: laporan tutup shift, selisih kas, kurang kas, lebih kas, uang kurang, uang lebih, seharusnya di laci, cetak laporan shift, pas

Laporan tutup shift tampil otomatis setelah Anda menutup shift dan bisa dicetak. Isinya:
- Modal awal, Penjualan tunai, Penjualan kartu (tidak masuk laci), dan Uang retur dikembalikan.
- Seharusnya di laci = modal awal + penjualan tunai − uang retur tunai.
- Uang dihitung = hasil hitungan Anda.
- Selisih: "Pas" bila sama, "Kurang Rp …" bila uang kurang, "Lebih Rp …" bila uang lebih.
- Disetor ke keuangan = uang dihitung − modal awal. Modal tetap di laci untuk shift berikutnya.
Serahkan uang setoran beserta laporan ini ke penerima setoran. Setoran baru tercatat setelah penerima mengonfirmasinya.

## Bagaimana pengawas menerima setoran dari kasir?
kata kunci: terima setoran, serah terima, setoran kasir, setor uang laci, uang shift, terima uang kasir, belum diterima

1. Buka WAFI MART › Shift Kasir & Setoran. Di atas tampil berapa shift yang "ditutup, setorannya belum diterima".
2. Pilih nomor shift yang berstatus "Ditutup — menunggu serah terima".
3. Hitung uang setoran yang diserahkan kasir.
4. Pilih kas atau rekening tujuan, lalu tekan Terima Setoran dan konfirmasi.
Jumlah setoran = uang dihitung − modal awal, karena modal tetap di laci toko. Setoran harus diterima orang lain, bukan kasir shift itu. Kas tujuan harus rekening kas/bank yang aktif dan tidak boleh sama dengan kas laci toko. Butuh hak Shift Kasir & Setoran (Ubah).

## Kasir lupa menutup shift, bagaimana cara menutupnya?
kata kunci: lupa tutup shift, shift masih terbuka, tutup paksa, shift kemarin, kasir pulang, shift menggantung, tutup shift pengawas

1. Buka WAFI MART › Shift Kasir & Setoran. Shift dari hari sebelumnya yang masih terbuka ditandai merah di atas.
2. Pilih shift tersebut.
3. Di kotak "Tutup shift atas nama pengawas", isi Uang di laci (hasil hitungan pengawas) dan Alasan (mis. kasir pulang tanpa menutup shift).
4. Tekan Tutup Shift dan konfirmasi.
Shift lalu tercatat ditutup dan dibukukan, dan setorannya bisa diterima seperti biasa. Shift yang dibiarkan terbuka membuat pembukuan harga pokoknya belum terbit. Butuh hak Shift Kasir & Setoran (Ubah).

## Apa yang ditampilkan di menu Shift Kasir & Setoran?
kata kunci: rekap shift, rekap harian, omzet harian, laba kotor, daftar shift, pengawas kasir, monitoring kasir, rekap kasir

WAFI MART › Shift Kasir & Setoran adalah layar pengawas kasir. Saring dengan tanggal Dari–Sampai, toko, dan status, lalu tekan Tampilkan.
- Rekap harian per toko: jumlah shift, struk, Omzet, Laba Kotor, Selisih Kas, dan status setoran ("… belum diterima" atau "Lengkap"). Omzet sudah dikurangi retur.
- Daftar shift: nomor shift, toko, kasir, waktu buka, penjualan, selisih, dan status (Buka, Ditutup — menunggu serah terima, Sudah diserahkan).
Klik nomor shift untuk melihat rincian struk dan retur, membatalkan struk, menutup shift yang terlupa, atau menerima setoran. Melihat butuh hak Shift Kasir & Setoran (Lihat). Tindakan butuh hak yang sama (Ubah).

## Bagaimana cara memasang PIN pengawas kasir?
kata kunci: pin pengawas, pasang pin, ganti pin, pin supervisor, pin approval, lupa pin pengawas, pin terkunci, profil

PIN pengawas dipakai untuk menyetujui diskon, retur, dan beda modal di layar kasir. PIN ini berbeda dengan kata sandi akun.
1. Klik nama Anda di bagian atas, lalu pilih Profil & Kata Sandi.
2. Di bagian PIN Pengawas Kasir, ketik kata sandi akun, PIN baru 6 angka, dan ulangi PIN.
3. Tekan Simpan PIN.
Bagian ini hanya muncul bagi pemegang hak Shift Kasir & Setoran (Ubah). PIN tidak boleh angka kembar atau berurutan (mis. 111111, 123456). Salah PIN 5 kali membuat PIN terkunci 15 menit. Pengawas tidak boleh menyetujui transaksinya sendiri. Kalau di kasir muncul "Belum ada pengawas yang memasang PIN", berarti belum ada pengawas yang memasang PIN.

## Apa arti pesan galat PIN pengawas di kasir?
kata kunci: pin pengawas salah, pilih pengawas, pengawas lain, pin terkunci, belum memasang pin, persetujuan ditolak, approval gagal

- "Pilih pengawas yang berhak menyetujui.": nama pengawas belum dipilih, atau orang itu sudah tidak punya hak Shift Kasir & Setoran (Ubah).
- "Persetujuan harus dari pengawas lain, bukan kasirnya sendiri.": minta orang lain.
- "… belum memasang PIN pengawas (menu Profil).": pengawas itu perlu memasang PIN dulu.
- "PIN pengawas salah.": ulangi dengan hati-hati.
- "PIN salah 5 kali — terkunci 15 menit." / "PIN … terkunci sampai …": tunggu sampai jam yang disebut, atau minta pengawas lain.
- "Tulis alasan diskon.": alasan wajib diisi.

## Bagaimana cara menambah barang baru?
kata kunci: tambah barang, barang baru, input barang, master barang, produk baru, data barang, item baru, daftar barang

1. Buka WAFI MART › Data Barang & Stok, lalu tambah barang baru.
2. Isi Nama Persediaan dan Satuan Dasar (satuan terkecil, mis. pcs, botol). Stok selalu dicatat dalam satuan ini.
3. Pilih Kategori dan Merk bila ada.
4. Isi Harga Perolehan Awal (acuan saat opname menemukan stok lebih).
5. Di bagian Data Toko, centang "Dijual di toko" lalu isi Harga Jual. Isi Stok Minimum bila perlu.
6. Tambahkan Satuan Konversi dan Barcode bila ada.
7. Pilih Akun Persediaan, Akun Beban Pemakaian, dan Akun Selisih Persediaan.
8. Simpan.
Kode barang dibuat otomatis. Barang baru belum punya stok, stoknya masuk lewat Penerimaan Barang atau impor stok awal. Butuh hak Persediaan (Buat).

## Bagaimana cara mengubah harga jual barang?
kata kunci: ubah harga, ganti harga, harga jual, naik harga, update harga, setting harga, harga baru, edit harga

1. Buka WAFI MART › Data Barang & Stok.
2. Cari barangnya (bisa dengan kode, nama, atau barcode), lalu buka ubah.
3. Ganti Harga Jual di bagian Data Toko. Harga ini per satuan dasar.
4. Untuk satuan besar (mis. DUS), isi harga jual di baris Satuan Konversi. Kalau dikosongkan, harganya = isi × harga jual satuan dasar.
5. Simpan.
Harga baru langsung dipakai di kasir untuk transaksi berikutnya. Struk lama tidak berubah. Butuh hak Persediaan (Ubah).

## Bagaimana cara membuat harga promo?
kata kunci: promo, harga promo, diskon terjadwal, harga spesial, promo mulai, promo selesai, harga coret, potongan promo

1. Buka WAFI MART › Data Barang & Stok, lalu ubah barangnya.
2. Di bagian Data Toko isi Harga Promo, Promo Mulai, dan Promo Selesai.
3. Simpan.
Selama tanggal promo berlaku, kasir otomatis memakai harga promo dan struk mencetak harga normal sebagai pembanding. Promo Selesai boleh dikosongkan bila promo berlaku sampai diubah lagi. Promo hanya berlaku untuk satuan dasar, satuan besar tetap memakai harganya sendiri. Kosongkan Harga Promo untuk menghentikan promo. Promo tidak butuh PIN pengawas.

## Bagaimana cara menambah satuan konversi seperti DUS atau PAK?
kata kunci: satuan konversi, dus, pak, karton, satuan besar, isi per dus, jual per dus, konversi satuan, renteng

1. Buka WAFI MART › Data Barang & Stok, lalu ubah barangnya.
2. Di bagian Satuan Konversi, tambah baris berisi nama satuan (mis. DUS), isi (mis. 24 satuan dasar), dan harga jual bila berbeda.
3. Simpan.
Kasir lalu bisa memilih satuan itu di keranjang, dan petugas gudang bisa menerima atau mentransfer dalam satuan itu. Stok tetap tercatat dalam satuan dasar (3 DUS isi 24 = 72 pcs). Kalau harga jual satuan besar kosong, harganya isi × harga jual satuan dasar.

## Bagaimana cara menambah barcode barang?
kata kunci: tambah barcode, barcode baru, kode batang, barcode dus, barcode ganda, scan barcode baru, ganti kemasan, barkode

1. Buka WAFI MART › Data Barang & Stok, lalu ubah barangnya.
2. Di bagian Barcode, tambah baris, lalu pindai barcode langsung ke kolomnya.
3. Pilih satuannya. Barcode dus sebaiknya diberi satuan DUS, sehingga memindainya di kasir menjual 1 dus.
4. Simpan.
Satu barang boleh punya beberapa barcode, mis. kemasan lama dan baru.

## Bagaimana cara melihat stok dan kartu stok barang?
kata kunci: cek stok, sisa stok, kartu stok, riwayat stok, mutasi barang, stok per lokasi, nilai persediaan, stok toko, stok gudang

1. Buka WAFI MART › Data Barang & Stok. Daftar menampilkan harga rata-rata, harga jual, stok, nilai, dan status tiap barang. Saring dengan pencarian kode/nama/barcode atau kategori.
2. Tekan Kartu Stok di baris barang untuk melihat semua pergerakan barang itu: masuk, keluar, penjualan, transfer, dan opname.
3. Di kartu stok, pilih lokasi bila ingin melihat satu toko atau gudang saja.
Kartu stok juga menampilkan stok saat ini, nilai persediaan, dan sisa lapisan harga beli. Laporan ringkas semua barang ada di WAFI MART › Laporan Persediaan. Butuh hak Persediaan (Lihat).

## Bagaimana mencatat barang yang dipakai sendiri (bukan dijual)?
kata kunci: pemakaian barang, barang dipakai, mutasi, barang keluar, pakai sendiri, barang untuk kantor, pengeluaran barang

1. Buka WAFI MART › Data Barang & Stok.
2. Tekan Mutasi di baris barang, lalu pilih Jenis "Pemakaian Barang".
3. Pilih Lokasi, Tanggal, Jumlah dipakai (dalam satuan dasar), dan Bagian pemakai. Isi keterangan bila perlu.
4. Tekan Simpan & Jurnalkan.
Stok berkurang dan dibukukan ke Akun Beban Pemakaian barang itu. Butuh hak Persediaan (Ubah). Untuk menghitung ulang banyak barang sekaligus, lebih baik pakai Stok Opname.

## Bagaimana cara mengatur kategori dan merk barang?
kata kunci: kategori barang, merk barang, merek, tambah kategori, kelompok barang, golongan barang, brand

Buka WAFI MART › Kategori Barang atau WAFI MART › Merk Barang untuk menambah, mengubah, atau menonaktifkan kategori dan merk. Kategori dan merk lalu dipilih di data barang. Kategori juga dipakai sebagai saringan di Laporan Toko dan Stok Opname. Impor barang toko membuat kategori dan merk baru secara otomatis dari namanya bila belum ada. Butuh hak Kategori Barang atau Merk Barang sesuai menunya.

## Bagaimana cara mengatur toko dan gudang (lokasi persediaan)?
kata kunci: lokasi, toko baru, gudang baru, lokasi persediaan, akun kasir, kas laci, batas retur, setting toko, cabang

Buka WAFI MART › Toko & Gudang (Lokasi). Tiap lokasi punya:
- Nama Lokasi dan Jenis: Gudang (penyimpanan), Toko (tempat barang dijual), atau Transit (barang dalam perjalanan antar lokasi, dipakai sistem).
- Unit Bisnis untuk pembukuan lokasi itu.
- Khusus toko, Akun Kasir: Kas Laci, Pendapatan Penjualan, Harga Pokok Penjualan, Selisih Kas, Batas Retur (hari), dan Bagian Pengelola Toko.
- Status aktif atau nonaktif.
Toko yang akun kasirnya belum lengkap tidak bisa dipakai membuka shift. Stok di lokasi nonaktif tidak bisa digerakkan. Butuh hak Lokasi Persediaan (gudang / toko). Isian akun sebaiknya diisi bersama bagian keuangan.

## Bagaimana cara impor data barang toko dari Excel?
kata kunci: impor barang, import barang, upload barang, excel barang, csv barang, data barang awal, pindah dari pos lama, template barang

1. Buka WAFI MART › Impor Barang & Stok Awal. Jenis "Barang Toko (Wafi Mart)" sudah terpilih.
2. Tekan Unduh Template CSV, lalu isi: nama, satuan, harga_jual (wajib), serta kode_barang, kategori, merk, barcode, harga_beli, stok_minimum, satuan_besar, isi_satuan_besar, harga_jual_satuan_besar, barcode_satuan_besar (tidak wajib).
3. Simpan sebagai CSV.
4. Pilih Akun Persediaan, Akun Beban Pemakaian, dan Akun Selisih Persediaan untuk seluruh berkas.
5. Unggah berkas, lalu tekan Periksa Berkas. Perbaiki baris yang bermasalah.
6. Tekan tombol Impor … Baris.
Impor ini tidak mengisi stok, karena stok diisi lewat impor stok awal. Barang yang kode atau barcodenya sudah ada dilewati, jadi aman diulang. Beberapa barcode dipisah titik koma (;). Butuh hak Impor Data Awal (pindahan sistem) (Buat).

## Bagaimana cara impor stok awal barang per lokasi?
kata kunci: stok awal, impor stok, saldo awal stok, stok pembuka, upload stok, stok pindahan, isi stok awal, cut off stok

1. Buka WAFI MART › Impor Barang & Stok Awal, lalu pilih jenis "Stok Awal Barang per Lokasi (Wafi Mart)".
2. Unduh template. Tiap baris = satu barang di satu lokasi: kode_barang ATAU barcode, lokasi (nama atau kode), jumlah (satuan terkecil), dan harga_pokok (boleh kosong, diganti harga beli di data barang).
3. Isi Tanggal saldo awal, mis. sehari sebelum mulai memakai kasir.
4. Pilih akun perantara saldo awal. Ini akun ekuitas penampung, bukan akun beban dan bukan akun aset. Mintalah bagian keuangan memilihkannya.
5. Periksa Berkas, lalu impor.
Impor ini langsung dibukukan. Barang yang sudah punya stok awal di lokasi yang sama dilewati. Impor barang toko harus dijalankan lebih dulu.

## Kenapa angka di berkas impor ditolak?
kata kunci: angka ditolak, format angka, titik koma, desimal, gaya indonesia, harga tidak terbaca, notasi ilmiah, barcode rusak, error impor

Angka di berkas impor harus ditulis gaya Indonesia: titik untuk ribuan, koma untuk desimal. Contoh benar: 1.500, 0,125, 2.916,67. Contoh salah: 1.5 atau 2916.67, karena angka gaya Inggris seperti ini ditolak, tidak ditebak. Pesannya: "Jumlah harus angka lebih dari nol, ditulis gaya Indonesia (1.500 atau 0,125 — bukan 1.5)" atau "Harga pokok bukan angka yang sah — tulis gaya Indonesia".
Barcode yang tampil seperti 8,99E+12 juga ditolak: "Barcode … rusak (notasi ilmiah Excel atau terlalu panjang)". Format kolom barcode sebagai Teks di Excel, lalu salin ulang barcodenya dari sumber. Nomor baris di daftar masalah mengikuti nomor baris Excel (baris 1 = judul kolom).

## Bagaimana membatalkan impor barang atau stok awal yang keliru?
kata kunci: batalkan impor, hapus impor, impor salah, ulang impor, riwayat impor, undo impor, impor dobel

1. Buka WAFI MART › Impor Barang & Stok Awal, pilih jenisnya.
2. Di Riwayat Impor, tekan Batalkan Impor pada impor yang keliru, lalu Konfirmasi Batalkan.
Urutannya: batalkan impor stok awal dulu, baru impor barang. Impor barang tidak bisa dibatalkan bila barangnya sudah punya pergerakan stok, atau sudah dipakai di Purchase Order, Penerimaan Barang, Invoice Vendor, Transfer, atau sudah terjual di kasir. Membatalkan impor butuh hak Impor Data Awal (pindahan sistem) (Hapus).

## Bagaimana cara mengirim barang dari gudang ke toko (transfer)?
kata kunci: transfer barang, kirim barang, pindah stok, mutasi gudang ke toko, antar gudang, transfer stok, kirim ke toko, pindah lokasi

1. Buka WAFI MART › Transfer Antar Gudang, lalu buat transfer baru.
2. Pilih tanggal kirim, lokasi asal, dan lokasi tujuan.
3. Tambah barang, satuan, dan jumlah (+ Tambah barang).
4. Tekan Kirim.
Barang keluar dari lokasi asal dan berstatus "Dalam perjalanan" sampai diterima di tujuan. Stok asal harus cukup. Pesan "stok … di … tidak cukup (tersedia …)" berarti jumlahnya melebihi stok. Kiriman yang belum diterima bisa dibatalkan dengan Batalkan Kiriman, yang butuh hak Transfer Antar Lokasi (kirim / terima / batal) (Hapus). Mengirim butuh hak yang sama (Buat).

## Bagaimana cara menerima barang transfer di toko tujuan?
kata kunci: terima transfer, barang datang dari gudang, terima kiriman, catat penerimaan transfer, barang sampai, transfer masuk

1. Buka WAFI MART › Transfer Antar Gudang, lalu buka transfer berstatus "Dalam perjalanan".
2. Isi tanggal terima dan jumlah yang BENAR-BENAR tiba untuk tiap barang. Isi catatan terima bila ada (mis. 2 pcs penyok, 1 dus kurang).
3. Tekan Catat Penerimaan.
Kalau semua tiba, transfer selesai. Kalau ada yang kurang, kekurangannya tertahan sebagai selisih sampai diputus orang lain. Jumlah tiba tidak boleh lebih dari yang dikirim. Kelebihan barang dicatat lewat Penerimaan Barang atau Opname. Satu kiriman hanya bisa diterima sekali. Butuh hak Transfer Antar Lokasi (kirim / terima / batal) (Ubah).

## Bagaimana memutus selisih transfer barang?
kata kunci: selisih transfer, barang kurang saat transfer, barang hilang, putuskan selisih, kembali ke asal, kehilangan barang, transfer selisih

Transfer berstatus "Selisih — menunggu putusan" berarti barang yang tiba lebih sedikit dari yang dikirim.
1. Buka transfernya di WAFI MART › Transfer Antar Gudang.
2. Di bagian Putusan selisih, pilih: barang dikembalikan ke lokasi asal (misalnya ternyata belum terangkut), atau "Bukukan sebagai kehilangan" dengan memilih Bagian penanggung.
3. Tekan Simpan Putusan.
Selisih harus diputus orang lain, bukan yang mencatat penerimaannya. Butuh hak Persetujuan Persediaan (putus selisih transfer, setujui opname) (Ubah).

## Bagaimana cara melakukan stok opname?
kata kunci: stok opname, hitung stok, cek fisik, stock opname, inventarisasi, hitung barang, opname toko, lembar hitung

1. Buka WAFI MART › Stok Opname, lalu buat opname baru.
2. Pilih tanggal, lokasi, kategori (bila hanya sebagian), dan cakupan: barang yang pernah ada di lokasi itu, atau semua barang aktif.
3. Tekan Buat Lembar Hitung.
4. Isi stok fisik hasil hitungan tiap barang. Bisa disimpan bertahap dengan Simpan Hitungan. Barang yang dikosongkan dianggap tidak dihitung.
5. Bila ada selisih, pilih Bagian yang menanggung selisih.
6. Tekan Simpan & Ajukan. Hitungan terkunci sampai diputus.
Stok tercatat dipotret saat angka tiap barang diisi, jadi penjualan sesudah barang itu dihitung tidak terbaca sebagai selisih. Hanya satu opname berjalan per lokasi. Butuh hak Stok Opname (hitung & ajukan) (Buat).

## Bagaimana menyetujui hasil stok opname?
kata kunci: setujui opname, approve opname, tolak opname, hitung ulang, bukukan opname, persetujuan opname, selisih opname

1. Buka WAFI MART › Stok Opname, lalu buka opname berstatus "Menunggu persetujuan".
2. Periksa selisih dan Nilai Selisih tiap barang.
3. Tekan Setujui & Bukukan agar stok disesuaikan dan selisihnya dibukukan, atau "Tolak — hitung ulang" agar lembarnya kembali ke draf.
Opname harus diputus orang lain, bukan yang membuat atau mengajukannya. Opname yang sudah disetujui tidak bisa dibatalkan, jadi buat opname baru untuk mengoreksinya. Opname draf bisa dibatalkan dengan Batalkan (hak Stok Opname (hitung & ajukan) (Hapus)). Menyetujui butuh hak Persetujuan Persediaan (putus selisih transfer, setujui opname) (Ubah).

## Bagaimana cara membuat Purchase Order (PO) ke pemasok?
kata kunci: purchase order, po, pesan barang, order ke supplier, pemesanan barang, pesan ke vendor, kulakan, belanja barang

1. Buka WAFI MART › Purchase Order, lalu buat PO baru.
2. Isi Tanggal PO, pilih vendor dan Unit Bisnis.
3. Tambah baris barang (+ Tambah baris), jumlah, dan harga.
4. Simpan. Nomor PO dibuat otomatis saat simpan.
PO hanya catatan pesanan. Belum ada stok masuk dan belum ada hutang. PO bisa dicetak dari halaman detailnya. Vendor yang belum ada perlu ditambahkan dulu di KEUANGAN › Vendor (butuh hak Vendor; bila tidak punya, minta bagian keuangan menambahkannya). PO bisa dibatalkan dengan Batalkan PO selama belum ada barang yang diterima dan belum ditagih. Butuh hak Purchase Order.

## Bagaimana mencatat barang datang dari pemasok (Penerimaan Barang)?
kata kunci: penerimaan barang, barang datang, barang masuk, terima barang supplier, surat jalan, stok masuk, terima dari vendor, GR

1. Buka WAFI MART › Penerimaan Barang, lalu buat penerimaan baru.
2. Pilih vendor. Bila ada PO, pilih di Dari Purchase Order. Barang yang belum datang akan dimuat, lalu ubah jumlahnya sesuai yang tiba (isi 0 untuk yang belum datang).
3. Isi Tanggal Terima, Diterima di Lokasi, No. Surat Jalan / Nota, dan keterangan.
4. Periksa jumlah dan harga tiap baris. Boleh dihitung dalam satuan konversi (mis. 3 DUS).
5. Tekan Simpan Penerimaan.
Stok langsung bertambah di lokasi itu. Tagihannya dicatat nanti lewat Invoice Vendor. Penerimaan tidak boleh melebihi sisa PO; pesan "diterima melebihi sisa PO" berarti perlu PO tambahan. Butuh hak Penerimaan Barang (stok masuk dari vendor) (Buat).

## Kenapa penerimaan barang tidak bisa disimpan atau dibatalkan?
kata kunci: penerimaan gagal, barang diterima ditolak, batal penerimaan, akun belum ditetapkan, PO selesai, vendor berbeda, error penerimaan

- "Akun "Barang Diterima Belum Ditagih" belum ditetapkan.": pengaturan akun ini belum diisi di Pengaturan Perusahaan. Hubungi bagian keuangan atau admin.
- "PO … berstatus …; barangnya tak bisa diterima lagi.": PO sudah selesai atau dibatalkan.
- "Vendor penerimaan berbeda dari vendor PO …": pilih vendor yang sama dengan PO.
- "… belum punya Akun Persediaan.": lengkapi data barang.
- "Lokasi penerimaan tidak ditemukan atau sudah nonaktif.": pilih lokasi lain.
- Saat membatalkan: "Sebagian barang sudah ditagih lewat invoice vendor. Void invoice-nya dulu." Bila barangnya sudah terjual, pembatalan juga ditolak dan perlu diselesaikan lewat Opname.
Membatalkan penerimaan butuh hak Penerimaan Barang (stok masuk dari vendor) (Hapus).

## Bagaimana mencatat invoice (tagihan) pembelian dari pemasok?
kata kunci: invoice vendor, tagihan supplier, faktur pembelian, nota tagihan, hutang supplier, invoice pembelian, tagihan pemasok

1. Buka KEUANGAN › Invoice Vendor, lalu buat invoice baru.
2. Pilih vendor, isi Nomor Invoice (dari vendor), Tanggal Invoice, dan Jatuh Tempo.
3. Untuk barang yang sudah dicatat lewat Penerimaan Barang, pakai "Muat dari Penerimaan". Harga terisi harga saat diterima. Bila harga invoice berbeda, ubah harganya, lalu isi Bagian di baris itu untuk selisihnya.
4. Simpan dengan Posting Invoice.
Jangan menagih barang yang sudah diterima lewat "Muat dari PO", karena stoknya bisa masuk dua kali. Sistem menolak dengan pesan "Tagih lewat "Muat dari Penerimaan"". Pembayaran invoice dikerjakan bagian keuangan. Butuh hak Invoice Vendor — siapa yang mencatat invoice mengikuti pembagian tugas di Wafi Mart; bila Anda tidak punya haknya, serahkan berkas tagihan pemasok ke bagian keuangan.

## Bagaimana cara mendaftarkan mitra kantin baru?
kata kunci: mitra kantin, tambah mitra, kantin basah, penyewa kantin, daftar mitra, tenant, pedagang kantin, mitra baru

1. Buka WAFI MART › Mitra Kantin, lalu tambah mitra baru.
2. Isi Kode mitra (2–20 huruf/angka, mis. KB01), Nama usaha, Nama pemilik, dan Telepon.
3. Isi masa sewa (Mulai–Sampai) dan keterangan sewa. Sewa hanya dicatat sebagai keterangan dan tidak ditagih sistem.
4. Isi rekening tujuan penarikan: Bank, Nomor rekening, Atas nama.
5. Simpan, lalu tambah menu dan buat akun mitra (lihat artikel berikutnya).
Penjualan kartu mitra bukan pendapatan Wafi Mart, melainkan saldo mitra yang nanti ditarik. Kode mitra tidak bisa diganti sesudah ada penjualan. Butuh hak Mitra Kantin Wafi Mart (data, menu, akun, laporan, penarikan) (Buat).

## Bagaimana mengatur daftar menu dan harga mitra kantin?
kata kunci: menu kantin, harga menu, tambah menu, ubah harga kantin, daftar makanan, menu mitra, nonaktifkan menu

1. Buka WAFI MART › Mitra Kantin, lalu pilih mitranya.
2. Di Daftar Menu, isi nama menu dan harga, lalu tekan + Tambah menu.
3. Untuk mengubah, tekan Ubah pada menu, ganti nama/harga atau hilangkan centang Aktif, lalu Simpan.
Menu diisi petugas Wafi Mart dari daftar yang disetorkan mitra. Mitra tidak mengisinya sendiri. Harga harus lebih dari nol, dan nama menu tidak boleh kembar di mitra yang sama. Perubahan harga berlaku untuk transaksi berikutnya dan tercatat di Jejak Audit. Menu kantin tidak punya stok. Menambah butuh hak Mitra Kantin Wafi Mart (Buat), mengubah butuh (Ubah).

## Bagaimana membuat akun login untuk mitra kantin?
kata kunci: akun mitra, login mitra, username mitra, kata sandi mitra, reset sandi mitra, mitra tidak bisa masuk, hp mitra

1. Buka WAFI MART › Mitra Kantin, lalu pilih mitranya.
2. Di Akun Mitra, isi username (3–50 huruf/angka, boleh titik, garis bawah, strip) dan Kata sandi awal (minimal 8 karakter).
3. Tekan + Buat akun.
Mitra masuk di halaman login biasa dari HP-nya dan hanya melihat layar kasir, riwayat, laporan, dan saldo miliknya. Untuk mengganti kata sandi atau menonaktifkan akun, ubah akunnya di bagian yang sama, lalu Simpan akun. Kosongkan kata sandi bila tidak diganti. Butuh hak Mitra Kantin Wafi Mart (Buat/Ubah).

## Bagaimana mitra kantin berjualan dengan kartu santri?
kata kunci: kasir mitra, kasir kantin, jual di kantin, bayar kartu kantin, tap kartu kantin, transaksi kantin, hp kasir kantin

Panduan ini bisa disampaikan ke mitra:
1. Mitra masuk dengan akunnya dari HP. Layar kasir langsung tampil, dengan menu terlaris di atas dan kotak "Cari menu…".
2. Tekan menu atau tombol + / − untuk memilih jumlah.
3. Tekan Bayar kartu, lalu santri menempelkan kartu ke pembaca. Tekan Cek kartu. Nama santri dan sisa batas kantin hari ini tampil.
4. Bila melewati batas harian, santri mengetik PIN belanjanya.
5. Selesaikan pembayaran sampai muncul "Pembayaran berhasil", lalu tekan Transaksi baru.
Kantin hanya menerima kartu santri, tidak ada pembayaran tunai yang dicatat di sistem. Bila muncul "BELUM PASTI", jangan terima tunai dan tekan "Cek lagi". Bila wali mematikan belanja kantin anaknya, muncul pesan bahwa kartu hanya bisa dipakai di Wafi Mart.

## Bagaimana mitra kantin membatalkan transaksi yang salah?
kata kunci: batal transaksi kantin, salah input kantin, kembalikan ke santri, batal kantin, refund kantin, riwayat kantin

Mitra membuka Riwayat di HP-nya, menekan Batalkan pada transaksi itu, menulis alasan pembatalan, lalu menekan "Batalkan & kembalikan ke santri". Uangnya kembali ke uang saku santri.
Pembatalan hanya bisa di hari yang sama dan hanya untuk pembayaran yang berhasil. Kalau sudah lewat hari, mitra mendapat pesan "Pembatalan hanya bisa di hari yang sama. Hubungi petugas Wafi Mart." Kalau muncul "Status pengembalian ke dompet santri BELUM PASTI", jangan beri uang tunai dan ulangi sebentar lagi; pengembalian tidak akan terjadi dua kali. Pembatalan sesudah lewat hari belum bisa dilakukan lewat aplikasi; laporkan kepada atasan Wafi Mart untuk diselesaikan di luar sistem.

## Bagaimana mitra mengajukan penarikan saldo?
kata kunci: tarik saldo mitra, pencairan mitra, saldo kantin, ajukan penarikan, uang kantin, withdraw mitra, saldo bisa ditarik

Mitra membuka menu Saldo di HP-nya. Di sana tampil Saldo bisa ditarik, Penjualan hari ini, dan Riwayat penarikan. Mitra mengisi nominal lalu menekan Ajukan penarikan.
- Saldo bisa ditarik = penjualan kartu yang berhasil sampai KEMARIN, dikurangi penarikan. Penjualan hari ini baru bisa ditarik besok, sesudah pencocokan malam.
- Hanya boleh ada satu pengajuan menunggu dalam satu waktu. Pengajuan yang belum diproses bisa dibatalkan dengan "Batalkan pengajuan".
- Biaya transfer dipotong dari nominal penarikan.
Pesan yang mungkin muncul: "Melebihi saldo yang bisa ditarik", "Masih ada pengajuan penarikan yang menunggu diproses", "Rekening tujuan belum diisi — minta petugas Wafi Mart mengisinya di data mitra."

## Bagaimana petugas memproses penarikan saldo mitra?
kata kunci: proses penarikan mitra, transfer ke mitra, bukti transfer, tolak penarikan, biaya transfer, penarikan menunggu, bayar mitra

1. Buka WAFI MART › Mitra Kantin, lalu tekan Penarikan Saldo. Pengajuan yang menunggu juga muncul di Tugas Saya.
2. Transfer dulu uangnya ke rekening mitra di luar sistem.
3. Tekan Sudah ditransfer, lalu pilih Dari rekening, Tanggal transfer, Biaya transfer (dipotong dari penarikan), dan unggah Bukti transfer (PDF/JPG/PNG/WEBP, wajib).
4. Tekan Simpan & jurnal.
Untuk menolak, tekan Tolak dan tulis alasan penolakan. Mitra menerima nominal dikurangi biaya transfer. Biaya transfer harus lebih kecil dari nominal penarikan. Akun "Hutang ke Mitra Kantin" harus sudah dipilih di layar Mitra Kantin. Butuh hak Mitra Kantin Wafi Mart (Ubah).

## Bagaimana melunasi saldo mitra kantin yang sudah berhenti (nonaktif)?
kata kunci: mitra berhenti, sewa habis, pelunasan mitra, mitra nonaktif, sisa saldo mitra, tutup mitra, mitra keluar

1. Buka WAFI MART › Mitra Kantin, ubah data mitra, lalu jadikan Nonaktif. Kasir dan akun mitra langsung tertutup.
2. Buka halaman mitra itu. Bila masih ada saldo, muncul kotak "Mitra ini nonaktif — sisa saldonya bisa dilunasi oleh petugas."
3. Tekan Ajukan pelunasan.
4. Pelunasan masuk antrean penarikan biasa. Proses seperti penarikan lain: transfer ke rekening mitra, unggah bukti, biaya transfer dipotong.
Pelunasan oleh petugas hanya untuk mitra nonaktif. Mitra aktif mengajukan sendiri dari HP-nya. Daftar mitra menandai "Masa sewa lewat" bila masa sewanya sudah habis. Butuh hak Mitra Kantin Wafi Mart (Ubah).

## Bagaimana melihat laporan penjualan kantin mitra?
kata kunci: laporan kantin, laporan mitra, penjualan mitra, omzet kantin, rekap kantin, penjualan kartu mitra

Buka WAFI MART › Mitra Kantin. Daftar mitra menampilkan menu aktif, penjualan hari ini, bulan ini, dan saldo tiap mitra. Untuk rincian, buka Laporan Penjualan Kartu Mitra dari layar itu, pilih Mitra serta tanggal Dari dan Sampai, lalu tekan Tampilkan. Mitra sendiri bisa melihat laporannya di menu Laporan pada HP-nya. Butuh hak Mitra Kantin Wafi Mart (Lihat).

## Apa itu Rekonsiliasi Kartu Santri dan apa yang harus dilakukan?
kata kunci: rekonsiliasi kartu, pencocokan malam, potongan tanpa struk, perlu diputus, selisih kartu, cocokkan kartu, rekon kartu santri

Setiap malam (00:40), belanja kartu santri kemarin dari kasir Wafi Mart dan kantin mitra dicocokkan per nomor dengan catatan ERP Al Wafi. Hasilnya ada di WAFI MART › Rekonsiliasi Kartu Santri:
- Cocok: tidak ada yang perlu dilakukan.
- Dibereskan otomatis: ada potongan tanpa struk (uang saku terpotong padahal struk tidak tersimpan), dan uangnya sudah dikembalikan otomatis ke santri.
- Perlu diputus: ada selisih lain, mis. uang dikembalikan tanpa retur di toko, atau struk kartu yang tidak dikenal Al Wafi. Periksa, selesaikan, lalu tekan "Tandai sudah ditangani" dengan catatan apa yang sudah dilakukan.
- Gagal (Al Wafi tak terjangkau): pilih tanggalnya di "Jalankan untuk tanggal", lalu tekan Jalankan sekarang. Aman diulang, pengembalian tidak pernah terjadi dua kali.
Hanya tanggal yang sudah lewat yang bisa dijalankan. Butuh hak Rekonsiliasi Belanja Kartu Santri (Wafi Mart ↔ Al Wafi) (Lihat untuk melihat, Ubah untuk menjalankan dan menandai).

## Bagaimana mencatat pencairan dana kartu santri dari Al Wafi?
kata kunci: pencairan al wafi, penerimaan dari al wafi, uang kartu santri masuk, piutang al wafi, transfer dari pesantren, terima pencairan

Belanja kartu santri di Wafi Mart dan kantin dibayar ERP Al Wafi lewat pencairan berkala.
1. Buka WAFI MART › Penerimaan dari Al Wafi. Tampil Piutang ke Al Wafi saat ini dan daftar pencairan 90 hari terakhir.
2. Cek di rekening bahwa uang pencairan sudah benar-benar masuk.
3. Pada pencairan berstatus menunggu, pilih "Masuk ke kas/bank", lalu tekan Terima dan konfirmasi.
Satu pencairan hanya bisa diterima sekali. Pencairan yang dibatalkan Al Wafi bertanda "Dibatalkan Al Wafi". Pencairan yang belum dicatat juga muncul di Tugas Saya. Butuh hak Penerimaan Pencairan dari Al Wafi (Buat). Bila daftar tidak tampil karena sambungan ke Al Wafi putus, coba lagi nanti.

## Apa saja isi Laporan Toko?
kata kunci: laporan toko, barang terlaris, margin, laba kotor barang, perlu dibeli, tidak laku, barang mati, saran beli, laporan penjualan

Buka WAFI MART › Laporan Toko. Ada empat tab:
- Barang Terlaris & Margin: jumlah terjual, omzet, harga pokok, laba kotor, dan margin per barang, sudah dikurangi retur.
- Per Kategori: ringkasan yang sama per kategori.
- Perlu Dibeli: barang yang stoknya di bawah atau sama dengan stok minimum, lengkap dengan laku per hari, perkiraan habis dalam berapa hari, dan Saran beli.
- Tidak Laku: barang berstok yang tidak terjual dalam sejumlah hari terakhir, dengan tanggal terakhir terjual dan umur stok tertua.
Saring dengan tanggal, toko, kategori, dan jumlah hari (bawaan 30). Retur mengurangi laporan menurut tanggal returnya. Butuh hak Laporan Toko (terlaris, margin, perlu dibeli, tidak laku). Laporan ini tidak membuka laporan keuangan lain.

## Kenapa barang tidak muncul di tab Perlu Dibeli?
kata kunci: stok minimum, perlu dibeli kosong, saran beli tidak muncul, reorder, barang habis tidak muncul, batas stok

Tab Perlu Dibeli hanya memuat barang toko yang punya Stok Minimum dan stoknya sudah di bawah atau sama dengan angka itu. Kalau Stok Minimum barang masih kosong, barang itu tidak akan pernah muncul. Isi Stok Minimum di WAFI MART › Data Barang & Stok (bagian Data Toko), atau lewat kolom stok_minimum saat impor barang. Saran beli dihitung agar cukup untuk jumlah hari yang dipilih menurut rata-rata laku hari-hari terakhir, dan minimal mengembalikan stok ke stok minimum. Kalau tidak ada barang yang kurang, tampil "Tidak ada barang di bawah stok minimum."

## Apa saja Tugas Saya yang khas Wafi Mart?
kata kunci: tugas saya, pekerjaan menunggu, notifikasi, lonceng, beranda, pengingat, to do, yang harus dikerjakan

Tugas Saya tampil di Beranda dan lonceng. Isinya pekerjaan yang menunggu Anda sesuai hak akses. Yang khas Wafi Mart:
- Penarikan saldo mitra menunggu diproses (bagi pemegang hak Mitra Kantin Wafi Mart (Ubah)).
- Pencairan dari Al Wafi belum dicatat diterima (hak Penerimaan Pencairan dari Al Wafi (Buat)).
- Tanggal rekonsiliasi kartu perlu diputus (hak Rekonsiliasi Belanja Kartu Santri (Ubah)).
- Invoice vendor mendekati atau lewat jatuh tempo (hak Invoice Vendor).
- Persetujuan yang menunggu Anda.
Klik tugasnya untuk langsung membuka menu terkait. Shift yang setorannya belum diterima dan shift kemarin yang masih terbuka tidak masuk Tugas Saya. Tanda pengingatnya ada di bagian atas WAFI MART › Shift Kasir & Setoran.

## Pengaturan apa saja yang khas Wafi Mart dan di mana mengaturnya?
kata kunci: pengaturan wafi mart, setting toko, akun hutang mitra, akun barang diterima belum ditagih, piutang al wafi, konfigurasi toko, setting awal

Pengaturan yang memengaruhi pekerjaan toko:
- Akun kasir dan batas hari retur tiap toko: WAFI MART › Toko & Gudang (Lokasi).
- Akun "Barang Diterima Belum Ditagih" (wajib sebelum penerimaan barang pertama): SETTING AWAL › Pengaturan Perusahaan.
- Akun "Hutang ke Mitra Kantin": di bagian bawah layar WAFI MART › Mitra Kantin.
- Akun "Piutang ke Al Wafi" untuk belanja kartu santri: SETTING AWAL › Setting Umum › Pengaturan Perusahaan, isian "Akun Piutang ke Al Wafi". Bila muncul pesan bahwa akun ini belum ditetapkan, isi di sana (butuh hak Pengaturan Perusahaan).
- PIN pengawas: Profil & Kata Sandi masing-masing pengawas.
- Hak akses: SETTING AWAL › Hak Akses Modul (khusus admin).
Batas belanja harian santri, PIN belanja, blokir kartu, dan izin belanja kantin diatur di ERP Al Wafi atau oleh wali, bukan di Wafi Mart.

## Hak akses apa yang dibutuhkan untuk tiap pekerjaan toko?
kata kunci: hak akses, menu tidak muncul, tidak bisa buka menu, izin akses, permission, akses ditolak, minta akses, 403

Mintalah admin membuka hak berikut di matriks hak akses sesuai tugas Anda:
- Kasir: Kasir Toko / POS (buka shift & berjualan) — Lihat dan Buat.
- Pengawas kasir: Shift Kasir & Setoran (rekap, batal struk, terima setoran) — Lihat dan Ubah, lalu pasang PIN pengawas.
- Data barang: Persediaan, Kategori Barang, Merk Barang, Lokasi Persediaan (gudang / toko).
- Gudang: Penerimaan Barang (stok masuk dari vendor), Transfer Antar Lokasi (kirim / terima / batal), Stok Opname (hitung & ajukan), Purchase Order.
- Pemutus selisih transfer dan opname: Persetujuan Persediaan (putus selisih transfer, setujui opname). Sebaiknya orang yang berbeda dari penghitung.
- Kantin: Mitra Kantin Wafi Mart (data, menu, akun, laporan, penarikan).
- Kartu santri: Rekonsiliasi Belanja Kartu Santri (Wafi Mart ↔ Al Wafi), Penerimaan Pencairan dari Al Wafi.
- Laporan: Laporan Toko (terlaris, margin, perlu dibeli, tidak laku).
- Impor: Impor Data Awal (pindahan sistem).
