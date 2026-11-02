@extends('layouts.app')

@section('title', 'Riwayat Laundry')

@section('content')
    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
        <label><span class="block text-xs text-gray-500">Dari</span><input type="date" name="dari" value="{{ $dari->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></label>
        <label><span class="block text-xs text-gray-500">Sampai</span><input type="date" name="sampai" value="{{ $sampai->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></label>
        <label><span class="block text-xs text-gray-500">Status</span>
            <select name="status" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua</option>
                @foreach (\App\Models\Laundry::LABEL as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach
            </select></label>
        <label><span class="block text-xs text-gray-500">Cari</span><input name="cari" value="{{ $cari }}" placeholder="nama / NIS / nomor" class="rounded-lg border-gray-300 text-sm"></label>
        <label class="flex items-center gap-1 pb-2"><input type="checkbox" name="kurang" value="1" @checked(request()->boolean('kurang')) class="rounded border-gray-300"> hanya yang kurang</label>
        <button class="rounded-lg border border-gray-300 px-3 py-2 hover:bg-gray-50">Tampilkan</button>
    </form>

    <p class="mt-3 text-sm text-gray-600"><b>{{ $ringkas['kantong'] }}</b> kantong · <b>{{ number_format($ringkas['berat'], 2, ',', '.') }}</b> kg</p>

    <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
                <tr><th class="px-3 py-2 text-start">Diterima</th><th class="px-3 py-2 text-start">Nomor</th><th class="px-3 py-2 text-start">Santri</th>
                    <th class="px-3 py-2 text-end">Berat</th><th class="px-3 py-2 text-end">Potong</th><th class="px-3 py-2 text-start">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $l)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2">{{ $l->diterima_pada->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2"><a href="{{ route('laundry.show', $l->id) }}" class="text-brand hover:underline">{{ $l->nomor }}</a></td>
                        <td class="px-3 py-2">{{ $l->santri?->nama }} <span class="text-xs text-gray-400">{{ $l->santri?->nis }}</span></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $l->berat }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $l->jumlahPotong() }}</td>
                        <td class="px-3 py-2">{{ $l->labelStatus() }}@if ($l->ada_kurang) <span class="text-xs font-semibold text-red-700">· kurang</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
