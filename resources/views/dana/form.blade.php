@extends('layouts.app')

@php $baru = ! $dana->exists; @endphp

@section('title', $baru ? 'Tambah Dana' : 'Ubah Dana ' . $dana->kode_dana)

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('dana.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

        <form method="POST" action="{{ $baru ? route('dana.store') : route('dana.update', $dana->kode_dana) }}"
              x-data="{ jenis: '{{ old('jenis', $dana->jenis) }}' }"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode_dana" label="Kode Dana" :value="old('kode_dana')" required placeholder="mis. WKF001" />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Dana</label>
                        <div class="rounded-lg bg-gray-100 px-3 py-2 font-mono text-sm text-gray-600">{{ $dana->kode_dana }}</div></div>
                @endif
                <x-field name="nama_dana" label="Nama Dana" :value="old('nama_dana', $dana->nama_dana)" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jenis <span class="text-red-500">*</span></label>
                    <select name="jenis" x-model="jenis" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        @foreach ($jenisOptions as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        <span x-show="jenis === 'tidak_terikat'">Boleh dipakai untuk apa saja.</span>
                        <span x-show="jenis === 'terikat_temporer'" x-cloak>Pembatasannya gugur bila peruntukannya tercapai atau masanya lewat.</span>
                        <span x-show="jenis === 'terikat_permanen'" x-cloak>Pokoknya tidak boleh dipakai selamanya (wakaf abadi).</span>
                    </p>
                </div>
                <x-field name="donatur" label="Donatur / Pemberi" :value="old('donatur', $dana->donatur)" />
            </div>

            <x-field name="peruntukan" label="Peruntukan" :value="old('peruntukan', $dana->peruntukan)" textarea
                     hint="Wajib untuk dana terikat — inilah yang akan dibaca donatur, dan yang membedakannya dari dana biasa." />

            <div class="grid gap-4 sm:grid-cols-4">
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Mulai</label>
                    <input type="date" name="tanggal_mulai" value="{{ old('tanggal_mulai', $dana->tanggal_mulai?->toDateString()) }}"
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Selesai</label>
                    <input type="date" name="tanggal_selesai" value="{{ old('tanggal_selesai', $dana->tanggal_selesai?->toDateString()) }}"
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm"></div>
                <x-field name="target_nominal" label="Target Nominal" type="number" :value="old('target_nominal', $dana->target_nominal)" />
                <x-field name="status" label="Status" :value="old('status', $dana->status ?? 'aktif')"
                         :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'selesai' => 'Selesai']" required />
            </div>

            {{-- Daftar akun beban yang boleh dibebani. Kosong = tanpa pembatasan
                 akun, dan itu dikatakan terang-terangan: dana terikat yang
                 daftarnya kosong pembatasannya baru tertulis, belum ditegakkan. --}}
            <div class="rounded-lg border border-brand/30 bg-brand-soft/40 p-4">
                <div class="mb-1 text-sm font-semibold text-gray-800">Peruntukan yang Ditegakkan</div>
                <p class="mb-3 text-xs text-gray-600">
                    Centang akun beban yang <b>boleh</b> dibebani dana ini. Jurnal yang membebankan akun di luar daftar
                    akan <b>ditolak</b>. Dibiarkan kosong berarti tak ada pembatasan akun —
                    <span x-show="jenis !== 'tidak_terikat'" x-cloak class="font-medium text-amber-800">untuk dana terikat, itu berarti peruntukannya hanya tertulis dan tidak dijaga mesin.</span>
                </p>

                @if ($bebanOptions === [])
                    <p class="text-xs text-gray-500">Belum ada akun beban aktif di Chart of Account.</p>
                @else
                    <div class="max-h-64 space-y-1 overflow-y-auto rounded border border-gray-200 bg-white p-3">
                        @foreach ($bebanOptions as $kode => $label)
                            <label class="flex items-center gap-2 text-xs text-gray-700">
                                <input type="checkbox" name="akun[]" value="{{ $kode }}"
                                       @checked(in_array($kode, old('akun', $akunTerpilih), true))
                                       class="rounded border-gray-300 text-brand focus:ring-brand">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('dana.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
