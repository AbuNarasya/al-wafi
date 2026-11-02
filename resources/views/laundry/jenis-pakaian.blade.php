@extends('layouts.app')

@section('title', 'Jenis Pakaian Laundry')

@section('content')
    <div class="mx-auto max-w-2xl">
        @if (\App\Support\Akses::boleh('laundry-master', 'buat'))
            <form method="POST" action="{{ route('laundry.jenis.simpan') }}" class="flex gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                @csrf
                <input name="nama" required maxlength="100" placeholder="Jenis pakaian baru" value="{{ old('nama') }}" class="flex-1 rounded-lg border-gray-300 text-sm">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Tambah</button>
            </form>
        @endif

        <div class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
            @foreach ($rows as $j)
                <form method="POST" action="{{ route('laundry.jenis.ubah', $j->id) }}" class="flex items-center gap-3 px-4 py-2 text-sm">
                    @csrf @method('PUT')
                    <input name="nama" value="{{ $j->nama }}" maxlength="100" @disabled(! \App\Support\Akses::boleh('laundry-master', 'ubah'))
                           @class(['flex-1 rounded-lg border-gray-300 text-sm', 'text-gray-400' => ! $j->aktif])>
                    <span class="w-20 text-end text-xs text-gray-400">{{ $pemakaian[$j->id] ?? 0 }}× dipakai</span>
                    <label class="flex items-center gap-1 text-xs text-gray-600">
                        <input type="checkbox" name="aktif" value="1" @checked($j->aktif) @disabled(! \App\Support\Akses::boleh('laundry-master', 'ubah')) class="rounded border-gray-300"> aktif</label>
                    @if (\App\Support\Akses::boleh('laundry-master', 'ubah'))
                        <button class="rounded-lg border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Simpan</button>
                    @endif
                </form>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-500">Jenis yang nonaktif tak muncul di penerimaan baru; riwayat lama tetap memakai nama saat dicatat.</p>
    </div>
@endsection
