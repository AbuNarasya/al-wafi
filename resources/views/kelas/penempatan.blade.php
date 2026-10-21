@extends('layouts.app')

@section('title', 'Penempatan Kelas')

@section('content')
    @php $bolehUbah = \App\Support\Akses::boleh('kelas', 'ubah'); @endphp

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Jenjang</span>
            <select name="jenjang" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                @foreach ($jenjang as $j)<option value="{{ $j->kode }}" @selected($j->kode === $pilih)>{{ $j->nama }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1"><span class="text-xs text-gray-500">Tingkat</span>
            <select name="tingkat" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua</option>
                @for ($t = 1; $t <= max(1, $jumlahTingkat); $t++)<option value="{{ $t }}" @selected((string) $tingkat === (string) $t)>{{ $t }}</option>@endfor
            </select>
        </label>
        <label class="flex items-center gap-1 pb-2"><input type="checkbox" name="tampil" value="semua" @checked($semua) onchange="this.form.submit()"> Tampilkan juga yang sudah berkelas</label>
        <a href="{{ route('kelas.index', ['jenjang' => $pilih]) }}" class="ml-auto pb-2 text-brand hover:underline">Master kelas &rarr;</a>
    </form>

    <form method="POST" action="{{ route('kelas.tempatkan') }}" x-data="{ dipilih: [] }">
        @csrf
        @if ($bolehUbah)
            <div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm shadow-sm">
                <span class="text-gray-600"><b x-text="dipilih.length">0</b> santri dipilih →</span>
                <select name="id_kelas" class="rounded-lg border-gray-300 text-sm">
                    @foreach ($kelas as $k)
                        <option value="{{ $k->id }}">Tingkat {{ $k->tingkat }} · {{ $k->nama }} ({{ $k->labelPeruntukan() }})</option>
                    @endforeach
                    <option value="">— kosongkan kelas —</option>
                </select>
                <button class="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" :disabled="dipilih.length === 0" :class="dipilih.length === 0 && 'opacity-50'">Tempatkan</button>
                @if ($kelas->isEmpty())
                    <span class="text-xs text-amber-700">Belum ada kelas untuk pilihan ini — buat dulu di Master kelas.</span>
                @endif
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="w-8 px-4 py-2">
                            @if ($bolehUbah)
                                <input type="checkbox" @change="dipilih = $event.target.checked ? @js($santri->pluck('id')->map(fn ($i) => (string) $i)->values()) : []">
                            @endif
                        </th>
                        <th class="px-4 py-2">Santri</th><th class="px-4 py-2">L/P</th><th class="px-4 py-2">Tingkat</th><th class="px-4 py-2">Kelas sekarang</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($santri as $s)
                        <tr>
                            <td class="px-4 py-2">@if ($bolehUbah)<input type="checkbox" name="santri[]" value="{{ $s->id }}" x-model="dipilih">@endif</td>
                            <td class="px-4 py-2">{{ $s->nama }}<div class="text-xs text-gray-500">{{ $s->nis }}</div></td>
                            <td class="px-4 py-2">{{ $s->jenis_kelamin }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $s->tingkat }}</td>
                            <td class="px-4 py-2">{!! $s->kelas ? e($s->kelas->nama) : '<span class="text-amber-700">belum berkelas</span>' !!}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">{{ $semua ? 'Tidak ada santri aktif di pilihan ini.' : 'Semua santri aktif di pilihan ini sudah berkelas.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
    <p class="mt-2 text-xs text-gray-500">Santri baru masuk daftar ini setelah berstatus aktif. Kenaikan tingkat mengosongkan kelas — santrinya muncul lagi di sini untuk ditempatkan ke kelas barunya.</p>
@endsection
