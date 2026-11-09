@extends('layouts.app')

@section('title', 'Metode Pembayaran Portal')

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Metode Pembayaran Portal</h2>
        <p class="mt-1 text-sm text-gray-600">
            Cara bayar yang ditawarkan kepada wali di portal — untuk bayar tagihan, bayar sekaligus, dan isi dompet.
            <b>Dompet Wali selalu aktif.</b> Mematikan sebuah metode hanya menahan pesanan <b>baru</b>: Virtual Account
            yang sudah terbit tetap bisa dibayar, dan bukti transfer yang menunggu tetap diverifikasi keuangan.
        </p>
    </div>

    <form method="POST" action="{{ route('metode_pembayaran.update') }}" class="space-y-4">
        @csrf @method('PUT')

        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <div class="font-medium text-gray-900">Dompet Wali</div>
                    <div class="text-xs text-gray-500">Potong saldo Dompet Wali. Selalu aktif.</div>
                </div>
                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Selalu nyala</span>
            </div>

            @foreach (\App\Support\MetodeBayar::METODE as $kode => $label)
                @php $nyala = (bool) old("aktif.$kode", $aktif[$kode]); @endphp
                <label class="flex cursor-pointer items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <div class="font-medium text-gray-900">{{ $label }}</div>
                        <div class="text-xs text-gray-500">
                            @if ($kode === 'midtrans')
                                Virtual Account lewat payment gateway Midtrans.
                                @unless ($midtransSiap)
                                    <span class="font-semibold text-amber-700">Belum terpasang (kunci / rekening) — walau nyala, tidak tampil ke wali.</span>
                                @endunless
                            @else
                                Wali transfer ke rekening pesantren lalu mengunggah bukti; masuk setelah diverifikasi keuangan. Cadangan saat VA bermasalah.
                            @endif
                        </div>
                    </div>
                    <input type="hidden" name="aktif[{{ $kode }}]" value="0">
                    <input type="checkbox" name="aktif[{{ $kode }}]" value="1" @checked($nyala)
                           class="h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
                </label>
            @endforeach
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <label for="pengumuman" class="block text-sm font-medium text-gray-700">Pengumuman untuk wali <span class="font-normal text-gray-400">(tidak wajib)</span></label>
            <p class="text-xs text-gray-500">Tampil di layar bayar & isi dompet, mis. "VA sedang gangguan, silakan gunakan Transfer + bukti". Kosongkan bila tak perlu.</p>
            <textarea id="pengumuman" name="pengumuman" rows="3" maxlength="500"
                      class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">{{ old('pengumuman', $pengumuman) }}</textarea>
        </div>

        <div class="flex items-center justify-between gap-4">
            <p class="text-xs text-gray-500">
                Minimal satu metode selain Dompet Wali harus tetap nyala.
                @if ($diubah)
                    Terakhir diubah {{ $diubah['oleh'] ?? '—' }}, {{ \Illuminate\Support\Carbon::parse($diubah['pada'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}.
                @endif
            </p>
            @if (\App\Support\Akses::boleh('metode-pembayaran', 'ubah'))
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
            @endif
        </div>
    </form>
</div>
@endsection
