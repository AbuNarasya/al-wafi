{{--
  Isian nominal berpemisah ribuan — "8.000.000", tanpa sen.

    <x-input-rupiah name="nominal" :value="$nominalDefault" required placeholder="mis. 20000000" />
    <x-input-rupiah name="umum[spp][nominal]" :value="$sel" nonaktif="bebas" lebar="w-44" />

  Yang dilihat petugas adalah isian TEKS bertopeng; yang terkirim adalah hidden
  berisi angka mentah. `<input type="number">` sengaja ditinggalkan: peramban
  menolak nilai yang bukan angka murni, jadi pemisah ribuan mustahil di sana.

  Keduanya sudah terisi dari server (bukan hanya oleh Alpine) supaya nilai bawaan
  tetap terkirim walau JS gagal dimuat — hidden yang kosong akan membuat tagihan
  terbit tanpa nominal, dan itu diam-diam.

  `required` dipasang pada isian TEKS, bukan hidden: hidden dikecualikan dari
  validasi bawaan peramban, jadi menaruhnya di sana membuat penjaga "wajib diisi"
  hilang tanpa suara. Nilai negatif mustahil — non-digit dibuang saat mengetik.

  `nonaktif` = ekspresi Alpine dari lingkup PEMANGGIL (mis. `bebas` pada grid
  Tarif). Dipasang ke KEDUANYA: isian yang mati tapi hidden-nya masih hidup akan
  tetap mengirim angka yang sengaja ditiadakan petugas.

  UNTUK BARIS BERULANG (`:name` dinamis, nilainya dipegang Alpine induk) komponen
  ini TIDAK dipakai — gunakan `fmtRupiah()` + `ketikRupiah($event)` langsung,
  lihat resources/js/app.js.
--}}
{{--
  `lebar` adalah slot TERSENDIRI, bukan lewat `class`, supaya pemanggil yang butuh
  lebar lain tak perlu menulis `!w-36`: kelas ber-`!` itu dirakit saat render dan
  TIDAK akan terpindai Tailwind (pemindaiannya literal), sehingga kelasnya tak
  pernah ikut ter-build. Ditulis sebagai prop, nilainya tetap berupa teks utuh di
  berkas Blade — dan itulah yang dilihat pemindai.
--}}
@props(['name', 'value' => null, 'required' => false, 'placeholder' => '', 'nonaktif' => null, 'lebar' => 'w-full'])

@php
    // "8000000.00" (Money) atau "8000000" (old()) → digit utuh, sen dibuang.
    $digit = ($value === null || $value === '') ? '' : (string) (int) $value;
@endphp

<span class="block" x-data="inputRupiah({ value: @js($digit) })">
    <input type="text" inputmode="numeric" autocomplete="off"
           x-ref="tampil" @input="ketik()"
           value="{{ $digit === '' ? '' : number_format((int) $digit, 0, ',', '.') }}"
           placeholder="{{ $placeholder }}" @required($required)
           @if ($nonaktif) :disabled="{{ $nonaktif }}" @endif
           {{ $attributes->merge(['class' => $lebar.' rounded border-gray-300 text-sm tabular-nums']) }}>
    <input type="hidden" name="{{ $name }}" value="{{ $digit }}" :value="mentah"
           @if ($nonaktif) :disabled="{{ $nonaktif }}" @endif>
</span>
