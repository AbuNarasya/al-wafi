@extends('layouts.app')

@section('title', 'Duplikat Jenis Biaya')

@section('content')
    <div class="mx-auto max-w-4xl space-y-4">
        <a href="{{ route('jenis_biaya.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke Jenis Biaya</a>

        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Duplikat ke Tahun Ajaran Baru</h2>
            <p class="mt-1 text-sm text-gray-500">
                Menyalin seluruh jenis biaya milik satu tahun ajaran ke tahun ajaran lain — nominal, jenjang, jalur,
                akun COA, dan unitnya ikut. Kode baru dibentuk dengan menukar dua digit tahun di dalam kode
                (mis. <code class="rounded bg-gray-100 px-1">UP-SMP27-REG</code> &rarr; <code class="rounded bg-gray-100 px-1">UP-SMP28-REG</code>).
                Nominal tetap bisa disunting setelah disalin.
            </p>

            <form method="GET" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Dari Tahun Ajaran</label>
                    <select name="sumber" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih —</option>
                        @foreach ($opsiTa as $kode => $label)
                            <option value="{{ $kode }}" @selected($sumber === $kode)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Ke Tahun Ajaran</label>
                    <select name="tujuan" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih —</option>
                        @foreach ($opsiTa as $kode => $label)
                            <option value="{{ $kode }}" @selected($tujuan === $kode)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50">Lihat Pratinjau</button>
                </div>
            </form>
        </div>

        @if ($rencana !== null)
            @php
                $siap = collect($rencana)->where('status', 'siap');
                $bentrok = collect($rencana)->where('status', 'bentrok');
            @endphp

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                    <div class="text-sm font-semibold text-gray-700">
                        Pratinjau — {{ $siap->count() }} akan disalin
                        @if ($bentrok->count()) <span class="font-normal text-amber-700">· {{ $bentrok->count() }} dilewati</span> @endif
                    </div>
                    @if ($siap->count() > 0)
                        <form method="POST" action="{{ route('jenis_biaya.duplikat') }}">
                            @csrf
                            <input type="hidden" name="sumber" value="{{ $sumber }}">
                            <input type="hidden" name="tujuan" value="{{ $tujuan }}">
                            <button class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                                Salin {{ $siap->count() }} Jenis Biaya
                            </button>
                        </form>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Kode Lama</th><th class="px-4 py-2">Kode Baru</th>
                                <th class="px-4 py-2">Nama Baru</th><th class="px-4 py-2">Tipe</th>
                                <th class="px-4 py-2">Berlaku Untuk</th><th class="px-4 py-2">Hasil</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rencana as $r)
                                <tr class="{{ $r['status'] === 'siap' ? '' : 'bg-amber-50/50' }}">
                                    <td class="px-4 py-2 text-gray-500">{{ $r['kode'] }}</td>
                                    <td class="px-4 py-2 font-medium text-gray-900">{{ $r['kode_baru'] }}</td>
                                    <td class="px-4 py-2 text-gray-700">{{ $r['nama_baru'] }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ str_replace('_', ' ', ucfirst($r['tipe'])) }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ $r['cakupan'] }}</td>
                                    <td class="px-4 py-2">
                                        @if ($r['status'] === 'siap')
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Akan disalin</span>
                                        @else
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Dilewati</span>
                                            <div class="mt-0.5 text-[11px] text-gray-500">{{ $r['alasan'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">
                                    Tidak ada jenis biaya pada T.A {{ $sumber }} untuk disalin.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
