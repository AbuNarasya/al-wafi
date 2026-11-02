{{-- Kenali santri: TAP KARTU RFID (utama). Pembaca kartu USB mengetik UID lalu Enter — formulir langsung terkirim.
     Kartu tertinggal → cari nama / NIS, dicatat sebagai cara "manual". Variabel: $rute, $galat, $hasilCari, $judul. --}}
<div class="mx-auto mt-6 max-w-md space-y-4">
    <form method="GET" action="{{ route($rute) }}" class="rounded-2xl border-2 border-dashed border-brand/40 bg-white p-8 text-center shadow-sm">
        <p class="text-lg font-bold text-gray-900">{{ $judul }}</p>
        <p class="mt-1 text-sm text-gray-500">Tempelkan kartu RFID santri ke pembaca kartu.</p>
        <input name="kartu" autofocus autocomplete="off" placeholder="Menunggu kartu…" class="mt-4 w-full rounded-lg border-gray-300 text-center font-mono text-sm">
        @if ($galat)<p class="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ $galat }}</p>@endif
    </form>

    <details @if (request()->filled('cari')) open @endif class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
        <summary class="cursor-pointer font-medium text-gray-700">Kartu tertinggal? Cari nama / NIS</summary>
        <form method="GET" action="{{ route($rute) }}" class="mt-3 flex gap-2">
            <input name="cari" value="{{ request('cari') }}" minlength="3" placeholder="min. 3 huruf" class="flex-1 rounded-lg border-gray-300 text-sm">
            <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white">Cari</button>
        </form>
        @if (request()->filled('cari'))
            <div class="mt-3 divide-y divide-gray-100">
                @forelse ($hasilCari as $s)
                    <a href="{{ route($rute, ['santri' => $s->id]) }}" class="flex justify-between py-2 hover:text-brand">
                        <span>{{ $s->nama }}</span><span class="text-xs text-gray-500">{{ $s->nis }}{{ $s->kelas ? ' · '.$s->kelas->nama : '' }}</span>
                    </a>
                @empty
                    <p class="py-2 text-gray-400">Tidak ada santri aktif yang cocok.</p>
                @endforelse
            </div>
            <p class="mt-2 text-xs text-amber-700">Dicatat sebagai pencarian manual (tanpa kartu).</p>
        @endif
    </details>
</div>
