@extends('layouts.app')

@section('title', 'Distribusi — '.$sesi->nama)

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $bolehBatal = \App\Support\Akses::boleh('sesi-tarik-tunai', 'hapus');
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('tarik_tunai.show', $sesi->id_sesi) }}" class="text-sm text-gray-500 hover:underline">← {{ $sesi->nama }}</a>
        <div class="text-sm text-gray-600">Diserahkan <b>{{ $sudah->count() }}</b> santri ({{ $rp($totalSerah) }}) · belum <b>{{ $belum->count() }}</b> · dana diambil {{ $rp($sesi->total_diambil) }}</div>
    </div>

    {{-- Pembaca RFID USB mengetik UID lalu Enter — formulir GET ini langsung terkirim. --}}
    <div class="mb-6 rounded-xl border-2 border-brand/30 bg-white p-5 shadow-sm">
        @if ($siap)
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Serahkan kepada</div>
                    <div class="text-2xl font-bold text-gray-900">{{ $siap->santri->nama }}</div>
                    <div class="text-sm text-gray-500">{{ $siap->santri->nis }} · {{ $siap->nomor }}</div>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold tabular-nums text-emerald-700">{{ $rp($siap->nominal_disetujui) }}</div>
                    @php $pc = $siap->pecahan(); @endphp
                    <div class="text-xs text-gray-500">{{ $pc['100000'] ? $pc['100000'].' × 100rb' : '' }}{{ $pc['100000'] && $pc['50000'] ? ' + ' : '' }}{{ $pc['50000'] ? $pc['50000'].' × 50rb' : '' }}</div>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <form method="POST" action="{{ route('tarik_tunai.serah', $sesi->id_sesi) }}" class="flex-1" data-no-confirm>
                    @csrf<input type="hidden" name="uid" value="{{ request('uid') }}">
                    <button autofocus class="w-full rounded-lg bg-emerald-600 py-3 text-base font-bold text-white hover:bg-emerald-700">Serahkan {{ $rp($siap->nominal_disetujui) }}</button>
                </form>
                <a href="{{ route('tarik_tunai.distribusi', $sesi->id_sesi) }}" class="rounded-lg border border-gray-300 px-4 py-3 text-sm">Batal</a>
            </div>
        @else
            <form method="GET" action="{{ route('tarik_tunai.distribusi', $sesi->id_sesi) }}">
                <label for="uid" class="mb-2 block text-sm font-semibold text-gray-800">Tempelkan kartu santri ke pembaca</label>
                <input id="uid" name="uid" type="password" autocomplete="off" autofocus required placeholder="Menunggu kartu…"
                       class="w-full rounded-lg border-gray-300 py-3 text-center text-lg tracking-widest">
            </form>
            @if ($galat)
                <p class="mt-3 rounded-lg bg-red-50 p-3 text-sm font-medium text-red-700">{{ $galat }}</p>
            @endif
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Belum diserahkan</div>
            <div class="divide-y divide-gray-100">
                @forelse ($belum as $p)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                        <div><div class="font-medium text-gray-900">{{ $p->santri->nama }}</div><div class="text-xs text-gray-500">{{ $p->santri->nis }}</div></div>
                        <div class="flex items-center gap-3">
                            <span class="font-semibold tabular-nums">{{ $rp($p->nominal_disetujui) }}</span>
                            <form method="POST" action="{{ route('tarik_tunai.serah_manual', $sesi->id_sesi) }}" x-data="{ panel: false }" class="relative" data-no-confirm>
                                @csrf<input type="hidden" name="id_pengajuan" value="{{ $p->id }}">
                                <button type="button" @click="panel = !panel" class="text-xs text-brand hover:underline">Tanpa kartu</button>
                                <div x-show="panel" x-cloak @click.outside="panel = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                    <input type="text" name="alasan" required placeholder="Alasan (mis. kartu hilang)" class="w-full rounded border-gray-300 text-sm">
                                    <button class="w-full rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">Serahkan {{ $rp($p->nominal_disetujui) }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-sm text-gray-400">Semua sudah diserahkan.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Sudah diserahkan</div>
            <div class="divide-y divide-gray-100">
                @forelse ($sudah as $p)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                        <div>
                            <div class="font-medium text-gray-900">{{ $p->santri->nama }}</div>
                            <div class="text-xs text-gray-500">{{ $p->diserahkan_pada->format('d/m H:i') }} · {{ $p->penyerah?->nama }} · {{ $p->cara_serah === 'kartu' ? 'kartu' : 'tanpa kartu: '.$p->alasan_manual }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-semibold tabular-nums">{{ $rp($p->nominal_disetujui) }}</span>
                            @if ($bolehBatal)
                                <form method="POST" action="{{ route('tarik_tunai.batal_serah', [$sesi->id_sesi, $p->id]) }}" x-data="{ panel: false }" class="relative" data-no-confirm>
                                    @csrf
                                    <button type="button" @click="panel = !panel" class="text-xs text-red-600 hover:underline">Batalkan</button>
                                    <div x-show="panel" x-cloak @click.outside="panel = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                        <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Batalkan penyerahan</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-sm text-gray-400">Belum ada.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
