{{--
    Penanda langkah verifikasi data (gaya halaman aktivasi Midtrans — permintaan user 2 Nov 2026):
    ① Data Wali → ② Data Santri → ③ Selesai. $aktif = langkah yang sedang dibuka,
    $lewat = jumlah langkah yang sudah disimpan. Langkah yang sudah dilewati bisa diklik.
--}}
@php
    $daftar = [
        1 => [__('Data Wali'), route('portal.verifikasi.wali')],
        2 => [__('Data Santri'), route('portal.verifikasi.santri')],
        3 => [__('Selesai'), route('portal.verifikasi.konfirmasi')],
    ];
@endphp
<ol class="mb-5 flex items-start rounded-3xl bg-white px-2 py-4 shadow-sm ring-1 ring-slate-200">
    @foreach ($daftar as $n => [$judul, $alamat])
        @php
            $sudah = $n <= $lewat && $n !== $aktif;
            $kini = $n === $aktif;
            $bisa = $n <= $lewat + 1 && ! $kini;
        @endphp
        <li class="relative flex flex-1 flex-col items-center text-center">
            @if (! $loop->last)
                {{-- Garis putus-putus menuju langkah berikutnya --}}
                <span class="absolute start-[calc(50%+1.25rem)] end-[calc(-50%+1.25rem)] top-[1.375rem] border-t-2 border-dotted border-slate-300" aria-hidden="true"></span>
            @endif
            {{-- Tautan di portal bertinggi minimum 44px (sasaran sentuh) — semua lingkaran diberi wadah
                 setinggi itu supaya sejajar, bisa diklik atau tidak. --}}
            <a @if ($bisa) href="{{ $alamat }}" @endif class="relative z-10 flex h-11 items-center">
                <span @class([
                    'flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ring-2',
                    'bg-amber-50 text-slate-900 ring-amber-500' => $kini || $sudah,
                    'bg-slate-100 text-slate-400 ring-slate-200' => ! $kini && ! $sudah,
                ])>{{ $n }}</span>
            </a>
            <span @class(['mt-2 text-xs font-semibold sm:text-sm', 'text-slate-900' => $kini || $sudah, 'text-slate-400' => ! $kini && ! $sudah])>{{ $judul }}</span>
            @if ($sudah)
                <span class="mt-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-medium text-emerald-800">{{ __('Sudah diisi') }}</span>
            @elseif ($kini)
                <span class="mt-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-medium text-amber-800">{{ __('Sedang diisi') }}</span>
            @endif
        </li>
    @endforeach
</ol>
