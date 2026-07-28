@extends('layouts.app')

@section('title', 'Laporan')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            ['neraca', 'Neraca', 'Posisi keuangan pada satu tanggal.'],
            ['laba-rugi', 'Laba Rugi', 'Pendapatan & beban dalam satu periode.'],
            ['perubahan-modal', 'Perubahan Modal', 'Mutasi ekuitas & laba berjalan.'],
            ['arus-kas', 'Arus Kas', 'Kas masuk & keluar per akun lawan.'],
            ['buku-besar', 'Buku Besar', 'Mutasi & saldo berjalan satu akun.'],
            ['aset', 'Laporan Aset', 'Aset tetap, akumulasi & nilai buku.'],
            ['persediaan', 'Laporan Persediaan', 'Stok & nilai persediaan.'],
            ['jurnal', 'Jurnal Mentah', 'Semua baris jurnal (export).'],
        ] as [$rute, $judul, $desc])
            <a href="{{ route('reports.' . str_replace('-', '_', $rute)) }}"
               class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow">
                <div class="text-base font-semibold text-gray-900">{{ $judul }}</div>
                <p class="mt-1 text-sm text-gray-500">{{ $desc }}</p>
            </a>
        @endforeach
    </div>
@endsection
