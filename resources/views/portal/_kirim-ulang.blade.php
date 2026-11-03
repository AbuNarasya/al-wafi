{{--
  Tombol "Kirim ulang kode" — terbuka sesudah jeda MasukWali::JEDA_DETIK (3 menit, keputusan user 3 Nov 2026).
  Hitung mundurnya hanya penanda; jedanya ditegakkan server (MasukWali::mintaKode).
  Pakai: @include('portal._kirim-ulang', ['aksi' => route('…'), 'sisa' => $sisaJeda])
--}}
<form method="POST" action="{{ $aksi }}" data-no-confirm class="mt-4 text-center"
      x-data="{ sisa: {{ (int) $sisa }} }"
      x-init="let t = setInterval(() => { sisa > 0 ? sisa-- : clearInterval(t) }, 1000)">
    @csrf
    <button type="submit" :disabled="sisa > 0"
            class="text-sm font-semibold text-brand underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline">
        <span x-show="sisa > 0" @if ((int) $sisa <= 0) style="display:none" @endif>{{ __('Kode belum masuk? Kirim ulang dalam') }}
            <span class="tabular-nums" x-text="String(Math.floor(sisa / 60)).padStart(2, '0') + ':' + String(sisa % 60).padStart(2, '0')">{{ sprintf('%02d:%02d', intdiv((int) $sisa, 60), (int) $sisa % 60) }}</span></span>
        <span x-show="sisa <= 0" @if ((int) $sisa > 0) style="display:none" @endif>{{ __('Kode belum masuk? Kirim ulang kode') }}</span>
    </button>
</form>
