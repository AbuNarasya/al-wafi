@extends('layouts.app')

@section('title', 'Rekonsiliasi Buku Pembantu')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Rekonsiliasi Buku Pembantu &harr; Buku Besar</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">
                Tiap modul menyimpan rinciannya sendiri; buku besar hanya menyimpan totalnya. Keduanya harus sama besar.
                Halaman ini tidak memperbaiki apa pun — ia hanya menaruh kedua angka bersebelahan.
            </p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Posisi tanggal</label>
                <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
    </div>

    @if ($data['cocok'])
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <b>Semua pos cocok.</b> Tiap buku pembantu sama besar dengan akun buku besarnya per
            {{ \Illuminate\Support\Carbon::parse($asOf)->format('d/m/Y') }}.
        </div>
    @else
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <b>{{ $data['jumlah_selisih'] }} pos berselisih.</b> Baca dulu catatan pada pos yang bersangkutan —
            sebagian selisih punya sebab yang sudah diketahui dan bukan kerusakan pembukuan.
        </div>
    @endif

    <div class="space-y-3">
        @foreach ($data['baris'] as $b)
            <div class="rounded-xl border bg-white p-4 shadow-sm {{ $b['cocok'] ? 'border-gray-200' : 'border-red-200' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-900">{{ $b['label'] }}</span>
                            @if ($b['cocok'])
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">cocok</span>
                            @else
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">selisih</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $b['keterangan'] }}</p>
                    </div>
                    @if ($b['tautan'])
                        <a href="{{ $b['tautan'] }}" class="shrink-0 text-xs text-brand hover:underline">Buka rinciannya &rarr;</a>
                    @endif
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-gray-50 px-3 py-2">
                        <div class="text-xs text-gray-500">Buku pembantu</div>
                        <div class="text-sm font-semibold tabular-nums text-gray-900">@rp($b['saldo_pembantu'])</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3 py-2">
                        <div class="text-xs text-gray-500">Buku besar</div>
                        <div class="text-sm font-semibold tabular-nums text-gray-900">@rp($b['saldo_buku_besar'])</div>
                    </div>
                    <div class="rounded-lg px-3 py-2 {{ $b['cocok'] ? 'bg-gray-50' : 'bg-red-50' }}">
                        <div class="text-xs {{ $b['cocok'] ? 'text-gray-500' : 'text-red-600' }}">Selisih</div>
                        <div class="text-sm font-semibold tabular-nums {{ $b['cocok'] ? 'text-gray-900' : 'text-red-800' }}">@rp($b['selisih'])</div>
                    </div>
                </div>

                <div class="mt-2 text-xs text-gray-500">
                    Akun buku besar:
                    @if ($b['akun'] === [])
                        <span class="text-amber-700">belum ditentukan</span>
                    @else
                        <span class="text-gray-600">{{ implode(' · ', $service->namaAkun($b['akun'])) }}</span>
                    @endif
                    <span class="text-gray-400">(sisi normal {{ $b['sisi'] }})</span>
                </div>

                @if ($b['catatan'])
                    <div class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ $b['catatan'] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Saldo buku besar dihitung dari saldo pembuka ditambah seluruh mutasi jurnal sampai tanggal yang dipilih.
        Saldo buku pembantu adalah keadaan <b>saat ini</b> — karena modul-modul itu tidak menyimpan riwayat saldo per tanggal,
        memundurkan tanggal hanya memundurkan sisi buku besarnya.
    </p>
@endsection
