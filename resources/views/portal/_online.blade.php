{{--
  Isian bayar online (Midtrans, Virtual Account). Dipakai Isi Dompet & bayar tagihan calon.
  Variabel: $aksi (URL POST), $nominalAwal, $min, $maks (null = tanpa batas atas), $biaya.
  Biaya layanan ditanggung wali — total ditampilkan SEBELUM tombol ditekan.
--}}
@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
@endphp

<form method="POST" action="{{ $aksi }}" class="space-y-3" data-no-confirm
      x-data="{ nominal: @js((int) old('nominal', $nominalAwal)), biaya: @js((int) $biaya),
                rp(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID') } }">
    @csrf
    <div>
        <label for="nominal_online" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Nominal') }}</label>
        <input id="nominal_online" name="nominal" type="number" step="1" required x-model.number="nominal"
               min="{{ (int) $min }}" @if ($maks !== null) max="{{ (int) $maks }}" @endif
               class="{{ $kelasInput }}">
        @if ($maks !== null)
            <p class="mt-1 text-xs text-slate-500">{{ __('Maksimal :nominal.', ['nominal' => 'Rp '.number_format((float) $maks, 0, ',', '.')]) }}</p>
        @else
            <p class="mt-1 text-xs text-slate-500">{{ __('Minimal :nominal.', ['nominal' => 'Rp '.number_format((float) $min, 0, ',', '.')]) }}</p>
        @endif
        @error('nominal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

    <div class="rounded-3xl bg-white p-4 text-sm shadow-sm ring-1 ring-slate-200">
        <div class="flex justify-between text-slate-600"><span>{{ __('Nominal') }}</span><span class="tabular-nums" x-text="rp(nominal)"></span></div>
        <div class="mt-1 flex justify-between text-slate-600"><span>{{ __('Biaya layanan Virtual Account') }}</span><span class="tabular-nums" x-text="rp(biaya)"></span></div>
        <div class="mt-2 flex justify-between border-t border-slate-100 pt-2 font-bold text-slate-900">
            <span>{{ __('Total dibayar') }}</span><span class="tabular-nums" x-text="rp((Number(nominal) || 0) + biaya)"></span>
        </div>
    </div>

    <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Lanjut ke pembayaran') }}</button>
    <p class="text-center text-xs text-slate-500">
        {{ __('Bayar lewat Virtual Account BCA, BNI, BRI, Mandiri, Permata, BSI, atau CIMB.') }}
        {{ __('Dicatat otomatis begitu pembayaran diterima — tanpa kirim bukti.') }}
    </p>
</form>
