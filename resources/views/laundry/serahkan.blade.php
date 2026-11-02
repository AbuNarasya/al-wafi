@extends('layouts.app')

@section('title', 'Serahkan Cucian')

@section('content')
    <a href="{{ route('laundry.index') }}" class="text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    @unless ($santri)
        @include('laundry._kenali', ['rute' => 'laundry.serahkan', 'judul' => 'Serahkan cucian — tap kartu santri'])
    @else
        @php $siap = $kantong->where('status', 'selesai'); $belum = $kantong->where('status', '!=', 'selesai'); @endphp
        <div class="mt-3 flex items-center justify-between">
            <p class="text-base font-semibold text-gray-900">{{ $santri->nama }}
                <span class="text-sm font-normal text-gray-500">{{ $santri->nis }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></p>
            <a href="{{ route('laundry.serahkan') }}" class="text-xs text-brand hover:underline">santri lain</a>
        </div>
        @if ($cara === 'manual')
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Tanpa kartu — dicatat sebagai pencarian manual.</p>
        @endif

        @if ($belum->isNotEmpty())
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">Belum selesai:
                @foreach ($belum as $b){{ $b->nomor }} ({{ $b->labelStatus() }})@if (! $loop->last), @endif @endforeach</p>
        @endif

        @if ($siap->isEmpty())
            <p class="mt-4 rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500 shadow-sm">Tidak ada cucian yang siap diambil.</p>
        @else
            <form method="POST" action="{{ route('laundry.simpan_serahkan') }}" class="mt-4 space-y-4"
                  x-data="{ kurang: {}, get adaKurang() { return Object.values(this.kurang).some(v => v) } }">
                @csrf
                @if ($cara === 'kartu')<input type="hidden" name="kartu" value="{{ $uid }}">@else<input type="hidden" name="santri" value="{{ $santri->id }}">@endif
                @foreach ($siap as $l)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                        <label class="flex items-center gap-2 font-semibold text-gray-900">
                            <input type="checkbox" name="id[]" value="{{ $l->id }}" checked class="rounded border-gray-300">
                            {{ $l->nomor }} · {{ $l->berat }} kg · diterima {{ $l->diterima_pada->timezone('Asia/Jakarta')->format('d/m H:i') }}
                        </label>
                        @if ($l->catatan)<p class="mt-1 text-xs text-gray-500">Catatan penerimaan: {{ $l->catatan }}</p>@endif
                        <table class="mt-2 w-full">
                            <thead class="text-xs text-gray-500"><tr><th class="text-start font-normal">Pakaian</th><th class="w-24 text-end font-normal">Diterima</th><th class="w-28 text-end font-normal">Dikembalikan</th></tr></thead>
                            <tbody>
                                @foreach ($l->item as $it)
                                    <tr class="border-t border-gray-100">
                                        <td class="py-1.5">{{ $it->nama_pakaian }}</td>
                                        <td class="text-end tabular-nums">{{ $it->jumlah }}</td>
                                        <td class="text-end">
                                            <input type="number" name="kembali[{{ $it->id }}]" min="0" max="{{ $it->jumlah }}" value="{{ old('kembali.'.$it->id, $it->jumlah) }}"
                                                   @input="kurang[{{ $it->id }}] = ($event.target.value === '' ? 0 : +$event.target.value) < {{ $it->jumlah }}"
                                                   class="w-20 rounded-lg border-gray-300 text-end text-sm tabular-nums">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach

                <div x-show="adaKurang" x-cloak class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm">
                    <p class="font-semibold text-red-800">Ada pakaian yang kurang.</p>
                    <label class="mt-2 block"><span class="text-xs text-red-700">Catatan * (dicari dulu / diganti / sudah dikonfirmasi santri)</span>
                        <textarea name="catatan_serah" rows="2" class="w-full rounded-lg border-red-300 text-sm">{{ old('catatan_serah') }}</textarea></label>
                </div>
                <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Serahkan</button>
            </form>
        @endif
    @endunless
@endsection
