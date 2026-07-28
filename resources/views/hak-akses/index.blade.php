@extends('layouts.app')

@section('title', 'Hak Akses Modul')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Pilih pengguna untuk mengatur matriks hak aksesnya. Administrator melewati matriks (selalu penuh).</p>
    <div class="mb-4"><x-filter-bar placeholder="Cari username / nama…" /></div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Username</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Peran</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($users as $u)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $u->username }}</td>
                        <td class="px-4 py-3">{{ $u->nama }}</td>
                        <td class="px-4 py-3">
                            @if ($u->is_admin)
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">ADMIN</span>
                            @else
                                <span class="text-gray-500">Pengguna</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('hak_akses.edit', $u) }}" class="text-indigo-600 hover:underline">Atur Hak Akses</a></td>
                    </tr>
                @endforeach
                <tr data-empty style="display:none"><td colspan="4" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
