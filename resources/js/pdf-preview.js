// Render PDF ke <canvas> via PDF.js (port PdfViewer.tsx app asli).
//
// Merender sendiri tiap halaman, BUKAN menyerahkan ke plugin PDF browser: bila
// setelan browser "unduh PDF" aktif, <iframe>/<embed> hanya menampilkan
// placeholder unduh. Modul ini di-*dynamic import* dari app.js (hanya dimuat
// saat ada PDF dibuka) agar pdfjs-dist tak membebani bundel utama.
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

/**
 * @param {HTMLElement} box  kontainer target (dikosongkan lebih dulu)
 * @param {string} url       URL berkas PDF (di-fetch dgn cookie sesi)
 * @param {(status: 'memuat'|'siap'|'gagal', pesan?: string) => void} [onStatus]
 */
export async function renderPdfInto(box, url, onStatus) {
    box.replaceChildren();
    onStatus?.('memuat');
    try {
        const pdfjs = await import('pdfjs-dist');
        pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const buf = await res.arrayBuffer();

        const pdf = await pdfjs.getDocument({ data: new Uint8Array(buf) }).promise;
        const lebar = box.clientWidth || 800;
        for (let n = 1; n <= pdf.numPages; n++) {
            const page = await pdf.getPage(n);
            const dasar = page.getViewport({ scale: 1 });
            const scale = Math.min(2, lebar / dasar.width);
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.className = 'mx-auto mb-3 max-w-full rounded shadow';
            box.appendChild(canvas);
            await page.render({ canvas, viewport }).promise;
        }
        onStatus?.('siap');
    } catch (e) {
        onStatus?.('gagal', e instanceof Error ? e.message : 'kesalahan tak dikenal');
    }
}
