@extends('layouts.app')

@section('title', 'Pelepasan Aset')

@section('content')
    <div class="mb-4">
        <a href="{{ route('assets.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Aset Tetap</a>
        <h1 class="mt-1 text-lg font-semibold text-gray-900">Pelepasan Aset Tetap</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Aset yang dijual, dihibahkan, atau dihapuskan dikeluarkan dari buku besar lewat dokumen berjurnal —
            bukan dengan menghapus barisnya. Penyusutannya berhenti sendiri begitu dilepas.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    @if (\App\Support\Akses::boleh('assets', 'hapus'))
        <form method="POST" action="{{ route('assets.lepas') }}" x-data="{ perlakuan: '{{ old('perlakuan', 'dijual') }}' }"
              class="mb-6 space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="kode_aset" label="Aset" :value="old('kode_aset')" :options="$asetOptions" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tanggal <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}" required
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Perlakuan <span class="text-red-500">*</span></label>
                    <select name="perlakuan" x-model="perlakuan" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        @foreach ($perlakuanOptions as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Harga jual & rekening hanya muncul untuk penjualan: hibah dan
                 penghapusan memang tak menerima uang, dan menyodorkan isiannya
                 hanya mengundang angka yang tak pernah ada. --}}
            <div x-show="perlakuan === 'dijual'" x-cloak class="grid gap-4 sm:grid-cols-2">
                <x-field name="harga_jual" label="Harga Jual" type="number" :value="old('harga_jual')" />
                <x-field name="kode_rekening" label="Rekening Penerima" :value="old('kode_rekening')" :options="$rekeningOptions" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_coa_akumulasi" label="Akun Akumulasi Penyusutan" :value="old('kode_coa_akumulasi')" :options="$coaOptions" required
                         hint="Didebet sebesar akumulasi penyusutan aset ini, agar bersih dari buku besar." />
                <div>
                    <x-field name="kode_coa_labarugi" label="Akun Laba/Rugi Pelepasan" :value="old('kode_coa_labarugi')" :options="$coaOptions" required />
                    <p class="mt-1 text-xs text-gray-500">
                        <span x-show="perlakuan === 'dijual'">Selisih harga jual dengan nilai buku masuk ke sini — bisa laba, bisa rugi.</span>
                        <span x-show="perlakuan !== 'dijual'" x-cloak>Nilai buku yang tersisa dibebankan penuh ke akun ini.</span>
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" />
                <x-field name="kode_bagian" label="Bagian" :value="old('kode_bagian')" :options="$bagianOptions"
                         hint="Wajib bila akun laba/rugi yang dipilih adalah akun Beban." />
            </div>

            <x-field name="alasan" label="Alasan Pelepasan" :value="old('alasan')" textarea required
                     hint="Aset adalah harta yayasan — alasan inilah yang akan dibaca saat pelepasannya dipertanyakan." />

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Lepaskan Aset</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5">No. Ref</th><th class="px-3 py-2.5">Tanggal</th>
                    <th class="px-3 py-2.5">Aset</th><th class="px-3 py-2.5">Perlakuan</th>
                    <th class="px-3 py-2.5 text-right">Perolehan</th>
                    <th class="px-3 py-2.5 text-right">Nilai Buku</th>
                    <th class="px-3 py-2.5 text-right">Harga Jual</th>
                    <th class="px-3 py-2.5 text-right">Laba/(Rugi)</th>
                    <th class="px-3 py-2.5">Status</th>
                    <th class="px-3 py-2.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="align-top hover:bg-gray-50 {{ $r->status === 'void' ? 'opacity-60' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap font-mono">{{ $r->nomor_ref }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-3 py-2">
                            {{ $r->aset?->nama_aset ?? $r->kode_aset }}
                            <div class="text-[11px] text-gray-400">{{ $r->alasan }}</div>
                        </td>
                        <td class="px-3 py-2">{{ $r->labelPerlakuan() }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($r->nilai_perolehan)</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($r->nilai_buku)</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($r->harga_jual)</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums {{ $r->untung() ? 'text-emerald-700' : 'text-red-700' }}">@rp($r->laba_rugi)</td>
                        <td class="px-3 py-2">
                            @if ($r->status === 'void')
                                <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-600">Void</span>
                                <div class="text-[11px] text-gray-400">{{ $r->void_reason }}</div>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Aktif</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{-- Berita acara / bukti jual menempel di sini, lewat
                                 modul lampiran yang sudah ada. --}}
                            <a href="{{ route('lampiran.index', ['jenis' => \App\Support\SumberLampiran::PELEPASAN_ASET, 'id' => $r->id]) }}"
                               class="mr-2 text-gray-600 hover:underline">Lampiran</a>
                            @if ($r->status === 'aktif' && \App\Support\Akses::boleh('assets', 'hapus'))
                                <form method="POST" action="{{ route('assets.pelepasan_void', $r->id) }}"
                                      onsubmit="return confirm('Batalkan pelepasan {{ $r->nomor_ref }}? Jurnalnya dibalik dan asetnya aktif kembali.')">
                                    @csrf @method('DELETE')
                                    <input type="text" name="alasan" required placeholder="Alasan" class="mb-1 w-28 rounded border border-gray-300 px-1.5 py-0.5 text-[11px]">
                                    <button class="text-red-600 hover:underline">Void</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">Belum ada aset yang dilepas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
