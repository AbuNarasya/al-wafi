@extends('layouts.app')

@section('title', 'Rekap Pembayaran Santri')

@section('content')
    <p class="mb-3 text-sm text-gray-500">Pilih santri untuk melihat seluruh tagihan &amp; riwayat pembayarannya (registrasi, uang pangkal, SPP, tagihan lain).</p>

    <form method="GET" id="filterRekap"></form>
    <div class="mb-4">
        <x-filter-server placeholder="Cari nama / no. pendaftaran / NIS…" :total="$rows->count()"
                         :reset="route('rekap_pembayaran.index')" :aktif="$q !== ''" form="filterRekap" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">No. Daftar / NIS</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Pembayaran</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $s)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $s->no_pendaftaran }}<div class="text-xs text-gray-400">{{ $s->nis ?? '—' }}</div></td>
                        <td class="px-4 py-3">{{ $s->nama }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ ucfirst(str_replace('_', ' ', $s->status)) }}</span></td>
                        <td class="px-4 py-3"><x-status-bayar :info="$bayar[$s->id] ?? null" /></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('rekap_pembayaran.show', $s->id) }}" class="text-brand hover:underline">Lihat Rekap</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">
                        {{ $q !== '' ? 'Tidak ada santri yang cocok dengan pencarian.' : 'Belum ada data santri.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows->count() >= 100)
        <p class="mt-2 text-xs text-gray-400">Menampilkan 100 santri pertama — persempit dengan pencarian.</p>
    @endif
@endsection
