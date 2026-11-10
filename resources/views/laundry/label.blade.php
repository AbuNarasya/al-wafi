{{-- Label kantong laundry — kertas termal 80 mm sejak 10 Nov 2026 (dulu 58 mm; paket 1, 3 Nov 2026) — ditempel/diikat di kantong sejak diterima sampai diserahkan.
     TANPA QR sejak 10 Nov 2026 (permintaan user): cukup nomor kantong, data santri, dan rincian cucian. Pemeriksaan &
     penyerahan memakai ketik nomor/nama/NIS dan tombol Cocok manual. ?cetak=1 → dialog cetak terbuka sendiri. --}}
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
    <style>
        /* Kertas termal 80 mm (10 Nov 2026): area cetak printer 80 mm ±72 mm. */
        @page { size: 80mm auto; margin: 2mm 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .label { width: 72mm; padding: 1mm; text-align: center; }
        .bagian { font-size: 10pt; font-weight: 700; letter-spacing: 1px; border: 1px solid #000; display: inline-block; padding: 0 3mm; }
        .nomor { font-size: 20pt; font-weight: 700; margin-top: 1mm; letter-spacing: .3px; }
        .nama { font-size: 14pt; font-weight: 700; line-height: 1.15; }
        .kecil { font-size: 10pt; margin-top: 1mm; }
        .daftar { margin-top: 2.5mm; border-top: 1px dashed #000; padding-top: 2mm; text-align: left; }
        .daftar .judul { font-size: 10pt; font-weight: 700; text-align: center; margin-bottom: 1.5mm; }
        .daftar table { width: 100%; border-collapse: collapse; font-size: 11pt; }
        .daftar td { padding: 1mm .5mm; border-bottom: 1px dotted #888; vertical-align: top; }
        .daftar td.jml { width: 10mm; text-align: right; font-weight: 700; padding-right: 2mm; }
        .daftar td.cek { width: 7mm; text-align: center; }
        .kotak { display: inline-block; width: 4mm; height: 4mm; border: 1px solid #000; }
        .daftar .total { font-size: 11pt; font-weight: 700; margin-top: 1.5mm; display: flex; justify-content: space-between; }
        .daftar .kosong { font-size: 10pt; text-align: center; font-style: italic; }
        .alat { margin: 12px; font-size: 14px; }
        @media print { .alat { display: none; } }
    </style>
</head>
<body>
    <div class="alat"><button type="button" onclick="window.print()">Cetak label</button></div>
    <div class="label">
        <span class="bagian">LAUNDRY {{ $akhwat ? 'AKHWAT' : 'IKHWAN' }}</span>
        <div class="nomor">{{ $l->nomor }}</div>
        <div class="nama" style="margin-top:1.5mm">{{ $s?->nama }}</div>
        <div class="kecil">{{ $s?->nis }}{{ $s?->kelas ? ' · '.$s->kelas->nama : ($s?->jenjang ? ' · '.$s->jenjang->nama : '') }}</div>
        <div class="kecil">Diterima {{ $l->diterima_pada?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} · {{ rtrim(rtrim(number_format((float) ($l->berat_terima ?? $l->berat), 2, ',', '.'), '0'), ',') }} kg</div>

        {{-- Daftar cucian (10 Nov 2026): alat cek saat packing cucian yang sudah selesai. Daftarnya baru ada sesudah
             meja Pemeriksaan — label yang dicetak saat penerimaan memberi tahu hal itu. --}}
        <div class="daftar">
            @if ($l->item->isNotEmpty())
                <div class="judul">DAFTAR CUCIAN{{ $l->diperiksa_pada ? ' · '.rtrim(rtrim(number_format((float) $l->berat, 2, ',', '.'), '0'), ',').' kg' : '' }}</div>
                <table>
                    @foreach ($l->item as $it)
                        <tr><td class="jml">{{ $it->jumlah }}</td><td>{{ $it->nama_pakaian }}</td><td class="cek"><span class="kotak"></span></td></tr>
                    @endforeach
                </table>
                <div class="total"><span>Total</span><span>{{ $l->jumlahPotong() }} potong</span></div>
            @else
                <div class="kosong">Daftar cucian diisi di meja Pemeriksaan — cetak ulang label sesudahnya untuk cek packing.</div>
            @endif
        </div>
    </div>
    @if (request()->boolean('cetak'))
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 200));</script>
    @endif
</body>
</html>
