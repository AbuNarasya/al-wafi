{{--
  Lonceng notifikasi di header. Isinya dua bagian dengan perilaku BERBEDA:
    • Tugas — dihitung dari keadaan nyata (TugasSaya), jadi tak bisa dipadamkan
      dengan diklik; hilang sendiri saat dokumennya diproses.
    • Kabar — baris notifikasi biasa, boleh ditandai sudah dibaca.
  Sengaja hemat query (2 per halaman): angka tugas memakai memo TugasSaya yang
  juga dipakai sidebar, kabar hanya mengambil 5 terbaru yang belum dibaca.
--}}
@php
    $tugas = \App\Support\TugasSaya::daftar();
    $jumlahTugas = \App\Support\TugasSaya::total();
    $notif = new \App\Services\Modules\NotificationService;
    $kabar = $notif->kabarBelumDibaca(auth()->user()->id_pengguna, 5);
    $jumlahKabar = $notif->hitungKabarBelumDibaca(auth()->user()->id_pengguna);
    $total = $jumlahTugas + $jumlahKabar;
@endphp

<div x-data="{ buka: false }" class="relative">
    <button type="button" @click="buka = !buka" class="relative rounded p-1.5 text-gray-500 hover:bg-gray-100"
            aria-label="Notifikasi{{ $total > 0 ? " ({$total} baru)" : '' }}">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if ($total > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
                {{ $total > 99 ? '99+' : $total }}
            </span>
        @endif
    </button>

    <div x-show="buka" x-cloak @click.outside="buka = false"
         class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg">
        <div class="border-b border-gray-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            Notifikasi
        </div>

        @if ($jumlahTugas > 0)
            <div class="border-b border-gray-100">
                <div class="bg-amber-50 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                    Menunggu dikerjakan
                </div>
                @foreach ($tugas as $t)
                    <a href="{{ url($t['url']) }}" class="flex items-start gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                        <span class="mt-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-100 px-1 text-[11px] font-bold text-amber-700">{{ $t['jumlah'] }}</span>
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-gray-900">{{ $t['menu'] }}</span>
                            <span class="block text-xs text-gray-500">{{ $t['label'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        @forelse ($kabar as $n)
            <a href="{{ url('/notifikasi') }}" class="block border-b border-gray-50 px-3 py-2 text-sm hover:bg-gray-50">
                <span class="block font-medium text-gray-900">{{ $n->judul }}</span>
                <span class="mt-0.5 block text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($n->pesan, 90) }}</span>
                <span class="mt-0.5 block text-[11px] text-gray-400">{{ optional($n->created_at)->diffForHumans() }}</span>
            </a>
        @empty
            @if ($jumlahTugas === 0)
                <div class="px-3 py-6 text-center text-sm text-gray-400">Tidak ada notifikasi baru.</div>
            @endif
        @endforelse

        <a href="{{ url('/notifikasi') }}" class="block bg-gray-50 px-3 py-2 text-center text-xs font-medium text-brand hover:underline">
            Lihat semua notifikasi
        </a>
    </div>
</div>
