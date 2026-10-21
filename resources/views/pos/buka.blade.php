@extends('layouts.pos')

@section('title', 'Buka Shift')
@section('kepala', 'Buka Shift Kasir')

@section('content')
    <div class="mx-auto mt-6 max-w-md">
        @if ($tokoOptions->isEmpty())
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
                Belum ada lokasi berjenis <b>toko</b>. Bagian keuangan perlu menambahkannya di
                <b>Keuangan → Aset &amp; Persediaan → Lokasi Persediaan</b>, lengkap dengan akun kasirnya.
            </div>
        @else
            <form method="POST" action="{{ route('pos.buka_shift') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
                  x-data="{ modal: @js((string) old('modal_awal', '')), sisa: @js($sisaLaci), lokasi: @js(old('kode_lokasi', $tokoOptions->first(fn ($t) => ! $t->akunKasirKurang())?->kode)) }">
                @csrf
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Toko</label>
                    <select name="kode_lokasi" required x-model="lokasi" class="w-full rounded-lg border-gray-300">
                        @foreach ($tokoOptions as $t)
                            @php $kurang = $t->akunKasirKurang(); @endphp
                            <option value="{{ $t->kode }}" @disabled($kurang) @selected(old('kode_lokasi') === $t->kode)>
                                {{ $t->nama }}{{ $kurang ? ' — belum siap (akun kasir belum lengkap)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Modal awal di laci</label>
                    <input type="text" inputmode="numeric" :value="fmtRupiah(modal)" @input="modal = ketikRupiah($event)" placeholder="0"
                           class="w-full rounded-lg border-gray-300 text-right text-lg tabular-nums">
                    <input type="hidden" name="modal_awal" :value="modal || 0">
                    <p class="mt-1 text-xs text-gray-500">Hitung uang kembalian yang ada di laci sebelum mulai. Modal tak ikut disetor saat shift ditutup.</p>
                    <template x-if="sisa[lokasi]">
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900">
                            <span>Menurut shift terakhir (<span x-text="sisa[lokasi].shift"></span>) laci seharusnya berisi
                                <b x-text="'Rp ' + Math.round(sisa[lokasi].sisa).toLocaleString('id-ID')"></b>.</span>
                            <button type="button" @click="modal = String(Math.round(sisa[lokasi].sisa))" class="font-semibold underline">Pakai angka ini</button>
                        </div>
                    </template>
                    <p x-show="sisa[lokasi] && modal !== '' && Math.round(Number(modal)) !== Math.round(sisa[lokasi].sisa)" x-cloak
                       class="mt-1 text-xs font-medium text-amber-700">Berbeda dari sisa laci shift terakhir — laporkan ke pengawas bila uang di laci memang kurang/lebih.</p>
                </div>
                <button class="w-full rounded-lg bg-brand py-3 text-base font-semibold text-white hover:bg-brand-dark">Buka Shift</button>
            </form>
        @endif
    </div>
@endsection
