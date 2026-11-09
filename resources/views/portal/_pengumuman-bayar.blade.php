{{-- Pengumuman metode pembayaran dari staf (MetodeBayar) + kabar bila tak satu pun cara bayar sedang tersedia.
     Isi pengumuman diketik staf — sengaja tidak diterjemahkan (keputusan user 22 Okt). --}}
@php $pengumumanBayar = \App\Support\MetodeBayar::pengumuman(); @endphp
@if ($pengumumanBayar)
    <div class="mt-4 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
        <p class="font-semibold">{{ __('Pengumuman pembayaran') }}</p>
        <p class="mt-1 whitespace-pre-line">{{ $pengumumanBayar }}</p>
    </div>
@endif
@if ($kosong ?? false)
    <div class="mt-4 rounded-3xl bg-white p-5 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
        {{ __('Cara pembayaran sedang tidak tersedia. Silakan coba lagi nanti atau hubungi bagian keuangan.') }}
    </div>
@endif
