@php
    $rp = fn ($v) => "Rp " . number_format((float) $v, 0, ',', '.');
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $labelTermin = ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum' => 'Belum'];
    $labelPot = ['berlaku' => 'Berlaku', 'earned' => 'Terkunci (earned)', 'hangus' => 'Hangus'];
    $rk = $d['rencana_aktif'];
    $meta = collect([
        ['No. Pendaftaran', $d['no_pendaftaran']],
        ['Nama Calon', $d['nama']],
        ['Wali / Keluarga', $d['nama_wali'] ?? '—'],
    ]);
    if ($d['potongan']) {
        $meta = $meta->concat([
            ['Uang Pangkal Normal', $rp($d['potongan']['nominal_normal'])],
            ['Potongan Gelombang ' . $d['potongan']['gelombang'], '− ' . $rp($d['potongan']['potongan'])],
            ['Status Potongan', $labelPot[$d['potongan']['status']] ?? $d['potongan']['status']],
        ]);
    }
    $meta = $meta->concat([
        ['Total Uang Pangkal', $rp($d['total'])],
        ['Sudah Terbayar', $rp($d['terbayar'])],
        ['Sisa', $rp($d['sisa'])],
        ['Status', ($d['status_tagihan']) . ' · ' . ($d['sudah_akrual'] ? 'sudah akrual (piutang)' : 'belum daftar ulang')],
        ['Kesepakatan', $rk ? 'Versi ' . $rk['versi'] . ' · disepakati ' . $tgl($rk['disepakati_pada']) : '—'],
    ]);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rencana Angsuran — {{ $d['nama'] }}</title>
    <style>
        *{font-family:Arial,Helvetica,sans-serif;box-sizing:border-box}
        body{margin:24px;font-size:12px;color:#222}
        .kop{display:flex;justify-content:space-between;border-bottom:2px solid #222;padding-bottom:10px}
        .kop .nm{font-size:18px;font-weight:bold}
        .kop .meta{font-size:11px;color:#666}
        .doc{text-align:right}.doc .t{font-size:14px;font-weight:bold}
        .metagrid{display:grid;grid-template-columns:1fr 1fr;gap:2px 32px;margin-top:16px}
        .metagrid .row{display:flex;justify-content:space-between;border-bottom:1px dashed #eee;padding:2px 0}
        .metagrid .row .k{color:#666}
        h4{margin:18px 0 4px}
        table{width:100%;border-collapse:collapse;font-size:11px}
        th,td{border-bottom:1px solid #ddd;padding:5px 6px;text-align:left}
        thead th{background:#f1f5f9;text-transform:uppercase;font-size:10px;color:#555}
        .r{text-align:right;font-family:monospace;white-space:nowrap}
        tfoot td{border-top:2px solid #cbd5e1;font-weight:bold}
        .ttd{display:flex;justify-content:space-between;margin-top:50px;text-align:center;font-size:11px}
        .ttd .line{margin-top:50px;border-top:1px solid #666;padding-top:3px}
        @media print{body{margin:0}}
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
            <div class="t">RENCANA ANGSURAN UANG PANGKAL</div>
            <div class="meta" style="font-family:monospace">{{ $d['no_pendaftaran'] }}</div>
            <div class="meta">Dicetak: {{ now()->translatedFormat('d F Y') }}</div>
        </div>
    </div>

    <div class="metagrid">
        @foreach ($meta as [$k, $v])
            <div class="row"><span class="k">{{ $k }}</span><span style="font-weight:600">{{ $v }}</span></div>
        @endforeach
    </div>

    <h4>Jadwal Termin{{ $rk ? ' (versi ' . $rk['versi'] . ')' : '' }}</h4>
    <table>
        <thead><tr><th>#</th><th class="r">Nominal</th><th class="r">Tertutup</th><th>Jatuh Tempo</th><th>Tgl Bayar</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($rk['termin'] ?? [] as $t)
                <tr>
                    <td>{{ $t['urutan'] }}</td>
                    <td class="r">{{ $rp($t['nominal']) }}</td>
                    <td class="r">{{ $rp($t['tertutup']) }}</td>
                    <td>{{ $tgl($t['jatuh_tempo']) }}</td>
                    <td>{{ $t['tanggal_lunas'] ? $tgl($t['tanggal_lunas']) : '—' }}</td>
                    <td>{{ $labelTermin[$t['status_termin']] ?? $t['status_termin'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:#999;padding:12px">Belum ada rencana angsuran aktif.</td></tr>
            @endforelse
        </tbody>
        @if ($rk)
            <tfoot><tr><td>Total</td><td class="r">{{ $rp($d['total']) }}</td><td class="r">{{ $rp($d['terbayar']) }}</td><td colspan="3"></td></tr></tfoot>
        @endif
    </table>

    @if (! empty($d['pembayaran']))
        <h4>Riwayat Pembayaran</h4>
        <table>
            <thead><tr><th>Tanggal</th><th>Nomor</th><th>Status</th><th class="r">Nominal</th></tr></thead>
            <tbody>
                @foreach ($d['pembayaran'] as $p)
                    <tr><td>{{ $tgl($p['tanggal']) }}</td><td style="font-family:monospace">{{ $p['nomor'] }}</td><td>{{ $p['status'] }}</td><td class="r">{{ $rp($p['nominal']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p style="margin-top:12px;font-size:11px;color:#666">Rencana angsuran adalah kesepakatan jadwal — tidak menerbitkan jurnal. Hanya pembayaran terverifikasi yang berjurnal.</p>

    <div class="ttd">
        <div style="width:40%"><div>Wali / Penanggung Jawab,</div><div class="line">( {{ $d['nama_wali'] ?? '................................' }} )</div></div>
        <div style="width:40%"><div>Petugas PPSB,</div><div class="line">( ................................ )</div></div>
    </div>
</body>
</html>
