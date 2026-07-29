@extends('layouts.app')

@section('title', 'Pengajuan Anggaran ' . $rec->nomor)

@php
    $NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $lintasTahun = count(array_unique(array_column($bulanUrut, 'tahun'))) > 1;
    $bulanLabels = array_map(
        fn ($u) => $lintasTahun ? $NAMA_BULAN[$u['bulan'] - 1] . " '" . substr((string) $u['tahun'], 2) : $NAMA_BULAN[$u['bulan'] - 1],
        $bulanUrut,
    );
    $labelStatus = [
        'diajukan' => 'bg-amber-100 text-amber-700', 'disetujui' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-red-100 text-red-700', 'dibatalkan' => 'bg-gray-100 text-gray-500',
    ];
    $kolomTotal = array_fill(0, 12, '0');
    $total = '0';
    foreach ($baris as $b) {
        foreach ($b['bulanan'] as $i => $v) {
            $kolomTotal[$i] = \App\Support\Money::add($kolomTotal[$i], $v);
            $total = \App\Support\Money::add($total, $v);
        }
    }
@endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('budget.pengajuan.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Status Pengajuan Anggaran</a>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">
                {{ $rec->nomor }}
                <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$rec->status] ?? 'bg-gray-100 text-gray-500' }}">{{ ucfirst($rec->status) }}</span>
            </h2>
        </div>
        @if ($bolehBatal)
            <form method="POST" action="{{ route('budget.pengajuan.batal', $rec->id) }}"
                  onsubmit="return confirm('Batalkan pengajuan {{ $rec->nomor }}? Rantai persetujuannya ikut ditutup.')">
                @csrf
                <button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Batalkan Pengajuan</button>
            </form>
        @endif
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    @if ($rec->status === 'disetujui')
        <div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            ✓ Usulan ini sudah diterapkan — anggaran {{ $rec->bagian?->nama_bagian ?? $rec->kode_bagian }} TA {{ $labelTa }} kini berisi rincian di bawah.
        </div>
    @elseif ($rec->status === 'diajukan')
        <div class="mb-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Menunggu persetujuan — angka di bawah <b>belum</b> menjadi anggaran dan belum dipakai memeriksa overbudget.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            {{-- Meta --}}
            <div class="grid grid-cols-2 gap-x-8 gap-y-1.5 rounded-xl border border-gray-200 bg-white p-5 text-sm shadow-sm">
                @foreach ([
                    ['Tahun Anggaran', $labelTa],
                    ['Bagian', $rec->bagian?->nama_bagian ?? $rec->kode_bagian],
                    ['Unit', $unit?->nama_unit ?? 'Semua Unit'],
                    ['Diajukan', $rec->created_at?->format('d M Y')],
                    ['Keterangan', $rec->keterangan ?: '—'],
                    ['Total Setahun', 'Rp ' . number_format((float) $total, 0, ',', '.')],
                ] as [$label, $val])
                    <div class="flex justify-between gap-3 border-b border-dashed border-gray-100 py-0.5">
                        <span class="text-gray-500">{{ $label }}</span>
                        <span class="text-right font-medium">{{ $val }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Rincian per akun × 12 slot TA --}}
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-left uppercase text-gray-500">
                        <tr>
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 min-w-[13rem]">Akun</th>
                            @foreach ($bulanLabels as $bl)
                                <th class="px-2 py-2 text-right min-w-[5.5rem]">{{ $bl }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right min-w-[7rem]">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($baris as $b)
                            @php $totalBaris = array_reduce($b['bulanan'], fn ($s, $v) => \App\Support\Money::add($s, $v), '0'); @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5">
                                    <div class="font-medium text-gray-800">{{ $b['nama_coa'] }}</div>
                                    <div class="font-mono text-[10px] text-gray-400">{{ $b['kode_coa'] }}</div>
                                </td>
                                @foreach ($b['bulanan'] as $v)
                                    <td class="px-2 py-1.5 text-right font-mono {{ (float) $v == 0.0 ? 'text-gray-300' : 'text-gray-700' }}">
                                        {{ (float) $v == 0.0 ? '—' : number_format((float) $v, 0, ',', '.') }}
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-right font-mono font-semibold">{{ number_format((float) $totalBaris, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="px-4 py-6 text-center text-gray-400">Pengajuan ini tidak punya rincian.</td></tr>
                        @endforelse
                    </tbody>
                    @if (count($baris) > 0)
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                                <td class="sticky left-0 z-10 bg-gray-50 px-3 py-2">Total</td>
                                @foreach ($kolomTotal as $v)
                                    <td class="px-2 py-2 text-right font-mono">{{ number_format((float) $v, 0, ',', '.') }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-right font-mono">{{ number_format((float) $total, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div>
            @if ($timeline)
                @include('pengajuan._timeline', ['t' => $timeline])
            @else
                <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-500 shadow-sm">
                    Pengajuan ini tidak punya rantai persetujuan.
                </div>
            @endif
        </div>
    </div>
@endsection
