@extends('layouts.app')

@section('title', 'Rekonsiliasi Kartu Santri')

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $label = fn ($s) => \App\Http\Controllers\RekonsiliasiKartuController::label($s);
        $boleh = \App\Support\Akses::boleh('rekonsiliasi-kartu', 'ubah');
    @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Tiap malam (00:40) belanja kartu santri kemarin dicocokkan per nomor dengan catatan ERP Al Wafi.
        <b>Potongan tanpa struk</b> — uang saku terpotong padahal struknya tak tersimpan — dikembalikan otomatis ke santri.
        Selisih lain (uang kembali tanpa retur di toko, struk kartu yang tak dikenal Al Wafi) ditandai di sini untuk diputus.
    </p>

    @if ($belumDitangani > 0)
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <b>{{ $belumDitangani }}</b> tanggal perlu diputus dan belum ditangani.
        </div>
    @endif

    @if ($boleh)
        <form method="POST" action="{{ route('rekonsiliasi_kartu.jalankan') }}" class="mb-5 flex flex-wrap items-end gap-2 text-sm">
            @csrf
            <div><label class="mb-1 block text-xs text-gray-500">Jalankan untuk tanggal</label>
                <input type="date" name="tanggal" value="{{ now()->subDay()->toDateString() }}" max="{{ now()->subDay()->toDateString() }}" required class="rounded-lg border-gray-300 text-sm"></div>
            <button class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">Jalankan sekarang</button>
            <span class="text-xs text-gray-400">Aman diulang — pengembalian tak pernah terjadi dua kali.</span>
        </form>
    @endif

    <div class="space-y-3">
        @forelse ($rows as $r)
            <div @class(['rounded-xl border bg-white p-4 text-sm',
                         'border-red-300' => $r->status === 'perlu_diputus' && ! $r->ditangani_pada,
                         'border-amber-300' => $r->status === 'gagal',
                         'border-gray-200' => ! in_array($r->status, ['perlu_diputus', 'gagal'], true) || $r->ditangani_pada])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-semibold text-gray-900">{{ $r->tanggal->translatedFormat('l, d M Y') }}</div>
                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                  'bg-emerald-100 text-emerald-800' => $r->status === 'cocok',
                                  'bg-sky-100 text-sky-800' => $r->status === 'dibereskan',
                                  'bg-red-100 text-red-800' => $r->status === 'perlu_diputus',
                                  'bg-amber-100 text-amber-800' => $r->status === 'gagal'])>{{ $label($r->status) }}</span>
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    {{ $r->jumlah_transaksi }} transaksi · bersih di Al Wafi {{ $rp($r->bersih_al_wafi) }}
                    @if ($r->jumlah_yatim) · <span class="text-sky-700">{{ $r->jumlah_yatim }} potongan tanpa struk dikembalikan ({{ $rp($r->nilai_yatim) }})</span>@endif
                    · dijalankan {{ $r->dijalankan_pada->format('d/m H:i') }}
                </div>
                @if ($r->pesan)<div class="mt-1 text-xs text-amber-800">{{ $r->pesan }}</div>@endif

                @if ($r->rincian)
                    <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-100">
                        @foreach ($r->rincian as $b)
                            <div class="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-xs">
                                <div class="min-w-0">
                                    <div class="font-medium {{ $b['perlu_diputus'] ? 'text-red-700' : 'text-gray-800' }}">{{ $b['masalah'] }}</div>
                                    <div class="font-mono text-gray-500">{{ $b['nomor'] }} · {{ $b['jenis'] === 'potong' ? 'potong' : 'kembali' }}</div>
                                    <div class="text-gray-600">{{ $b['tindakan'] }}</div>
                                </div>
                                <div class="shrink-0 font-semibold tabular-nums">{{ $rp($b['nominal']) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($r->status === 'perlu_diputus')
                    @if ($r->ditangani_pada)
                        <div class="mt-2 text-xs text-gray-500">Ditangani {{ $r->penangan?->nama }} {{ $r->ditangani_pada->format('d/m H:i') }}: {{ $r->catatan_tangani }}</div>
                    @elseif ($boleh)
                        <form method="POST" action="{{ route('rekonsiliasi_kartu.tangani', $r->id) }}" class="mt-3 flex flex-wrap gap-2">
                            @csrf
                            <input type="text" name="catatan" required maxlength="250" placeholder="Apa yang sudah dilakukan?" class="min-w-[16rem] flex-1 rounded-lg border-gray-300 text-sm">
                            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">Tandai sudah ditangani</button>
                        </form>
                    @endif
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">Belum ada rekonsiliasi.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
