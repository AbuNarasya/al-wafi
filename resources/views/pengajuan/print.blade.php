<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak Pengajuan {{ $rec->nomor }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            /* A4 portrait: rincian pengajuan bisa berbaris banyak dan blok
               tanda tangan tumbuh mengikuti jumlah tahap approval, jadi A5
               landscape (148mm) terlalu pendek dan memotong isinya. */
            @page { size: A4 portrait; margin: 12mm; }
            /* Marginnya sudah diberi @page; batas lebar & padding layar
               dilepas agar isi tak melebar melewati area cetak. */
            .lembar { max-width: none !important; padding: 0 !important; }
            /* Blok tanda tangan jangan terbelah dua halaman. */
            .ttd { break-inside: avoid; page-break-inside: avoid; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body class="bg-white text-gray-800">
@php
    $jenisLabel = ['pembayaran' => 'Pembayaran', 'uang_muka' => 'Uang Muka', 'penyelesaian_uang_muka' => 'Penyelesaian UM'][$rec->jenis] ?? $rec->jenis;
    $logs = collect($timeline['logs'] ?? []);
    $penyetuju = fn ($urutan) => $logs->first(fn ($l) => $l->aksi === 'approve' && (int) $l->urutan === (int) $urutan);
    $verifikator = $logs->first(fn ($l) => $l->aksi === 'verifikasi');
    $sebutan = fn ($t) => ! empty($t['fungsi']) ? "Tim {$t['fungsi']}" : ($t['nama_level_pengajuan'] ?? "Peringkat {$t['peringkat']}");

    // Pemohon → para approver (tahap berlaku) → verifikator keuangan.
    $signatures = [];
    $signatures[] = ['label' => 'Diajukan oleh', 'nama' => $rec->pemohon?->nama ?? '—', 'waktu' => null];
    foreach (($timeline['tahap'] ?? []) as $t) {
        $log = $penyetuju($t['urutan']);
        $signatures[] = $log
            ? ['label' => "Disetujui — {$t['nama_tahap']}", 'nama' => $log->nama_pengguna, 'waktu' => optional($log->waktu)->format('d M Y')]
            : ['label' => "{$t['nama_tahap']} ({$sebutan($t)})", 'nama' => null];
    }
    $signatures[] = $verifikator
        ? ['label' => 'Diverifikasi — Keuangan', 'nama' => $verifikator->nama_pengguna, 'waktu' => optional($verifikator->waktu)->format('d M Y')]
        : ['label' => 'Diverifikasi — Keuangan', 'nama' => null];
@endphp

    <div class="lembar mx-auto max-w-3xl p-8">
        {{-- Toolbar (tidak ikut cetak) --}}
        <div class="no-print mb-6 flex items-center justify-between">
            <a href="{{ route('pengajuan.show', $rec->id) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak</button>
        </div>

        {{-- Kop surat --}}
        <div class="flex items-start justify-between border-b-2 border-gray-800 pb-3">
            <div>
                <div class="text-lg font-bold">{{ $company->nama_perusahaan ?? 'Perusahaan' }}</div>
                @if ($company?->alamat)<div class="text-xs text-gray-500">{{ $company->alamat }}</div>@endif
                @if ($company?->telepon || $company?->email)
                    <div class="text-xs text-gray-500">{{ collect([$company->telepon ?? null, $company->email ?? null])->filter()->join(' · ') }}</div>
                @endif
            </div>
            <div class="text-right">
                <div class="text-base font-bold tracking-wide">PENGAJUAN PEMBAYARAN</div>
                <div class="font-mono text-sm">{{ $rec->nomor }}</div>
                <div class="mt-1 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs uppercase text-gray-600">{{ str_replace('_', ' ', $rec->status) }}</div>
            </div>
        </div>

        {{-- Meta --}}
        <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-1.5 text-sm">
            @foreach ([
                ['Tanggal', $rec->tanggal->format('d F Y')],
                ['Jenis', $jenisLabel],
                ['Bagian', $rec->bagian?->nama_bagian ?? $rec->kode_bagian],
                ['No. Bukti', $rec->referensi ?: '—'],
                ['Keterangan', $rec->keterangan],
                ['Total', "Rp " . number_format((float) $rec->nominal, 0, ',', '.')],
            ] as [$label, $val])
                <div class="flex justify-between gap-3 border-b border-dashed border-gray-100 py-0.5">
                    <span class="text-gray-500">{{ $label }}</span>
                    <span class="text-right font-medium">{{ $val }}</span>
                </div>
            @endforeach
        </div>

        {{-- Tabel rincian --}}
        <table class="mt-5 w-full border-collapse text-sm">
            <thead>
                <tr class="border-y border-gray-300 bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <th class="px-2 py-1.5">#</th><th class="px-2 py-1.5">Akun</th><th class="px-2 py-1.5">Unit</th>
                    <th class="px-2 py-1.5">Keterangan</th><th class="px-2 py-1.5 text-right">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rec->details as $i => $d)
                    <tr class="border-b border-gray-100">
                        <td class="px-2 py-1.5 text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-2 py-1.5">{{ $d->kode_coa }} — {{ $d->nama_coa }}</td>
                        <td class="px-2 py-1.5">{{ $d->kode_unit }}</td>
                        <td class="px-2 py-1.5">{{ $d->keterangan ?: '—' }}</td>
                        <td class="px-2 py-1.5 text-right font-mono">Rp {{ number_format((float) $d->nominal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-gray-300 font-semibold">
                    <td class="px-2 py-1.5" colspan="4">Total Pengajuan</td>
                    <td class="px-2 py-1.5 text-right font-mono">Rp {{ number_format((float) $rec->nominal, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Tanda tangan: pemohon → approver (per tahap berlaku) → verifikator --}}
        {{-- Maksimal 4 kolom: di A4 portrait (lebar cetak ±186mm) lebih dari itu
             membuat tiap kotak terlalu sempit; sisanya turun ke baris berikutnya. --}}
        <div class="ttd mt-8 grid gap-6 text-center text-xs" style="grid-template-columns: repeat({{ min(max(count($signatures), 1), 4) }}, minmax(0, 1fr));">
            @foreach ($signatures as $s)
                <div>
                    <div class="text-gray-500">{{ $s['label'] }}</div>
                    @if ($s['nama'])
                        <div class="mt-2 rounded border border-gray-300 px-2 py-2">
                            <div class="font-semibold uppercase tracking-wide text-gray-700">Digitally Signed</div>
                            <div class="mt-1 font-medium">{{ $s['nama'] }}</div>
                            @if ($s['waktu'])<div class="mt-0.5 text-[10px] text-gray-500">{{ $s['waktu'] }}</div>@endif
                        </div>
                    @else
                        <div class="mt-12 border-t border-gray-400 pt-1">( ................................ )</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 350));</script>
</body>
</html>
