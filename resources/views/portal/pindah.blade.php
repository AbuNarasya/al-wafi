@extends('layouts.portal')

@section('title', 'Pindah saldo')
@section('kembali', route('portal.dompet'))

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white shadow-sm"><x-portal.ikon nama="dompet" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">Pindah saldo</h1>
            <p class="text-sm text-slate-500">Dompet Wali {{ $rp($saldoWali) }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('portal.dompet.pindah') }}" data-no-confirm class="space-y-4"
          x-data="{ ...pinPad(@js(\App\Services\PortalWali\SesiWali::punyaPin()), @js(route('portal.pin'))),
              anak: @js($anak->map(fn ($a) => ['id' => $a->id, 'nama' => $a->nama, 'santri' => (float) ($a->dompet?->saldo ?? 0), 'tabungan' => (float) ($a->tabungan?->saldo ?? 0)])->values()),
              wali: {{ (float) $saldoWali }}, idSantri: {{ $anak->first()->id }}, arah: 'wali_santri',
              get a() { return this.anak.find(x => x.id == this.idSantri) },
              get saldoAsal() { return this.arah.startsWith('wali') ? this.wali : this.a.santri },
              rp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID') },
          }" @submit.prevent="buka()">
        @csrf

        {{-- Urutan: ARAH dulu, baru ANAK — wali memutuskan "ke mana uangnya" sebelum "anak yang mana". --}}
        <div>
            <p class="mb-2 text-sm font-medium text-slate-700">Pindahkan</p>
            <div class="space-y-2">
                @foreach ($arah as $kode => [$dari, $ke, $label])
                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-white px-4 py-3 text-sm shadow-sm ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-brand">
                        <input type="radio" name="arah" value="{{ $kode }}" x-model="arah" class="text-brand focus:ring-brand">
                        <span class="font-medium text-slate-900">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500">Tabungan tak bisa dipindah langsung &mdash; <a href="{{ route('portal.dompet.tabungan') }}" class="font-semibold text-brand">ajukan penarikan tabungan</a>.</p>
        </div>

        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <label class="mb-1 block text-sm font-medium text-slate-700">Anak</label>
            <select name="id_santri" x-model="idSantri" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base">
                @foreach ($anak as $a)
                    <option value="{{ $a->id }}">{{ $a->nama }}</option>
                @endforeach
            </select>
            <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600">
                <div class="rounded-2xl bg-slate-50 px-3 py-2">Uang saku<br><b class="text-sm text-slate-900" x-text="rp(a.santri)"></b></div>
                <div class="rounded-2xl bg-slate-50 px-3 py-2">Tabungan<br><b class="text-sm text-slate-900" x-text="rp(a.tabungan)"></b></div>
            </div>
        </div>

        <div>
            <label for="nominal" class="mb-1 block text-sm font-medium text-slate-700">Nominal</label>
            <input id="nominal" name="nominal" type="number" min="1" step="1" required :max="saldoAsal"
                   value="{{ old('nominal') }}" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
            <p class="mt-1 text-xs text-slate-500">Saldo asal: <span x-text="rp(saldoAsal)"></span></p>
        </div>

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Pindahkan</button>

        @include('portal._pin', ['judul' => 'Konfirmasi pemindahan saldo'])
    </form>
@endsection
