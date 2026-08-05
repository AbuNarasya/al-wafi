@php
    $rp = fn ($v) => "Rp " . number_format((float) $v, 0, ',', '.');
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $labelTagihan = ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum_bayar' => 'Belum bayar', 'batal' => 'Batal'];
    $sum = fn ($k) => collect($rows)->sum(fn ($r) => (float) $r[$k]);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Angsuran Uang Pangkal</title>
    <style>
        *{font-family:Arial,Helvetica,sans-serif;box-sizing:border-box}
        body{margin:24px;font-size:12px;color:#222}
        .kop{display:flex;justify-content:space-between;border-bottom:2px solid #222;padding-bottom:10px}
        .kop .nm{font-size:18px;font-weight:bold}
        .kop .meta{font-size:11px;color:#666}
        .doc{text-align:right}
        .doc .t{font-size:15px;font-weight:bold;letter-spacing:.5px}
        table{width:100%;border-collapse:collapse;margin-top:18px;font-size:11px}
        th,td{border-bottom:1px solid #ddd;padding:5px 6px;text-align:left}
        thead th{background:#f1f5f9;text-transform:uppercase;font-size:10px;color:#555;border-bottom:1px solid #cbd5e1}
        .r{text-align:right;font-family:monospace;white-space:nowrap}
        tfoot td{border-top:2px solid #cbd5e1;font-weight:bold}
        .ttd{display:flex;justify-content:space-between;margin-top:60px;text-align:center;font-size:11px}
        .ttd .line{margin-top:50px;border-top:1px solid #666;padding-top:3px}
        /* Di LAYAR ponsel, sepuluh kolom tak muat 375px dan halaman ikut
           tergeser mendatar — dokumen ini biasa dibuka dulu sebelum dicetak.
           Saat MENCETAK pembungkusnya dinetralkan supaya tabelnya tak terpotong. */
        .geser{overflow-x:auto}
        @media print{body{margin:0}.noprint{display:none}.geser{overflow:visible}}
    </style>
</head>
<body onload="window.print()">
    <div class="kop">
        <div>
            <div class="nm">{{ $company?->nama_perusahaan ?? 'AL Wafi' }}</div>
            @if ($company?->alamat)<div class="meta">{{ $company->alamat }}</div>@endif
            @if ($company?->telepon || $company?->email)<div class="meta">{{ collect([$company?->telepon, $company?->email])->filter()->join(' · ') }}</div>@endif
        </div>
        <div class="doc">
            <div class="t">REKAP ANGSURAN UANG PANGKAL</div>
            <div class="meta">Dicetak: {{ now()->translatedFormat('d F Y') }}</div>
            <div class="meta">{{ count($rows) }} santri berjadwal aktif</div>
        </div>
    </div>

    <div class="geser">
    <table>
        <thead>
            <tr><th>#</th><th>No. Pendaftaran</th><th>Nama</th><th>Komponen</th><th>Wali</th><th class="r">Total</th><th class="r">Terbayar</th><th class="r">Sisa</th><th>Termin berikut</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td style="font-family:monospace">{{ $r['no_pendaftaran'] }}</td>
                    <td>{{ $r['nama'] }}</td>
                    <td>{{ $r['label_komponen'] ?: 'Uang Pangkal' }}</td>
                    <td>{{ $r['nama_wali'] ?? '—' }}</td>
                    <td class="r">{{ $rp($r['total']) }}</td>
                    <td class="r">{{ $rp($r['terbayar']) }}</td>
                    <td class="r">{{ $rp($r['sisa']) }}</td>
                    <td>
                        {{ $r['termin_berikut'] ? '#' . $r['termin_berikut']['urutan'] . ' · ' . $tgl($r['termin_berikut']['jatuh_tempo']) : '—' }}
                        @if ($r['termin_berikut'])<div style="color:#777;font-size:10px">{{ $r['termin_berikut']['label_komponen'] }}</div>@endif
                    </td>
                    <td>{{ $labelTagihan[$r['status_tagihan']] ?? $r['status_tagihan'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:#999;padding:16px">Tidak ada rencana angsuran aktif.</td></tr>
            @endforelse
        </tbody>
        @if (count($rows))
            <tfoot>
                <tr><td colspan="5">Total ({{ count($rows) }} santri, uang pangkal + perlengkapan)</td><td class="r">{{ $rp($sum('total')) }}</td><td class="r">{{ $rp($sum('terbayar')) }}</td><td class="r">{{ $rp($sum('sisa')) }}</td><td colspan="2"></td></tr>
            </tfoot>
        @endif
    </table>
    </div>

    <div class="ttd">
        <div style="width:40%"><div>Mengetahui,</div><div class="line">( ................................ )</div></div>
        <div style="width:40%"><div>Petugas PPSB,</div><div class="line">( ................................ )</div></div>
    </div>
</body>
</html>
