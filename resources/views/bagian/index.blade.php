@extends('layouts.app')

@section('title', 'Bagian / Struktur Organisasi')

@php $labelLevel = [1 => 'Yayasan', 2 => 'Bidang', 3 => 'Bagian']; @endphp

@section('content')
    <div x-data="bagianTree()">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" x-model="q" placeholder="Cari kode / nama…"
                       class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
                <button type="button" @click="expandAll()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Buka semua</button>
                <button type="button" @click="collapseAll()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Tutup semua</button>
            </div>

            @if (\App\Support\Akses::boleh('bagian', 'buat'))
                <a href="{{ route('bagian.create') }}"
                   class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                    + Tambah Bagian
                </a>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Nama Bagian</th>
                        <th class="px-4 py-3">Tingkat</th>
                        <th class="px-4 py-3">Induk</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($nodes as $b)
                        <tr data-kode="{{ $b->kode_bagian }}"
                            data-ancestors="{{ implode(',', $b->ancestors) }}"
                            data-text="{{ strtolower($b->kode_bagian . ' ' . $b->nama_bagian) }}"
                            x-show="visible($el)" x-cloak class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1" style="padding-left: {{ $b->depth * 20 }}px">
                                    @if ($b->has_children)
                                        <button type="button" @click="toggle('{{ $b->kode_bagian }}')"
                                                class="w-4 text-gray-400 hover:text-gray-600"
                                                x-text="collapsed['{{ $b->kode_bagian }}'] ? '▸' : '▾'">▾</button>
                                    @else
                                        <span class="inline-block w-4 text-center text-gray-300">·</span>
                                    @endif
                                    <span class="font-medium text-gray-900">{{ $b->kode_bagian }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ $b->nama_bagian }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $labelLevel[$b->level] ?? $b->level }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $b->kode_induk ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $b->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ ucfirst($b->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if (\App\Support\Akses::boleh('bagian', 'ubah'))
                                        <a href="{{ route('bagian.edit', $b) }}" class="text-brand hover:underline">Ubah</a>
                                    @endif
                                    @if (\App\Support\Akses::boleh('bagian', 'hapus'))
                                        <form method="POST" action="{{ route('bagian.destroy', $b) }}"
                                              onsubmit="return confirm('Hapus bagian {{ $b->kode_bagian }}?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function bagianTree() {
            return {
                q: '',
                collapsed: {},
                toggle(k) { this.collapsed[k] = !this.collapsed[k]; },
                expandAll() { this.collapsed = {}; },
                collapseAll() {
                    // Tutup setiap node yang muncul sebagai ancestor baris manapun.
                    const c = {};
                    document.querySelectorAll('tr[data-ancestors]').forEach((tr) => {
                        (tr.dataset.ancestors || '').split(',').filter(Boolean).forEach((a) => { c[a] = true; });
                    });
                    this.collapsed = c;
                },
                visible(el) {
                    const q = this.q.trim().toLowerCase();
                    if (q) return (el.dataset.text || '').includes(q);
                    const anc = (el.dataset.ancestors || '').split(',').filter(Boolean);
                    return !anc.some((a) => this.collapsed[a]);
                },
            };
        }
    </script>
@endsection
