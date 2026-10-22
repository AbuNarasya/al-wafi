{{-- Pesanan VA yang belum dibayar: jalan kembali bagi wali yang sudah keluar dari halaman VA (lupa nomornya)
     atau berubah pikiran. $daftar = PembayaranPortal::vaMenunggu(); $idTagihan (opsional) = tagihan yang sedang
     dibuka — bagiannya disebut, dan "Ganti metode" kembali ke layar bayar tagihan itu. --}}
@php
    $rpVa = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $idTagihan ??= null;
@endphp
@foreach ($daftar as $p)
    @php
        $isi = $p->jenis === 'bayar_kelompok'
            ? $p->rincian->map(fn ($r) => ['nama' => \App\Http\Controllers\PortalKeluargaController::namaTagihan($r->tagihan), 'santri' => $r->tagihan->santri->nama, 'nominal' => $r->nominal])
            : collect([['nama' => \App\Http\Controllers\PortalKeluargaController::namaTagihan($p->tagihan), 'santri' => $p->tagihan->santri->nama, 'nominal' => $p->nominal]]);
        $konfirmasi = $isi->count() > 1
            ? __('Batalkan Virtual Account ini? Ke-:jumlah tagihan di dalamnya (:daftar) akan terbuka untuk dibayar dengan cara lain. Nomor VA-nya tidak bisa dipakai lagi.', ['jumlah' => $isi->count(), 'daftar' => $isi->pluck('nama')->join(', ')])
            : __('Batalkan Virtual Account ini lalu pilih cara bayar lain? Nomor VA-nya tidak bisa dipakai lagi.');
    @endphp
    <div class="mt-4 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
        <div class="flex items-start justify-between gap-2">
            <p class="font-semibold">{{ __('Pembayaran online menunggu dibayar') }}</p>
            <p class="shrink-0 font-bold tabular-nums">{{ $rpVa($p->total) }}</p>
        </div>
        @if ($isi->count() > 1)
            <p class="mt-1 text-xs text-amber-800">{{ __('Gabungan :jumlah tagihan:', ['jumlah' => $isi->count()]) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-amber-800">
                @foreach ($isi as $i)
                    <li class="flex justify-between gap-2"><span class="min-w-0 truncate">{{ $i['nama'] }} — {{ $i['santri'] }}</span><span class="shrink-0 tabular-nums">{{ $rpVa($i['nominal']) }}</span></li>
                @endforeach
            </ul>
        @else
            <p class="mt-1 text-xs text-amber-800">{{ $isi->first()['nama'] }} — {{ $isi->first()['santri'] }}</p>
        @endif

        @if ($p->nomor_va)
            <p class="mt-2 text-xs">VA {{ $p->labelBank() }}: <span class="font-mono font-bold">{{ $p->nomor_va }}</span></p>
        @else
            <p class="mt-2 text-xs">{{ __('Bank belum dipilih.') }}</p>
        @endif
        @if ($p->kedaluwarsa_pada)
            <p class="text-xs">{{ __('Berlaku sampai :waktu WIB.', ['waktu' => $p->kedaluwarsa_pada->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('d F Y, H:i')]) }}</p>
        @endif

        <div class="mt-3 grid grid-cols-2 gap-2">
            <a href="{{ route('portal.online.lihat', $p->order_id) }}" class="rounded-2xl bg-brand py-2.5 text-center text-sm font-semibold text-white">{{ __('Lanjutkan bayar') }}</a>
            <form method="POST" action="{{ route('portal.online.batal', $p->order_id) }}" data-confirm="{{ $konfirmasi }}">
                @csrf
                <input type="hidden" name="ganti" value="1">
                @if ($idTagihan)
                    <input type="hidden" name="tagihan" value="{{ $idTagihan }}">
                @endif
                <button class="w-full rounded-2xl bg-white py-2.5 text-sm font-semibold text-brand ring-1 ring-amber-300">{{ __('Ganti metode') }}</button>
            </form>
        </div>
    </div>
@endforeach
