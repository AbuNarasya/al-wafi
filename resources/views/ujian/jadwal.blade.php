@extends('layouts.app')

@section('title', 'Jadwal Ujian & Kartu Peserta')

@section('content')
    @php
        $bolehBuat = \App\Support\Akses::boleh('jadwal-ujian', 'buat');
        $bolehUbah = \App\Support\Akses::boleh('jadwal-ujian', 'ubah');
        $bolehHapus = \App\Support\Akses::boleh('jadwal-ujian', 'hapus');
    @endphp

    <form method="GET" class="mb-4 flex items-center gap-2 text-sm">
        <span class="text-gray-500">Tahun ajaran</span>
        <select name="ta" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
            @foreach ($ta as $t)<option value="{{ $t }}" @selected($t === $pilih)>{{ $t }}</option>@endforeach
        </select>
    </form>

    <p class="mb-3 text-sm text-gray-600">
        Tanggal ujian seorang peserta dicari dari yang paling khusus: <b>tanggal khusus peserta</b> (diisi di detail santri) →
        jadwal <b>jenjang + gelombang</b> → jadwal <b>gelombang</b> → jadwal <b>jenjang</b> → jadwal <b>seluruh T.A</b>.
        Tanpa jadwal, kartu bertuliskan "Jadwal menyusul".
    </p>

    @if ($bolehBuat)
        <form method="POST" action="{{ route('ujian.jadwal.simpan') }}" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm shadow-sm">
            @csrf <input type="hidden" name="tahun_ajaran" value="{{ $pilih }}">
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Jenjang</span>
                <select name="kode_jenjang" class="rounded-lg border-gray-300 text-sm"><option value="">Semua jenjang</option>
                    @foreach ($jenjang as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
            </label>
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Gelombang</span>
                <select name="kode_gelombang" class="rounded-lg border-gray-300 text-sm"><option value="">Semua gelombang</option>
                    @foreach ($gelombang as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
            </label>
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Tanggal</span><input type="date" name="tanggal" required class="rounded-lg border-gray-300 text-sm"></label>
            <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Jam</span><input name="jam" maxlength="50" placeholder="07.30 – 12.00 WIB" class="w-36 rounded-lg border-gray-300 text-sm"></label>
            <label class="flex flex-1 flex-col gap-1"><span class="text-xs text-gray-500">Tempat</span><input name="tempat" maxlength="255" placeholder="Kampus Putri" class="min-w-48 rounded-lg border-gray-300 text-sm"></label>
            <button class="rounded-lg bg-brand px-3 py-2 font-semibold text-white">Tambah jadwal</button>
        </form>
    @endif

    <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Jenjang</th><th class="px-4 py-2">Gelombang</th><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Jam</th><th class="px-4 py-2">Tempat</th><th class="px-4 py-2 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($jadwal as $j)
                    <tr>
                        <td class="px-4 py-2">{{ $j->jenjang?->nama ?? 'Semua jenjang' }}</td>
                        <td class="px-4 py-2">{{ $j->kode_gelombang ? ($gelombang[$j->kode_gelombang] ?? $j->kode_gelombang) : 'Semua gelombang' }}</td>
                        <td class="px-4 py-2">{{ $j->tanggal->locale('id')->translatedFormat('l, j F Y') }}</td>
                        <td class="px-4 py-2">{{ $j->jam ?: '—' }}</td>
                        <td class="px-4 py-2">{{ $j->tempat ?: '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($bolehHapus)
                                <form method="POST" action="{{ route('ujian.jadwal.hapus', $j->id) }}" data-confirm="Hapus jadwal ini?">@csrf @method('DELETE')
                                    <button class="text-xs font-medium text-red-600 hover:underline">Hapus</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada jadwal ujian untuk T.A {{ $pilih }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-sm font-bold text-gray-800">Isi kartu peserta</h2>
    <form method="POST" action="{{ route('ujian.pengaturan') }}" class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
        @csrf @method('PUT')
        <label class="mb-3 flex items-center gap-2">Kupon makan untuk
            <input type="number" name="kupon_makan_orang" min="1" max="20" value="{{ $pengaturan?->kupon_makan_orang ?? 2 }}" @disabled(! $bolehUbah) class="w-20 rounded-lg border-gray-300 text-sm"> orang
        </label>
        <label class="block"><span class="text-xs text-gray-500">Daftar PERHATIAN — satu butir per baris; baris berawalan <b>#</b> menjadi butir bernomor di bawah baris sebelumnya. Kosong = isi bawaan.</span>
            <textarea name="perhatian_kartu_ujian" rows="9" @disabled(! $bolehUbah) placeholder="{{ $perhatianBawaan }}" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs">{{ $pengaturan?->perhatian_kartu_ujian }}</textarea>
        </label>
        @if ($bolehUbah)<button class="mt-2 rounded-lg bg-brand px-3 py-2 font-semibold text-white">Simpan</button>@endif
    </form>
@endsection
