@extends('layouts.app')

@section('title', 'Batch Tagihan')

@php
    $warna = [
        'draft' => 'bg-gray-100 text-gray-700',
        'diotorisasi' => 'bg-amber-100 text-amber-800',
        'dirilis' => 'bg-emerald-100 text-emerald-700',
        'sebagian' => 'bg-blue-50 text-blue-700',
        'gagal' => 'bg-red-100 text-red-700',
        'dibatalkan' => 'bg-gray-100 text-gray-500',
    ];
    $label = [
        'draft' => 'Draft',
        'diotorisasi' => 'Menunggu rilis',
        'dirilis' => 'Dirilis',
        'sebagian' => 'Sebagian',
        'gagal' => 'Gagal',
        'dibatalkan' => 'Dibatalkan',
    ];
@endphp

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Menyusun tagihan lebih dulu, memeriksanya dengan tenang, lalu menerbitkannya &mdash; sekarang atau
        pada waktu yang Anda tetapkan. Selama masih draft atau menunggu rilis, <b>belum ada satu pun tagihan
        maupun jurnal yang lahir</b>.
    </p>
    <p class="mb-4 text-xs text-gray-400">
        Penerbitan langsung tetap ada di modulnya masing-masing. Batch dipakai bila hasilnya perlu diperiksa
        dulu, atau bila rilisnya harus tetap jalan saat petugasnya libur.
    </p>

    {{-- Tugas yang masih menunggu orang ini. Ditaruh DI SINI, bukan hanya di
         lonceng: yang diminta pengingat ini justru menyusun sesuatu yang belum
         ada, dan halaman inilah tempat menyusunnya. --}}
    @foreach ($pengingat as $p)
        <div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-amber-900">{{ $p['judul'] }}</div>
                <div class="text-xs text-amber-800">{{ $p['pesan'] }}</div>
            </div>
            <form method="POST" action="{{ route('pengingat_terbit.konfirmasi') }}" data-no-confirm>
                @csrf
                <input type="hidden" name="id_jadwal" value="{{ $p['id_jadwal'] }}">
                <input type="hidden" name="periode" value="{{ $p['periode'] }}">
                <button type="submit"
                        class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                    Sudah saya kerjakan
                </button>
            </form>
        </div>
    @endforeach

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if ($bolehSusun !== [])
            <a href="{{ route('batch_tagihan.susun') }}"
               class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                Susun Batch Baru
            </a>
        @endif

        <a href="{{ route('pengingat_terbit.index') }}"
           class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Jadwal Pengingat
        </a>

        <form method="GET" class="ml-auto">
            <select name="status" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                <option value="">Semua status</option>
                @foreach ($label as $kode => $teks)
                    <option value="{{ $kode }}" @selected($status === $kode)>{{ $teks }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if ($daftar->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
            Belum ada batch tagihan.
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Batch</th>
                        <th class="px-4 py-3">Modul</th>
                        <th class="px-4 py-3 text-right">Baris</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Rilis</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Disusun</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($daftar as $b)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('batch_tagihan.show', $b->id) }}"
                                   class="font-medium text-brand hover:underline">{{ $b->judul }}</a>
                                @if ($b->catatan)
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $b->catatan }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $b->labelModul() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $b->jumlah_baris }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">@rp($b->total)</td>
                            <td class="px-4 py-3 text-gray-600">
                                @if ($b->rilis_pada)
                                    {{ $b->rilis_pada->format('d/m/Y H:i') }}
                                @else
                                    <span class="text-gray-400">manual</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$b->status] ?? '' }}">
                                    {{ $label[$b->status] ?? $b->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                {{ $b->penyusun?->nama }}<br>
                                {{ $b->created_at?->format('d/m/Y H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif
@endsection
