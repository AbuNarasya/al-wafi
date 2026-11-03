@extends('layouts.app')

@section('title', 'Pos Keluar/Masuk Santri')

@php $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—'; @endphp

@section('content')
    <a href="{{ route('perizinan.index') }}" class="text-sm text-brand hover:underline">&larr; Perizinan Insidental</a>

    @unless ($santri)
        {{-- Sama dengan laundry: tap kartu RFID; kartu tertinggal → cari nama/NIS (dicatat "manual"). --}}
        @include('laundry._kenali', ['rute' => 'perizinan.pos', 'judul' => 'Pos keluar/masuk — tap kartu santri'])
    @else
        <div class="mx-auto mt-4 max-w-2xl space-y-3">
            <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-base font-semibold text-gray-900">{{ $santri->nama }} <span class="text-sm font-normal text-gray-500">{{ $santri->nis }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></p>
                <a href="{{ route('perizinan.pos') }}" class="text-xs text-brand hover:underline">santri lain</a>
            </div>
            @if ($cara === 'manual')<p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Tanpa kartu — dicatat sebagai pencarian manual.</p>@endif

            @forelse ($izin as $z)
                <div @class(['rounded-xl border bg-white p-4 text-sm shadow-sm', 'border-red-300' => $z->terlambat(), 'border-gray-200' => ! $z->terlambat()])>
                    <p class="font-semibold text-gray-900">{{ $z->tipe?->nama }} <span class="font-mono text-xs font-normal text-gray-500">{{ $z->nomor }}</span></p>
                    @if ($z->keperluan)<p class="text-gray-600">{{ $z->keperluan }}</p>@endif
                    <p class="mt-1 text-gray-600">Keluar {{ $jam($z->rencana_keluar) }} · kembali {{ $jam($z->rencana_kembali) }}</p>
                    @if ($z->penjemput_nama)<p class="text-gray-600">Penjemput: {{ $z->penjemput_nama }}{{ $z->penjemput_hubungan ? ' ('.$z->penjemput_hubungan.')' : '' }}{{ $z->penjemput_hp ? ' · '.$z->penjemput_hp : '' }}</p>@endif
                    @if ($z->terlambat())<p class="mt-1 font-semibold text-red-700">Terlambat kembali.</p>@endif
                    <form method="POST" action="{{ route('perizinan.catat_pos', $z->id) }}" data-no-confirm class="mt-3">
                        @csrf
                        <input type="hidden" name="cara" value="{{ $cara }}">
                        @if ($z->status === 'disetujui')
                            <input type="hidden" name="aksi" value="keluar">
                            <button class="rounded-lg bg-violet-600 px-4 py-2 font-semibold text-white">Catat KELUAR</button>
                        @else
                            <input type="hidden" name="aksi" value="kembali">
                            <span class="me-2 text-gray-500">Keluar {{ $jam($z->keluar_pada) }}</span>
                            <button class="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white">Catat KEMBALI</button>
                        @endif
                    </form>
                </div>
            @empty
                <p class="rounded-xl border border-red-200 bg-red-50 p-6 text-center text-sm font-semibold text-red-800">Tidak ada izin yang disetujui untuk santri ini — tidak boleh keluar.</p>
            @endforelse
        </div>
    @endunless
@endsection
