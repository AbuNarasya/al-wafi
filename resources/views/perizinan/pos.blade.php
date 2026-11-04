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
                    <p class="font-semibold text-gray-900">{{ $z->tipe?->nama }}@if ($z->id_jadwal) <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-normal text-indigo-700">rutin</span>@endif <span class="font-mono text-xs font-normal text-gray-500">{{ $z->nomor }}</span></p>
                    @if ($z->keperluan)<p class="text-gray-600">{{ $z->keperluan }}</p>@endif
                    <p class="mt-1 text-gray-600">Keluar {{ $jam($z->rencana_keluar) }} · kembali {{ $jam($z->rencana_kembali) }}</p>
                    @if ($z->penjemput_nama || $z->penjemput_jenis)<p class="mt-1 text-gray-600">Penjemput: @include('perizinan._penjemput')</p>@endif
                    @if ($z->terlambat())<p class="mt-1 font-semibold text-red-700">Terlambat kembali.</p>@endif
                    @php
                        $aksi = $z->status === 'disetujui' ? 'keluar' : 'kembali';
                        // Kebijakan tunggakan (Tahap 3): tertahan kecuali diizinkan pemegang hak perizinan-tunggakan.
                        $tahan = \App\Services\Modules\TunggakanIzin::diPos($z, $aksi);
                        $bolehIzinkan = \App\Support\Akses::boleh(\App\Services\Modules\TunggakanIzin::MODUL_IZIN, 'ubah');
                    @endphp
                    @if ($tahan)
                        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 font-semibold text-red-800">
                            TERTAHAN — {{ \App\Services\Modules\TunggakanIzin::teks($tahan) }}.
                            <span class="block text-xs font-normal">{{ $aksi === 'keluar' ? 'Kebijakan: izin tidak diberikan selama menunggak.' : 'Kebijakan: belum boleh kembali sebelum tunggakan diselesaikan.' }}
                                @unless ($bolehIzinkan) Hubungi petugas yang berhak mengizinkan. @endunless</span>
                        </p>
                    @endif
                    @if (! $tahan || $bolehIzinkan)
                        <form method="POST" action="{{ route('perizinan.catat_pos', $z->id) }}" @if ($tahan) data-confirm="Izinkan {{ $aksi }} walau masih menunggak? Tercatat di jejak audit atas nama Anda." @else data-no-confirm @endif class="mt-3">
                            @csrf
                            <input type="hidden" name="cara" value="{{ $cara }}">
                            <input type="hidden" name="aksi" value="{{ $aksi }}">
                            @if ($tahan)
                                <input name="izin_tunggakan" required maxlength="500" placeholder="Alasan mengizinkan (wajib)" class="mb-2 w-full rounded-lg border-gray-300 text-sm">
                            @endif
                            @if ($aksi === 'keluar')
                                <button class="rounded-lg bg-violet-600 px-4 py-2 font-semibold text-white">{{ $tahan ? 'Izinkan keluar' : 'Catat KELUAR' }}</button>
                            @else
                                <span class="me-2 text-gray-500">Keluar {{ $jam($z->keluar_pada) }}</span>
                                <button class="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white">{{ $tahan ? 'Izinkan masuk' : 'Catat KEMBALI' }}</button>
                            @endif
                        </form>
                    @endif
                </div>
            @empty
                <p class="rounded-xl border border-red-200 bg-red-50 p-6 text-center text-sm font-semibold text-red-800">Tidak ada izin yang disetujui untuk santri ini — tidak boleh keluar.</p>
            @endforelse
        </div>
    @endunless
@endsection
