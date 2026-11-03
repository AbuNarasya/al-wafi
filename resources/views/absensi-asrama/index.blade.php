@extends('layouts.app')

@section('title', 'Absensi Asrama (ISE)')

@php $label = \App\Services\Modules\AbsensiAsramaService::LABEL_KATEGORI; @endphp

@section('content')
    <div class="mb-3">
        <h2 class="text-xl font-semibold text-gray-900">Absensi Asrama (ISE)</h2>
        <p class="text-sm text-gray-500">Catatan absen asrama yang dikirim ISE ke ERP — tampil di portal wali (menu Kehadiran asrama). Spesifikasi untuk pengembang ISE: <code>docs/integrasi-ise-absensi.md</code>.</p>
    </div>
    @unless ($aktif)
        <p class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Pintu penerima ISE <b>belum aktif</b>: isi <code>ISE_TOKEN</code> di <code>.env</code> server, lalu berikan token itu ke pengembang ISE.</p>
    @endunless

    <div class="mb-3 flex flex-wrap gap-2 text-sm">
        @foreach (\App\Http\Controllers\AbsensiAsramaController::TAB as $k => $l)
            <a href="{{ route('absensi_asrama.index', ['tab' => $k]) }}" @class(['rounded-full px-4 py-1.5 font-semibold',
                'bg-brand text-white' => $tab === $k, 'bg-white text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50' => $tab !== $k])>{{ $l }}@if ($k === 'tak_tercocok' && $takTercocok) <span class="ms-1">{{ $takTercocok }}</span>@endif</a>
        @endforeach
    </div>

    @if ($tab !== 'log')
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($tab === 'data')<input type="date" name="tanggal" value="{{ $tanggal->format('Y-m-d') }}" class="rounded-lg border-gray-300 text-sm">@endif
            <input name="q" value="{{ $cari }}" placeholder="nama / NIS" class="w-48 rounded-lg border-gray-300 text-sm">
            <select name="kategori" class="rounded-lg border-gray-300 text-sm"><option value="">semua kategori</option>
                @foreach ($label as $k => $l)<option value="{{ $k }}" @selected($kategori === $k)>{{ $l }}</option>@endforeach</select>
            <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm font-semibold text-white">Saring</button>
        </form>
        @if ($tab === 'tak_tercocok')
            <p class="mb-3 text-sm text-gray-600">Santri di kiriman ISE yang tak cocok dengan santri ERP (id santri ERP / NIS). Betulkan NIS-nya di ISE atau ERP — kiriman berikutnya otomatis tercocok.</p>
        @endif
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-4 py-2">Waktu</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Asrama</th><th class="px-4 py-2">Sesi</th>
                    <th class="px-4 py-2">Status ISE</th><th class="px-4 py-2">Kategori</th><th class="px-4 py-2">Pencatat · catatan</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $a)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-xs">{{ $a->waktu->timezone('Asia/Jakarta')->format('d/m H:i') }}</td>
                            <td class="px-4 py-2">{{ $a->santri?->nama ?? $a->nama_santri }}
                                <span class="block text-xs {{ $a->santri ? 'text-gray-400' : 'font-semibold text-red-600' }}">{{ $a->santri ? $a->santri->nis : 'tak tercocok · NIS '.($a->nis ?: '—').' · id ISE '.($a->ise_santri_id ?: '—') }}</span></td>
                            <td class="px-4 py-2 text-xs">{{ $a->asrama }}</td>
                            <td class="px-4 py-2 text-xs">{{ $a->sesi }}</td>
                            <td class="px-4 py-2 text-xs">{{ $a->status }}</td>
                            <td class="px-4 py-2 text-xs">{{ $label[$a->kategori] ?? $a->kategori }}@if ($a->dikabarkan_pada)<span class="block text-gray-400">dikabarkan ke wali</span>@endif</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $a->pencatat }}@if ($a->catatan)<span class="block">{{ $a->catatan }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                    <th class="px-4 py-2">Waktu</th><th class="px-4 py-2 text-right">Jumlah</th><th class="px-4 py-2 text-right">Disimpan</th><th class="px-4 py-2 text-right">Dihapus</th>
                    <th class="px-4 py-2 text-right">Ditolak</th><th class="px-4 py-2 text-right">Tak tercocok</th><th class="px-4 py-2">Galat</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $r->jumlah }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $r->disimpan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $r->dihapus }}</td>
                            <td @class(['px-4 py-2 text-right tabular-nums', 'font-semibold text-red-700' => $r->ditolak > 0])>{{ $r->ditolak }}</td>
                            <td @class(['px-4 py-2 text-right tabular-nums', 'font-semibold text-amber-700' => $r->tak_tercocok > 0])>{{ $r->tak_tercocok }}</td>
                            <td class="max-w-md truncate px-4 py-2 text-xs text-gray-500" title="{{ $r->galat }}">{{ $r->galat }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada kiriman dari ISE.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
