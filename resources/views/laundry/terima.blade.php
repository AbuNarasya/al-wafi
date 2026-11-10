@extends('layouts.app')

@section('title', 'Terima Cucian')

@section('content')
    {{-- Label kantong baru: TIDAK lagi dicetak otomatis (permintaan user 10 Nov 2026) — petugas menekan tombol
         Cetak label bila perlu. Halaman label membuka dialog cetaknya sendiri (?cetak=1) di tab baru. --}}
    @if (session('cetak_label'))
        <a href="{{ route('laundry.label', session('cetak_label')) }}?cetak=1" target="_blank"
           class="mb-3 inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a1 1 0 011-1h16a1 1 0 011 1v6a1 1 0 01-1 1h-2M6 14h12v7H6z"/></svg>
            Cetak label
        </a>
    @endif
    <a href="{{ route('laundry.index') }}" class="block text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    @unless ($santri)
        @include('laundry._kenali', ['rute' => 'laundry.terima', 'judul' => 'Terima cucian — tap kartu santri', 'cariDulu' => true])
    @else
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <form method="POST" action="{{ route('laundry.simpan_terima') }}" data-no-confirm class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm lg:col-span-2"
                  x-data="{ berat: @js(old('berat', '')),
                            terpakai: @js((float) $kuota['terpakai']), kuota: @js((float) $kuota['kuota']), tarif: @js($kuota['tarif'] !== null ? (float) $kuota['tarif'] : null),
                            get sesudah() { return this.terpakai + (parseFloat(this.berat) || 0) },
                            get lebih() { return Math.max(0, this.sesudah - this.kuota) } }">
                @csrf
                @if ($cara === 'kartu')<input type="hidden" name="kartu" value="{{ $uid }}">@else<input type="hidden" name="santri" value="{{ $santri->id }}">@endif
                <div class="flex items-center justify-between">
                    <p class="text-base font-semibold text-gray-900">{{ $santri->nama }}
                        <span class="text-sm font-normal text-gray-500">{{ $santri->nis }} · {{ $santri->jenjang?->nama }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></p>
                    <a href="{{ route('laundry.terima') }}" class="text-xs text-brand hover:underline">santri lain</a>
                </div>
                @if ($cara === 'manual')
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Tanpa kartu — dicatat sebagai pencarian manual.</p>
                @endif

                <label class="block max-w-xs"><span class="text-xs text-gray-500">Berat (kg) *</span>
                    <input name="berat" type="number" step="0.01" min="0.01" max="{{ \App\Services\Modules\LaundryService::BERAT_MAKS }}" required autofocus
                           x-model="berat" class="w-full rounded-lg border-gray-300 text-lg font-semibold tabular-nums"></label>

                {{-- Kuota bulan ini (tahap 2): terpakai sebelumnya + setoran ini. Kelebihannya ditagih awal bulan depan. --}}
                <div class="rounded-lg px-3 py-2 text-xs" :class="lebih > 0 ? 'bg-amber-50 text-amber-900 ring-1 ring-amber-200' : 'bg-gray-50 text-gray-600'">
                    Kuota {{ now()->locale('id')->translatedFormat('F') }}: <b>{{ number_format((float) $kuota['kuota'], 1, ',', '.') }} kg</b> ·
                    terpakai <b x-text="sesudah.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></b> kg
                    <span x-show="lebih > 0"> · <b>melebihi kuota <span x-text="lebih.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></span> kg</b>
                        <span x-show="tarif !== null"> — perkiraan tagihan bulan depan Rp <span x-text="Math.round(lebih * (tarif || 0)).toLocaleString('id-ID')"></span></span></span>
                    <span x-show="lebih <= 0"> · sisa <span x-text="Math.max(0, kuota - sesudah).toLocaleString('id-ID', { maximumFractionDigits: 2 })"></span> kg</span>
                </div>

                {{-- Paket 1 (3 Nov 2026): di penerimaan cukup DITIMBANG — jenis & jumlah pakaian dicatat di meja pemeriksaan. --}}
                <p class="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">Cukup ditimbang. Jenis &amp; jumlah pakaian dicatat di meja <b>Pemeriksaan</b>, sekaligus timbang ulang. Label kantong tercetak otomatis sesudah disimpan.</p>

                <label class="block"><span class="text-xs text-gray-500">Catatan (noda, sobek, pakaian khusus, …)</span>
                    <textarea name="catatan" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ old('catatan') }}</textarea></label>
                <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Simpan &amp; terima</button>
            </form>

            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <p class="mb-2 font-semibold text-gray-900">Cucian yang masih di laundry</p>
                @forelse ($berjalan as $b)
                    <a href="{{ route('laundry.show', $b->id) }}" class="flex justify-between border-t border-gray-100 py-1.5 first:border-0 hover:underline">
                        <span>{{ $b->nomor }} · {{ $b->berat }} kg</span><span class="text-xs text-gray-500">{{ $b->labelStatus() }}</span></a>
                @empty
                    <p class="text-gray-400">Tidak ada.</p>
                @endforelse
            </div>
        </div>
    @endunless
@endsection
