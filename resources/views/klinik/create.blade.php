@extends('layouts.app')

@section('title', 'Catat Kunjungan Klinik')

@section('content')
    <a href="{{ route('klinik.index') }}" class="text-sm text-brand hover:underline">&larr; Klinik</a>

    @unless ($santri)
        {{-- Santri WAJIB tap kartu RFID. Pembaca kartu USB mengetik UID lalu Enter — formulir langsung terkirim. --}}
        <form method="GET" class="mx-auto mt-6 max-w-md rounded-2xl border-2 border-dashed border-brand/40 bg-white p-8 text-center shadow-sm">
            <p class="text-lg font-bold text-gray-900">Tap kartu santri</p>
            <p class="mt-1 text-sm text-gray-500">Tempelkan kartu RFID santri ke pembaca kartu.</p>
            <input name="kartu" autofocus autocomplete="off" placeholder="Menunggu kartu…" class="mt-4 w-full rounded-lg border-gray-300 text-center font-mono text-sm">
            @if ($galat)<p class="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ $galat }}</p>@endif
        </form>
    @else
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <form method="POST" action="{{ route('klinik.store') }}" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm lg:col-span-2"
                  x-data="{ tl: @js(old('tindak_lanjut', 'kembali_kelas')), obat: @js(old('obat', [['nama' => '', 'dosis' => '', 'jumlah' => '']])) }">
                @csrf <input type="hidden" name="kartu" value="{{ $uid }}">
                <div class="flex items-center justify-between">
                    <p class="text-base font-semibold text-gray-900">{{ $santri->nama }} <span class="text-sm font-normal text-gray-500">{{ $santri->nis }} · {{ $santri->jenjang?->nama }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></p>
                    <a href="{{ route('klinik.create') }}" class="text-xs text-brand hover:underline">tap kartu lain</a>
                </div>
                <label class="block"><span class="text-xs text-gray-500">Keluhan *</span>
                    <textarea name="keluhan" rows="2" required class="w-full rounded-lg border-gray-300 text-sm">{{ old('keluhan') }}</textarea></label>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <label><span class="text-xs text-gray-500">Suhu (°C)</span><input name="suhu" type="number" step="0.1" value="{{ old('suhu') }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
                    <label><span class="text-xs text-gray-500">Tensi</span><input name="tensi" value="{{ old('tensi') }}" placeholder="110/70" class="w-full rounded-lg border-gray-300 text-sm"></label>
                    <label><span class="text-xs text-gray-500">Nadi (/menit)</span><input name="nadi" type="number" value="{{ old('nadi') }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
                    <label><span class="text-xs text-gray-500">Berat (kg)</span><input name="berat" type="number" step="0.1" value="{{ old('berat') }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
                </div>
                <label class="block"><span class="text-xs text-gray-500">Pemeriksaan / diagnosis</span>
                    <textarea name="pemeriksaan" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ old('pemeriksaan') }}</textarea></label>
                <label class="block"><span class="text-xs text-gray-500">Tindakan</span>
                    <textarea name="tindakan" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ old('tindakan') }}</textarea></label>

                <div>
                    <span class="text-xs text-gray-500">Obat yang diberikan</span>
                    <template x-for="(o, i) in obat" :key="i">
                        <div class="mt-1 flex gap-2">
                            <input :name="`obat[${i}][nama]`" x-model="o.nama" placeholder="Nama obat" class="flex-1 rounded-lg border-gray-300 text-sm">
                            <input :name="`obat[${i}][dosis]`" x-model="o.dosis" placeholder="Dosis (3×1)" class="w-28 rounded-lg border-gray-300 text-sm">
                            <input :name="`obat[${i}][jumlah]`" x-model="o.jumlah" placeholder="Jumlah" class="w-24 rounded-lg border-gray-300 text-sm">
                            <button type="button" @click="obat.splice(i, 1)" class="px-2 text-gray-400" x-show="obat.length > 1">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="obat.push({ nama: '', dosis: '', jumlah: '' })" class="mt-1 text-xs text-brand hover:underline">+ obat</button>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <label><span class="text-xs text-gray-500">Tindak lanjut *</span>
                        <select name="tindak_lanjut" x-model="tl" class="w-full rounded-lg border-gray-300 text-sm">
                            @foreach (\App\Models\KunjunganKlinik::TINDAK_LANJUT as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select></label>
                    <label x-show="tl === 'dirujuk'" x-cloak><span class="text-xs text-gray-500">Dirujuk ke *</span>
                        <input name="rujukan_ke" value="{{ old('rujukan_ke') }}" placeholder="RS …" class="w-full rounded-lg border-gray-300 text-sm"></label>
                </div>
                <p class="text-xs text-gray-500">Wali santri langsung dikabari (push notification kategori Klinik dan kesehatan).</p>
                <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Simpan kunjungan</button>
            </form>

            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <p class="mb-2 font-semibold text-gray-900">5 kunjungan terakhir</p>
                @forelse ($riwayat as $r)
                    <a href="{{ route('klinik.show', $r->id) }}" class="block border-t border-gray-100 py-1.5 first:border-0 hover:underline">
                        <span class="text-xs text-gray-500">{{ $r->datang_pada->timezone('Asia/Jakarta')->format('d/m/Y') }}</span> {{ \Illuminate\Support\Str::limit($r->keluhan, 40) }}</a>
                @empty
                    <p class="text-gray-400">Belum pernah ke klinik.</p>
                @endforelse
                <a href="{{ route('klinik.riwayat', $santri->id) }}" class="mt-2 block text-xs text-brand hover:underline">Semua riwayat &rarr;</a>
            </div>
        </div>
    @endunless
@endsection
