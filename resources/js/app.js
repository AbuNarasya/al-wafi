import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * rowFilter — padanan RowFilter.tsx app asli (branch dev) untuk Blade/Alpine.
 *
 * Filter inline per-kolom + cari global, murni client-side atas baris tabel yang
 * sudah dirender server. Dipakai di halaman daftar master (data dimuat penuh,
 * tanpa pagination).
 *
 * Konvensi markup (lihat komponen <x-filter-bar> & <x-fcol>):
 *   <div x-data="rowFilter" x-cloak>
 *     <input data-role="global" @input="apply()">        // cari global (OR antar sel)
 *     <table>
 *       <thead>
 *         <tr> …header… </tr>
 *         <tr> <th><input data-col="0"></th> … <select data-col="3"> … </tr>
 *       </thead>
 *       <tbody>
 *         <tr data-row> …sel… </tr>
 *         <tr data-empty style="display:none"> …tak ada data cocok… </tr>
 *       </tbody>
 *     </table>
 *   </div>
 *
 * - data-col = indeks kolom (0-based) yang difilter. <input> = substring, <select>
 *   = cocok persis (opsi diturunkan otomatis dari nilai sel unik).
 * - Antar kolom digabung AND; cari global OR antar semua sel di baris.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('rowFilter', () => ({
        count: 0,
        hasFilter: false,

        init() {
            // Isi opsi dropdown tiap kolom select dari nilai sel unik.
            this.$root.querySelectorAll('select[data-col]').forEach((sel) => {
                const idx = Number(sel.dataset.col);
                const seen = new Set();
                this.rows().forEach((row) => {
                    const t = this.cellText(row, idx);
                    if (t) seen.add(t);
                });
                [...seen]
                    .sort((a, b) => a.localeCompare(b, 'id'))
                    .forEach((v) => {
                        const o = document.createElement('option');
                        o.value = v;
                        o.textContent = v;
                        sel.appendChild(o);
                    });
            });
            this.apply();
        },

        rows() {
            return [...this.$root.querySelectorAll('tbody tr[data-row]')];
        },

        cellText(row, idx) {
            return (row.children[idx]?.textContent || '').replace(/\s+/g, ' ').trim();
        },

        apply() {
            const root = this.$root;
            const g = root.querySelector('[data-role="global"]');
            const q = (g?.value || '').trim().toLowerCase();
            const filters = [...root.querySelectorAll('[data-col]')]
                .map((el) => ({
                    idx: Number(el.dataset.col),
                    type: el.tagName === 'SELECT' ? 'select' : 'text',
                    val: (el.value || '').trim(),
                }))
                .filter((f) => f.val !== '');

            let shown = 0;
            this.rows().forEach((row) => {
                let ok = true;
                for (const f of filters) {
                    const cell = this.cellText(row, f.idx);
                    if (f.type === 'select') {
                        if (cell !== f.val) { ok = false; break; }
                    } else if (!cell.toLowerCase().includes(f.val.toLowerCase())) {
                        ok = false;
                        break;
                    }
                }
                if (ok && q) {
                    ok = [...row.children].some((c) =>
                        (c.textContent || '').toLowerCase().includes(q));
                }
                row.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });

            this.count = shown;
            this.hasFilter = q !== '' || filters.length > 0;
            const empty = root.querySelector('[data-empty]');
            if (empty) empty.style.display = shown === 0 ? '' : 'none';
        },

        reset() {
            this.$root
                .querySelectorAll('[data-col],[data-role="global"]')
                .forEach((el) => { el.value = ''; });
            this.apply();
        },
    }));
});

/**
 * berkasPreview — pratinjau berkas santri di dalam app (port BerkasSantriModal +
 * PdfViewer.tsx). PDF dirender via PDF.js (pdf-preview.js, dynamic import agar
 * pdfjs-dist tidak masuk bundel utama); gambar via <img>; jenis lain → tautan
 * "Buka di tab baru".
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('berkasPreview', () => ({
        open: false,
        dok: null, // { id, nama, mime, url }
        status: '', // '' | 'memuat' | 'siap' | 'gagal'
        pesan: '',

        get isPdf() { return this.dok?.mime === 'application/pdf'; },
        get isGambar() { return (this.dok?.mime || '').startsWith('image/'); },

        buka(dok) {
            this.dok = dok;
            this.open = true;
            this.status = '';
            this.pesan = '';
            document.body.style.overflow = 'hidden';
            if (this.isPdf) {
                this.$nextTick(() => this.muatPdf());
            }
        },

        tutup() {
            this.open = false;
            this.dok = null;
            document.body.style.overflow = '';
            if (this.$refs.pdfbox) this.$refs.pdfbox.replaceChildren();
        },

        async muatPdf() {
            const box = this.$refs.pdfbox;
            if (!box) return;
            const { renderPdfInto } = await import('./pdf-preview.js');
            await renderPdfInto(box, this.dok.url, (s, m) => {
                this.status = s;
                if (m) this.pesan = m;
            });
        },
    }));
});

/**
 * searchSelect — dropdown yang bisa dicari (ketik untuk memfilter), pengganti
 * <select> polos. Dipakai komponen Blade <x-search-select>. Menulis nilai ke
 * <input type="hidden"> agar tetap terkirim di form biasa.
 */
window.searchSelect = function ({ options = [], value = '' } = {}) {
    return {
        open: false,
        q: '',
        value: value ?? '',
        options: options || [],
        gaya: '', // style panel (position:fixed) — hindari ter-clip overflow container
        filtered() {
            const s = this.q.trim().toLowerCase();
            return s ? this.options.filter((o) => String(o.l).toLowerCase().includes(s)) : this.options;
        },
        label() {
            const o = this.options.find((x) => String(x.v) === String(this.value));
            return o ? o.l : '';
        },
        pick(v) {
            this.value = String(v);
            this.open = false;
            this.q = '';
        },
        posisikan() {
            const r = this.$refs.btn.getBoundingClientRect();
            const lebar = Math.max(r.width, 224);
            // Buka ke atas bila ruang bawah sempit.
            const ruangBawah = window.innerHeight - r.bottom;
            const top = ruangBawah < 240 && r.top > ruangBawah ? '' : `${r.bottom + 2}px`;
            const bottom = top === '' ? `${window.innerHeight - r.top + 2}px` : '';
            this.gaya = `left:${r.left}px; width:${lebar}px;` + (top ? `top:${top};` : `bottom:${bottom};`);
        },
        buka() {
            this.open = !this.open;
            if (this.open) this.$nextTick(() => { this.posisikan(); this.$refs.q && this.$refs.q.focus(); });
        },
        init() {
            const f = () => { if (this.open) this.posisikan(); };
            window.addEventListener('scroll', f, true);
            window.addEventListener('resize', f);
        },
    };
};

/**
 * inputRupiah — isian nominal berpemisah ribuan. Dipakai komponen <x-input-rupiah>.
 *
 * `<input type="number">` TIDAK BISA menampilkan pemisah ribuan: peramban menolak
 * nilai yang bukan angka murni, jadi "8.000.000" langsung dianggap kosong. Karena
 * itu yang dilihat petugas adalah isian TEKS bertopeng, sementara yang terkirim ke
 * server adalah <input type="hidden"> berisi angka mentah — pola yang sama dengan
 * searchSelect di atas, dan alasan yang sama: form biasa harus tetap jalan.
 *
 * Rupiah utuh saja, tanpa sen. Nominal pesantren tak pernah berpecahan, dan ".00"
 * yang terbawa dari basis data (Money selalu 2 desimal) justru bikin petugas ragu.
 *
 * Posisi kursor dipulihkan lewat HITUNGAN DIGIT sebelum kursor, bukan indeks
 * karakter: setelah diformat ulang, jumlah titik di kiri kursor bisa bertambah
 * atau berkurang, dan memakai indeks karakter membuat kursor melompat tiap kali
 * ribuan baru terbentuk.
 */

/** Nilai dari PHP: "8000000.00" (Money) atau "8000000" (old() setelah gagal validasi). */
function rpDariServer(v) {
    const t = String(v ?? '').trim();
    if (t === '') return '';
    const n = Number(t);

    return Number.isFinite(n) ? String(Math.trunc(Math.abs(n))) : t.replace(/\D/g, '');
}

/**
 * Yang diketik/ditempel petugas, dalam format Indonesia: titik = pemisah ribuan
 * (semua digitnya dipertahankan), koma = pemisah desimal (sennya DIBUANG). Tanpa
 * aturan koma itu, menempel "8000000,00" dari layar lain akan terbaca
 * 800.000.000 — seratus kali lipat, tanpa peringatan apa pun.
 */
function rpDariKetikan(v) {
    return String(v).split(',')[0].replace(/\D/g, '').replace(/^0+(?=\d)/, '');
}

/** Indeks karakter tepat setelah digit ke-n pada teks yang sudah diformat. */
function rpPosisiDigitKe(teks, n) {
    if (n <= 0) return 0;
    let hitung = 0;
    for (let i = 0; i < teks.length; i++) {
        if (teks[i] >= '0' && teks[i] <= '9' && ++hitung === n) return i + 1;
    }

    return teks.length;
}

/** Angka mentah → tampilan berpemisah ribuan. Dipakai juga dari ekspresi Alpine. */
window.fmtRupiah = function (v) {
    const digit = rpDariServer(v);

    return digit === '' ? '' : digit.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

/**
 * Penangan `@input` untuk isian rupiah yang nilainya dipegang Alpine (baris
 * berulang cash in/out, jurnal, invoice, termin angsuran — yang totalnya
 * dihitung hidup). Elemennya diformat di tempat, dan yang DIKEMBALIKAN adalah
 * angka mentah supaya penjumlahannya tetap berupa angka:
 *
 *   <input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)"
 *          @input="row.nominal = ketikRupiah($event)">
 *
 * Pola dua bagian ini dipakai — bukan <x-input-rupiah> — karena baris berulang
 * butuh `:name` dinamis dan nilainya harus mengalir ke state Alpine induknya,
 * dua hal yang tak bisa diberikan komponen ber-hidden-input.
 */
window.ketikRupiah = function (ev) {
    const el = ev.target;
    const digitKiri = rpDariKetikan(el.value.slice(0, el.selectionStart)).length;
    const mentah = rpDariKetikan(el.value);

    el.value = window.fmtRupiah(mentah);
    const pos = rpPosisiDigitKe(el.value, digitKiri);
    el.setSelectionRange(pos, pos);

    return mentah;
};

window.inputRupiah = function ({ value = '' } = {}) {
    return {
        mentah: '',

        init() {
            this.mentah = rpDariServer(value);
            this.$refs.tampil.value = window.fmtRupiah(this.mentah);
        },

        ketik() {
            this.mentah = window.ketikRupiah({ target: this.$refs.tampil });
        },
    };
};

// SETELAH semua pembantu `window.*` di atas terdefinisi, bukan sebelumnya:
// Alpine.start() langsung menyisir DOM, dan ekspresi `x-data="inputRupiah(…)"`
// yang pembantunya belum ada hanya menghasilkan peringatan di konsol —
// isiannya diam-diam jadi tak berfungsi.
Alpine.start();

/**
 * Konfirmasi global — dialog box untuk SETIAP tombol pengambilan keputusan.
 *
 * Semua form yang MENGUBAH data (method != GET) dicegat di fase CAPTURE lalu
 * menampilkan dialog konfirmasi sebelum benar-benar terkirim. Pesannya diambil
 * dari `data-confirm`, atau dibaca ulang dari `onsubmit="return confirm('…')"`
 * lama (jadi 47 form yang sudah punya konfirmasi tak perlu diubah & TIDAK dobel),
 * atau pesan default. Dilewati: form GET (cari/filter) & form ber-`data-no-confirm`
 * (mis. login, logout).
 *
 * Dipakai fase capture + stopPropagation agar handler `onsubmit` lama tidak ikut
 * jalan (mencegah dialog ganda). Setelah dikonfirmasi, form dikirim programatik
 * (`form.submit()`) sehingga tidak memicu ulang interseptor ini.
 */
(function () {
    const PESAN_DEFAULT = 'Yakin memproses tindakan ini? Periksa kembali sebelum melanjutkan.';

    function ambilPesan(form) {
        if (form.dataset.confirm) return form.dataset.confirm;
        const os = form.getAttribute('onsubmit') || '';
        const m = os.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
        return m ? m[2] : PESAN_DEFAULT;
    }

    window.konfirmasi = function (pesan) {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);padding:16px;';
            overlay.innerHTML =
                '<div style="width:100%;max-width:26rem;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden;font-family:inherit;">'
                + '<div style="padding:20px 20px 6px;font-size:15px;font-weight:600;color:#111827;">Konfirmasi</div>'
                + '<div data-role="pesan" style="padding:0 20px 18px;font-size:14px;color:#4b5563;line-height:1.55;"></div>'
                + '<div style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;background:#f9fafb;border-top:1px solid #f0f0f0;">'
                + '<button type="button" data-role="batal" style="border-radius:8px;border:1px solid #d1d5db;background:#fff;padding:8px 16px;font-size:13px;color:#374151;cursor:pointer;">Batal</button>'
                + '<button type="button" data-role="ok" style="border-radius:8px;border:0;background:#164a9e;padding:8px 16px;font-size:13px;font-weight:600;color:#fff;cursor:pointer;">Lanjutkan</button>'
                + '</div></div>';
            overlay.querySelector('[data-role="pesan"]').textContent = pesan;
            document.body.appendChild(overlay);
            const prevOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

            const tutup = (hasil) => {
                overlay.remove();
                document.body.style.overflow = prevOverflow;
                document.removeEventListener('keydown', onKey, true);
                resolve(hasil);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); tutup(false); }
                else if (e.key === 'Enter') { e.preventDefault(); tutup(true); }
            };
            overlay.querySelector('[data-role="ok"]').addEventListener('click', () => tutup(true));
            overlay.querySelector('[data-role="batal"]').addEventListener('click', () => tutup(false));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) tutup(false); });
            document.addEventListener('keydown', onKey, true);
            setTimeout(() => overlay.querySelector('[data-role="ok"]').focus(), 30);
        });
    };

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') return;
        if (form.hasAttribute('data-no-confirm')) return;

        e.preventDefault();
        e.stopPropagation(); // capture: hentikan sebelum target → onsubmit inline tak jalan

        // Validasi bawaan dulu — jangan minta konfirmasi untuk form yang tak valid.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const submitter = e.submitter;
        window.konfirmasi(ambilPesan(form)).then((ok) => {
            if (!ok) return;
            // Pertahankan tombol submit yang diklik (untuk form multi-tombol).
            if (submitter && submitter.name) {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = submitter.name;
                h.value = submitter.value ?? '';
                form.appendChild(h);
            }
            form.submit(); // programatik → tidak memicu interseptor ini lagi
        });
    }, true); // fase capture
})();

// Bersihkan Service Worker sisa aplikasi React lama (PWA) yang mungkin masih
// terdaftar di origin dev (localhost) dan mengintersepsi request — menyebabkan
// respons/redirect yang keliru. App Laravel ini bukan PWA.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations()
        .then((regs) => regs.forEach((r) => r.unregister()))
        .catch(() => {});
}

/**
 * SEPARATOR RIBUAN untuk SEMUA isian uang (mis. 750000 → 750.000).
 *
 * MASALAHNYA: `<input type="number">` menolak titik, dan 21 isian uang di app
 * ini terikat `x-model` yang menyuapi total otomatis (total kas masuk, indikator
 * balance jurnal, jumlah termin). Bila teks berformat masuk ke state Alpine,
 * JavaScript membaca "750.000" sebagai 750 → angka keuangan RUSAK diam-diam.
 *
 * SOLUSI: input BERPASANGAN. Input asli tetap ada — hanya disembunyikan — dan
 * tetap memegang `name` + `x-model`, sehingga state Alpine & data terkirim
 * SELALU angka mentah. Di sebelahnya dipasang input teks yang menampilkan versi
 * berformat. Ketikan pengguna disalin balik sebagai angka mentah lalu memicu
 * event `input` agar Alpine ikut menghitung ulang. Sebaliknya, saat Alpine
 * mengisi nilai secara program (mis. nominal = qty × harga, atau "Muat dari PO"),
 * penulisan `value` dicegat agar tampilan ikut diperbarui.
 *
 * Hanya nama field UANG yang diproses; kuantiti, tahun, nilai tes, gelombang,
 * tenor, dan stok sengaja DILEWATI agar tak salah baca. Opt-out: `data-tanpa-pisah`.
 */
(function () {
    const NAMA_UANG = new Set([
        'nominal', 'nominal_uang_muka', 'nominal_realisasi', 'nominal_spp',
        'harga_satuan', 'harga_perolehan', 'nilai_residu', 'akumulasi_depresiasi',
        'potongan', 'pokok_awal', 'margin', 'saldo', 'saldo_bank',
        'debet', 'kredit', 'max_transaksi',
    ]);

    /** "details[0][nominal]" → "nominal"; "saldo_bank" → "saldo_bank". */
    function namaDasar(nama) {
        if (!nama) return '';
        const m = nama.match(/\[([^\[\]]+)\]\s*$/);
        return (m ? m[1] : nama).trim();
    }

    const format = new Intl.NumberFormat('id-ID');

    /** "1234567.5" → "1.234.567,5" (bagian desimal dibiarkan apa adanya). */
    function keTampilan(mentah) {
        const s = String(mentah ?? '').trim();
        if (s === '') return '';
        const minus = s.startsWith('-') ? '-' : '';
        const [bulat, desimal] = s.replace('-', '').split('.');
        if (bulat === '' && desimal === undefined) return '';
        const angka = Number(bulat || '0');
        if (!Number.isFinite(angka)) return s;
        return minus + format.format(angka) + (desimal !== undefined ? ',' + desimal : '');
    }

    /**
     * Buang desimal yang tak berarti: "50000000.00" → "50000000", "1500.50" →
     * "1500.5". Kolom uang di database bertipe decimal(18,2) sehingga nilai bulat
     * pun datang sebagai "…​,00" — mengganggu dibaca di isian.
     *
     * SENGAJA hanya dipakai untuk nilai yang DATANG dari server/Alpine, bukan saat
     * pengguna mengetik: merapikan sambil diketik akan menghapus koma yang baru
     * saja ditekan ("1500," / "1500,0") sebelum angka desimalnya sempat masuk.
     */
    function rapikanDesimal(mentah) {
        const s = String(mentah ?? '').trim();
        if (!s.includes('.')) return s;

        return s.replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    }

    /** "1.234.567,5" → "1234567.5" (titik = pemisah ribuan, koma = desimal). */
    function keMentah(tampilan) {
        const s = String(tampilan ?? '').replace(/\./g, '').replace(',', '.');
        const bersih = s.replace(/[^0-9.\-]/g, '');
        return bersih === '-' || bersih === '' ? '' : bersih;
    }

    function pasang(asli) {
        if (asli.dataset.rpSiap || asli.hasAttribute('data-tanpa-pisah')) return;
        if (!NAMA_UANG.has(namaDasar(asli.getAttribute('name')))) return;
        asli.dataset.rpSiap = '1';

        const tampil = document.createElement('input');
        tampil.type = 'text';
        tampil.inputMode = 'numeric';
        tampil.autocomplete = 'off';
        tampil.className = asli.className;
        if (asli.placeholder) tampil.placeholder = asli.placeholder;
        if (asli.hasAttribute('required')) {
            // Wajib-isi dipindah ke input tampilan: input tersembunyi tak bisa
            // difokuskan browser sehingga validasi HTML5-nya akan macet.
            tampil.required = true;
            asli.removeAttribute('required');
        }
        if (asli.disabled) tampil.disabled = true;
        asli.type = 'hidden';
        asli.after(tampil);

        // Alpine mengendalikan `disabled` pada input asli (mis. baris persediaan)
        // → tampilan mengikuti agar tetap sinkron.
        new MutationObserver(() => { tampil.disabled = asli.disabled; })
            .observe(asli, { attributes: true, attributeFilter: ['disabled'] });

        tampil.value = keTampilan(rapikanDesimal(asli.value));

        // Menandai penulisan yang BERASAL dari ketikan pengguna, agar setter di
        // bawah tak merapikan desimal di tengah ketik (mengetik "1500,0" sebagai
        // langkah menuju "1500,05" akan kehilangan komanya).
        let dariKetikan = false;

        tampil.addEventListener('input', () => {
            const sebelum = tampil.value.slice(0, tampil.selectionStart ?? 0).replace(/\D/g, '').length;
            const mentah = keMentah(tampil.value);
            tampil.value = keTampilan(mentah);
            // Kembalikan kursor ke posisi setara (dihitung dari jumlah digit).
            let digit = 0, pos = 0;
            for (; pos < tampil.value.length && digit < sebelum; pos++) {
                if (/\d/.test(tampil.value[pos])) digit++;
            }
            tampil.setSelectionRange(pos, pos);

            if (asli.value !== mentah) {
                dariKetikan = true;
                asli.value = mentah; // lewat setter asli (lihat intercept di bawah)
                dariKetikan = false;
                asli.dispatchEvent(new Event('input', { bubbles: true }));
                asli.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Penulisan `value` oleh Alpine/JS lain → perbarui tampilan.
        const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        Object.defineProperty(asli, 'value', {
            configurable: true,
            get() { return desc.get.call(this); },
            set(v) {
                desc.set.call(this, v);
                if (dariKetikan) return; // tampilan sudah persis seperti yang diketik
                const baru = keTampilan(rapikanDesimal(v));
                if (tampil.value !== baru) tampil.value = baru;
            },
        });
    }

    function sapu(akar) {
        (akar.querySelectorAll ? akar.querySelectorAll('input[type="number"]') : []).forEach(pasang);
    }

    document.addEventListener('DOMContentLoaded', () => {
        sapu(document);
        // Baris tabel ditambahkan Alpine (kas masuk/keluar, jurnal, PO, termin…)
        // muncul belakangan → tetap ikut diproses.
        new MutationObserver((mutasi) => {
            mutasi.forEach((m) => m.addedNodes.forEach((n) => {
                if (n.nodeType !== 1) return;
                if (n.matches?.('input[type="number"]')) pasang(n);
                sapu(n);
            }));
        }).observe(document.body, { childList: true, subtree: true });
    });
})();

/**
 * AUTO-FILTER: mengetik di kotak cari langsung menyaring, tanpa tombol "Cari".
 *
 * Halaman master menyaring seketika di browser (lihat `rowFilter`), sedangkan
 * daftar BERPAGINASI harus menyaring di server agar hasilnya tetap sahih lintas
 * halaman. Supaya rasanya sama, input ber-`data-filter-auto` mengirim form GET
 * otomatis setelah jeda ketik singkat — pengguna tak perlu menekan tombol.
 *
 * Karena pengiriman form berarti halaman dimuat ulang, fokus & posisi kursor
 * dikembalikan ke kotak yang sama setelah muat, sehingga ketikan bisa
 * dilanjutkan tanpa terasa terputus.
 */
(function () {
    const JEDA = 400; // ms — cukup untuk mengetik beberapa huruf sebelum menyaring
    const KUNCI = 'filterFokus';

    document.addEventListener('input', (e) => {
        const el = e.target.closest?.('[data-filter-auto]');
        if (!el || !el.form) return;

        clearTimeout(el._tundaFilter);
        el._tundaFilter = setTimeout(() => {
            try {
                sessionStorage.setItem(KUNCI, el.name || '');
            } catch { /* mode privat: lewati saja */ }
            el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
        }, JEDA);
    });

    document.addEventListener('DOMContentLoaded', () => {
        let nama = null;
        try {
            nama = sessionStorage.getItem(KUNCI);
            sessionStorage.removeItem(KUNCI);
        } catch { /* abaikan */ }
        if (nama === null) return;

        const el = document.querySelector(`[data-filter-auto][name="${nama}"]`) || document.querySelector('[data-filter-auto]');
        if (!el) return;
        el.focus();
        const n = el.value.length;
        el.setSelectionRange?.(n, n); // kursor kembali ke ujung ketikan
    });
})();
