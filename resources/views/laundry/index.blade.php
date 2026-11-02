@extends('layouts.app')

@section('title', 'Papan Laundry')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex gap-2">
            <input name="cari" value="{{ $cari }}" placeholder="Cari nama / NIS / nomor" class="w-64 rounded-lg border-gray-300 text-sm">
            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Cari</button>
        </form>
        <div class="flex gap-2">
            @if (\App\Support\Akses::boleh('laundry', 'buat'))
                <a href="{{ route('laundry.terima') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">+ Terima cucian</a>
            @endif
            @if (\App\Support\Akses::boleh('laundry', 'ubah'))
                <a href="{{ route('laundry.serahkan') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Serahkan cucian</a>
            @endif
        </div>
    </div>
    <p class="mt-2 text-xs text-gray-500">Diserahkan hari ini: <b>{{ $diserahkanHariIni }}</b> kantong.
        Kantong yang Selesai diserahkan lewat <b>Serahkan cucian</b> (tap kartu santri &amp; cocokkan pakaian).</p>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        @foreach ($kolom as $status => $daftar)
            @php $bisaMaju = isset(\App\Models\Laundry::MAJU[$status]) && \App\Support\Akses::boleh('laundry', 'ubah'); @endphp
            {{-- Tanpa dialog konfirmasi: dipakai puluhan kali sehari, dan langkahnya ringan (tak menyentuh uang). --}}
            <form method="POST" action="{{ route('laundry.majukan') }}" data-no-confirm class="flex flex-col rounded-xl border border-gray-200 bg-gray-50 shadow-sm"
                  x-data="{ pilih: [], semua: @js($daftar->pluck('id')->all()) }">
                @csrf <input type="hidden" name="dari" value="{{ $status }}">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <p class="font-semibold text-gray-900">{{ \App\Models\Laundry::LABEL[$status] }} <span class="ms-1 rounded-full bg-white px-2 text-xs text-gray-600 ring-1 ring-gray-200">{{ $daftar->count() }}</span></p>
                    @if ($bisaMaju && $daftar->isNotEmpty())
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            <input type="checkbox" class="rounded border-gray-300" @change="pilih = $event.target.checked ? [...semua] : []"> semua</label>
                    @endif
                </div>
                <div class="max-h-[60vh] flex-1 space-y-2 overflow-y-auto p-3">
                    @forelse ($daftar as $l)
                        <div class="rounded-lg bg-white p-3 text-sm ring-1 ring-gray-200">
                            <div class="flex items-start gap-2">
                                @if ($bisaMaju)<input type="checkbox" name="id[]" value="{{ $l->id }}" x-model.number="pilih" class="mt-0.5 rounded border-gray-300">@endif
                                <a href="{{ route('laundry.show', $l->id) }}" class="min-w-0 flex-1 hover:underline">
                                    <p class="truncate font-medium text-gray-900">{{ $l->santri?->nama }}</p>
                                    <p class="text-xs text-gray-500">{{ $l->santri?->kelas?->nama ?? $l->santri?->nis }} · {{ $l->nomor }}</p>
                                </a>
                                <span class="shrink-0 text-end text-xs text-gray-500">{{ $l->berat }} kg<br>{{ $l->jumlahPotong() }} pcs</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-400">{{ \App\Models\Laundry::LABEL[$status] }} {{ $l->{"{$status}_pada"}?->timezone('Asia/Jakarta')->format('d/m H:i') }}</p>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-400">Kosong</p>
                    @endforelse
                </div>
                @if ($bisaMaju && $daftar->isNotEmpty())
                    <div class="border-t border-gray-200 p-3">
                        <button :disabled="pilih.length === 0" class="w-full rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">
                            <span x-text="pilih.length"></span> kantong &rarr; {{ \App\Models\Laundry::LABEL[\App\Models\Laundry::MAJU[$status]] }}</button>
                    </div>
                @endif
            </form>
        @endforeach
    </div>
@endsection
