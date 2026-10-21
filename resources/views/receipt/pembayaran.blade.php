{{-- Receipt pembayaran tagihan santri (dompdf) — format mengikuti contoh user 21 Okt 2026. $r = Receipt::data(). --}}
@php $rp = fn ($n) => number_format((float) $n, 0, ',', '.'); @endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 16mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #222; }
    table { border-collapse: collapse; width: 100%; }
    .kepala td { vertical-align: top; }
    .judul { border-left: 3px solid #1ba0e2; padding-left: 8px; }
    .judul b { font-size: 12pt; }
    .bagian { background: #f2f2f2; font-weight: bold; font-size: 10.5pt; padding: 5px 6px; margin-top: 14px; }
    .isi { padding: 5px 6px 0; }
    .isi td { padding: 1px 0; vertical-align: top; }
    .lbl { width: 92px; color: #444; }
    .rinci { margin-top: 6px; }
    .rinci th { text-align: left; font-weight: bold; border: 1px solid #ccc; padding: 6px; background: #fafafa; }
    .rinci td { border: 1px solid #ccc; padding: 6px; }
    .kanan { text-align: right; }
    .total td { border: 1px solid #ccc; padding: 6px; }
    .lunas { color: #0a7d3e; font-weight: bold; }
    .kecil { font-size: 7.5pt; color: #666; }
</style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td class="judul">
                <b>RECEIPT</b><br>
                Nomor : #{{ $r['nomor'] }}<br>
                Tanggal : {{ $r['tanggal']?->locale('id')->translatedFormat('j M Y, H:i') }}<br>
                ({{ $r['tanggal']?->locale('id')->translatedFormat('l') }})
            </td>
            <td class="kanan">@if ($r['logo'])<img src="{{ $r['logo'] }}" style="height:56px">@endif</td>
        </tr>
    </table>

    <div class="bagian">RINCIAN PEMBAYARAN</div>
    <table class="isi">
        <tr>
            <td>NO. PEMBAYARAN: {{ $r['nomor'] }}</td>
            <td>METODE: {{ $r['metode'] }}</td>
            <td class="kanan">STATUS: <span class="lunas">Lunas / Terverifikasi</span></td>
        </tr>
    </table>

    <table style="margin-top:0">
        <tr>
            <td style="width:50%; vertical-align:top; padding-right:10px">
                <div class="bagian">DATA PEMBAYAR</div>
                <table class="isi">
                    <tr><td class="lbl">Nama</td><td>: {{ $r['wali']['nama'] ?: '—' }}</td></tr>
                    <tr><td class="lbl">Email</td><td>: {{ $r['wali']['email'] ?: '—' }}</td></tr>
                    <tr><td class="lbl">No. Telepon</td><td>: {{ $r['wali']['telepon'] ?: '—' }}</td></tr>
                </table>
            </td>
            <td style="width:50%; vertical-align:top">
                <div class="bagian">DATA LEMBAGA</div>
                <table class="isi">
                    <tr><td class="lbl">Nama</td><td>: {{ $r['lembaga']['nama'] }}</td></tr>
                    @if ($r['lembaga']['npwp'])<tr><td class="lbl">NPWP</td><td>: {{ $r['lembaga']['npwp'] }}</td></tr>@endif
                    @if ($r['lembaga']['alamat'])<tr><td class="lbl">Alamat</td><td>: {{ $r['lembaga']['alamat'] }}</td></tr>@endif
                    @if ($r['lembaga']['telepon'])<tr><td class="lbl">Telepon</td><td>: {{ $r['lembaga']['telepon'] }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <div class="bagian">SANTRI</div>
    <table class="isi">
        @foreach ($r['santri'] as $s)
            <tr><td><b>{{ $s->nama }}</b> · {{ $s->nis ?: $s->no_pendaftaran }} · {{ $s->jenjang?->nama ?? $s->kode_jenjang }}{{ $s->kelas ? ' · kelas '.$s->kelas->nama : ($s->tingkat ? ' · tingkat '.$s->tingkat : '') }}</td></tr>
        @endforeach
    </table>

    <div class="bagian">RINCIAN TAGIHAN</div>
    <table class="rinci">
        <tr><th style="width:24px">No</th><th style="width:110px">Jenis</th><th>Keterangan</th><th style="width:28px">Qty</th><th class="kanan" style="width:90px">Harga Rp</th><th class="kanan" style="width:90px">Total Rp</th></tr>
        @foreach ($r['item'] as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td><td><b>{{ $it['jenis'] }}</b></td><td>{{ $it['uraian'] }}</td>
                <td class="kanan">1</td><td class="kanan">{{ $rp($it['nominal']) }}</td><td class="kanan">{{ $rp($it['nominal']) }}</td>
            </tr>
        @endforeach
    </table>
    <table class="total" style="width:42%; margin-left:58%">
        <tr><td>TOTAL</td><td class="kanan">{{ $rp($r['total']) }}</td></tr>
        <tr><td>Dibayar dengan {{ $r['metode'] }}{{ $r['rekening'] ? ' ('.$r['rekening'].')' : '' }}</td><td class="kanan">{{ $rp($r['total']) }}</td></tr>
        <tr><td><b>JUMLAH DIBAYAR</b></td><td class="kanan"><b>{{ $rp($r['total']) }}</b></td></tr>
    </table>

    <p style="margin-top:10px"><span class="kecil">Terbilang:</span> <i>{{ ucfirst(\App\Support\Terbilang::rupiah($r['total'])) }}</i></p>

    <p class="kecil" style="margin-top:16px">
        Tanggal bayar: {{ $r['tanggal_bayar'] ? \Illuminate\Support\Carbon::parse($r['tanggal_bayar'])->locale('id')->translatedFormat('j F Y') : '—' }}.
        Receipt ini dibuat oleh sistem dan sah tanpa tanda tangan. Pertanyaan: hubungi bagian keuangan {{ $r['lembaga']['nama'] }}{{ $r['lembaga']['telepon'] ? ' ('.$r['lembaga']['telepon'].')' : '' }}.
    </p>
</body>
</html>
