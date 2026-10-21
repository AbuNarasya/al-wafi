@extends('layouts.app')

@section('title', 'Kelas')

@section('content')
    @php
        $bolehBuat = \App\Support\Akses::boleh('kelas', 'buat');
        $bolehUbah = \App\Support\Akses::boleh('kelas', 'ubah');
        $bolehHapus = \App\Support\Akses::boleh('kelas', 'hapus');
        $jenjangPilih = $jenjang->firstWhere('kode', $pilih);
        $warna = ['L' => 'bg-blue-50 text-blue-700', 'P' => 'bg-pink-50 text-pink-700', 'C' => 'bg-gray-100 text-gray-600'];
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-1 border-b border-gray-200 text-sm">
            @foreach ($jenjang as $j)
                <a href="{{ route('kelas.index', ['jenjang' => $j->kode]) }}" class="-mb-px border-b-2 px-3 py-2 {{ $j->kode === $pilih ? 'border-brand font-semibold text-brand' : 'border-transparent text-gray-500' }}">{{ $j->nama }}</a>
            @endforeach
        </div>
        <a href="{{ route('kelas.penempatan', ['jenjang' => $pilih]) }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white">
            Penempatan Kelas @if ($belum > 0)<span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $belum }} belum berkelas</span>@endif
        </a>
    </div>

    @if ($bolehBuat)
        <form method="POST" action="{{ route('kelas.store') }}" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm shadow-sm">
            @csrf <input type="hidden" name="kode_jenjang" value="{{ $pilih }}">
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Tingkat</span>
                <select name="tingkat" required class="rounded-lg border-gray-300 text-sm">
                    @for ($t = 1; $t <= max(1, (int) $jenjangPilih?->jumlah_tingkat); $t++)
                        <option value="{{ $t }}" @selected((int) old('tingkat') === $t)>{{ $t }}</option>
                    @endfor
                </select>
            </label>
            <label class="flex min-w-64 flex-1 flex-col gap-1"><span class="text-xs text-gray-500">Nama kelas</span>
                <input name="nama" required maxlength="120" value="{{ old('nama') }}" placeholder="mis. 7A - ABU BAKAR ASH SHIDDIQ" class="rounded-lg border-gray-300 text-sm">
            </label>
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Peruntukan</span>
                <select name="peruntukan" class="rounded-lg border-gray-300 text-sm">
                    @foreach (\App\Models\Kelas::PERUNTUKAN as $k => $l)<option value="{{ $k }}" @selected(old('peruntukan', 'C') === $k)>{{ $l }}</option>@endforeach
                </select>
            </label>
            <button class="rounded-lg bg-brand px-3 py-2 font-semibold text-white">Tambah kelas</button>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Tingkat</th><th class="px-4 py-2">Nama kelas</th><th class="px-4 py-2">Peruntukan</th><th class="px-4 py-2 text-right">Santri</th><th class="px-4 py-2">Status</th><th class="px-4 py-2 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($kelas as $k)
                    <tr x-data="{ ubah: false }">
                        <td class="px-4 py-2 tabular-nums">{{ $k->tingkat }}</td>
                        <td class="px-4 py-2 font-medium text-gray-900">
                            <span x-show="! ubah">{{ $k->nama }}<span class="block text-xs font-normal text-gray-500">Wali kelas: {{ $k->waliKelas?->nama ?? '—' }}</span></span>
                            @if ($bolehUbah)
                                <form x-show="ubah" x-cloak method="POST" action="{{ route('kelas.update', $k->id) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="kode_jenjang" value="{{ $k->kode_jenjang }}"><input type="hidden" name="tingkat" value="{{ $k->tingkat }}">
                                    <input name="nama" value="{{ $k->nama }}" required maxlength="120" class="w-72 rounded border-gray-300 text-sm">
                                    <select name="peruntukan" class="rounded border-gray-300 text-sm">
                                        @foreach (\App\Models\Kelas::PERUNTUKAN as $p => $l)<option value="{{ $p }}" @selected($k->peruntukan === $p)>{{ $l }}</option>@endforeach
                                    </select>
                                    <select name="id_wali_kelas" class="rounded border-gray-300 text-sm" title="Wali kelas">
                                        <option value="">— wali kelas —</option>
                                        @foreach ($pengguna as $id => $n)<option value="{{ $id }}" @selected((int) $k->id_wali_kelas === (int) $id)>{{ $n }}</option>@endforeach
                                    </select>
                                    <select name="status" class="rounded border-gray-300 text-sm">
                                        <option value="aktif" @selected($k->status === 'aktif')>aktif</option><option value="nonaktif" @selected($k->status === 'nonaktif')>nonaktif</option>
                                    </select>
                                    <button class="rounded bg-brand px-2 py-1 text-xs font-semibold text-white">Simpan</button>
                                </form>
                            @endif
                        </td>
                        <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $warna[$k->peruntukan] }}">{{ $k->labelPeruntukan() }}</span></td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <a href="{{ route('kelas.penempatan', ['jenjang' => $k->kode_jenjang, 'tingkat' => $k->tingkat, 'tampil' => 'semua']) }}" class="text-brand hover:underline">{{ $k->santri_count }}</a>
                        </td>
                        <td class="px-4 py-2 text-xs {{ $k->status === 'aktif' ? 'text-emerald-700' : 'text-gray-400' }}">{{ $k->status }}</td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex items-center justify-end gap-3">
                                @if ($bolehUbah)<button type="button" @click="ubah = ! ubah" class="text-xs font-medium text-brand hover:underline" x-text="ubah ? 'Batal' : 'Ubah'">Ubah</button>@endif
                                @if ($bolehHapus && $k->santri_count === 0)
                                    <form method="POST" action="{{ route('kelas.destroy', $k->id) }}" data-confirm="Hapus kelas {{ $k->nama }}?">
                                        @csrf @method('DELETE') <button class="text-xs font-medium text-red-600 hover:underline">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada kelas di jenjang ini. Tambahkan di atas, atau impor santri lama (kelasnya dibuat otomatis).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
