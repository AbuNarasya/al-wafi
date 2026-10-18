@php
    $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Retur {{ $retur->nomor }}</title>
    {{-- Struk thermal 58 mm — gaya sengaja ditulis di sini, sama seperti struk penjualan. --}}
    <style>
        @page { size: 58mm auto; margin: 2mm; }
        body { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; width: 54mm; margin: 0 auto; color: #000; }
        .tengah { text-align: center; }
        .garis { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .kanan { text-align: right; white-space: nowrap; }
        .besar { font-size: 13px; font-weight: bold; }
    </style>
</head>
<body @if ($cetak) onload="window.print()" @endif>
    <div class="tengah besar">RETUR PENJUALAN</div>
    <div class="tengah">{{ $retur->shift->lokasi->nama }}</div>
    <div class="garis"></div>
    <div>{{ $retur->nomor }}</div>
    <div>{{ $retur->waktu->format('d/m/Y H:i') }} · {{ $retur->kasir?->nama }}</div>
    <div>Struk asal: {{ $retur->penjualan->nomor }}</div>
    <div class="garis"></div>
    <table>
        @foreach ($retur->details as $d)
            <tr><td colspan="2">{{ $d->persediaan?->nama_persediaan }}</td></tr>
            <tr>
                <td>{{ $angka($d->kuantiti_input) }} {{ $d->baris?->satuan }} · {{ $d->kondisi === 'layak' ? 'layak' : 'rusak' }}</td>
                <td class="kanan">{{ $rp($d->nilai) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="garis"></div>
    <table><tr class="besar"><td>UANG KEMBALI</td><td class="kanan">{{ $rp($retur->total) }}</td></tr></table>
    <div>Alasan: {{ $retur->alasan }}</div>
    <div>Disetujui: {{ $retur->penyetuju?->nama }}</div>
    <div class="garis"></div>
    <div class="tengah">Tanda tangan pembeli<br><br><br>(..................)</div>
</body>
</html>
