@extends('layouts.app')

@section('title', 'Penarikan Saldo Mitra')

@section('content')
@php $bolehUbah = \App\Support\Akses::boleh('mitra-kantin', 'ubah'); @endphp
<div class="mx-auto max-w-5xl space-y-4">
    <a href="{{ route('mitra_kantin.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Mitra Kantin</a>
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Penarikan Saldo Mitra</h2>
        <p class="mt-1 text-sm text-gray-600">
            Transfer ke rekening mitra lebih dulu, lalu tandai selesai dengan bukti transfer. Biaya transfer dipotong dari penarikan:
            mitra menerima nominal − biaya. Jurnal: D Hutang ke Mitra Kantin / K Kas-Bank sebesar nominal.
        </p>
    </div>

    <div class="flex flex-wrap gap-2 text-sm">
        @foreach (['diajukan' => 'Menunggu diproses', 'selesai' => 'Selesai', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan', 'semua' => 'Semua'] as $k => $l)
            <a href="{{ route('mitra_kantin.penarikan', ['status' => $k]) }}"
               class="rounded-full px-3 py-1 {{ $status === $k ? 'bg-brand text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200' }}">{{ $l }}</a>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($rows as $p)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm" x-data="{ aksi: '' }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold">{{ $p->mitra->nama }} <span class="text-xs font-normal text-gray-400">{{ $p->nomor }}</span></div>
                        <div class="text-sm text-gray-600">Ke {{ $p->bank_nama }} {{ $p->bank_rekening }} a.n. {{ $p->bank_atas_nama }}</div>
                        <div class="text-xs text-gray-400">Diajukan {{ $p->created_at->format('d/m/Y H:i') }}{{ $p->catatan_mitra ? ' · '.$p->catatan_mitra : '' }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-bold tabular-nums">@rp($p->nominal)</div>
                        <div class="text-xs">{{ \App\Models\PenarikanMitra::LABEL[$p->status] }}</div>
                    </div>
                </div>
                @if ($p->status === 'selesai')
                    <div class="mt-2 text-sm text-gray-600">
                        Ditransfer {{ $p->tanggal_transfer?->format('d/m/Y') }} oleh {{ $p->pemroses?->nama }} · diterima mitra <b>@rp($p->diterima())</b>, biaya @rp($p->biaya_transfer)
                        @if ($p->bukti_path)
                            · <a href="{{ route('mitra_kantin.penarikan.bukti', $p->id) }}" target="_blank" class="text-brand underline">bukti</a>
                        @endif
                    </div>
                @elseif ($p->status === 'ditolak')
                    <div class="mt-2 text-sm text-red-600">Ditolak {{ $p->pemroses?->nama }}: {{ $p->alasan_tolak }}</div>
                @elseif ($p->status === 'diajukan' && $bolehUbah)
                    <div class="mt-3 flex gap-2">
                        <button type="button" @click="aksi = aksi === 'proses' ? '' : 'proses'" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white">Sudah ditransfer</button>
                        <button type="button" @click="aksi = aksi === 'tolak' ? '' : 'tolak'" class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700">Tolak</button>
                    </div>
                    <form x-show="aksi === 'proses'" x-cloak method="POST" action="{{ route('mitra_kantin.penarikan.proses', $p->id) }}" enctype="multipart/form-data"
                          class="mt-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2"
                          data-confirm="Tandai penarikan ini sudah ditransfer? Jurnalnya langsung dibuat.">
                        @csrf
                        <label class="text-xs text-gray-600">Dari rekening
                            <select name="kode_coa_bank" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                                <option value="">— pilih —</option>
                                @foreach ($bankOptions as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-xs text-gray-600">Tanggal transfer
                            <input type="date" name="tanggal_transfer" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="text-xs text-gray-600">Biaya transfer (dipotong dari penarikan)
                            <x-input-rupiah name="biaya_transfer" :value="0" />
                        </label>
                        <label class="text-xs text-gray-600">Bukti transfer <span class="text-red-500">*</span>
                            <input type="file" name="bukti" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm">
                        </label>
                        <div class="sm:col-span-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan &amp; jurnal</button></div>
                    </form>
                    <form x-show="aksi === 'tolak'" x-cloak method="POST" action="{{ route('mitra_kantin.penarikan.tolak', $p->id) }}" class="mt-3 flex gap-2">
                        @csrf
                        <input type="text" name="alasan" required maxlength="255" placeholder="Alasan penolakan" class="flex-1 rounded-lg border-gray-300 text-sm">
                        <button class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white">Tolak</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="rounded-xl bg-white p-8 text-center text-sm text-gray-400 shadow-sm">Tidak ada penarikan.</div>
        @endforelse
        {{ $rows->links() }}
    </div>

    @if ($bolehUbah)
        <form method="POST" action="{{ route('mitra_kantin.akun_hutang') }}" class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
            @csrf @method('PUT')
            <label class="flex-1 text-xs text-gray-600">Akun "Hutang ke Mitra Kantin" (penjualan kartu mitra dikreditkan ke sini)
                <select name="kode_coa_hutang_mitra" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— pilih —</option>
                    @foreach ($coaOptions as $kode => $label)
                        <option value="{{ $kode }}" @selected($akunHutang === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Simpan akun</button>
        </form>
    @endif
</div>
@endsection
