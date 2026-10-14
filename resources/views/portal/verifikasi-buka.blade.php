@extends('layouts.portal')

@section('title', 'Verifikasi Data Keluarga')

@section('content')
    <div class="mb-5 mt-2 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="data" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">Verifikasi data keluarga</h1>
        <p class="mt-1 text-sm text-slate-500">Mohon periksa data keluarga & santri yang tercatat di pesantren, lalu perbarui bila ada yang keliru.
            Berlaku sampai <b>{{ $sampai->copy()->subDay()->locale('id')->translatedFormat('j F Y') }}</b>.</p>
    </div>

    {{-- data-no-confirm: memilih peran tak mengubah data apa pun, tak perlu dialog konfirmasi. --}}
    <form method="POST" action="{{ route('portal.verifikasi.buka', $kode) }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <p class="mb-3 text-sm font-semibold text-slate-900">Anda membuka sebagai:</p>
        <div class="space-y-2">
            @foreach (\App\Models\Wali::PERAN as $p => $label)
                @php $nama = $wali->{'nama_'.$p}; @endphp
                {{-- Peran wali (bukan orang tua) yang kosong tak ditawarkan — ayah & ibu selalu,
                     karena namanya justru bisa jadi yang perlu dilengkapi. --}}
                @if ($p !== 'wali' || filled($nama))
                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-300 bg-white px-4 py-3 has-[:checked]:border-brand has-[:checked]:bg-blue-50">
                        <input type="radio" name="peran" value="{{ $p }}" required class="text-brand focus:ring-brand">
                        <span class="text-sm">
                            <span class="block font-semibold text-slate-900">{{ $label }}</span>
                            <span class="block text-slate-500">{{ $nama ?: 'nama belum tercatat' }}</span>
                        </span>
                    </label>
                @endif
            @endforeach
        </div>
        @error('peran')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror

        @if ($butuhNis)
            {{-- Langkah kedua: link yang diteruskan ke orang lain tak cukup untuk membuka data. --}}
            <div class="mt-4">
                <label for="nis" class="mb-1 block text-sm font-semibold text-slate-900">NIS salah satu anak Anda</label>
                <input id="nis" name="nis" inputmode="numeric" data-bukan-rupiah required autocomplete="off" value="{{ old('nis') }}"
                       class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
                <p class="mt-1 text-xs text-slate-500">Tertera di rapor atau kartu santri. Bila punya lebih dari satu anak, cukup salah satu.</p>
                @error('nis')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        @endif

        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Lanjut periksa data</button>
    </form>
    <p class="mt-4 px-2 text-center text-xs text-slate-500">Link ini khusus untuk keluarga Anda. Mohon tidak dibagikan.</p>
@endsection
