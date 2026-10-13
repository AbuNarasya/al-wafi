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
        $caraAwal = $bisaTransfer && (! $bisaDompet || old('kode_rekening')) ? 'transfer' : 'dompet';
    @endphp

    <div x-data="{ cara: @js($caraAwal) }">
    {{-- Calon dari keluarga yang sudah ber-Dompet Wali: boleh pilih dompet ATAU transfer. --}}
    @if ($bisaDompet && $bisaTransfer)
        <div class="mt-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
            <button type="button" @click="cara = 'dompet'" :class="cara === 'dompet' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">Dari Dompet Wali</button>
            <button type="button" @click="cara = 'transfer'" :class="cara === 'transfer' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">Transfer bank</button>
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
              x-show="cara === 'transfer'" @if ($bisaDompet) x-cloak @endif
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
