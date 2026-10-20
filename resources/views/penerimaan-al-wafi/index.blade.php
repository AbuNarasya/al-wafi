@extends('layouts.app')

@section('title', 'Penerimaan dari Al Wafi')

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $boleh = \App\Support\Akses::boleh('penerimaan-al-wafi', 'buat');
    @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Belanja kartu santri tercatat sebagai <b>piutang ke Al Wafi</b> saat shift ditutup. ERP Al Wafi membayarnya lewat pencairan
        mingguan; begitu uangnya masuk ke rekening, terima pencairannya di sini — jurnalnya <b>D Bank / K Piutang ke Al Wafi</b>.
    </p>

    @if ($saldoPiutang !== null)
        <div class="mb-4 inline-block rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm">
            <div class="text-xs text-gray-500">Piutang ke Al Wafi saat ini</div>
            <div class="text-lg font-bold tabular-nums text-gray-900">{{ $rp($saldoPiutang) }}</div>
        </div>
    @endif

    @if ($galat)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $galat }}</div>
    @endif

    <div class="space-y-3">
        @forelse ($daftar as $p)
            <div class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                <div class="min-w-[12rem]">
                    <div class="font-mono font-semibold text-gray-900">{{ $p['nomor'] }}</div>
                    <div class="text-xs text-gray-500">Dicairkan {{ \Illuminate\Support\Carbon::parse($p['tanggal'])->format('d/m/Y') }}
                        · s.d. {{ \Illuminate\Support\Carbon::parse($p['sampai_tanggal'])->format('d/m/Y') }} · {{ $p['jumlah_transaksi'] }} transaksi</div>
                    <div class="text-lg font-bold tabular-nums text-gray-900">{{ $rp($p['total']) }}</div>
                </div>
                @if ($p['penerimaan'])
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs text-emerald-700">
                        Diterima {{ $p['penerimaan']->tanggal->format('d/m/Y') }} oleh {{ $p['penerimaan']->penerima?->nama }}</span>
                @elseif ($p['status'] !== 'aktif')
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-500">Dibatalkan Al Wafi</span>
                @elseif ($boleh)
                    <form method="POST" action="{{ route('penerimaan_al_wafi.store') }}" class="flex flex-1 flex-wrap items-end justify-end gap-2"
                          onsubmit="return confirm('Uang {{ $rp($p['total']) }} sudah masuk ke rekening yang dipilih?')">
                        @csrf
                        <input type="hidden" name="nomor" value="{{ $p['nomor'] }}">
                        <div class="min-w-[14rem]"><x-field name="kode_coa_bank" label="Masuk ke kas/bank" :options="['' => '— pilih —'] + $rekening" required /></div>
                        <button class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">Terima</button>
                    </form>
                @else
                    <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs text-sky-700">Menunggu diterima</span>
                @endif
            </div>
        @empty
            @unless ($galat)
                <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">Belum ada pencairan dari Al Wafi (90 hari terakhir).</div>
            @endunless
        @endforelse
    </div>
@endsection
