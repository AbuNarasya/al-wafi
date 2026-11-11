{{--
  Tombol "?" + panel ASISTEN BANTUAN STAF (11 Nov 2026) — dipasang di layouts.app bila saklar staf nyala.
  Layar kasir (layouts.pos / layouts.mitra) sengaja tidak memuatnya. Nama layar yang sedang dibuka ikut
  dikirim (keputusan user) — hanya NAMANYA, bukan isi layar. Percakapan dimuat saat panel dibuka.
--}}
@php
    $layarAktif = collect([
        \App\Support\Navigation::activeGroup(),
        \App\Support\Navigation::activeSub(),
        \App\Support\Navigation::labelUrl(\App\Support\Navigation::activeUrl()),
    ])->filter()->unique()->implode(' › ') ?: ($judulHalaman ?? '');
@endphp
<div x-data="bantuanStaf({ layar: @js($layarAktif), alamat: @js(route('bantuan_staf.tanya')), muat: @js(route('bantuan_staf.percakapan')) })"
     @keydown.escape.window="buka = false" class="print:hidden">
    <button type="button" @click="bukaPanel()" x-show="! buka"
            class="fixed bottom-4 right-4 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-brand text-xl font-bold text-white shadow-lg ring-4 ring-white hover:bg-brand-dark"
            aria-label="Bantuan" title="Bantuan — tanya cara memakai aplikasi">?</button>

    <div x-show="buka" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/30 sm:bg-transparent sm:pointer-events-none" @click="buka = false"></div>
    <aside x-show="buka" x-cloak x-transition
           class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl ring-1 ring-gray-200 sm:w-96">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-bold text-gray-900">Asisten Bantuan</p>
                <p class="truncate text-xs text-gray-500" x-text="layar ? 'Layar: ' + layar : 'Tanya cara memakai aplikasi'"></p>
            </div>
            <button type="button" @click="buka = false" class="text-2xl leading-none text-gray-400 hover:text-gray-600" aria-label="Tutup">&times;</button>
        </div>

        <div x-ref="gulir" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
            <div class="max-w-[90%] rounded-2xl rounded-tl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800">
                Tanyakan cara memakai aplikasi, misalnya "bagaimana cara menerbitkan SPP?" atau "tombol di layar ini untuk apa?". Asisten tidak bisa melihat data dan tidak bisa mengubah apa pun.
            </div>
            <template x-for="(p, i) in pesan" :key="i">
                <div :class="p.dari === 'saya' ? 'flex justify-end' : ''">
                    <div :class="p.dari === 'saya' ? 'max-w-[90%] rounded-2xl rounded-tr-sm bg-brand px-3 py-2 text-sm text-white' : 'max-w-[90%] rounded-2xl rounded-tl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800'">
                        <p class="whitespace-pre-line" x-text="p.teks"></p>
                        <template x-if="p.artikel && p.artikel.length">
                            <div class="mt-2 space-y-1 border-t border-gray-200 pt-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Panduan terkait</p>
                                <template x-for="a in p.artikel" :key="a.id">
                                    <details class="rounded-lg bg-white px-3 py-2">
                                        <summary class="cursor-pointer text-sm font-medium text-brand" x-text="a.judul"></summary>
                                        <p class="mt-1 whitespace-pre-line text-sm text-gray-700" x-html="tebal(a.isi)"></p>
                                    </details>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <div x-show="sedang" class="max-w-[90%] rounded-2xl rounded-tl-sm bg-gray-100 px-3 py-2 text-sm text-gray-500">Sedang menyiapkan jawaban…</div>
        </div>

        <form @submit.prevent="kirim" data-tanpa-muat data-no-confirm class="border-t border-gray-100 p-3">
            <p x-show="galat" x-cloak x-text="galat" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700"></p>
            <div class="flex items-end gap-2">
                <textarea x-ref="isian" x-model="tanya" @keydown.enter.prevent="if (! $event.shiftKey) kirim()" rows="2" maxlength="{{ \App\Services\PortalWali\AsistenWali::MAKS_PERTANYAAN }}"
                          placeholder="Tulis pertanyaan…" :disabled="sedang || sisa <= 0"
                          class="flex-1 resize-none rounded-lg border-gray-300 text-sm"></textarea>
                <button type="submit" :disabled="sedang || sisa <= 0 || ! tanya.trim()"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Kirim</button>
            </div>
            <p class="mt-2 text-[11px] leading-snug text-gray-500">
                Jawaban dibuat otomatis dari panduan dan bisa keliru. Jangan menulis kata sandi, PIN, atau data pribadi.
                <span x-show="sisa !== null" x-text="'Sisa pertanyaan hari ini: ' + sisa"></span>
            </p>
        </form>
    </aside>
</div>

<script>
    function bantuanStaf({ layar, alamat, muat }) {
        return {
            layar, buka: false, termuat: false, pesan: [], sisa: null, tanya: '', sedang: false, galat: '',
            async bukaPanel() {
                this.buka = true;
                this.$nextTick(() => this.$refs.isian?.focus());
                if (this.termuat) return;
                try {
                    const r = await fetch(muat, { headers: { 'Accept': 'application/json' } });
                    if (r.ok) {
                        const d = await r.json();
                        this.pesan = d.percakapan || [];
                        this.sisa = d.sisa;
                        this.termuat = true;
                    }
                } catch (e) { /* panel tetap bisa dipakai; riwayat saja yang tak tampil */ }
                this.$nextTick(() => this.turun());
            },
            turun() { const g = this.$refs.gulir; if (g) g.scrollTop = g.scrollHeight; },
            // Isi artikel di-escape dulu, baru **tebal** dijadikan <b> — tak ada HTML lain yang lolos.
            tebal(s) {
                const aman = String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                return aman.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
            },
            async kirim() {
                const t = this.tanya.trim();
                if (! t || this.sedang || this.sisa === 0) return;
                this.galat = ''; this.sedang = true;
                this.pesan.push({ dari: 'saya', teks: t }); this.tanya = '';
                this.$nextTick(() => this.turun());
                try {
                    const r = await fetch(alamat, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ pertanyaan: t, layar: this.layar }) });
                    const d = await r.json().catch(() => ({}));
                    if (! r.ok) {
                        this.galat = d.message || (r.status === 429 ? 'Terlalu cepat. Tunggu sebentar lalu coba lagi.' : 'Pertanyaan belum terkirim. Coba lagi sebentar lagi.');
                        this.pesan.pop(); this.tanya = t;
                    } else {
                        this.pesan.push({ dari: 'asisten', teks: d.jawaban, artikel: d.artikel || [] });
                        this.sisa = d.sisa;
                    }
                } catch (e) {
                    this.galat = 'Koneksi terputus. Coba lagi.';
                    this.pesan.pop(); this.tanya = t;
                } finally {
                    this.sedang = false;
                    this.$nextTick(() => this.turun());
                }
            },
        };
    }
</script>
