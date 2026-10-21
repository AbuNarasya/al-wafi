{{--
  Papan PIN 6 digit — diletakkan DI DALAM <form x-data="pinPad(...)" @submit.prevent="buka()" data-no-confirm>.
  Papan inilah konfirmasinya, jadi formulirnya tak memakai dialog konfirmasi bawaan.
  $judul (opsional) = apa yang dikonfirmasi, mis. "Bayar Rp 600.000".
--}}
<input type="hidden" name="pin" :value="pin">
<div x-show="terbuka" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/50" @keydown.escape.window="tutup()">
    <div class="w-full max-w-lg rounded-t-3xl bg-white p-5 pb-8 shadow-xl" @click.outside="tutup()" style="padding-bottom: calc(2rem + env(safe-area-inset-bottom))">
        <div class="flex items-center justify-between">
            <p class="text-lg font-bold text-slate-900">Masukkan PIN</p>
            <button type="button" x-show="! memproses" @click="tutup()" class="px-2 text-2xl leading-none text-slate-400" aria-label="Tutup">&times;</button>
        </div>
        @isset($judul)
            <p class="text-sm text-slate-500">{{ $judul }}</p>
        @endisset
        <div class="my-6 flex justify-center gap-3" aria-live="polite" :aria-label="pin.length + ' dari 6 angka'">
            <template x-for="i in 6" :key="i">
                <span class="h-4 w-4 rounded-full ring-2 ring-brand" :class="i <= pin.length ? 'bg-brand' : 'bg-transparent'"></span>
            </template>
        </div>
        {{-- PIN lengkap & sedang dikirim: papan angka berganti animasi supaya jeda server tak terlihat macet. --}}
        <div x-show="memproses" x-cloak class="flex flex-col items-center justify-center gap-3 py-10" role="status" aria-live="polite">
            <svg class="h-10 w-10 animate-spin text-brand" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <p class="text-sm font-semibold text-slate-700">Memproses transaksi…</p>
            <p class="text-xs text-slate-500">Mohon tunggu, jangan tutup halaman ini.</p>
        </div>
        <div x-show="! memproses" class="mx-auto grid max-w-xs grid-cols-3 gap-3">
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d)
                <button type="button" @click="ketik('{{ $d }}')" class="h-14 rounded-2xl bg-slate-100 text-xl font-bold text-slate-900 active:bg-slate-200">{{ $d }}</button>
            @endforeach
            <a href="{{ route('portal.pin') }}" class="flex h-14 items-center justify-center text-xs font-semibold text-brand">Lupa PIN</a>
            <button type="button" @click="ketik('0')" class="h-14 rounded-2xl bg-slate-100 text-xl font-bold text-slate-900 active:bg-slate-200">0</button>
            <button type="button" @click="hapus()" class="flex h-14 items-center justify-center rounded-2xl text-slate-600" aria-label="Hapus">
                <x-portal.ikon nama="kembali" />
            </button>
        </div>
    </div>
</div>
