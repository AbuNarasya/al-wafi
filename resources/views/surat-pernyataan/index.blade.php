@extends('layouts.app')

@section('title', 'Surat Pernyataan Wali')

@section('content')
    @php
        $bolehBuat = \App\Support\Akses::boleh('surat-pernyataan', 'buat');
        $bolehUbah = \App\Support\Akses::boleh('surat-pernyataan', 'ubah');
        $bolehHapus = \App\Support\Akses::boleh('surat-pernyataan', 'hapus');
    @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl text-sm text-gray-500">
            Teks yang dibaca dan disetujui wali saat mendaftarkan anaknya lewat portal wali.
            Hanya <b>satu versi yang aktif</b> &mdash; itulah yang tampil kepada wali. Versi yang
            <b>sudah disetujui</b> seorang wali terkunci: untuk mengubah isinya, buat versi baru.
        </p>
        @if ($bolehBuat)
            <a href="{{ route('surat_pernyataan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Versi Baru</a>
        @endif
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">
            Belum ada surat pernyataan. Selama kosong, wali <b>tidak bisa menyelesaikan pendaftaran</b> di portal.
        </div>
    @elseif ($rows->where('status', 'aktif')->isEmpty())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Tak ada versi yang aktif. Selama itu, wali <b>tidak bisa menyelesaikan pendaftaran</b> di portal.
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($rows as $r)
            @php $dipakai = $r->persetujuan_count; @endphp
            <div x-data="{ buka: {{ $r->status === 'aktif' ? 'true' : 'false' }} }"
                 @class(['rounded-xl border bg-white shadow-sm', 'border-emerald-400' => $r->status === 'aktif', 'border-gray-200' => $r->status !== 'aktif'])>
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <button type="button" @click="buka = ! buka" class="flex min-w-0 items-center gap-2 text-left">
                        <span class="text-gray-400" x-text="buka ? '▾' : '▸'"></span>
                        <span class="font-semibold text-gray-900">Versi {{ $r->versi }}</span>
                        <span class="truncate text-gray-600">&middot; {{ $r->judul }}</span>
                    </button>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        @if ($r->status === 'aktif')
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700">Aktif</span>
                        @else
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-500">Nonaktif</span>
                        @endif
                        @if ($dipakai > 0)
                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 font-medium text-indigo-700" title="Terkunci — tak bisa diubah atau dihapus">Disetujui {{ $dipakai }} wali &middot; terkunci</span>
                        @endif
                        <span class="text-gray-400">Berlaku {{ $r->berlaku_mulai?->format('d/m/Y') }}</span>
                    </div>
                </div>

                <div x-show="buka" x-cloak class="border-t border-gray-100 px-4 py-4">
                    <div class="max-h-96 overflow-y-auto whitespace-pre-line rounded-lg bg-gray-50 p-4 text-sm leading-relaxed text-gray-800">{{ $r->isi }}</div>

                    <div class="mt-3 flex flex-wrap items-center justify-end gap-3 text-sm">
                        @if ($bolehUbah && $r->status !== 'aktif')
                            <form method="POST" action="{{ route('surat_pernyataan.aktifkan', $r->id) }}"
                                  onsubmit="return confirm('Jadikan versi {{ $r->versi }} sebagai surat yang tampil kepada wali? Versi aktif sekarang akan dinonaktifkan.')">
                                @csrf
                                <button class="rounded-lg border border-emerald-600 px-3 py-1.5 font-medium text-emerald-700 hover:bg-emerald-50">Jadikan Aktif</button>
                            </form>
                        @endif
                        @if ($bolehUbah && $dipakai === 0)
                            <a href="{{ route('surat_pernyataan.edit', $r->id) }}" class="text-brand hover:underline">Ubah</a>
                        @endif
                        @if ($bolehHapus && $dipakai === 0 && $r->status !== 'aktif')
                            <form method="POST" action="{{ route('surat_pernyataan.destroy', $r->id) }}" onsubmit="return confirm('Hapus versi {{ $r->versi }}?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline">Hapus</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
