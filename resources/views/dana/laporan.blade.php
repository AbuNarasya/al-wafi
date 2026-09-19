@extends('layouts.app')

@section('title', 'Laporan Pertanggungjawaban Dana')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Laporan Pertanggungjawaban Dana</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">
                Diterima, terpakai, dan sisa tiap dana — inilah yang dikirim ke donatur.
                Angkanya dihitung dari jurnal bertanda dana, bukan dari catatan terpisah.
            </p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
            <a href="{{ route('dana.laporan') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Sejak awal</a>
        </form>
    </div>

    @php $defisit = collect($data['baris'])->firstWhere('defisit', true); @endphp
    @if ($defisit)
        {{-- Terpakai melebihi yang diterima berarti dana itu menombok dari uang
             lain. Paling sering luput karena kasnya bercampur di rekening yang
             sama — jadi harus dikatakan di paling atas. --}}
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <b>Ada dana yang terpakai melebihi penerimaannya.</b> Itu berarti belanjanya ditombok dari uang lain —
            periksa baris yang bertanda merah di bawah.
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Dana</th>
                    <th class="px-4 py-3">Donatur</th>
                    <th class="px-4 py-3 text-right">Target</th>
                    <th class="px-4 py-3 text-right">Diterima</th>
                    <th class="px-4 py-3 text-right">Terpakai</th>
                    <th class="px-4 py-3 text-right">Sisa</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['baris'] as $b)
                    <tr class="align-top hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $b['nama_dana'] }}</div>
                            <div class="text-xs text-gray-500">{{ $b['kode_dana'] }} · {{ $b['label_jenis'] }}</div>
                            @if ($b['peruntukan'])
                                <div class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($b['peruntukan'], 90) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $b['donatur'] ?: '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">@rp($b['target'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-700">@rp($b['diterima'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-800">@rp($b['terpakai'])</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $b['defisit'] ? 'text-red-700' : 'text-gray-900' }}">
                            @rp($b['sisa'])
                            @if ($b['defisit'])<div class="text-[11px] font-normal text-red-600">melebihi penerimaan</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada dana terdaftar.</td></tr>
                @endforelse
            </tbody>
            @if ($data['baris'] !== [])
                <tfoot class="bg-gray-50 font-semibold text-gray-900">
                    <tr>
                        <td class="px-4 py-3" colspan="3">TOTAL</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($data['total']['diterima'])</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($data['total']['terpakai'])</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($data['total']['sisa'])</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Diterima = kredit bersih pada akun Pendapatan bertanda dana ini. Terpakai = debet bersih pada akun Beban
        bertanda dana ini. Baris jurnal yang <b>tidak</b> bertanda dana tidak terhitung di mana pun pada laporan ini.
    </p>
@endsection
