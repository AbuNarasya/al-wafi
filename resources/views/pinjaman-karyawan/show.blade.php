@extends('layouts.app')

@section('title', 'Pinjaman ' . $rec->nomor)

@php
    $warna = ['aktif' => 'bg-amber-100 text-amber-700', 'lunas' => 'bg-emerald-100 text-emerald-700', 'void' => 'bg-gray-100 text-gray-500'];
@endphp

@section('content')
    <div class="mb-4">
        <a href="{{ route('pinjaman_karyawan.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Pinjaman Karyawan</a>
        <h2 class="mt-1 text-xl font-semibold text-gray-900">
            {{ $rec->nomor }}
            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$rec->status] ?? '' }}">{{ ucfirst($rec->status) }}</span>
        </h2>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="grid grid-cols-2 gap-x-8 gap-y-1.5 rounded-xl border border-gray-200 bg-white p-5 text-sm shadow-sm">
                @foreach ([
                    ['Karyawan', $rec->karyawan?->nama ?? $rec->kode_karyawan],
                    ['Bagian', $rec->karyawan?->bagian?->nama_bagian ?? '—'],
                    ['Tanggal Akad', $rec->tanggal->format('d M Y')],
                    ['Akun Piutang', $rec->kode_coa_piutang],
                    ['Pokok', 'Rp ' . number_format((float) $rec->pokok, 0, ',', '.')],
                    ['Terbayar', 'Rp ' . number_format((float) $rec->terbayar, 0, ',', '.')],
                    ['Sisa', 'Rp ' . number_format((float) $rec->sisa, 0, ',', '.')],
                    ['Keterangan', $rec->keterangan ?: '—'],
                ] as [$label, $val])
                    <div class="flex justify-between gap-3 border-b border-dashed border-gray-100 py-0.5">
                        <span class="text-gray-500">{{ $label }}</span>
                        <span class="text-right font-medium">{{ $val }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Jadwal termin: kesepakatan, bukan transaksi. --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Jadwal Termin</div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Jatuh Tempo</th><th class="px-4 py-2 text-right">Nominal</th><th class="px-4 py-2">Keterangan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rec->termin as $t)
                            <tr>
                                <td class="px-4 py-2 text-gray-400">{{ $t->urutan }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $t->jatuh_tempo->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">@rp($t->nominal)</td>
                                <td class="px-4 py-2 text-gray-500">{{ $t->keterangan ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Belum ada jadwal termin.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-400">
                    Termin adalah kesepakatan jadwal — tidak berjurnal. Yang mengurangi hutang hanyalah pembayaran di bawah.
                </p>
            </div>

            {{-- Riwayat pembayaran --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Riwayat Pembayaran</div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Cara</th><th class="px-4 py-2 text-right">Nominal</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rec->pembayaran as $b)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-800">{{ $b->nomor }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $b->tanggal->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs {{ $b->cara === 'potong_gaji' ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700' }}">
                                        {{ $caraOptions[$b->cara] ?? $b->cara }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">@rp($b->nominal)</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Belum ada pembayaran.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Form cicilan --}}
        <div>
            @if ($rec->status === 'aktif' && \App\Support\Akses::boleh('pinjaman-karyawan', 'ubah'))
                <form method="POST" action="{{ route('pinjaman_karyawan.bayar', $rec->id) }}"
                      x-data="{ cara: 'tunai' }"
                      class="space-y-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    @csrf
                    <div class="text-sm font-semibold text-gray-800">Catat Cicilan</div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Cara Bayar <span class="text-red-500">*</span></label>
                        <select name="cara" x-model="cara" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            @foreach ($caraOptions as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-field name="tanggal" label="Tanggal" type="date" :value="now()->toDateString()" required />
                    <x-field name="nominal" label="Nominal" type="number" required
                             :hint="'Sisa saat ini Rp ' . number_format((float) $rec->sisa, 0, ',', '.')" />

                    <div x-show="cara === 'tunai'" x-cloak>
                        <x-field name="kode_rekening" label="Kas/Rekening Penerima" :options="$rekeningOptions" />
                    </div>
                    <div x-show="cara === 'potong_gaji'" x-cloak>
                        <x-field name="kode_coa_lawan" label="Akun Beban Gaji" :options="$bebanOptions" />
                        <p class="mt-1 text-xs text-gray-500">
                            Potong gaji <b>tidak menerima uang</b>: jurnalnya mendebit beban gaji, bukan kas.
                            Bayarkan gajinya sebesar <b>netto</b> lewat Kas Keluar agar total bebannya tetap utuh.
                        </p>
                    </div>

                    <x-field name="keterangan" label="Keterangan" />

                    <button class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Cicilan</button>
                </form>
            @elseif ($rec->status === 'lunas')
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    Pinjaman ini sudah lunas.
                </div>
            @endif
        </div>
    </div>
@endsection
