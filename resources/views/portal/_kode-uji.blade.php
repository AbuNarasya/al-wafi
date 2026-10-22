{{-- Mode uji (dev.erp): surel tak dikirim, jadi kodenya ditampilkan di sini. Lihat MasukWali::modeUji(). --}}
@if (session('kode_uji'))
    <div class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-center text-sm text-amber-900">
        <span class="block text-xs font-semibold uppercase tracking-wide">{{ __('Mode uji — surel tidak dikirim') }}</span>
        {{ __('Kode Anda:') }} <b class="text-xl tracking-[0.3em]">{{ session('kode_uji') }}</b>
    </div>
@endif
