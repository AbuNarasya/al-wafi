@extends('layouts.app')

@section('title', 'Generate NIS')

@php
    $bolehTerbit = \App\Support\Akses::boleh('nis', 'buat');
    $bolehSetel = \App\Support\Akses::boleh('nis', 'ubah');
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Generate NIS</h2>
        <p class="mt-1 text-sm text-gray-500">
            NIS diterbitkan <b>massal &amp; manual</b>, bukan otomatis saat daftar ulang — nomornya
            berurut menurut <b>abjad nama</b> dalam satu angkatan jenjang, dan abjad baru bisa
            ditentukan setelah seluruh angkatan diterima.
        </p>
    </div>

    {{-- Pengaturan format --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-800">Format NIS</h3>
        <form method="POST" action="{{ route('nis.format') }}" class="mt-3 flex flex-wrap items-end gap-3"
              data-confirm="Ubah format NIS? Yang sudah terbit tidak berubah — format ini berlaku untuk penerbitan berikutnya.">
            @csrf @method('PUT')
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">Pola</label>
                <input type="text" name="format" value="{{ old('format', $pengaturan->format) }}" required maxlength="100"
                       @disabled(! $bolehSetel)
                       class="w-72 rounded-lg border-gray-300 px-3 py-1.5 font-mono text-sm disabled:bg-gray-100">
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                <div class="text-[11px] font-medium text-emerald-700">Contoh hasil</div>
                <div class="font-mono text-base font-bold text-emerald-900">{{ $contoh }}</div>
            </div>
            @if ($bolehSetel)
                <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-900">Simpan Format</button>
            @endif
        </form>

        <div class="mt-3 grid gap-1 text-[11px] text-gray-500 sm:grid-cols-2 lg:grid-cols-3">
            <div><code class="font-mono text-gray-700">&#123;TA4&#125;</code> — tahun ajaran masuk jenjang, 4 digit (2026/2027 → 2627)</div>
            <div><code class="font-mono text-gray-700">&#123;TA2&#125;</code> — 2 digit tahun pertamanya (26)</div>
            <div><code class="font-mono text-gray-700">&#123;TINGKAT2&#125;</code> — tingkat saat masuk jenjang (07)</div>
            <div><code class="font-mono text-gray-700">&#123;URUT3&#125;</code> — urutan abjad (001)</div>
            <div><code class="font-mono text-gray-700">&#123;JENJANG&#125;</code> — kode jenjangnya</div>
            <div class="text-gray-400">Angka di belakang TINGKAT/URUT = jumlah digitnya.</div>
        </div>
        <p class="mt-2 text-[11px] text-amber-700">
            Mengubah format <b>tidak</b> mengubah NIS yang sudah terbit — nomor lama tetap berlaku
            dan tetap bisa dicari.
        </p>
    </div>

    {{-- Penyaring --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Jenjang</label>
            <select name="jenjang" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiJenjang as $kode => $nama)
                    <option value="{{ $kode }}" @selected($filter['jenjang'] === $kode)>{{ $nama }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">T.A Masuk Jenjang</label>
            <select name="tahun_ajaran" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiTahunAjaran as $t)
                    <option value="{{ $t }}" @selected($filter['tahun_ajaran'] === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Saring</button>
        @if (array_filter($filter))
            <a href="{{ route('nis.index') }}" class="px-2 py-1.5 text-sm text-gray-500 hover:underline">Reset</a>
        @endif
    </form>

    {{-- Pratinjau + penerbitan --}}
    <form method="POST" action="{{ route('nis.terbitkan') }}"
          data-confirm="Terbitkan NIS untuk santri yang dicentang? Nomor yang sudah terbit tidak bisa ditarik kembali.">
        @csrf
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm" x-data="{ semua: true }">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        @if ($bolehTerbit)
                            <th class="px-4 py-3">
                                <input type="checkbox" x-model="semua" @change="$el.closest('table').querySelectorAll('input[name=\'id_santri[]\']').forEach(c => c.checked = semua)"
                                       checked class="rounded border-gray-300 text-brand focus:ring-brand">
                            </th>
                        @endif
                        <th class="px-4 py-3">NIS Usulan</th>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Jenjang</th>
                        <th class="px-4 py-3">Tingkat Masuk</th>
                        <th class="px-4 py-3">T.A Masuk</th>
                        <th class="px-4 py-3 text-right">Urut</th>
                        <th class="px-4 py-3">NIS Lama</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($daftar as $r)
                        <tr class="hover:bg-gray-50">
                            @if ($bolehTerbit)
                                <td class="px-4 py-2">
                                    <input type="checkbox" name="id_santri[]" value="{{ $r['id_santri'] }}" checked
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                </td>
                            @endif
                            <td class="px-4 py-2 font-mono font-semibold text-gray-900">{{ $r['nis'] }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('santri.show', $r['id_santri']) }}" class="text-brand hover:underline">{{ $r['nama'] }}</a>
                                <div class="text-xs text-gray-400">{{ $r['no_pendaftaran'] }}</div>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ $r['jenjang'] }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $r['tingkat'] ? 'Tingkat '.$r['tingkat'] : '—' }}</td>
                            <td class="px-4 py-2 tabular-nums text-gray-600">{{ $r['tahun_ajaran'] ?: '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $r['urut'] }}</td>
                            {{-- Yang punya NIS lama berarti baru NAIK JENJANG — nomor
                                 lamanya tetap tersimpan dan tetap bisa dicari. --}}
                            <td class="px-4 py-2 font-mono text-xs">
                                @if ($r['nis_lama'])
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800" title="Naik jenjang — NIS lama disimpan sebagai riwayat">{{ $r['nis_lama'] }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $bolehTerbit ? 8 : 7 }}" class="px-4 py-12 text-center text-sm text-gray-400">
                                Semua santri aktif sudah punya NIS untuk jenjangnya sekarang.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($daftar !== [] && $bolehTerbit)
            <div class="mt-3 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    Terbitkan NIS ({{ count($daftar) }} santri)
                </button>
                <p class="text-xs text-emerald-800">
                    Nomornya dihitung ulang saat disimpan, jadi tetap benar walau ada petugas lain
                    yang menerbitkan lebih dulu.
                </p>
            </div>
        @endif
    </form>
@endsection
