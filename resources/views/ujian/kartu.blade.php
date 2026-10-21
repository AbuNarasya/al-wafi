{{-- Kartu peserta ujian seleksi (21 Okt 2026) — halaman cetak A4 berdiri sendiri, dipakai staf & portal wali.
     $k = UjianSeleksi::dataKartu(); $kembali = alamat tombol kembali. --}}
@php
    $s = $k['santri'];
    $warna = $k['putri'] ? '#5f5aa8' : '#164a9e';
    $pihak = $k['putri'] ? 'PUTRI' : 'PUTRA';
    $tgl = $k['jadwal'] ? mb_strtoupper($k['jadwal']['tanggal']->locale('id')->translatedFormat('l, j F Y')) : 'JADWAL MENYUSUL';
    $jam = $k['jadwal']['jam'] ?? null;
    $nomor = $s->no_pendaftaran;
    $baris = collect(preg_split('/\R/', $k['perhatian']))->map(fn ($b) => trim($b))->filter()->values();
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kartu Peserta Ujian · {{ $s->nama }}</title>
    @vite('resources/js/kartu-ujian.js')
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #111; background: #f1f5f9; }
        .lembar { width: 186mm; margin: 0 auto; background: #fff; padding: 8mm 6mm; }
        .alat { max-width: 186mm; margin: 12px auto; display: flex; gap: 8px; justify-content: space-between; align-items: center; font-size: 14px; }
        .alat a { color: #164a9e; text-decoration: none; font-weight: 600; }
        .alat button { background: {{ $warna }}; color: #fff; border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 700; font-size: 14px; cursor: pointer; }
        .pasang { display: flex; gap: 8mm; justify-content: center; }
        .kolom { width: 82mm; text-align: center; }
        .label-sisi { font-weight: 700; font-size: 11pt; margin-bottom: 3mm; }
        .kartu { border: 1.2px dashed #555; padding: 3mm; }
        .kepala { background: {{ $warna }}; color: #fff; font-weight: 700; font-size: 13pt; line-height: 1.2; padding: 4mm 3mm; text-transform: uppercase; border-bottom: 1.2mm solid #2b2b55; }
        .judul { font-weight: 700; font-size: 12.5pt; color: #2b2b55; margin-top: 3mm; }
        .judul .jalur { color: #c0262d; }
        .kecil { font-size: 8.5pt; text-transform: uppercase; margin-top: 1mm; }
        .foto, .qr { width: 42mm; height: 52mm; margin: 3mm auto 2mm; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .foto { background: #e5e7eb; border: 1px solid #d1d5db; font-size: 8pt; color: #6b7280; padding: 2mm; }
        .foto img { width: 100%; height: 100%; object-fit: cover; }
        .qr { background: #fbd5d5; padding: 2mm; height: 42mm; }
        .qr svg { width: 100%; height: 100%; }
        .nomor { font-weight: 700; font-size: 17pt; letter-spacing: .5px; }
        .nama { font-family: 'Times New Roman', serif; font-size: 11pt; margin-top: 1mm; }
        .kaki { background: {{ $warna }}; color: #fff; font-size: 9.5pt; padding: 1.5mm; margin-top: 3mm; text-transform: uppercase; }
        .kupon { width: 82mm; margin: 6mm 0 0; }
        .kupon .kepala { font-size: 15pt; }
        .kupon .sub { font-size: 9pt; font-weight: 400; text-transform: none; }
        .perhatian { margin-top: 8mm; font-family: 'Times New Roman', serif; font-size: 11pt; }
        .perhatian h3 { margin: 0 0 1mm; font-size: 11pt; }
        .perhatian ul, .perhatian ol { margin: 0; padding-left: 6mm; }
        .perhatian ul { list-style: '- '; padding-left: 3mm; }
        @media print { body { background: #fff; } .alat { display: none; } .lembar { padding: 0; width: auto; } }
    </style>
</head>
<body>
    <div class="alat">
        <a href="{{ $kembali }}">&larr; Kembali</a>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <div class="lembar">
        <div class="pasang">
            <div class="kolom">
                <div class="label-sisi">DEPAN</div>
                <div class="kartu">
                    <div class="kepala">{{ $k['lembaga'] }}<br>{{ $pihak }}</div>
                    <div class="judul">KARTU PESERTA <span class="jalur">{{ mb_strtoupper($k['jalur']) }}</span></div>
                    <div class="kecil">Ujian Seleksi {{ $k['putri'] ? 'Santriwati' : 'Santri' }} Baru<br>TA. {{ $s->tahun_ajaran }}</div>
                    <div class="foto">@if ($k['foto'])<img src="{{ $k['foto'] }}" alt="Foto {{ $s->nama }}">@else Tempel pas foto 3×4 di sini @endif</div>
                    <div class="nomor">{{ $nomor }}</div>
                    <div class="nama">{{ $s->nama }}</div>
                    <div class="kecil">{{ $tgl }}{{ $jam ? ' · '.$jam : '' }}</div>
                    <div class="kaki">{{ $k['jenjang'] }} {{ $pihak }}</div>
                </div>
            </div>
            <div class="kolom">
                <div class="label-sisi">BELAKANG</div>
                <div class="kartu">
                    <div class="kepala">{{ $k['lembaga'] }}<br>{{ $pihak }}</div>
                    <div class="judul">SCAN DISINI</div>
                    <div class="kecil">Ujian Seleksi {{ $k['putri'] ? 'Santriwati' : 'Santri' }} Baru<br>TA. {{ $s->tahun_ajaran }}</div>
                    <div class="qr" data-qr="{{ $k['qr'] }}"></div>
                    <div class="nomor">{{ $nomor }}</div>
                    <div class="nama">{{ $s->nama }}</div>
                    <div class="kecil">{{ $tgl }}{{ $k['jadwal']['tempat'] ?? null ? ' · '.$k['jadwal']['tempat'] : '' }}</div>
                    <div class="kaki">{{ $k['jenjang'] }} {{ $pihak }}</div>
                </div>
            </div>
        </div>

        <div class="kartu kupon" style="text-align:center">
            <div class="kepala">Kupon Makan<br><span class="sub">(Untuk {{ $k['kupon'] }} Orang)</span></div>
            <div class="nomor" style="margin-top:3mm">{{ $nomor }}</div>
            <div class="nama">{{ $s->nama }}</div>
            <div class="kaki" style="font-size:8pt">Panitia Penerimaan Santri Baru TA. {{ $s->tahun_ajaran }}<br>Jenjang {{ $k['jenjang'] }} {{ $pihak }}</div>
        </div>

        <div class="perhatian">
            <h3>PERHATIAN:</h3>
            {{-- Baris berawalan "#" = butir bernomor di bawah baris sebelumnya; selain itu butir berstrip. --}}
            <ul>
                @foreach ($baris as $i => $b)
                    @continue(str_starts_with($b, '#'))
                    <li>{{ $b }}
                        @php $sub = $baris->slice($i + 1)->takeWhile(fn ($x) => str_starts_with($x, '#')); @endphp
                        @if ($sub->isNotEmpty())
                            <ol>@foreach ($sub as $x)<li>{{ ltrim(substr($x, 1)) }}</li>@endforeach</ol>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</body>
</html>
