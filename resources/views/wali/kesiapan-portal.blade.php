@extends('layouts.app')

@section('title', 'Kesiapan Portal Wali')

@section('content')
    @php
        $bolehUbah = \App\Support\Akses::boleh('wali', 'ubah');
        $label = \App\Models\Wali::PERAN;
        $belum = $perlu - $siap;
    @endphp

    <a href="{{ route('wali.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Wali / Keluarga</a>

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Wali masuk portal dengan <b>email</b> &mdash; kode masuknya dikirim ke sana. Halaman ini menunjukkan keluarga
        yang <b>belum bisa masuk</b> dan sebabnya. Perbaikannya lewat tombol <b>Ubah</b> di tiap baris.
        Yang dihitung hanya keluarga dengan anak <b>aktif atau masih dalam proses PSB</b>.
    </p>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-gray-500">Keluarga yang perlu bisa masuk</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($perlu, 0, ',', '.') }}</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
            <div class="text-xs text-emerald-700">Sudah bisa masuk</div>
            <div class="mt-1 text-2xl font-semibold text-emerald-800">{{ number_format($siap, 0, ',', '.') }}</div>
        </div>
        <div @class(['rounded-xl border p-4 shadow-sm', 'border-amber-200 bg-amber-50' => $belum > 0, 'border-gray-200 bg-white' => $belum === 0])>
            <div @class(['text-xs', 'text-amber-700' => $belum > 0, 'text-gray-500' => $belum === 0])>Belum bisa masuk</div>
            <div @class(['mt-1 text-2xl font-semibold', 'text-amber-800' => $belum > 0, 'text-gray-900' => $belum === 0])>{{ number_format($belum, 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- 1. Email kembar --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-4 py-3">
            <div class="text-sm font-semibold text-gray-700">Email dipakai lebih dari satu keluarga <span class="font-normal text-gray-400">({{ count($kembar) }} alamat)</span></div>
            <p class="mt-0.5 text-xs text-gray-500">
                Masuk dengan alamat ini <b>ditolak</b>: dompet melekat per keluarga, jadi portal tak boleh menebak keluarga yang mana.
                Ganti email di salah satu keluarga, atau &mdash; bila ternyata keluarga yang sama tercatat dua kali &mdash; satukan datanya.
            </p>
        </div>
        @forelse ($kembar as $k)
            <div class="border-t border-gray-100 px-4 py-3 first:border-t-0">
                <div class="mb-2 font-medium text-gray-900">{{ $k['email'] }}</div>
                <div class="space-y-2">
                    @foreach ($k['keluarga'] as $w)
                        <div class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                            <div>
                                <span class="font-medium text-gray-800">{{ $w['nama'] ?? '—' }}</span>
                                <span class="text-xs text-gray-500">&middot; {{ $w['telepon'] ?? '' }} &middot; tercatat sebagai email
                                    {{ collect($w['peran'])->map(fn ($p) => $label[$p] ?? $p)->implode(' & ') }}</span>
                                <div class="text-xs text-gray-500">
                                    Anak: {{ collect($w['anak'] ?? [])->map(fn ($a) => $a['nama'].' ('.$a['status'].')')->implode(', ') ?: 'tak ada yang aktif' }}
                                </div>
                            </div>
                            @if ($bolehUbah && isset($w['id']))
                                <a href="{{ route('wali.edit', ['id' => $w['id'], 'dari' => 'kesiapan']) }}" class="text-brand hover:underline">Ubah</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm text-gray-400">Tak ada email kembar.</div>
        @endforelse
    </div>

    {{-- 2. Tanpa email --}}
    <div x-data="rowFilter" x-cloak class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <div>
                <div class="text-sm font-semibold text-gray-700">Keluarga tanpa email <span class="font-normal text-gray-400">({{ count($tanpa_email) }})</span></div>
                <p class="mt-0.5 text-xs text-gray-500">Isi email ayah, ibu, atau wali &mdash; siapa pun yang akan memakai portal.</p>
            </div>
            @if (count($tanpa_email) > 0)
                <x-filter-bar placeholder="Cari nama / telepon / anak…" />
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Keluarga</th><th class="px-4 py-3">Telepon</th><th class="px-4 py-3">Anak</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($tanpa_email as $w)
                        <tr data-row class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $w['nama'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $w['telepon'] ?? '' }}</td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ collect($w['anak'] ?? [])->map(fn ($a) => $a['nama'].' ('.$a['status'].')')->implode(', ') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($bolehUbah)
                                    <a href="{{ route('wali.edit', ['id' => $w['id'], 'dari' => 'kesiapan']) }}" class="text-brand hover:underline">Ubah</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Semua keluarga sudah punya email.</td></tr>
                    @endforelse
                    <tr data-empty style="display:none"><td colspan="4" class="px-4 py-6 text-center text-gray-400">Tidak ada yang cocok dengan pencarian.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
