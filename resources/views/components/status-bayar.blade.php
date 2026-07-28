{{--
  Status pembayaran mutakhir seorang santri untuk tampilan DAFTAR.
  Isinya satu baris dari RekapPembayaranService::ringkasMassal().

  "Menunggu verifikasi" sengaja jadi status tersendiri dan menang atas
  "Belum/Sebagian": uangnya sudah disetor & tercatat, hanya belum diakui
  keuangan — tanpa ini santri yang baru membayar tampak seperti belum bayar.
  Nominalnya TIDAK pernah dijumlahkan ke "terbayar" karena memang belum diakui.
--}}
@props(['info' => null])

@php
    $status = $info['status'] ?? 'tanpa_tagihan';
    $warna = [
        'lunas' => 'bg-emerald-100 text-emerald-700',
        'menunggu' => 'bg-amber-100 text-amber-800',
        'sebagian' => 'bg-blue-100 text-blue-700',
        'belum' => 'bg-rose-100 text-rose-700',
        'tanpa_tagihan' => 'bg-gray-100 text-gray-500',
    ][$status];
    $label = [
        'lunas' => 'Lunas',
        'menunggu' => 'Menunggu verifikasi',
        'sebagian' => 'Sebagian',
        'belum' => 'Belum bayar',
        'tanpa_tagihan' => 'Belum ditagih',
    ][$status];
@endphp

<span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $warna }}">{{ $label }}</span>
@if ($info && $status !== 'tanpa_tagihan')
    <div class="mt-0.5 text-[11px] leading-tight text-gray-500">
        @if ($status === 'menunggu')
            @rp($info['menunggu']) menunggu &middot; sisa @rp($info['sisa'])
        @elseif ($status === 'lunas')
            @rp($info['terbayar'])
        @else
            sisa @rp($info['sisa']) dari @rp($info['tagihan'])
        @endif
    </div>
@endif
