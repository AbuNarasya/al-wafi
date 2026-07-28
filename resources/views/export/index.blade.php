@extends('layouts.app')

@section('title', 'Export Data')

@php
    // Tombol format (submit button name=format) untuk form khusus.
    $labelFmt = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Export Data</h2>
        <p class="mt-1 text-sm text-gray-500">Unduh seluruh data (master &amp; transaksi) ke format <b>Excel</b>, <b>PDF</b>, atau <b>CSV</b>. Kolom berlabel Bahasa Indonesia dengan nama relasi (vendor/customer/unit/akun) di-resolve.</p>
    </div>

    {{-- ============ EXPORT KHUSUS (dengan filter) ============ --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700">Export Khusus (dengan filter &amp; kolom terformat)</div>
        <div class="space-y-5 p-4">

            {{-- Jurnal mentah --}}
            <form method="GET" action="{{ route('export.jurnal_mentah') }}" x-data="{ semua: false }">
                <div class="mb-2 text-sm font-medium text-gray-800">Data Mentah Jurnal (per baris)</div>
                <label class="mb-2 flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="semua" value="1" x-model="semua" class="rounded border-gray-300"> Semua data (abaikan rentang tanggal)
                </label>
                <div class="grid items-end gap-3 sm:grid-cols-4" :class="semua ? 'opacity-40' : ''">
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Unit Bisnis</label>
                        <select name="kode_unit" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">@foreach ($unitOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Dari</label><input type="date" name="from" :disabled="semua" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Sampai</label><input type="date" name="to" :disabled="semua" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
                    <div class="flex gap-1">
                        @foreach ($labelFmt as $f => $lbl)
                            <button type="submit" name="format" value="{{ $f }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </form>

            {{-- Buku besar per akun --}}
            <form method="GET" action="{{ route('export.buku_besar') }}" class="border-t border-gray-100 pt-4">
                <div class="mb-2 text-sm font-medium text-gray-800">Buku Besar per Akun (Hutang / Piutang / Uang Muka / akun neraca lainnya)</div>
                <div class="grid items-end gap-3 sm:grid-cols-4">
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Akun COA</label>
                        <x-search-select name="kode_coa" :options="$coaOptions" placeholder="— pilih akun —" required /></div>
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Dari</label><input type="date" name="from" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Sampai</label><input type="date" name="to" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
                    <div class="flex gap-1">
                        @foreach ($labelFmt as $f => $lbl)
                            <button type="submit" name="format" value="{{ $f }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </form>

            {{-- Aset tetap per kategori --}}
            <form method="GET" action="{{ route('export.aset') }}" class="border-t border-gray-100 pt-4">
                <div class="mb-2 text-sm font-medium text-gray-800">Aset Tetap — per Kategori</div>
                <div class="grid items-end gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-gray-600">Kategori Aset</label>
                        <select name="kategori" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">@foreach ($kategoriOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div class="sm:col-span-2 flex gap-1">
                        @foreach ($labelFmt as $f => $lbl)
                            <button type="submit" name="format" value="{{ $f }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ============ BROWSER DATASET (Master & Transaksi) ============ --}}
    @foreach ($datasets as $grup => $items)
        <div class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700">{{ $grup }}</div>
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach ($items as $ds)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-800">{{ $ds['label'] }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex justify-end gap-1">
                                    @foreach ($labelFmt as $f => $lbl)
                                        <a href="{{ route('export.dataset', $ds['key']) }}?format={{ $f }}"
                                           class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</a>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
