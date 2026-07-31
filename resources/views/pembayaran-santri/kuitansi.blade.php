<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuitansi {{ $p->nomor }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            /* A4 portrait: isi kuitansi lebih tinggi dari A5 landscape (148mm)
               sehingga dulu terpotong jadi dua halaman. */
            @page { size: A4 portrait; margin: 12mm; }
            /* Marginnya sudah diberi @page; batas lebar & padding layar
               dilepas agar isi tak melebar melewati area cetak. */
            .lembar { max-width: none !important; padding: 0 !important; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body class="bg-white text-gray-800">
@php
    $santri = $p->santri;
    $jenis = $p->tagihan?->jenis;
    $labelTipe = [
        'registrasi' => 'Biaya Registrasi',
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Biaya Perlengkapan',
        'spp' => 'SPP',
        'lain' => 'Tagihan Lain-lain',
        // Dicocokkan lewat PERILAKU, bukan kode tipe: tipe buatan sendiri ikut
        // berlabel benar, dan kuitansi tak jatuh ke "Pembayaran" yang kabur.
    ][\App\Models\TipeBiaya::perilakuDari($jenis?->tipe) ?? ''] ?? 'Pembayaran';

    $labelSumber = ['manual' => 'Setoran langsung', 'dompet_wali' => 'Potong Dompet Wali', 'xendit' => 'Pembayaran daring'][$p->sumber] ?? $p->sumber;
    $sisaSesudah = $p->tagihan ? (float) $p->tagihan->sisa : null;
    $lunas = $p->tagihan?->status === 'lunas';
@endphp

    <div class="lembar mx-auto max-w-3xl p-6">
        {{-- Toolbar (tidak ikut cetak) --}}
        <div class="no-print mb-5 flex items-center justify-between">
            <a href="{{ route(($lingkup === 'ppsb' ? 'pembayaran_ppsb' : 'pembayaran_kesantrian') . '.index') }}"
               class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
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
                <div class="text-base font-bold uppercase tracking-wide text-gray-900">Kuitansi Pembayaran</div>
                <div class="text-xs text-gray-600">No. {{ $p->nomor }}</div>
                <div class="text-xs text-gray-600">{{ $p->tanggal->format('d F Y') }}</div>
            </div>
        </div>

        {{-- Identitas santri --}}
        <table class="mt-4 w-full text-xs">
            <tr>
                <td class="w-32 py-0.5 align-top text-gray-500">Telah diterima dari</td>
                <td class="py-0.5 font-semibold text-gray-900">
                    {{ $santri->wali?->nama ?? '—' }}
                    <span class="font-normal text-gray-500">(wali dari {{ $santri->nama }})</span>
                </td>
                <td class="w-28 py-0.5 align-top text-gray-500">No. Pendaftaran</td>
                <td class="py-0.5 text-gray-900">{{ $santri->no_pendaftaran }}</td>
            </tr>
            <tr>
                <td class="py-0.5 align-top text-gray-500">Nama santri</td>
                <td class="py-0.5 text-gray-900">{{ $santri->nama }}</td>
                <td class="py-0.5 align-top text-gray-500">NIS</td>
                <td class="py-0.5 text-gray-900">{{ $santri->nis ?? '—' }}</td>
            </tr>
            <tr>
                <td class="py-0.5 align-top text-gray-500">Jenjang / T.A</td>
                <td class="py-0.5 text-gray-900">{{ $santri->jenjang?->nama ?? $santri->kode_jenjang ?? '—' }} · {{ $santri->tahun_ajaran ?? '—' }}</td>
                <td class="py-0.5 align-top text-gray-500">Metode</td>
                <td class="py-0.5 text-gray-900">{{ $p->metode ?: $labelSumber }}</td>
            </tr>
        </table>

        {{-- Rincian --}}
        <table class="mt-4 w-full border border-gray-300 text-xs">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="border-b border-gray-300 px-3 py-2">Untuk Pembayaran</th>
                    <th class="border-b border-gray-300 px-3 py-2">Periode</th>
                    <th class="border-b border-gray-300 px-3 py-2 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-3 py-2 text-gray-900">
                        <div class="font-semibold">{{ $labelTipe }}</div>
                        <div class="text-[11px] text-gray-500">{{ $jenis?->nama ?? $p->tagihan?->kode_jenis ?? 'Pembayaran santri' }}</div>
                    </td>
                    <td class="px-3 py-2 text-gray-700">{{ $p->tagihan?->periode ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900">@rp($p->nominal)</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="bg-gray-50">
                    <td colspan="2" class="border-t border-gray-300 px-3 py-2 text-right font-semibold text-gray-700">Total Diterima</td>
                    <td class="border-t border-gray-300 px-3 py-2 text-right text-sm font-bold tabular-nums text-gray-900">@rp($p->nominal)</td>
                </tr>
            </tfoot>
        </table>

        {{-- Terbilang --}}
        <div class="mt-3 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-xs">
            <span class="text-gray-500">Terbilang:</span>
            <span class="font-semibold capitalize italic text-gray-900">{{ \App\Support\Terbilang::rupiah($p->nominal) }}</span>
        </div>

        {{-- Status tagihan setelah pembayaran ini --}}
        @if ($p->tagihan)
            <div class="mt-2 flex items-center justify-between text-[11px] text-gray-600">
                <div>
                    Sisa tagihan {{ $jenis?->nama ?? '' }} setelah pembayaran ini:
                    <b class="text-gray-900">@rp($sisaSesudah)</b>
                    @if ($lunas)<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 font-semibold text-emerald-700">LUNAS</span>@endif
                </div>
                <div>Diterima pada rekening: {{ $p->rekening?->nama_rekening ?? $p->kode_rekening }}</div>
            </div>
        @endif

        {{-- Tanda tangan: dari jejak sistem, tidak pernah diketik manual --}}
        <div class="mt-6 grid grid-cols-3 gap-4 text-center text-[11px]">
            <div>
                <div class="text-gray-500">Penyetor</div>
                <div class="h-12"></div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $santri->wali?->nama ?? '(………………)' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Dicatat oleh</div>
                <div class="flex h-12 items-center justify-center text-[10px] italic text-gray-400">tercatat digital</div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $p->pencatat?->nama ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Diverifikasi — Keuangan</div>
                <div class="flex h-12 items-center justify-center text-[10px] italic text-gray-400">
                    {{ optional($p->diverifikasi_pada)->format('d/m/Y H:i') }}
                </div>
                <div class="border-t border-gray-400 pt-1 text-gray-900">{{ $p->pemverifikasi?->nama ?? '—' }}</div>
            </div>
        </div>

        <p class="mt-4 text-center text-[10px] text-gray-400">
            Kuitansi ini sah tanpa tanda tangan basah — diterbitkan sistem setelah pembayaran diverifikasi keuangan.
        </p>
    </div>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
