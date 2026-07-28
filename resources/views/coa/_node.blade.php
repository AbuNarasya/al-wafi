{{-- Node tree COA rekursif. Butuh: $group, $groups (semua), $details (semua). --}}
@php
    $children = $groups->where('kode_induk', $group->kode_grup)->sortBy('kode_grup');
    $accounts = $group->level == 3 ? $details->where('kode_grup', $group->kode_grup)->sortBy('kode_coa') : collect();
    $headerCls = $group->level == 1 ? 'text-[15px] font-bold text-gray-800'
        : ($group->level == 2 ? 'text-sm font-semibold text-gray-700' : 'text-sm font-medium text-gray-600');
@endphp
<div class="{{ $group->level == 1 ? 'mb-5' : 'mt-1.5' }}">
    <div class="flex items-center gap-2 {{ $headerCls }}">
        <span class="font-mono">{{ $group->kode_grup }}</span>
        <span>— {{ $group->nama_grup }}</span>
        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-normal text-gray-400">L{{ $group->level }}</span>
    </div>
    <div class="ml-2 border-l border-gray-200 pl-4">
        @foreach ($children as $c)
            @include('coa._node', ['group' => $c])
        @endforeach
        @if ($group->level == 3 && $accounts->isEmpty())
            <div class="py-1 text-xs italic text-gray-300">(belum ada akun)</div>
        @endif
        @foreach ($accounts as $a)
            <div class="flex items-center gap-2 py-0.5 text-sm">
                <span class="font-mono text-xs text-gray-400">{{ $a->kode_coa }}</span>
                <span class="text-gray-800">{{ $a->nama_coa }}</span>
                <span class="rounded px-1.5 py-0.5 text-[10px] {{ $a->jenis_saldo === 'debet' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' }}">{{ $a->jenis_saldo }}</span>
                @if ($a->status === 'nonaktif')
                    <span class="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] text-gray-500">nonaktif</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
