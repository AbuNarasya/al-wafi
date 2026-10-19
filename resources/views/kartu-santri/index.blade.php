@extends('layouts.app')

@section('title', 'Kartu Santri')

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $samar = fn ($uid) => strlen($uid) > 4 ? str_repeat('•', min(6, strlen($uid) - 4)).substr($uid, -4) : $uid;
        $bolehPasang = \App\Support\Akses::boleh('kartu-santri', 'buat');
        $bolehUbah = \App\Support\Akses::boleh('kartu-santri', 'ubah');
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-1 border-b border-gray-200 text-sm">
            <a href="{{ route('kartu_santri.index') }}" class="-mb-px border-b-2 px-3 py-2 {{ $tab === 'santri' ? 'border-brand font-semibold text-brand' : 'border-transparent text-gray-500' }}">Santri aktif</a>
            <a href="{{ route('kartu_santri.index', ['tab' => 'diblokir']) }}" class="-mb-px border-b-2 px-3 py-2 {{ $tab === 'diblokir' ? 'border-brand font-semibold text-brand' : 'border-transparent text-gray-500' }}">Kartu diblokir</a>
        </div>
        <div class="text-sm text-gray-600">{{ $ringkas['berkartu'] }} dari {{ $ringkas['aktif'] }} santri aktif sudah berkartu · batas bawaan {{ $rp($batasBawaan) }}/hari</div>
    </div>

    @if ($tab === 'santri')
        <form method="GET" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <input type="text" name="q" value="{{ $q }}" placeholder="Cari nama / NIS…" class="rounded-lg border-gray-300 text-sm">
            <label class="flex items-center gap-1"><input type="checkbox" name="tanpa_kartu" value="1" @checked(request()->boolean('tanpa_kartu')) onchange="this.form.submit()"> Hanya yang belum berkartu</label>
            <button class="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Cari</button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Kartu</th><th class="px-4 py-2 text-right">Batas harian</th><th class="px-4 py-2">PIN belanja</th><th class="px-4 py-2 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($santri as $s)
                        <tr x-data="{ buka: false }">
                            <td class="px-4 py-2">{{ $s->nama }}<div class="text-xs text-gray-500">{{ $s->nis }} · {{ $s->jenjang?->nama }}</div></td>
                            <td class="px-4 py-2 font-mono text-xs">
                                @if ($s->kartuAktif)
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-700">{{ $samar($s->kartuAktif->uid) }}</span>
                                @else
                                    <span class="text-gray-400">belum ada</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $s->dompet?->batas_harian !== null ? $rp($s->dompet->batas_harian) : $rp($batasBawaan) }}
                                @if ($s->dompet?->batas_harian === null)<div class="text-[11px] text-gray-400">bawaan</div>@endif
                            </td>
                            <td class="px-4 py-2">{!! isset($punyaPin[$s->id]) ? '<span class="text-emerald-700">terpasang</span>' : '<span class="text-gray-400">belum (dipasang wali)</span>' !!}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    @if ($bolehPasang)
                                        <button type="button" @click="buka = !buka; $nextTick(() => $refs.uid?.focus())" class="text-xs font-medium text-brand hover:underline">{{ $s->kartuAktif ? 'Ganti kartu' : 'Pasang kartu' }}</button>
                                    @endif
                                    @if ($bolehUbah && $s->kartuAktif)
                                        <form method="POST" action="{{ route('kartu_santri.blokir', $s->kartuAktif->id) }}" onsubmit="return confirm('Blokir kartu {{ addslashes($s->nama) }}?')">
                                            @csrf <input type="hidden" name="alasan" value="Diblokir staf">
                                            <button class="text-xs font-medium text-red-600 hover:underline">Blokir</button>
                                        </form>
                                    @endif
                                </div>
                                {{-- Pembaca RFID USB mengetik UID lalu Enter — formulirnya langsung terkirim. --}}
                                <form x-show="buka" x-cloak method="POST" action="{{ route('kartu_santri.pasang') }}" class="mt-2 flex justify-end gap-2">
                                    @csrf <input type="hidden" name="id_santri" value="{{ $s->id }}">
                                    <input x-ref="uid" type="text" name="uid" required autocomplete="off" placeholder="Tempelkan kartu…" class="w-44 rounded border-gray-300 font-mono text-sm">
                                    <button class="rounded bg-brand px-3 py-1 text-xs font-semibold text-white">Simpan</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada santri aktif yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $santri->links() }}</div>
        <p class="mt-2 text-xs text-gray-500">Banyak kartu sekaligus: <b>Impor Data Awal → Kartu Santri (RFID)</b>. Batas harian & PIN belanja diatur wali dari portal.</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Kartu</th><th class="px-4 py-2">Diblokir</th><th class="px-4 py-2">Alasan</th><th class="px-4 py-2 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($diblokir as $k)
                        <tr>
                            <td class="px-4 py-2">{{ $k->santri?->nama }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $samar($k->uid) }}</td>
                            <td class="px-4 py-2 text-xs">{{ $k->diblokir_pada?->format('d/m/Y H:i') }} · {{ $k->diblokir_oleh_wali ? 'oleh wali' : 'oleh staf' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-600">{{ $k->alasan_blokir }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($bolehUbah && $k->alasan_blokir !== 'Diganti kartu baru')
                                    <form method="POST" action="{{ route('kartu_santri.buka', $k->id) }}" onsubmit="return confirm('Kartu sudah ditemukan & boleh dipakai lagi?')">
                                        @csrf <button class="text-xs font-medium text-brand hover:underline">Buka blokir</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada kartu yang diblokir.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $diblokir->links() }}</div>
    @endif
@endsection
