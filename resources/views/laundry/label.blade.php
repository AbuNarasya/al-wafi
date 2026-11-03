{{-- Label kantong laundry 58 mm (paket 1, 3 Nov 2026) — ditempel/diikat di kantong sejak diterima sampai diserahkan.
     QR berisi NOMOR kantong: dipindai di meja pemeriksaan & saat penyerahan. ?cetak=1 → dialog cetak terbuka sendiri. --}}
@php
    $s = $l->santri;
    $akhwat = \App\Support\BagianLaundry::dariJk($s?->jenis_kelamin) === 'akhwat';
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label {{ $l->nomor }}</title>
    @vite('resources/js/kartu-ujian.js')
    <style>
        @page { size: 58mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .label { width: 54mm; padding: 1mm; text-align: center; }
        .bagian { font-size: 8pt; font-weight: 700; letter-spacing: 1px; border: 1px solid #000; display: inline-block; padding: 0 2mm; }
        .nomor { font-size: 15pt; font-weight: 700; margin-top: 1mm; letter-spacing: .3px; }
        .qr { width: 30mm; height: 30mm; margin: 1mm auto; }
        .qr svg { width: 100%; height: 100%; }
        .nama { font-size: 11pt; font-weight: 700; line-height: 1.15; }
        .kecil { font-size: 8pt; margin-top: .8mm; }
        .alat { margin: 12px; font-size: 14px; }
        @media print { .alat { display: none; } }
    </style>
</head>
<body>
    <div class="alat"><button type="button" onclick="window.print()">Cetak label</button></div>
    <div class="label">
        <span class="bagian">LAUNDRY {{ $akhwat ? 'AKHWAT' : 'IKHWAN' }}</span>
        <div class="nomor">{{ $l->nomor }}</div>
        <div class="qr" data-qr="{{ $l->nomor }}"></div>
        <div class="nama">{{ $s?->nama }}</div>
        <div class="kecil">{{ $s?->nis }}{{ $s?->kelas ? ' · '.$s->kelas->nama : ($s?->jenjang ? ' · '.$s->jenjang->nama : '') }}</div>
        <div class="kecil">Diterima {{ $l->diterima_pada?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} · {{ rtrim(rtrim(number_format((float) ($l->berat_terima ?? $l->berat), 2, ',', '.'), '0'), ',') }} kg</div>
    </div>
    @if (request()->boolean('cetak'))
        {{-- QR digambar asinkron — beri jeda sebelum membuka dialog cetak. --}}
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
    @endif
</body>
</html>
