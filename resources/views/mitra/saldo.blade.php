@extends('layouts.mitra')

@section('title', 'Saldo')

@section('content')
<div class="space-y-3">
    @foreach ($kabar as $k)
        <div class="rounded-xl p-3 text-sm shadow-sm {{ $k->judul === 'Penarikan ditolak' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' }}">
            <div class="font-semibold">{{ $k->judul }}</div>
            <div>{{ $k->pesan }}</div>
        </div>
    @endforeach

    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="text-xs text-gray-500">Saldo bisa ditarik</div>
        <div class="text-2xl font-bold tabular-nums">@rp($saldo['bisa_ditarik'])</div>
        <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-gray-600">
            <div>Penjualan hari ini<div class="font-semibold tabular-nums">@rp($saldo['tertahan'])</div><span class="text-gray-400">bisa ditarik mulai besok</span></div>
            <div>Sedang diajukan<div class="font-semibold tabular-nums">@rp($saldo['diajukan'])</div></div>
        </div>
    </div>

    <form method="POST" action="{{ route('mitra.ajukan') }}" class="space-y-2 rounded-xl bg-white p-4 shadow-sm"
          data-confirm="Ajukan penarikan ini? Biaya transfer dipotong dari nominal yang ditarik.">
        @csrf
        <div class="text-sm font-semibold">Ajukan penarikan</div>
        <div class="text-xs text-gray-500">Ke {{ $mitra->bank_nama ?? '—' }} {{ $mitra->bank_rekening ?? '(rekening belum diisi)' }} a.n. {{ $mitra->bank_atas_nama ?? '—' }}. Biaya transfer dipotong dari penarikan.</div>
        <x-input-rupiah name="nominal" :value="old('nominal', (int) $saldo['bisa_ditarik'])" required />
        <input type="text" name="catatan" maxlength="255" value="{{ old('catatan') }}" placeholder="Catatan (tidak wajib)" class="w-full rounded-lg border-gray-300 text-sm">
        <button class="w-full rounded-lg bg-brand py-3 text-sm font-semibold text-white">Ajukan penarikan</button>
    </form>

    <div class="space-y-2">
        <div class="text-sm font-semibold">Riwayat penarikan</div>
        @forelse ($rows as $p)
            <div class="rounded-xl bg-white p-3 text-sm shadow-sm ring-1 ring-gray-200">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold tabular-nums">@rp($p->nominal)</div>
                        <div class="text-xs text-gray-500">{{ $p->nomor }} · {{ $p->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                        {{ ['diajukan' => 'bg-amber-50 text-amber-700', 'selesai' => 'bg-emerald-50 text-emerald-700', 'ditolak' => 'bg-red-50 text-red-700', 'dibatalkan' => 'bg-gray-100 text-gray-500'][$p->status] }}">
                        {{ \App\Models\PenarikanMitra::LABEL[$p->status] }}
                    </span>
                </div>
                @if ($p->status === 'selesai')
                    <div class="mt-1 text-xs text-gray-600">
                        Ditransfer {{ $p->tanggal_transfer?->format('d/m/Y') }} · diterima <b>@rp($p->diterima())</b> (biaya transfer @rp($p->biaya_transfer))
                        @if ($p->bukti_path)
                            · <a href="{{ route('mitra.bukti', $p->id) }}" target="_blank" class="text-brand underline">bukti</a>
                        @endif
                    </div>
                @elseif ($p->status === 'ditolak')
                    <div class="mt-1 text-xs text-red-600">Alasan: {{ $p->alasan_tolak }}</div>
                @elseif ($p->status === 'diajukan')
                    <form method="POST" action="{{ route('mitra.batal_ajuan', $p->id) }}" data-confirm="Batalkan pengajuan ini?" class="mt-1">
                        @csrf
                        <button class="text-xs font-semibold text-red-600">Batalkan pengajuan</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-400 shadow-sm">Belum ada penarikan.</div>
        @endforelse
    </div>
</div>
@endsection
