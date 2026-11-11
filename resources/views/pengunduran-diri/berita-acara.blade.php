{{-- Berita Acara Clearance Pengunduran Diri (dompdf) — App\Services\Modules\BeritaAcaraClearance::data(). --}}
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H:i') : '—';
    $s = $p->santri;
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #222; }
    table { border-collapse: collapse; width: 100%; }
    .kop { border-bottom: 2px solid #164a9e; padding-bottom: 5px; }
    .kop b { font-size: 12pt; color: #164a9e; }
    h1 { font-size: 12pt; text-align: center; margin: 10px 0 1px; }
    .nomor { text-align: center; color: #555; margin-bottom: 6px; }
    .bagian { background: #eef3fb; font-weight: bold; font-size: 9pt; padding: 3px 6px; margin-top: 8px; }
    .isi td { padding: 1px 6px; vertical-align: top; }
    .lbl { width: 140px; color: #555; }
    .rinci { margin-top: 3px; }
    .rinci th { text-align: left; border: 1px solid #ccc; padding: 3px 5px; background: #fafafa; font-size: 7.5pt; }
    .rinci td { border: 1px solid #ccc; padding: 3px 5px; vertical-align: top; }
    .ok { color: #0a7d3e; font-weight: bold; }
    .kecil { font-size: 7.5pt; color: #666; }
    .sah td { vertical-align: top; }
</style>
</head>
<body>
    <table class="kop"><tr><td>
        <b>{{ $lembaga }}</b>
        @if ($alamat)<br><span class="kecil">{{ $alamat }}</span>@endif
    </td></tr></table>

    <h1>BERITA ACARA CLEARANCE PENGUNDURAN DIRI SANTRI</h1>
    <div class="nomor">Nomor: {{ $p->nomor }}</div>

    <div class="bagian">A. Data Santri</div>
    <table class="isi">
        <tr><td class="lbl">Nama</td><td>: <b>{{ $s->nama }}</b></td></tr>
        <tr><td class="lbl">NIS</td><td>: {{ $s->nis ?: '—' }}{{ $s->nisn ? ' · NISN '.$s->nisn : '' }}</td></tr>
        <tr><td class="lbl">Jenjang / Tingkat</td><td>: {{ $s->jenjang?->nama ?? $s->kode_jenjang }}{{ $s->tingkat ? ' / Tingkat '.$s->tingkat : '' }}{{ $s->kelas?->nama ? ' — Kelas '.$s->kelas->nama : '' }}</td></tr>
        <tr><td class="lbl">Angkatan (T.A masuk)</td><td>: {{ $s->tahun_ajaran ?: '—' }}</td></tr>
        <tr><td class="lbl">Wali / keluarga</td><td>: {{ $p->wali?->nama ?? '—' }}</td></tr>
    </table>

    <div class="bagian">B. Pengajuan</div>
    <table class="isi">
        <tr><td class="lbl">Diajukan oleh</td><td>: {{ $pengaju }}</td></tr>
        <tr><td class="lbl">Tanggal diajukan</td><td>: {{ $tgl($p->created_at) }}</td></tr>
        <tr><td class="lbl">Rencana keluar</td><td>: {{ $p->rencana_tanggal?->locale('id')->translatedFormat('j F Y') ?? '—' }}</td></tr>
        <tr><td class="lbl">Alasan</td><td>: {{ $p->alasan }}</td></tr>
    </table>

    <div class="bagian">C. Persetujuan &amp; Clearance</div>
    <table class="rinci">
        <tr><th style="width:30%">Tahap</th><th style="width:22%">Petugas</th><th style="width:18%">Waktu</th><th>Catatan</th></tr>
        @foreach ($tahap as $t)
            <tr>
                <td>{{ $t['label'] }}</td>
                <td>@if ($t['pada'])<span class="ok">✓</span> {{ $t['oleh'] ?? '—' }}@else — @endif</td>
                <td>{{ $tgl($t['pada']) }}</td>
                <td>{{ $t['catatan'] ?: '—' }}</td>
            </tr>
        @endforeach
    </table>

    <div class="bagian">D. Keadaan Keuangan (saat dokumen dicetak)</div>
    <table class="isi">
        <tr><td class="lbl">Sisa tagihan</td><td>: {{ $rp($keuangan['tetap_total']) }}{{ $keuangan['tetap'] ? '' : ' (tidak ada)' }}</td></tr>
        @foreach ($keuangan['tetap'] as $b)
            <tr><td></td><td class="kecil">· {{ $b['nama'] }} — {{ $rp($b['sisa']) }}</td></tr>
        @endforeach
        <tr><td class="lbl">Titipan (dompet + tabungan)</td><td>: {{ $rp($keuangan['titipan']['total']) }}</td></tr>
    </table>

    <div class="bagian">E. Dokumen</div>
    @if ($p->dokumen->isNotEmpty())
        <table class="rinci">
            <tr><th style="width:35%">Jenis</th><th>Keterangan / berkas</th><th style="width:20%">Diunggah</th></tr>
            @foreach ($p->dokumen as $d)
                <tr>
                    <td>{{ \App\Models\DokumenPengajuanKeluar::JENIS[$d->jenis] ?? $d->jenis }}</td>
                    <td>{{ $d->keterangan ?: $d->nama_asli }}</td>
                    <td>{{ $tgl($d->created_at) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <table class="isi"><tr><td>Tidak ada dokumen yang diunggah.</td></tr></table>
    @endif

    <div class="bagian">F. Status Akhir</div>
    <table class="isi">
        <tr><td class="lbl">Status</td><td>: <b>{{ $p->labelStatus() }}</b></td></tr>
        <tr><td class="lbl">Dikeluarkan</td><td>: {{ $p->dikeluarkan_pada ? $tgl($p->dikeluarkan_pada).' oleh '.($dikeluarkan_oleh ?? '—') : 'belum (menunggu proses akhir bulan)' }}</td></tr>
        <tr><td class="lbl">Dapodik</td><td>: {{ $p->dapodik_pada ? 'ditandai '.$tgl($p->dapodik_pada).' oleh '.($dapodik_oleh ?? '—') : 'belum ditandai' }}</td></tr>
    </table>

    <table class="sah" style="margin-top:10px; page-break-inside: avoid">
        <tr>
            <td style="width:96px"><img src="{{ $qr }}" style="width:88px;height:88px"></td>
            <td style="padding-left:8px">
                <b>Dokumen ini disahkan secara elektronik dan tidak memerlukan tanda tangan basah.</b><br>
                Setiap persetujuan di atas tercatat atas nama akun petugasnya beserta waktunya.<br><br>
                Kode verifikasi: <b style="font-size:10pt">{{ $kode }}</b><br>
                <span class="kecil">Pindai QR atau buka {{ $url }} untuk memastikan keaslian dokumen ini.</span><br>
                <span class="kecil">Dicetak {{ $dicetak->locale('id')->translatedFormat('j F Y, H:i') }} WIB.</span>
            </td>
        </tr>
    </table>
</body>
</html>
