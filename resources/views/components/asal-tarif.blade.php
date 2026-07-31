{{--
  Kalimat ASAL TARIF dengan namanya ditebalkan:
  "Tarif <b>SMA</b> · jalur <b>Anak Karyawan</b> · T.A <b>2026/2027</b>".

    <x-asal-tarif :bagian="$tarif['bagian']" :teks="$tarif['label']" />

  `bagian` datang dari TarifService::cari(). Penebalannya SENGAJA di sini, bukan
  dirakit di service: nama jenjang & jalur adalah isian pemakai, jadi ia harus
  melewati escaping Blade — service yang mengembalikan `<b>` memaksa setiap
  pemakainya me-render tanpa escape.

  `bagian` null (tahun ajaran santri kosong, sel belum diisi, atau kalimatnya
  memang bukan asal tarif) → `teks` ditampilkan apa adanya. Jadi komponen ini
  aman dipasang di mana pun kalimat itu dulu dicetak polos.
--}}
@props(['bagian' => null, 'teks' => null])

@if ($bagian)
    Tarif <b>{{ $bagian['jenjang'] ?? 'tanpa jenjang' }}</b>

    @if (($bagian['tingkat'] ?? null) !== null)
        tingkat <b>{{ $bagian['tingkat'] }}</b>
    @endif

    ·

    @if ($bagian['jalur'] ?? null)
        jalur <b>{{ $bagian['jalur'] }}</b>
    @else
        baris <b>Umum</b>
    @endif

    · T.A <b>{{ $bagian['tahun_ajaran'] }}</b>

    @if ($bagian['catatan'] ?? null)
        {{ $bagian['catatan'] }}
    @endif
@else
    {{ $teks }}
@endif
