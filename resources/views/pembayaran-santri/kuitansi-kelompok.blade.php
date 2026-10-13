<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuitansi Gabungan {{ $baris->first()->nomor }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { size: A4 portrait; margin: 12mm; }
            .lembar { max-width: none !important; padding: 0 !important; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body class="bg-white text-gray-800">
@php
    // Kuitansi GABUNGAN — beberapa tagihan yang dibayar sekaligus dari Portal Wali (satu
    // dompet / satu bukti transfer / satu VA). Kuitansi per tagihan tetap ada di daftar.
    $pertama = $baris->first();
    $wali = $pertama->santri?->wali;
    $total = $baris->sum(fn ($p) => (float) $p->nominal);
    $labelSumber = ['manual' => 'Transfer bank', 'dompet_wali' => 'Potong Dompet Wali', 'midtrans' => 'Virtual Account (Midtrans)'][$pertama->sumber] ?? $pertama->sumber;
    $anak = $baris->pluck('santri.nama')->unique()->implode(', ');
@endphp

    <div class="lembar mx-auto max-w-3xl p-6">
        <div class="no-print mb-5 flex items-center justify-between">
            <a href="{{ route(($lingkup === 'ppsb' ? 'pembayaran_ppsb' : 'pembayaran_kesantrian') . '.index') }}"
               class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak</button>
        </div>

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
                <div class="text-base font-bold uppercase tracking-wide text-gray-900">Kuitansi Pembayaran Gabungan</div>
                <div class="text-xs text-gray-600">No. {{ $pertama->nomor }}{{ $baris->count() > 1 ? ' s/d '.$baris->last()->nomor : '' }}</div>
                <div class="text-xs text-gray-600">{{ $pertama->tanggal->format('d F Y') }}</div>
            </div>
        </div>

        <table class="mt-4 w-full text-xs">
            <tr>
                <td class="w-32 py-0.5 align-top text-gray-500">Telah diterima dari</td>
                <td class="py-0.5 font-semibold text-gray-900">{{ $wali?->nama ?? '—' }} <span class="font-normal text-gray-500">(wali dari {{ $anak }})</span></td>
            </tr>
            <tr>
                <td class="py-0.5 align-top text-gray-500">Cara bayar</td>
                <td class="py-0.5 text-gray-900">{{ $labelSumber }} &middot; sekali bayar untuk {{ $baris->count() }} tagihan</td>
            </tr>
        </table>

        <table class="mt-4 w-full border border-gray-300 text-xs">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="border-b border-gray-300 px-3 py-2">No.</th>
                    <th class="border-b border-gray-300 px-3 py-2">Santri</th>
                    <th class="border-b border-gray-300 px-3 py-2">Untuk pembayaran</th>
                    <th class="border-b border-gray-300 px-3 py-2">Periode</th>
                    <th class="border-b border-gray-300 px-3 py-2 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($baris as $p)
                    <tr class="border-b border-gray-200">
                        <td class="px-3 py-2 text-gray-700">{{ $p->nomor }}</td>
                        <td class="px-3 py-2 text-gray-900">{{ $p->santri?->nama }}</td>
                        <td class="px-3 py-2 text-gray-900">
                            {{ $p->tagihan?->jenis?->nama ?? 'Pembayaran santri' }}
                            @if ($p->tagihan?->status === 'lunas')<span class="ml-1 rounded bg-emerald-100 px-1 text-[10px] font-semibold text-emerald-700">LUNAS</span>@endif
                        </td>
                        <td class="px-3 py-2 text-gray-700">{{ $p->tagihan?->periode ?? '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-900">@rp($p->nominal)</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-gray-50">
                    <td colspan="4" class="border-t border-gray-300 px-3 py-2 text-right font-semibold text-gray-700">Total Diterima</td>
                    <td class="border-t border-gray-300 px-3 py-2 text-right text-sm font-bold tabular-nums text-gray-900">@rp($total)</td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-3 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-xs">
            <span class="text-gray-500">Terbilang:</span>
            <span class="font-semibold capitalize italic text-gray-900">{{ \App\Support\Terbilang::rupiah($total) }}</span>
        </div>

        <div class="mt-6 grid grid-cols-3 gap-4 text-center text-[11px]">
            <div>
                <div class="text-gray-500">Penyetor</div>
                <div class="h-12"></div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $wali?->nama ?? '(………………)' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Dicatat oleh</div>
                <div class="flex h-12 items-center justify-center text-[10px] italic text-gray-400">tercatat digital</div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $pertama->namaPencatat() }}</div>
            </div>
            <div>
                <div class="text-gray-500">Diverifikasi — Keuangan</div>
                <div class="flex h-12 items-center justify-center text-[10px] italic text-gray-400">{{ optional($pertama->diverifikasi_pada)->format('d/m/Y H:i') }}</div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $pertama->pemverifikasi?->nama ?? ($pertama->sumber === 'manual' ? '—' : 'Sistem (otomatis)') }}</div>
            </div>
        </div>

        <p class="mt-4 text-center text-[10px] text-gray-400">
            Kuitansi ini sah tanpa tanda tangan basah — diterbitkan sistem setelah pembayaran diverifikasi.
        </p>
    </div>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
