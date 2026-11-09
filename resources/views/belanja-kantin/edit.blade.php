@extends('layouts.app')

@section('title', 'Belanja Kartu di Kantin')

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Belanja Kartu di Kantin</h2>
        <p class="mt-1 text-sm text-gray-600">
            Aturan kartu santri untuk <b>kantin mitra</b> yang menyewa lahan di area Wafi Mart (mis. Kantin Basah).
            Uangnya tetap dari dompet uang saku santri, dengan PIN belanja anak bila melewati batas harian.
        </p>
    </div>

    <form method="POST" action="{{ route('belanja_kantin.update') }}" class="space-y-4"
          x-data="{ mode: @js(old('mode_batas', $p['mode_batas'])) }">
        @csrf @method('PUT')

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-semibold text-gray-800">Batas belanja harian</div>
            <div class="mt-3 space-y-2">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="radio" name="mode_batas" value="gabung" x-model="mode" @checked(old('mode_batas', $p['mode_batas']) === 'gabung') class="mt-1 text-brand focus:ring-brand">
                    <span>
                        <span class="font-medium text-gray-900">Gabung</span>
                        <span class="block text-xs text-gray-500">Satu batas per hari untuk Wafi Mart dan semua kantin bersama-sama.</span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="radio" name="mode_batas" value="pisah" x-model="mode" @checked(old('mode_batas', $p['mode_batas']) === 'pisah') class="mt-1 text-brand focus:ring-brand">
                    <span>
                        <span class="font-medium text-gray-900">Pisah</span>
                        <span class="block text-xs text-gray-500">Batas Wafi Mart dan batas Kantin dihitung sendiri-sendiri. Semua kantin mitra berbagi satu batas kantin.</span>
                    </span>
                </label>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <div class="text-xs font-medium text-gray-700">Batas bawaan Wafi Mart</div>
                    <div class="mt-1 text-sm font-semibold tabular-nums">@rp($batasMartBawaan)</div>
                    <div class="text-xs text-gray-500">Diubah di Pengaturan Perusahaan (Batas jajan harian).</div>
                </div>
                <div x-show="mode === 'pisah'" x-cloak>
                    <label class="text-xs font-medium text-gray-700">Batas bawaan Kantin <span class="text-red-500">*</span></label>
                    <x-input-rupiah name="batas_kantin_bawaan" :value="old('batas_kantin_bawaan', (int) $p['batas_kantin_bawaan'])" />
                    <div class="text-xs text-gray-500">Berlaku untuk santri yang walinya belum mengatur batas kantin sendiri.</div>
                </div>
            </div>
        </div>

        <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <div class="font-medium text-gray-900">Wali boleh menyalakan/mematikan belanja di kantin</div>
                <div class="text-xs text-gray-500">
                    Nyala: di portal wali muncul pilihan "Belanja di kantin" per anak; anak yang dimatikan walinya ditolak di semua kantin
                    (Wafi Mart tetap bisa). Mati: pilihan itu tak tampil dan semua santri boleh belanja di kantin — pilihan wali tetap
                    tersimpan dan berlaku lagi bila dinyalakan.
                </div>
                @if ($dimatikanWali > 0)
                    <div class="mt-1 text-xs font-semibold text-amber-700">{{ $dimatikanWali }} santri aktif dimatikan belanja kantinnya oleh wali.</div>
                @endif
            </div>
            <input type="hidden" name="saklar_wali" value="0">
            <input type="checkbox" name="saklar_wali" value="1" @checked((bool) old('saklar_wali', $p['saklar_wali']))
                   class="h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
        </label>

        <div class="flex items-center justify-between gap-4">
            <p class="text-xs text-gray-500">
                @if ($diubah)
                    Terakhir diubah {{ $diubah['oleh'] ?? '—' }}, {{ \Illuminate\Support\Carbon::parse($diubah['pada'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}.
                @endif
            </p>
            @if (\App\Support\Akses::boleh('belanja-kantin', 'ubah'))
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
            @endif
        </div>
    </form>
</div>
@endsection
