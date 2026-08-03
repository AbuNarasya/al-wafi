@php
    $diotorisasi = ! is_null($pp->diotorisasi_pada);
    $labelSumber = \App\Models\PerintahPembayaranDetail::SUMBER;
    // Kode verifikasi: memungkinkan penerima lembar mencocokkannya kembali ke
    // aplikasi. Diturunkan dari nomor & waktu otorisasi, jadi lembar yang
    // dicetak ulang membawa kode yang sama.
    $kodeVerifikasi = $diotorisasi
        ? strtoupper(substr(md5($pp->nomor.$pp->diotorisasi_pada->timestamp), 0, 4).'-'.substr($pp->nomor, -4))
        : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak {{ $pp->nomor }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 14mm; }
        }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Cap DRAF sama mencoloknya dengan cap VOID pada bukti kas — lembar yang
           belum diotorisasi tak boleh bisa disodorkan seolah sudah disetujui. */
        .cap-draf { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; z-index: 10; }
        .cap-draf span {
            transform: rotate(-20deg); text-align: center; line-height: 1.15;
            border: 6px solid rgb(161 98 7 / 0.32); color: rgb(161 98 7 / 0.32);
            font-size: 64px; font-weight: 800; letter-spacing: 0.1em;
            padding: 0.06em 0.28em; border-radius: 12px;
        }
        .cap-draf small { display: block; font-size: 20px; letter-spacing: 0.06em; }
    </style>
</head>
<body class="bg-white text-gray-900">
    <div class="relative mx-auto max-w-3xl p-8">
        @unless ($diotorisasi)
            <div class="cap-draf"><span>DRAF<small>BELUM DIOTORISASI</small></span></div>
        @endunless

        <div class="no-print mb-6 flex items-center justify-between">
            <a href="{{ route('perintah_pembayaran.show', $pp->kode_transaksi) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
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
                </div>
            </div>
            <div class="text-right">
                <div class="text-lg font-bold uppercase tracking-wide text-gray-800">Perintah Pembayaran</div>
                <div class="mt-1 font-mono text-sm text-gray-700">{{ $pp->nomor }}</div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
            <div>
                <div class="text-xs uppercase text-gray-400">Keterangan</div>
                <div class="font-semibold">{{ $pp->keterangan }}</div>
                <div class="mt-3 text-xs uppercase text-gray-400">Rekening Sumber (rencana)</div>
                <div class="font-medium">{{ $pp->rekeningRencana?->nama_rekening ?? '—' }}</div>
            </div>
            <div class="text-right">
                <table class="ml-auto text-sm">
                    <tr><td class="pr-3 text-gray-500">Tanggal Dokumen</td><td class="font-medium">{{ $pp->tanggal->format('d M Y') }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Tanggal Bayar</td><td class="font-medium">{{ $pp->tanggal_bayar?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Metode</td><td class="font-medium">{{ \App\Models\PerintahPembayaran::METODE[$pp->metode] ?? '—' }}</td></tr>
                    <tr><td class="pr-3 text-gray-500">Status</td><td class="font-medium">{{ \App\Models\PerintahPembayaran::STATUS[$pp->status] ?? $pp->status }}</td></tr>
                </table>
            </div>
        </div>

        {{-- Rincian: DUA kolom nominal berdampingan, supaya baris yang ditunda,
             dikurangi, dan ditambahkan terbaca oleh yang menerima lembarnya. --}}
        <table class="mt-6 w-full border-collapse text-sm">
            <thead>
                <tr class="border-y border-gray-300 bg-gray-100 text-left text-xs uppercase text-gray-600">
                    <th class="w-8 px-3 py-2">No</th>
                    <th class="px-3 py-2">Kewajiban</th>
                    <th class="px-3 py-2">Keterangan</th>
                    <th class="px-3 py-2 text-right">Diajukan</th>
                    <th class="px-3 py-2 text-right">Diotorisasi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pp->detail as $idx => $d)
                    @php $ditunda = in_array($d->status_baris, ['ditunda', 'batal'], true); @endphp
                    <tr class="border-b border-gray-200 align-top {{ $ditunda ? 'text-red-800' : '' }}">
                        <td class="px-3 py-2 text-gray-500">{{ $idx + 1 }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $d->nomor_dokumen }}</div>
                            <div class="text-[10px] text-gray-500">
                                {{ $labelSumber[$d->sumber] ?? $d->sumber }}{{ $d->pihak ? ' · '.$d->pihak : '' }}
                                @if ($d->ditambahkan_pengotorisasi) · ditambahkan @endif
                            </div>
                        </td>
                        <td class="px-3 py-2 text-gray-600">
                            {{ $d->keterangan }}
                            @if ($d->alasan)<div class="text-[10px] italic">{{ $d->alasan }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $d->ditambahkan_pengotorisasi ? '—' : number_format((float) $d->nominal_diajukan, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            @if ($ditunda)
                                <span class="text-xs">{{ $d->status_baris === 'batal' ? 'batal' : 'ditunda' }}</span>
                            @else
                                {{ number_format((float) $d->nominal_diotorisasi, 0, ',', '.') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-800 font-bold">
                    <td class="px-3 py-2.5 text-right" colspan="3">TOTAL</td>
                    <td class="px-3 py-2.5 text-right tabular-nums text-gray-600">{{ number_format((float) $pp->total_diajukan, 0, ',', '.') }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format((float) $pp->total_diotorisasi, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-3 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm">
            <span class="text-gray-500">Terbilang:</span>
            <span class="font-semibold capitalize italic text-gray-900">{{ \App\Support\Terbilang::rupiah($pp->total_diotorisasi) }}</span>
        </div>

        @if ($diotorisasi)
            <div class="mt-6 rounded-lg border border-dashed border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                <div class="font-bold">✓ Digitally approved</div>
                <div>{{ $pp->pengotorisasi?->nama }} · {{ $pp->diotorisasi_pada->format('d M Y H:i') }} WIB</div>
                @if ($pp->catatan_otorisasi)<div class="mt-0.5 text-xs">Catatan: {{ $pp->catatan_otorisasi }}</div>@endif
                <div class="mt-1 font-mono text-[10px] text-blue-700">verifikasi: {{ $kodeVerifikasi }}</div>
            </div>
        @else
            <div class="mt-6 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-600">
                Belum diotorisasi. Lembar ini <b>bukan</b> perintah bayar yang sah.
            </div>
        @endif

        @if ($pp->ditutup_pada)
            <div class="mt-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">
                <b>Dinyatakan selesai</b> {{ $pp->ditutup_pada->format('d M Y H:i') }}.
                @if ($pp->alasan_tutup) Alasan: {{ $pp->alasan_tutup }} @endif
                Kewajiban yang belum direalisasikan batal dibayar dari perintah ini.
            </div>
        @endif

        <div class="mt-10 grid grid-cols-3 gap-6 text-center text-xs">
            @foreach ([
                'Disusun oleh' => $pp->penyusun?->nama,
                'Diotorisasi' => $diotorisasi ? $pp->pengotorisasi?->nama : null,
                'Dilaksanakan' => null,
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
            Dicetak {{ now()->format('d M Y H:i') }} · {{ $pp->nomor }}
        </div>
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
