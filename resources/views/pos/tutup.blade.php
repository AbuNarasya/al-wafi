@extends('layouts.pos')

@section('title', 'Tutup Shift')
@section('kepala', 'Tutup Shift — '.$shift->lokasi->nama)
@section('subkepala', 'Shift '.$shift->nomor.' · '.$ringkas['struk'].' struk')

@section('aksi')
    <a href="{{ route('pos.index') }}" class="rounded bg-white px-2 py-1 text-xs font-semibold text-brand-dark hover:bg-white/90">&larr; Kembali ke Kasir</a>
@endsection

@section('content')
    <form method="POST" action="{{ route('pos.tutup.simpan') }}" class="mx-auto max-w-lg space-y-4"
          x-data="{ lembar: @js(collect(\App\Models\ShiftKasir::PECAHAN)->mapWithKeys(fn ($p) => [$p => old('pecahan.'.$p, '')])->all()),
                    get total() { return Object.entries(this.lembar).reduce((s, [p, n]) => s + p * (parseInt(n, 10) || 0), 0); } }"
          onsubmit="return confirm('Tutup shift sekarang? Sesudah ditutup tak bisa berjualan di shift ini lagi.')">
        @csrf
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-1 font-semibold text-gray-800">Hitung uang di laci</div>
            <p class="mb-4 text-xs text-gray-500">
                Hitung <b>seluruh</b> uang di laci, termasuk modal awal, lalu isi jumlah lembar/keping per pecahan.
                Berapa seharusnya baru ditampilkan sesudah shift ditutup.
            </p>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-2">
                @foreach (\App\Models\ShiftKasir::PECAHAN as $p)
                    <label class="flex items-center justify-between gap-2 text-sm">
                        <span class="tabular-nums text-gray-700">{{ number_format($p, 0, ',', '.') }}</span>
                        <input type="number" min="0" step="1" name="pecahan[{{ $p }}]" x-model="lembar[{{ $p }}]" placeholder="0"
                               class="w-20 rounded border-gray-300 text-right text-sm">
                    </label>
                @endforeach
            </div>
            <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3">
                <span class="text-sm text-gray-600">Total uang dihitung</span>
                <span class="text-2xl font-bold tabular-nums" x-text="'Rp ' + total.toLocaleString('id-ID')"></span>
            </div>
        </div>
        <input type="text" name="catatan" value="{{ old('catatan') }}" placeholder="Catatan (opsional, mis. ada uang rusak)"
               class="w-full rounded-lg border-gray-300 text-sm">
        <button class="w-full rounded-xl bg-amber-500 py-3 text-base font-bold text-white hover:bg-amber-600">Tutup Shift</button>
    </form>
@endsection
