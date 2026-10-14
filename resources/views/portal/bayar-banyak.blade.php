@extends('layouts.portal')

@section('title', 'Bayar sekaligus')
@section('kembali', route('portal.tagihan'))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $penuh = \App\Services\PortalWali\PembayaranPortal::PENUH;
    $label = ['dompet' => 'Dompet Wali', 'online' => 'Online (VA)', 'transfer' => 'Transfer + bukti'];
    $caraAwal = old('kode_rekening') && in_array('transfer', $cara, true) ? 'transfer' : $cara[0];
    $awal = collect($nominal)->map(fn ($n) => (int) $n)->all();
@endphp

@section('content')
  <div x-data="{ cara: @js($caraAwal), nominal: @js($awal), biaya: @js((int) $biaya), saldo: @js((float) $saldo),
                get total() { return Object.values(this.nominal).reduce((a, b) => a + (Number(b) || 0), 0) },
                rp(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID') } }">

    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Bayar {{ $tagihan->count() }} tagihan sekaligus</p>
        <div class="mt-3 divide-y divide-slate-100">
            @foreach ($tagihan as $t)
                @php $wajibPenuh = in_array($t->perilaku, $penuh, true); @endphp
                <div class="py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ \App\Http\Controllers\PortalKeluargaController::namaTagihan($t) }}</p>
                            @if ($ket = \App\Http\Controllers\PortalKeluargaController::keteranganTagihan($t))
                                <p class="text-xs text-slate-600">{{ $ket }}</p>
                            @endif
                            <p class="text-xs text-slate-500">{{ $t->santri->nama }} &middot; sisa {{ $rp($t->sisa) }}</p>
                        </div>
                        @if ($wajibPenuh)
                            <p class="shrink-0 font-bold tabular-nums text-slate-900">{{ $rp($nominal[$t->id]) }}</p>
                        @endif
                    </div>
                    @unless ($wajibPenuh)
                        <input type="number" step="1" min="1" max="{{ (int) $nominal[$t->id] }}" x-model.number="nominal[{{ $t->id }}]"
                               aria-label="Nominal {{ \App\Http\Controllers\PortalKeluargaController::namaTagihan($t) }}"
                               class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-base focus:border-brand focus:ring-brand">
                        <p class="mt-1 text-xs text-slate-500">Boleh dicicil, maksimal {{ $rp($nominal[$t->id]) }}.</p>
                    @endunless
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex justify-between border-t border-slate-200 pt-3 text-base font-bold text-slate-900">
            <span>Total</span><span class="tabular-nums" x-text="rp(total)"></span>
        </div>
    </div>

    @if (count($cara) > 1)
        <div @class(['mt-4 grid gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm', 'grid-cols-2' => count($cara) === 2, 'grid-cols-3' => count($cara) === 3])>
            @foreach ($cara as $c)
                <button type="button" @click="cara = @js($c)" :class="cara === @js($c) ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ $label[$c] }}</button>
            @endforeach
        </div>
    @endif

    {{-- Nominal per tagihan ikut terkirim di form mana pun yang dipakai. --}}
    @php $tersembunyi = '<template x-for="(n, id) in nominal" :key="id"><input type="hidden" :name="`nominal[${id}]`" :value="n"></template>'; @endphp

    @if (in_array('dompet', $cara, true))
        <form method="POST" action="{{ route('portal.bayar_banyak.dompet') }}" class="mt-4 space-y-3" data-no-confirm
              x-show="cara === 'dompet'" @if ($caraAwal !== 'dompet') x-cloak @endif
              x-data="pinPad(@js(\App\Services\PortalWali\SesiWali::punyaPin()), @js(route('portal.pin')))" @submit.prevent="buka()">
            @csrf
            {!! $tersembunyi !!}
            <div class="flex items-center justify-between rounded-3xl bg-gradient-to-br from-brand to-slate-900 p-4 text-white shadow-lg">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-blue-100/80">Saldo Dompet Wali</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $rp($saldo) }}</p>
                </div>
                <a href="{{ route('portal.dompet.isi') }}" class="shrink-0 rounded-full bg-accent px-3 py-1 text-xs font-bold text-slate-900">Isi saldo</a>
            </div>
            <p x-show="total > saldo" x-cloak class="rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                Saldo belum cukup untuk total ini. <a href="{{ route('portal.dompet.isi') }}" class="font-semibold underline">Isi Dompet Wali</a> dulu.
            </p>
            <button :disabled="total > saldo" class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white disabled:opacity-50">
                Bayar <span x-text="rp(total)"></span> dari Dompet
            </button>
            <p class="text-center text-xs text-slate-500">Semua tagihan terbayar bersamaan, atau tidak sama sekali.</p>
            @include('portal._pin', ['judul' => 'Bayar '.$tagihan->count().' tagihan dari Dompet Wali'])
        </form>
    @endif

    @if (in_array('online', $cara, true))
        <form method="POST" action="{{ route('portal.bayar_banyak.online') }}" class="mt-4 space-y-3" data-no-confirm
              x-show="cara === 'online'" @if ($caraAwal !== 'online') x-cloak @endif>
            @csrf
            {!! $tersembunyi !!}
            <div class="rounded-3xl bg-white p-4 text-sm shadow-sm ring-1 ring-slate-200">
                <div class="flex justify-between text-slate-600"><span>Total tagihan</span><span class="tabular-nums" x-text="rp(total)"></span></div>
                <div class="mt-1 flex justify-between text-slate-600"><span>Biaya layanan Virtual Account (sekali)</span><span class="tabular-nums" x-text="rp(biaya)"></span></div>
                <div class="mt-2 flex justify-between border-t border-slate-100 pt-2 font-bold text-slate-900">
                    <span>Total dibayar</span><span class="tabular-nums" x-text="rp(total + biaya)"></span>
                </div>
            </div>
            <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Lanjut ke pembayaran</button>
            <p class="text-center text-xs text-slate-500">Satu nomor Virtual Account untuk semua tagihan di atas. Dicatat otomatis begitu dibayar.</p>
        </form>
    @endif

    @if (in_array('transfer', $cara, true))
        <form method="POST" action="{{ route('portal.bayar_banyak.transfer') }}" enctype="multipart/form-data" class="mt-4"
              x-show="cara === 'transfer'" @if ($caraAwal !== 'transfer') x-cloak @endif
              data-confirm="Kirim bukti transfer ini? Keuangan memeriksa total transfernya untuk semua tagihan sekaligus.">
            @csrf
            {!! $tersembunyi !!}
            @include('portal._transfer', ['tanpaNominal' => true])
            @if ($rekening !== [])
                <p class="mt-4 rounded-2xl bg-blue-50 px-4 py-3 text-sm text-slate-700">
                    Transfer <b>tepat <span x-text="rp(total)"></span></b> dalam satu kali transfer, lalu kirim satu bukti.
                </p>
                <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim bukti transfer</button>
            @endif
        </form>
    @endif
  </div>
@endsection
