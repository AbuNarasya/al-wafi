@extends('layouts.app')

@section('title', 'Pengunduran Diri Santri')

@section('content')
    @php
        $tabs = ['tindakan' => "Perlu tindakan saya ({$jumlahPerlu})", 'siap' => "Siap dikeluarkan ({$jumlahSiap})", 'berjalan' => 'Sedang berjalan', 'semua' => 'Semua'];
        $warna = ['ditolak' => 'bg-red-50 text-red-700', 'dibatalkan' => 'bg-gray-100 text-gray-500', 'selesai' => 'bg-emerald-50 text-emerald-700',
            'siap_dikeluarkan' => 'bg-amber-50 text-amber-700', 'dikembalikan' => 'bg-amber-50 text-amber-700'];
    @endphp

    <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 text-sm">
        @foreach ($tabs as $k => $l)
            <a href="{{ route('pengunduran_diri.index', ['tab' => $k]) }}" class="-mb-px border-b-2 px-3 py-2 {{ $tab === $k ? 'border-brand font-semibold text-brand' : 'border-transparent text-gray-500' }}">{{ $l }}</a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('pengunduran_diri.keluarkan') }}" x-data="{ dipilih: [] }"
          data-confirm="Keluarkan santri terpilih? Status santri menjadi Keluar dan sisa tagihan PPSB dibatalkan. SPP & tagihan lain tetap tertagih.">
        @csrf
        @if ($tab === 'siap' && $bolehKeluarkan && $daftar->isNotEmpty())
            <div class="mb-3 flex items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm shadow-sm">
                <span class="text-gray-600"><b x-text="dipilih.length">0</b> dipilih</span>
                <button class="rounded-lg bg-red-700 px-3 py-1.5 font-semibold text-white" :disabled="dipilih.length === 0" :class="dipilih.length === 0 && 'opacity-50'">Keluarkan santri (proses akhir bulan)</button>
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        @if ($tab === 'siap' && $bolehKeluarkan)<th class="w-8 px-4 py-2"></th>@endif
                        <th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Kelas</th><th class="px-4 py-2">Diajukan</th><th class="px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($daftar as $p)
                        <tr>
                            @if ($tab === 'siap' && $bolehKeluarkan)
                                <td class="px-4 py-2"><input type="checkbox" name="pengajuan[]" value="{{ $p->id }}" x-model="dipilih"></td>
                            @endif
                            <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('pengunduran_diri.show', $p->id) }}" class="text-brand hover:underline">{{ $p->nomor }}</a></td>
                            <td class="px-4 py-2">{{ $p->santri?->nama }}<div class="text-xs text-gray-500">{{ $p->santri?->nis }} · {{ $p->santri?->jenjang?->nama }}</div></td>
                            <td class="px-4 py-2 text-xs">{{ $p->santri?->kelas?->nama ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs">{{ $p->created_at->format('d/m/Y') }}</td>
                            <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $warna[$p->status] ?? 'bg-blue-50 text-brand' }}">{{ $p->labelStatus() }}</span>
                                @if ($p->status === 'selesai' && ! $p->dapodik_pada)<div class="text-[11px] text-amber-600">belum ditandai Dapodik</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada pengajuan di sini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
@endsection
