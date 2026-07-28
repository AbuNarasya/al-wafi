@extends('layouts.app')

@section('title', 'Ringkasan Outstanding')

@section('content')
    <p class="mb-4 text-sm text-gray-500">Rekap saldo outstanding lintas modul (read-only, tanpa jurnal).</p>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('kontrol.aging_ap') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-brand">
            <div class="text-xs uppercase tracking-wide text-gray-400">Hutang Vendor</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">@rp($s['hutang_vendor']['total'])</div>
            <div class="mt-1 text-xs text-gray-500">{{ $s['hutang_vendor']['jumlah'] }} invoice belum lunas</div>
        </a>
        <a href="{{ route('kontrol.uang_muka_operasional') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-brand">
            <div class="text-xs uppercase tracking-wide text-gray-400">Uang Muka Operasional</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">@rp($s['uang_muka_operasional']['total'])</div>
            <div class="mt-1 text-xs text-gray-500">{{ $s['uang_muka_operasional']['jumlah'] }} outstanding</div>
        </a>
        <a href="{{ route('kontrol.uang_muka_customer') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-brand">
            <div class="text-xs uppercase tracking-wide text-gray-400">Uang Muka Customer</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">@rp($s['uang_muka_customer']['total'])</div>
            <div class="mt-1 text-xs text-gray-500">{{ $s['uang_muka_customer']['jumlah'] }} belum diakui</div>
        </a>
        <a href="{{ route('kontrol.accrue_prepaid') }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-brand">
            <div class="text-xs uppercase tracking-wide text-gray-400">Accrue &amp; Prepaid</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">@rp($s['accrue']['total'])</div>
            <div class="mt-1 text-xs text-gray-500">{{ $s['accrue']['jumlah'] }} aktif</div>
        </a>
    </div>

    <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Aging Hutang Vendor</h3>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Belum Jatuh Tempo</th><th class="px-4 py-3">1–30 hari</th><th class="px-4 py-3">31–60</th><th class="px-4 py-3">61–90</th><th class="px-4 py-3">&gt;90</th></tr>
            </thead>
            <tbody>
                <tr class="tabular-nums">
                    <td class="px-4 py-3">@rp($s['hutang_vendor']['aging']['belum_jatuh_tempo'])</td>
                    <td class="px-4 py-3">@rp($s['hutang_vendor']['aging']['1-30'])</td>
                    <td class="px-4 py-3">@rp($s['hutang_vendor']['aging']['31-60'])</td>
                    <td class="px-4 py-3">@rp($s['hutang_vendor']['aging']['61-90'])</td>
                    <td class="px-4 py-3 {{ (float) $s['hutang_vendor']['aging']['>90'] > 0 ? 'font-semibold text-red-600' : '' }}">@rp($s['hutang_vendor']['aging']['>90'])</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
