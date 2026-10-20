@extends('layouts.app')

@section('title', 'Penarikan Tunai Kegiatan')

@section('content')
    @php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Untuk kegiatan di luar pesantren (outbound, rihlah) santri membawa uang saku tunai. Wali mengajukan dari portal —
        dari <b>Dompet Santri</b>, kelipatan Rp 50.000 — dan saldonya langsung dicadangkan. Bagian kesantrian memutus
        pengajuannya; keuangan mengambil uangnya di bank lalu membagikannya lewat tap kartu.
    </p>

    @if (\App\Support\Akses::boleh('sesi-tarik-tunai', 'buat'))
        <details class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm" @if ($errors->any() || session('error')) open @endif>
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-brand">+ Buat sesi baru</summary>
            <form method="POST" action="{{ route('tarik_tunai.store') }}" class="space-y-4 border-t border-gray-100 p-4">
                @csrf
                @include('tarik-tunai._jadwal')
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field name="kode_jenjang" label="Peserta: jenjang" :options="['' => '— pilih —'] + $opsiJenjang" required />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Peserta: tingkat <span class="font-normal text-gray-400">(kosong = semua tingkat)</span></label>
                        <div class="flex flex-wrap gap-3 pt-1 text-sm">
                            @foreach (range(1, 6) as $t)
                                <label class="flex items-center gap-1"><input type="checkbox" name="tingkat[]" value="{{ $t }}" @checked(in_array($t, old('tingkat', []))) class="rounded border-gray-300"> {{ $t }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500">Peserta bisa ditambah (lewat NIS) atau dikeluarkan sesudah sesi dibuat. Sesi baru belum terlihat wali sampai Anda membukanya.</p>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Buat sesi</button>
            </form>
        </details>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Kegiatan</th><th class="px-4 py-2">Batas pengajuan</th><th class="px-4 py-2">Distribusi</th><th class="px-4 py-2 text-right">Peserta</th><th class="px-4 py-2 text-right">Maks/santri</th><th class="px-4 py-2">Tahap</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono"><a href="{{ route('tarik_tunai.show', $r->id_sesi) }}" class="text-brand hover:underline">{{ $r->nomor }}</a></td>
                        <td class="px-4 py-2">{{ $r->nama }}</td>
                        <td class="px-4 py-2">{{ $r->batas_pengajuan->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $r->tanggal_distribusi->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->santri_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->maks_per_santri) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-800">{{ $r->labelTahap() }}</span>
                            @if ($r->status === 'berjalan' && ! $r->dibuka)
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Tertutup untuk wali</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada sesi penarikan tunai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
