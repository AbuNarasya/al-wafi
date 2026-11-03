# Integrasi ISE → ERP Al Wafi: Absensi Asrama

Dokumen ini untuk **pengembang ISE**. ISE **mengirim (push)** setiap catatan absen asrama
(menu *Boarding School → Attendance History*) ke ERP Al Wafi, supaya wali santri bisa melihatnya
secara real-time di Portal Wali (menu **Kehadiran asrama**).

## 1. Alamat & keamanan

| | |
|---|---|
| Alamat produksi | `POST https://erp.al-wafi.sch.id/api/ise/v1/absensi-asrama` |
| Alamat uji (dev) | `POST https://dev.erp.al-wafi.sch.id/api/ise/v1/absensi-asrama` |
| Autentikasi | Header `Authorization: Bearer <TOKEN>`. Token diberikan pengelola ERP, **rahasia**, dan berbeda untuk produksi & dev. |
| Format | `Content-Type: application/json`, UTF-8 |
| Batas | Paling banyak **500 catatan per kiriman**; paling banyak 600 kiriman per menit |

Selama token belum dipasang di ERP, alamat ini menjawab **404**.

## 2. Isi kiriman

```json
{
  "data": [
    {
      "id": "att-123456",
      "santri": { "id_erp": 812, "nis": "2425010541", "id_ise": "STD-9981", "nama": "GHALEILA PRICELA SAPUTRA" },
      "waktu": "2026-11-04T04:32:00+07:00",
      "asrama": "DAMASCUS 1C",
      "tipe": "Fajr Congregational Prayer",
      "status": "Setelah Adzan",
      "catatan": "Terminal: Masjid NI (masjidni)",
      "pencatat": "Admin"
    }
  ]
}
```

| Kolom | Wajib | Keterangan |
|---|---|---|
| `id` | ya | ID **unik** catatan absen di ISE (≤ 100 karakter). Mengirim `id` yang sama lagi = **memperbarui** catatan itu (koreksi), bukan menambah. |
| `santri.id_erp` | disarankan | ID santri di ERP. Diutamakan untuk pencocokan (kelak ISE memakai data santri ERP). |
| `santri.nis` | disarankan | NIS santri — dipakai bila `id_erp` kosong/tak cocok. |
| `santri.id_ise`, `santri.nama` | tidak | Untuk penelusuran bila santri tak tercocok. |
| `waktu` | ya | Waktu absen, **ISO-8601 dengan zona waktu** (mis. `+07:00`). |
| `asrama` | tidak | Nama asrama/kamar. |
| `tipe` | ya | Tipe absen / sesi (mis. *Fajr Congregational Prayer*, *Morning Dzikir*). |
| `status` | ya | Status apa adanya dari ISE (mis. *Sebelum Adzan*, *Setelah Adzan*, *Masbuq*, *Tidak Jama'ah*, *Hadir*, *Alpa*, *Izin*, *Haid*, …). |
| `catatan`, `pencatat` | tidak | Disimpan untuk staf; **tidak ditampilkan ke wali**. |
| `dihapus` | tidak | `true` = catatan dengan `id` ini dihapus di ISE → ERP menghapusnya juga (kolom lain boleh kosong). |

## 3. Balasan

`200 OK`:

```json
{ "jumlah": 3, "disimpan": 2, "dihapus": 0, "ditolak": 1, "tak_tercocok": 1,
  "galat": [ { "indeks": 2, "id": "att-9", "pesan": "waktu, tipe, dan status wajib diisi (waktu ISO-8601)" } ] }
```

- Catatan yang **ditolak** tidak disimpan — perbaiki lalu kirim ulang.
- Catatan **tak tercocok** (santri tak dikenali) **tetap disimpan**; staf ERP melihatnya di layar *Absensi Asrama (ISE) → Tak tercocok*.

| Kode | Arti | Tindakan ISE |
|---|---|---|
| 200 | Diterima (lihat `ditolak`/`galat` per catatan) | — |
| 401 | Token salah | Periksa token |
| 404 | Pintu belum diaktifkan di ERP | Hubungi pengelola ERP |
| 413 | Lebih dari 500 catatan | Pecah kiriman |
| 422 | Format `data` salah | Perbaiki format |
| 429 / 5xx / gagal jaringan | Sementara | **WAJIB kirim ulang** (lihat §4) |

## 4. Kirim ulang (wajib)

Push hanya andal bila ISE **mengulang kiriman yang gagal** (ERP mati sebentar, internet putus):

1. Simpan antrean catatan yang belum berhasil terkirim (status selain 200).
2. Ulangi dengan jeda bertambah (mis. 1 mnt, 5 mnt, 15 mnt, lalu tiap jam) sampai berhasil.
3. Kirim ulang aman: `id` yang sama tidak membuat data ganda.

Disarankan juga **sinkron harian** (mis. pukul 23.30) yang mengirim ulang seluruh catatan hari itu — menambal yang mungkin terlewat.

## 5. Contoh `curl`

```bash
curl -X POST https://dev.erp.al-wafi.sch.id/api/ise/v1/absensi-asrama \
  -H "Authorization: Bearer <TOKEN-DEV>" -H "Content-Type: application/json" \
  -d '{"data":[{"id":"uji-1","santri":{"nis":"2425010541"},"waktu":"2026-11-04T04:32:00+07:00","tipe":"Fajr Congregational Prayer","status":"Setelah Adzan"}]}'
```

## 6. Yang dilakukan ERP

- **Wali** melihat: waktu, sesi, asrama, dan status (status *Haid* ditampilkan *Uzur*), plus ringkasan 7/30 hari.
- Ketidakhadiran di dalam rentang **Perizinan Insidental** yang disetujui (izin dikelola di ERP) ditampilkan **Izin**.
- Wali menerima **kabar di HP** bila anaknya tercatat tidak hadir (mis. *Alpa*, *Tidak Jama'ah*) di luar izin — sekali per catatan, hanya untuk absen ≤ 24 jam terakhir.

## 7. Pemasangan token (pengelola ERP)

1. Buat token acak: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
2. Isi `ISE_TOKEN=<token>` di `.env` server (opsional `ISE_IP=<ip-ise>` untuk membatasi alamat pengirim).
3. Jalankan `php artisan config:cache` (dengan `umask 002`).
4. Berikan token & alamat kepada pengembang ISE lewat jalur aman (bukan grup chat umum).
