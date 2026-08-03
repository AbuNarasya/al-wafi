{{--
    Bukti Kas Masuk (RV) & Bukti Kas Keluar (PV) — SATU berkas untuk keduanya.

    Sengaja tidak dipisah dua view: kedua bukti ini hanya berbeda pada arah uang,
    lawan transaksi, dan satu kolom rincian. Dipisah, keduanya pasti perlahan
    berbeda bentuk — dan dua lembar bukti yang berbeda rupa dari satu aplikasi
    yang sama akan ditanya terus oleh yang memeriksa.

    $jenis = 'masuk' | 'keluar'.
--}}
@php
    $masuk = $jenis === 'masuk';
    $judul = $masuk ? 'Bukti Kas Masuk' : 'Bukti Kas Keluar';
    $kode = $masuk ? 'Receivable Voucher (RV)' : 'Payment Voucher (PV)';
    // Lawan transaksinya beda tabel, jadi namanya diresolusi di sini — bukan
    // dua cabang @if yang berulang di sepanjang berkas.
    $lawan = $masuk ? $rec->customer?->nama_customer : $rec->vendor?->nama_vendor;
    $batal = $rec->status !== 'aktif';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak {{ $judul }} {{ $rec->nomor_transaksi }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 14mm; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Cap VOID menutupi seluruh lembar supaya bukti yang sudah dibatalkan
           tak bisa dipakai lagi hanya karena tercetak sebelum di-void. */
        .cap-void {
            position: absolute; inset: 0; display: grid; place-items: center;
            pointer-events: none; z-index: 10;
        }
        .cap-void span {
            transform: rotate(-24deg);
            border: 6px solid rgb(185 28 28 / 0.35); color: rgb(185 28 28 / 0.35);
            font-size: 92px; font-weight: 800; letter-spacing: 0.12em;
            padding: 0.05em 0.25em; border-radius: 12px; line-height: 1;
        }
    </style>
</head>
<body class="bg-white text-gray-900">
    <div class="relative mx-auto max-w-3xl p-8">
        @if ($batal)
            <div class="cap-void"><span>VOID</span></div>
        @endif

        {{-- Toolbar (tidak ikut cetak) --}}
        <div class="no-print mb-6 flex items-center justify-between">
            <a href="{{ route($masuk ? 'cash_in.show' : 'cash_out.show', $rec->kode_transaksi) }}"
               class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">🖨 Cetak</button>
        </div>

        {{-- Kop --}}
        <div class="flex items-start justify-between border-b-2 border-gray-800 pb-4">
            <div>
                <div class="text-xl font-bold text-gray-900">{{ $company->nama_perusahaan ?? 'Pesantren' }}</div>
                @if ($company?->alamat)<div class="mt-0.5 text-xs text-gray-600">{{ $company->alamat }}</div>@endif
                <div class="text-xs text-gray-600">
                    @if ($company?->telepon)Telp: {{ $company->telepon }}@endif
                    @if ($company?->email) · {{ $company->email }}@endif
                    @if ($company?->npwp) · NPWP: {{ $company->npwp }}@endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-lg font-bold uppercase tracking-wide text-gray-800">{{ $judul }}</div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500">{{ $kode }}</div>
                <div class="mt-1 font-mono text-sm text-gray-700">{{ $rec->nomor_transaksi }}</div>
            </div>
        </div>

        {{-- Info voucher --}}
        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <div class="text-xs uppercase text-gray-400">{{ $masuk ? 'Diterima dari' : 'Dibayarkan kepada' }}</div>
                <div class="font-semibold">{{ $lawan ?: '—' }}</div>
                <div class="mt-3 text-xs uppercase text-gray-400">{{ $masuk ? 'Disetor ke rekening' : 'Dibayar dari rekening' }}</div>
                <div class="font-medium">{{ $rec->rekening?->nama_rekening ?? $rec->kode_rekening }}</div>
            </div>
            <div class="text-right">
                <table class="ml-auto text-sm">
                    <tr><td class="pr-3 text-gray-500">Tanggal</td><td class="font-medium">{{ $rec->tanggal->format('d M Y') }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Unit Bisnis</td><td class="font-medium">{{ $rec->unit?->nama_unit ?? $rec->kode_unit }}</td></tr>
                    @if ($rec->referensi)
                        <tr><td class="pr-3 text-gray-500">Referensi</td><td class="font-medium">{{ $rec->referensi }}</td></tr>
                    @endif
                    <tr><td class="pr-3 text-gray-500">Status</td><td class="font-medium {{ $batal ? 'text-red-700' : '' }}">{{ ucfirst($rec->status) }}</td></tr>
                </table>
            </div>
        </div>

        <div class="mt-4 text-sm"><span class="text-gray-500">Keterangan:</span> {{ $rec->keterangan }}</div>

        {{-- Rincian akun --}}
        <table class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-y border-gray-300 bg-gray-100 text-left text-xs uppercase text-gray-600">
                    <th class="w-8 px-3 py-2">No</th>
                    <th class="px-3 py-2">Akun {{ $masuk ? '(Kredit)' : '(Debit)' }}</th>
                    @if ($masuk)<th class="px-3 py-2">Jenis</th>@endif
                    <th class="px-3 py-2">Keterangan</th>
                    <th class="px-3 py-2 text-right">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rec->details as $idx => $d)
                    <tr class="border-b border-gray-200 align-top">
                        <td class="px-3 py-2 text-gray-500">{{ $idx + 1 }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $d->nama_coa }}</div>
                            <div class="font-mono text-[10px] text-gray-500">{{ $d->kode_coa }}</div>
                        </td>
                        @if ($masuk)
                            <td class="px-3 py-2 text-gray-600">{{ \App\Models\CashIn::JENIS[$d->jenis_kas_masuk] ?? $d->jenis_kas_masuk }}</td>
                        @endif
                        <td class="px-3 py-2 text-gray-600">{{ $d->keterangan }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($d->nominal)</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-800 font-bold">
                    <td class="px-3 py-2.5 text-right" colspan="{{ $masuk ? 4 : 3 }}">TOTAL</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">@rp($rec->nominal)</td>
                </tr>
            </tfoot>
        </table>

        {{-- Terbilang — yang dibaca pemeriksa lebih dulu saat angkanya diragukan. --}}
        <div class="mt-3 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm">
            <span class="text-gray-500">Terbilang:</span>
            <span class="font-semibold capitalize italic text-gray-900">{{ \App\Support\Terbilang::rupiah($rec->nominal) }}</span>
        </div>

        @if ($batal)
            <div class="mt-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{-- Dua @if BERURUTAN wajib dipisah: `@endif@if` membuat @if kedua tak
                     terkompilasi dan @endif-nya jadi yatim. --}}
                <b>Voucher ini telah dibatalkan (void)</b>@if ($rec->void_at) pada {{ $rec->void_at->format('d M Y H:i') }}@endif
                @if ($rec->void_by) oleh {{ $rec->void_by }}@endif.
                @if ($rec->void_reason)<div class="mt-0.5 text-xs">Alasan: {{ $rec->void_reason }}</div>@endif
                <div class="mt-0.5 text-xs">Jurnalnya sudah dibalik — lembar ini bukan bukti transaksi yang sah.</div>
            </div>
        @endif

        {{-- Tanda tangan. EMPAT kolom: pembuat, pemeriksa, penyetuju, dan pihak
             yang memegang uangnya — kolom terakhir itu yang membedakan bukti kas
             dari dokumen internal biasa. --}}
        <div class="mt-10 grid grid-cols-4 gap-4 text-center text-xs">
            @foreach ([
                'Dibuat oleh' => $rec->user?->nama,
                'Diperiksa' => null,
                'Disetujui' => null,
                ($masuk ? 'Penyetor' : 'Penerima') => $lawan,
            ] as $peran => $nama)
                <div>
                    <div class="text-gray-500">{{ $peran }},</div>
                    <div class="mt-14 border-t border-gray-400 pt-1 text-gray-700">
                        {{ $nama ? '( '.$nama.' )' : '( ________________ )' }}
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8 text-center text-[10px] text-gray-400">
            Dicetak {{ now()->format('d M Y H:i') }} · {{ $rec->nomor_transaksi }}
        </div>
    </div>

    <script>
        // Auto-buka dialog cetak saat halaman siap.
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
