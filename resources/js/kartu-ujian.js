// Kartu peserta ujian: QR digambar di browser (server dev.erp tak bisa memasang paket composer pembuat QR).
// Setiap elemen [data-qr] berisi tautan bertanda tangan untuk absensi panitia.
import QRCode from 'qrcode';

document.querySelectorAll('[data-qr]').forEach((el) => {
    QRCode.toString(el.dataset.qr, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' })
        .then((svg) => { el.innerHTML = svg; })
        .catch(() => { el.textContent = 'QR gagal dibuat'; });
});
