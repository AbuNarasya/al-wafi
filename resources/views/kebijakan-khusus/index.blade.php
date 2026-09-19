@extends('layouts.app')

@section('title', 'Kebijakan Khusus Santri')

@section('content')
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900">Kebijakan Khusus Santri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Keringanan, potongan, dan beasiswa — satu tempat untuk semuanya. Tiap kebijakan punya <b>alasan</b>,
            <b>yang menyetujui</b>, dan <b>masa berlaku</b>; dua surat wajib terlampir sebelum boleh disetujui.
            Tagihan yang <b>sudah terbit</b> tidak disentuh — yang berubah adalah penerbitan berikutnya.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    @if (\App\Support\Akses::boleh('kebijakan-khusus', 'buat'))
        <form method="POST" action="{{ route('kebijakan_khusus.store') }}"
              class="mb-6 space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            @csrf
            <div class="text-sm font-semibold text-gray-800">Ajukan Kebijakan Baru</div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="id_santri" label="Santri" :value="old('id_santri')" :options="$santriOptions" required />
                <x-field name="jenis" label="Jenis" :value="old('jenis', 'keringanan')" :options="$jenisOptions" required />
                <x-field name="perilaku" label="Biaya yang Terdampak" :value="old('perilaku', 'spp')" :options="$perilakuOptions" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="cara" label="Cara" :value="old('cara', 'nominal_khusus')" :options="$caraOptions" required />
                <x-field name="besaran" label="Besaran" type="number" :value="old('besaran')" required
                         hint="Rupiah untuk nominal/nominal khusus; angka persen untuk potongan persen." />
                <x-field name="tahun_ajaran" label="Tahun Ajaran" :value="old('tahun_ajaran')" :options="$taOptions"
                         hint="Kosongkan bila berlaku untuk semua tahun ajaran." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Berlaku Mulai</label>
                    <input type="date" name="berlaku_mulai" value="{{ old('berlaku_mulai') }}" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Berlaku Sampai</label>
                    <input type="date" name="berlaku_sampai" value="{{ old('berlaku_sampai') }}" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm"></div>
            </div>

            <x-field name="alasan" label="Alasan" :value="old('alasan')" textarea required
                     hint="Keadaan yang mendasari permohonan wali. Inilah yang membedakan keringanan dari angka yang ditimpa begitu saja." />

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Ajukan</button>
            </div>
        </form>
    @endif

    <form method="GET" class="mb-3 flex items-end gap-2">
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Status</label>
            <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach (['diajukan' => 'Diajukan', 'disetujui' => 'Berlaku', 'ditolak' => 'Ditolak', 'berakhir' => 'Berakhir'] as $k => $v)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>
                @endforeach
            </select></div>
        <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Saring</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5">Santri</th><th class="px-3 py-2.5">Kebijakan</th>
                    <th class="px-3 py-2.5">Berlaku</th><th class="px-3 py-2.5">Surat</th>
                    <th class="px-3 py-2.5">Status</th><th class="px-3 py-2.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $k)
                    @php $kurang = $kurangSurat[$k->id] ?? []; @endphp
                    <tr class="align-top hover:bg-gray-50">
                        <td class="px-3 py-2">
                            <div class="font-medium text-gray-900">{{ $k->santri?->nama ?? '—' }}</div>
                            <div class="text-[11px] text-gray-400">{{ $k->santri?->nis }}</div>
                        </td>
                        <td class="px-3 py-2">
                            <div class="text-gray-800">{{ $k->labelJenis() }} — {{ $perilakuOptions[$k->perilaku] ?? $k->perilaku }}</div>
                            <div class="text-[11px] text-gray-500">{{ $k->ringkas() }}</div>
                            <div class="mt-0.5 text-[11px] text-gray-400">{{ $k->alasan }}</div>
                        </td>
                        <td class="px-3 py-2 text-gray-600">
                            {{ $k->tahun_ajaran ?: 'semua T.A' }}
                            <div class="text-[11px] text-gray-400">
                                {{ $k->berlaku_mulai?->format('d/m/Y') ?: '—' }} s.d. {{ $k->berlaku_sampai?->format('d/m/Y') ?: 'seterusnya' }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{-- Dua surat, dua tautan terpisah: hanya begitu sistem bisa
                                 tahu mana yang sudah ada dan mana yang belum. --}}
                            <a href="{{ route('lampiran.index', ['jenis' => $jenisPermohonan, 'id' => $k->id]) }}" class="text-brand hover:underline">Permohonan wali</a><br>
                            <a href="{{ route('lampiran.index', ['jenis' => $jenisPersetujuan, 'id' => $k->id]) }}" class="text-brand hover:underline">Persetujuan yayasan</a>
                            @if ($kurang !== [] && $k->status === 'diajukan')
                                <div class="mt-0.5 text-[11px] text-amber-700">belum ada: {{ implode(', ', $kurang) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($k->status === 'diajukan')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">Diajukan</span>
                            @elseif ($k->status === 'disetujui')
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Berlaku</span>
                            @elseif ($k->status === 'ditolak')
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">Ditolak</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500">Berakhir</span>
                            @endif
                            @if ($k->pemutus)
                                <div class="text-[11px] text-gray-400">oleh {{ $k->pemutus->nama }}</div>
                            @endif
                            @if ($k->catatan_keputusan)
                                <div class="text-[11px] text-gray-400">{{ $k->catatan_keputusan }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right">
                            @if (\App\Support\Akses::boleh('kebijakan-khusus', 'ubah'))
                                @if ($k->status === 'diajukan')
                                    <div x-data="{ buka: false }" class="inline-block text-left">
                                        <button type="button" @click="buka = !buka" class="text-brand hover:underline">Putuskan</button>
                                        <div x-show="buka" x-cloak class="mt-2 w-60 space-y-2 rounded-lg border border-gray-200 p-2">
                                            <form method="POST" action="{{ route('kebijakan_khusus.setujui', $k->id) }}" class="space-y-1">
                                                @csrf
                                                <input type="text" name="catatan_keputusan" placeholder="Catatan (opsional)" class="w-full rounded border border-gray-300 px-2 py-1 text-[11px]">
                                                <button class="w-full rounded bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700" @disabled($kurang !== [])>
                                                    {{ $kurang === [] ? 'Setujui' : 'Surat belum lengkap' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('kebijakan_khusus.tolak', $k->id) }}" class="space-y-1">
                                                @csrf
                                                <input type="text" name="catatan_keputusan" required placeholder="Alasan penolakan" class="w-full rounded border border-gray-300 px-2 py-1 text-[11px]">
                                                <button class="w-full rounded border border-red-300 px-2 py-1 text-[11px] font-semibold text-red-700 hover:bg-red-50">Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                @elseif ($k->status === 'disetujui')
                                    <form method="POST" action="{{ route('kebijakan_khusus.akhiri', $k->id) }}" class="space-y-1">
                                        @csrf
                                        <input type="text" name="catatan_keputusan" required placeholder="Alasan diakhiri" class="w-32 rounded border border-gray-300 px-1.5 py-0.5 text-[11px]">
                                        <button class="text-gray-600 hover:underline">Akhiri</button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada kebijakan khusus.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
