{{--
  Pembungkus daftar master yang urutan barisnya bisa diseret.

    <x-urut-tabel :url="route('jenjang.urutan')" :boleh="$bolehUrut">
        … kotak cari + <table> …
    </x-urut-tabel>

  Kotak cari IKUT di dalam pembungkus supaya event input/change-nya sampai ke
  sini — dari situlah diketahui ada baris yang sedang tersembunyi filter, yang
  membuat pengubahan urutan dikunci (lihat `urutTabel` di app.js).

  `boleh` = hak UBAH modulnya. Tanpa hak itu pembungkusnya tak dipasang sama
  sekali; kolom pegangannya tetap dirender <x-urut-kepala>/<x-urut-sel> supaya
  nomor kolom filter (<x-fcol :col="…">) tidak bergeser antar pengguna.
--}}
@props(['url', 'boleh' => true])

@if (! $boleh)
    {{ $slot }}
@else
    <div x-data="urutTabel('{{ $url }}')" @input="periksa()" @change="periksa()"
         @dragstart="mulai($event)" @dragover.prevent="lewati($event)"
         @drop.prevent="selesai()" @dragend="selesai()">
        {{ $slot }}

        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            <span class="text-gray-400">
                Seret <span class="font-bold text-gray-500">&#10287;</span> atau tekan
                <span class="font-bold text-gray-500">&#9650;&#9660;</span> untuk mengubah urutan tampil.
                Urutan ini dipakai di seluruh aplikasi.
            </span>
            <span x-show="pesan" x-cloak x-text="pesan"
                  :class="status === 'gagal' ? 'font-medium text-red-600' : 'font-medium text-emerald-600'"></span>
        </div>
    </div>
@endif
