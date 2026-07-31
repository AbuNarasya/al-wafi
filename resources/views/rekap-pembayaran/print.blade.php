<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap Pembayaran — {{ $santri->nama }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { size: A4 portrait; margin: 12mm; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body class="bg-white text-gray-800">
    <div class="mx-auto max-w-4xl p-6">
        {{-- Toolbar (tidak ikut cetak) --}}
        <div class="no-print mb-5 flex items-center justify-between">
            <a href="{{ route('rekap_pembayaran.show', $santri->id) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak</button>
        </div>

        {{-- Kop --}}
        <div class="flex items-start justify-between border-b-2 border-gray-800 pb-3">
            <div>
                <div class="text-lg font-bold text-gray-900">{{ $company->nama_perusahaan ?? 'Yayasan' }}</div>
                @if ($company?->alamat)<div class="mt-0.5 text-[11px] text-gray-600">{{ $company->alamat }}</div>@endif
                <div class="text-[11px] text-gray-600">
                    @if ($company?->telepon)Telp: {{ $company->telepon }}@endif
                    @if ($company?->email) · {{ $company->email }}@endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-base font-bold uppercase tracking-wide text-gray-900">Rekap Pembayaran Santri</div>
                <div class="text-xs text-gray-600">Dicetak {{ now()->format('d F Y H:i') }}</div>
            </div>
        </div>

        {{-- Identitas --}}
        <table class="mt-4 w-full text-xs">
            <tr>
                <td class="w-28 py-0.5 text-gray-500">Nama santri</td>
                <td class="py-0.5 font-semibold text-gray-900">{{ $santri->nama }}</td>
                <td class="w-28 py-0.5 text-gray-500">No. Pendaftaran</td>
                <td class="py-0.5 text-gray-900">{{ $santri->no_pendaftaran }}</td>
            </tr>
            <tr>
                <td class="py-0.5 text-gray-500">Wali</td>
                <td class="py-0.5 text-gray-900">{{ $santri->wali?->nama ?? '—' }}</td>
                <td class="py-0.5 text-gray-500">NIS</td>
                <td class="py-0.5 text-gray-900">{{ $santri->nis ?? '—' }}</td>
            </tr>
            <tr>
                <td class="py-0.5 text-gray-500">Jenjang / T.A</td>
                <td class="py-0.5 text-gray-900">{{ $santri->jenjang?->nama ?? $santri->kode_jenjang ?? '—' }} · {{ $santri->tahun_ajaran ?? '—' }}</td>
                <td class="py-0.5 text-gray-500">Status</td>
                <td class="py-0.5 text-gray-900">{{ ucfirst(str_replace('_', ' ', $santri->status)) }}</td>
            </tr>
        </table>

        {{-- Ringkasan --}}
        <div class="mt-4 grid grid-cols-4 gap-2 text-center text-xs">
            <div class="rounded border border-gray-300 px-2 py-2">
                <div class="text-[10px] text-gray-500">Total Tagihan</div>
                <div class="font-bold tabular-nums text-gray-900">@rp($ringkasan['tagihan'])</div>
            </div>
            <div class="rounded border border-gray-300 px-2 py-2">
                <div class="text-[10px] text-gray-500">Sudah Dibayar</div>
                <div class="font-bold tabular-nums text-gray-900">@rp($ringkasan['terbayar'])</div>
            </div>
            <div class="rounded border border-gray-300 px-2 py-2">
                <div class="text-[10px] text-gray-500">Sisa</div>
                <div class="font-bold tabular-nums text-gray-900">@rp($ringkasan['sisa'])</div>
            </div>
            <div class="rounded border border-gray-300 px-2 py-2">
                <div class="text-[10px] text-gray-500">Menunggu Verifikasi</div>
                <div class="font-bold tabular-nums text-gray-900">@rp($ringkasan['menunggu'])</div>
            </div>
        </div>

        {{-- Tagihan --}}
        <div class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-600">Rincian Tagihan</div>
        <table class="mt-1 w-full border border-gray-300 text-[11px]">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="border-b border-gray-300 px-2 py-1.5">Jenis</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Periode</th>
                    <th class="border-b border-gray-300 px-2 py-1.5 text-right">Nominal</th>
                    <th class="border-b border-gray-300 px-2 py-1.5 text-right">Terbayar</th>
                    <th class="border-b border-gray-300 px-2 py-1.5 text-right">Sisa</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tagihan as $t)
                    <tr class="border-b border-gray-200">
                        <td class="px-2 py-1.5 text-gray-900">{{ $t['jenis'] }}</td>
                        <td class="px-2 py-1.5 text-gray-600">{{ $t['periode'] ?? '—' }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">@rp($t['nominal'])</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">@rp($t['terbayar'])</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">@rp($t['sisa'])</td>
                        <td class="px-2 py-1.5 text-gray-600">{{ str_replace('_', ' ', ucfirst($t['status'])) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-2 py-4 text-center text-gray-400">Belum ada tagihan.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pembayaran --}}
        <div class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-600">Riwayat Pembayaran</div>
        <table class="mt-1 w-full border border-gray-300 text-[11px]">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="border-b border-gray-300 px-2 py-1.5">Tanggal</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Nomor</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Untuk</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Metode</th>
                    <th class="border-b border-gray-300 px-2 py-1.5 text-right">Nominal</th>
                    <th class="border-b border-gray-300 px-2 py-1.5">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pembayaran as $p)
                    <tr class="border-b border-gray-200">
                        <td class="px-2 py-1.5 text-gray-600">{{ $p->tanggal->format('d/m/Y') }}</td>
                        <td class="px-2 py-1.5 text-gray-900">{{ $p->nomor }}</td>
                        <td class="px-2 py-1.5 text-gray-700">{{ $p->tagihan?->jenis?->nama ?? '—' }}{{ $p->tagihan?->periode ? " ({$p->tagihan->periode})" : '' }}</td>
                        <td class="px-2 py-1.5 text-gray-600">{{ $p->metode ?: ($p->sumber === 'dompet_wali' ? 'Dompet Wali' : '—') }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">@rp($p->nominal)</td>
                        <td class="px-2 py-1.5 text-gray-600">{{ ucfirst(str_replace('_', ' ', $p->status)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-2 py-4 text-center text-gray-400">Belum ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-8 flex justify-end text-center text-[11px]">
            <div class="w-56">
                <div class="text-gray-500">Mengetahui — Bagian Keuangan</div>
                <div class="h-14"></div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">( ……………………………… )</div>
            </div>
        </div>

        <p class="mt-3 text-[10px] text-gray-400">
            Angka "Sudah Dibayar" hanya menghitung pembayaran yang telah diverifikasi keuangan.
        </p>
    </div>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
