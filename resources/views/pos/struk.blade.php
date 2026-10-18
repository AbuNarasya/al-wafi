@php
    $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $jual->nomor }}</title>
    {{-- Struk printer thermal 58 mm. Gaya ditulis di sini (bukan Tailwind) supaya tetap
         utuh saat dicetak dari iframe tanpa menunggu berkas CSS aplikasi. --}}
    <style>
        @page { size: 58mm auto; margin: 2mm; }
        body { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; width: 54mm; margin: 0 auto; color: #000; }
        .tengah { text-align: center; }
        .garis { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .kanan { text-align: right; white-space: nowrap; }
        .besar { font-size: 13px; font-weight: bold; }
        .batal { font-size: 16px; font-weight: bold; text-align: center; border: 2px solid #000; margin: 4px 0; }
    </style>
</head>
<body @if ($cetak) onload="window.print()" @endif>
    <div class="tengah besar">{{ $jual->shift->lokasi->nama }}</div>
    @if ($perusahaan)<div class="tengah">{{ $perusahaan->nama_perusahaan }}</div>@endif
    <div class="garis"></div>
    <div>{{ $jual->nomor }}</div>
    <div>{{ $jual->waktu->format('d/m/Y H:i') }} · {{ $jual->kasir?->nama }}</div>
    @if ($jual->status === 'batal')<div class="batal">DIBATALKAN</div>@endif
    <div class="garis"></div>
    <table>
        @foreach ($jual->details as $d)
            <tr><td colspan="2">{{ $d->persediaan?->nama_persediaan }}</td></tr>
            <tr>
                <td>{{ $angka($d->kuantiti_input) }} {{ $d->satuan }} × {{ $rp($d->harga_satuan) }}</td>
                <td class="kanan">{{ $rp((float) $d->total + (float) $d->diskon) }}</td>
            </tr>
            @if ($d->promo && $d->harga_normal)
                <tr><td colspan="2">&nbsp; promo (normal {{ $rp($d->harga_normal) }})</td></tr>
            @endif
            @if ((float) $d->diskon > 0)
                <tr><td>&nbsp; diskon</td><td class="kanan">-{{ $rp($d->diskon) }}</td></tr>
            @endif
        @endforeach
    </table>
    @if ((float) $jual->diskon_struk > 0)
        <div class="garis"></div>
        <table><tr><td>Diskon</td><td class="kanan">-{{ $rp($jual->diskon_struk) }}</td></tr></table>
    @endif
    <div class="garis"></div>
    <table>
        <tr class="besar"><td>TOTAL</td><td class="kanan">{{ $rp($jual->total) }}</td></tr>
        <tr><td>Tunai</td><td class="kanan">{{ $rp($jual->bayar) }}</td></tr>
        <tr><td>Kembali</td><td class="kanan">{{ $rp($jual->kembalian) }}</td></tr>
    </table>
    @if ($jual->disetujui_oleh)
        <div>Diskon disetujui: {{ $jual->penyetuju?->nama }}</div>
    @endif
    <div class="garis"></div>
    <div class="tengah">Terima kasih — barang yang sudah dibeli<br>tidak dapat ditukar tanpa struk ini.</div>
</body>
</html>
