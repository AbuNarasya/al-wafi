@extends('layouts.portal')

@section('title', 'Bayar tagihan')
@section('kembali', route('portal.tagihan', ['anak' => $tagihan->id_santri]))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $nama = \App\Http\Controllers\PortalKeluargaController::namaTagihan($tagihan);
@endphp

@section('content')
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm text-slate-500">{{ $tagihan->santri->nama }}</p>
        <p class="font-semibold text-slate-900">{{ $nama }}</p>
        <p class="mt-2 text-xs font-semibold uppercase tracking-widest text-slate-500">Sisa tagihan</p>
        <p class="text-3xl font-bold tabular-nums text-slate-900">{{ $rp($tagihan->sisa) }}</p>
        @if ((float) $ruang < (float) $tagihan->sisa)
            <p class="mt-1 text-sm font-medium text-amber-700">{{ $rp((float) $tagihan->sisa - (float) $ruang) }} sedang menunggu verifikasi.</p>
        @endif
    </div>

    @php
        $bisaDompet = \App\Services\PortalWali\PembayaranPortal::bisaDompet($alur);
        $bisaTransfer = \App\Services\PortalWali\PembayaranPortal::bisaTransfer($alur);
        // Kembali dari galat isian transfer → tetap di pilihan transfer.
        $caraAwal = match (true) {
            $bisaTransfer && old('kode_rekening') !== null => 'transfer',
            $bisaDompet => 'dompet',
            $online => 'online',
            default => 'transfer',
        };
        $pilihan = array_filter([
            'dompet' => $bisaDompet ? 'Dompet Wali' : null,
            'online' => $online ? 'Online (VA)' : null,
            'transfer' => $bisaTransfer ? 'Transfer + bukti' : null,
        ]);
    @endphp

    @if ($pesanan)
        <a href="{{ route('portal.online.lihat', $pesanan->order_id) }}" class="mt-4 flex items-center justify-between gap-2 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
            <span>Pembayaran online {{ $rp($pesanan->total) }} menunggu dibayar</span>
            <span class="font-semibold">Lanjutkan &rarr;</span>
        </a>
    @endif

    <div x-data="{ cara: @js($caraAwal) }">
    {{-- Lebih dari satu cara bayar (dompet / online / transfer): wali memilih. --}}
    @if (count($pilihan) > 1)
        <div @class(['mt-4 grid gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm', 'grid-cols-2' => count($pilihan) === 2, 'grid-cols-3' => count($pilihan) === 3])>
            @foreach ($pilihan as $kode => $label)
                <button type="button" @click="cara = @js($kode)" :class="cara === @js($kode) ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($online)
        <div class="mt-4" x-show="cara === 'online'" @if ($caraAwal !== 'online') x-cloak @endif>
            @include('portal._online', ['aksi' => route('portal.online.tagihan', $tagihan->id), 'nominalAwal' => (int) $ruang,
                'min' => 1, 'maks' => (int) $ruang, 'biaya' => $biaya])
        </div>
    @endif

    @if ($bisaDompet)
      <div x-show="cara === 'dompet'">
        <div class="mt-4 flex items-center justify-between rounded-3xl bg-gradient-to-br from-brand to-slate-900 p-4 text-white shadow-lg">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-blue-100/80">Saldo Dompet Wali</p>
                <p class="text-2xl font-bold tabular-nums">{{ $rp($saldo) }}</p>
            </div>
            <a href="{{ route('portal.dompet.isi') }}" class="shrink-0 self-center whitespace-nowrap rounded-full bg-accent px-3 py-1 text-xs font-bold leading-5 text-slate-900">Isi saldo</a>
        </div>

        @if ((float) $saldo <= 0 || ($penuh && (float) $saldo < (float) $tagihan->sisa))
            <div class="mt-4 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                Saldo belum cukup{{ $penuh ? ' untuk melunasi tagihan ini sekaligus' : '' }}.
                <a href="{{ route('portal.dompet.isi') }}" class="font-semibold underline">Isi Dompet Wali</a> dulu.
            </div>
        @else
            <form method="POST" action="{{ route('portal.bayar.dompet', $tagihan->id) }}" class="mt-4 space-y-3" data-no-confirm
                  x-data="pinPad(@js(\App\Services\PortalWali\SesiWali::punyaPin()), @js(route('portal.pin')))" @submit.prevent="buka()">
                @csrf
                <div>
                    <label for="nominal" class="mb-1 block text-sm font-medium text-slate-700">Nominal dibayar</label>
                    @if ($penuh)
                        <input type="hidden" name="nominal" value="{{ (float) $tagihan->sisa }}">
                        <div class="rounded-2xl bg-slate-50 px-4 py-3 text-base font-semibold text-slate-900">{{ $rp($tagihan->sisa) }}</div>
                        <p class="mt-1 text-xs text-slate-500">Tagihan ini harus dilunasi sekaligus.</p>
                    @else
                        <input id="nominal" name="nominal" type="number" min="1" step="1" required
                               max="{{ (int) min((float) $ruang, (float) $saldo) }}"
                               value="{{ old('nominal', (int) min((float) $ruang, (float) $saldo)) }}"
                               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
                        <p class="mt-1 text-xs text-slate-500">Boleh dicicil. Maksimal {{ $rp(min((float) $ruang, (float) $saldo)) }}.</p>
                    @endif
                    @error('nominal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
                <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Bayar dari Dompet</button>
                <p class="text-center text-xs text-slate-500">Saldo langsung terpotong setelah PIN benar.</p>

                @include('portal._pin', ['judul' => 'Bayar '.$nama.' dari Dompet Wali'])
            </form>
        @endif
      </div>
    @endif

    @if ($bisaTransfer)
        <form method="POST" action="{{ route('portal.bayar.transfer', $tagihan->id) }}" enctype="multipart/form-data" class="mt-5"
              x-show="cara === 'transfer'" @if ($caraAwal !== 'transfer') x-cloak @endif
              data-confirm="Kirim bukti transfer ini? Keuangan akan memeriksanya sebelum pembayaran diakui.">
            @csrf
            @include('portal._transfer', ['nominalAwal' => (int) $ruang])
            @if ($rekening !== [])
                <button class="mt-5 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim bukti transfer</button>
            @endif
        </form>
    @endif
    </div>
@endsection
