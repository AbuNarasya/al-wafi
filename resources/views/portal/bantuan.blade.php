@extends('layouts.portal')

@section('title', __('Bantuan'))

@section('content')
@php
    $awal = $percakapan->flatMap(fn ($p) => [['dari' => 'wali', 'teks' => $p->pertanyaan], ['dari' => 'asisten', 'teks' => $p->jawaban, 'artikel' => []]])->values();
@endphp
<div x-data="asistenWali({ pesan: @js($awal), sisa: @js($sisa), alamat: @js(route('portal.bantuan.tanya')) })" class="space-y-4">

    {{-- Obrolan --}}
    <div class="rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600"><x-portal.ikon nama="bantuan" /></span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900">{{ __('Asisten Bantuan') }}</p>
                <p class="text-xs text-slate-500">{{ __('Menjawab cara memakai portal. Tidak bisa melihat data anak Anda.') }}</p>
            </div>
        </div>

        <div x-ref="gulir" class="max-h-[55vh] space-y-3 overflow-y-auto px-4 py-4">
            <div class="max-w-[85%] rounded-2xl rounded-tl-sm bg-slate-100 px-3 py-2 text-sm text-slate-800">
                {{ __('Assalamu\'alaikum. Silakan tanyakan cara memakai portal, misalnya cara membayar tagihan, mengisi saldo, atau mengajukan izin.') }}
            </div>
            <template x-for="(p, i) in pesan" :key="i">
                <div :class="p.dari === 'wali' ? 'flex justify-end' : ''">
                    <div :class="p.dari === 'wali' ? 'max-w-[85%] rounded-2xl rounded-tr-sm bg-brand px-3 py-2 text-sm text-white' : 'max-w-[85%] rounded-2xl rounded-tl-sm bg-slate-100 px-3 py-2 text-sm text-slate-800'">
                        <p class="whitespace-pre-line" x-text="p.teks"></p>
                        <template x-if="p.artikel && p.artikel.length">
                            <div class="mt-2 space-y-1 border-t border-slate-200 pt-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Panduan terkait') }}</p>
                                <template x-for="a in p.artikel" :key="a.id">
                                    <details class="rounded-xl bg-white px-3 py-2">
                                        <summary class="cursor-pointer text-sm font-medium text-brand" x-text="a.judul"></summary>
                                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700" x-html="tebal(a.isi)"></p>
                                    </details>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <div x-show="sedang" x-cloak class="max-w-[85%] rounded-2xl rounded-tl-sm bg-slate-100 px-3 py-2 text-sm text-slate-500">{{ __('Sedang menyiapkan jawaban…') }}</div>
        </div>

        <form @submit.prevent="kirim" data-tanpa-muat data-no-confirm class="border-t border-slate-100 p-3">
            <p x-show="galat" x-cloak x-text="galat" class="mb-2 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700"></p>
            <div class="flex items-end gap-2">
                <textarea x-model="tanya" @keydown.enter.prevent="if (! $event.shiftKey) kirim()" rows="1" maxlength="{{ \App\Services\PortalWali\AsistenWali::MAKS_PERTANYAAN }}"
                          placeholder="{{ __('Tulis pertanyaan…') }}" :disabled="sedang || sisa <= 0"
                          class="min-h-[2.75rem] flex-1 resize-none rounded-2xl border-slate-300 text-sm focus:border-brand focus:ring-brand"></textarea>
                <button type="submit" :disabled="sedang || sisa <= 0 || ! tanya.trim()"
                        class="h-11 shrink-0 rounded-2xl bg-brand px-4 text-sm font-semibold text-white disabled:opacity-40">{{ __('Kirim') }}</button>
            </div>
            <p class="mt-2 text-[11px] leading-snug text-slate-500">
                {{ __('Jangan menulis PIN, password, kode OTP, NIK, atau nomor rekening. Jawaban dibuat otomatis dan bisa keliru — untuk hal penting, hubungi admin pesantren.') }}
                <span x-text="'{{ __('Sisa pertanyaan hari ini:') }} ' + sisa"></span>
            </p>
        </form>
    </div>

    {{-- Semua panduan, bisa dicari tanpa AI --}}
    @if ($artikel->isNotEmpty())
        <div x-data="{ cari: '' }" class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Semua panduan') }}</h2>
            <input x-model="cari" type="search" placeholder="{{ __('Cari panduan…') }}" class="mb-2 w-full rounded-2xl border-slate-300 text-sm">
            <div class="divide-y divide-slate-100">
                @foreach ($artikel as $a)
                    <details x-show="! cari || @js(mb_strtolower($a->judul.' '.$a->kata_kunci.' '.$a->isi)).includes(cari.toLowerCase())" class="py-2">
                        <summary class="cursor-pointer text-sm font-medium text-slate-800">{{ $a->judul }}</summary>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{!! preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', e($a->isi)) !!}</p>
                    </details>
                @endforeach
            </div>
        </div>
    @endif
</div>

<script>
    function asistenWali({ pesan, sisa, alamat }) {
        return {
            pesan, sisa, tanya: '', sedang: false, galat: '',
            init() { this.$nextTick(() => this.turun()); },
            // Isi artikel di-escape dulu, baru **tebal** dijadikan <b> — tak ada HTML lain yang lolos.
            tebal(s) {
                const aman = String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                return aman.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
            },
            turun() { const g = this.$refs.gulir; g.scrollTop = g.scrollHeight; },
            async kirim() {
                const t = this.tanya.trim();
                if (! t || this.sedang || this.sisa <= 0) return;
                this.galat = ''; this.sedang = true;
                this.pesan.push({ dari: 'wali', teks: t }); this.tanya = '';
                this.$nextTick(() => this.turun());
                try {
                    const r = await fetch(alamat, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ pertanyaan: t }) });
                    const d = await r.json().catch(() => ({}));
                    if (! r.ok) {
                        this.galat = d.message || @js(__('Pertanyaan belum terkirim. Coba lagi sebentar lagi.'));
                        if (r.status === 429 && ! d.message) this.galat = @js(__('Terlalu cepat. Tunggu sebentar lalu coba lagi.'));
                        this.pesan.pop(); this.tanya = t;
                    } else {
                        this.pesan.push({ dari: 'asisten', teks: d.jawaban, artikel: d.artikel || [] });
                        this.sisa = d.sisa;
                    }
                } catch (e) {
                    this.galat = @js(__('Koneksi terputus. Coba lagi.'));
                    this.pesan.pop(); this.tanya = t;
                } finally {
                    this.sedang = false;
                    this.$nextTick(() => this.turun());
                }
            },
        };
    }
</script>
@endsection
