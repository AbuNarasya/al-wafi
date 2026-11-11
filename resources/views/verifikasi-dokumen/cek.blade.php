<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Cek Keaslian Dokumen — {{ \App\Support\Instans::nama() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-gray-100 p-4">
    @php $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H:i') : '—'; @endphp
    <div class="mx-auto max-w-lg space-y-4 py-6">
        <div class="text-center">
            <x-logo class="mx-auto mb-2 h-14 w-14 rounded-2xl text-2xl" />
            <h1 class="text-lg font-semibold text-gray-900">Cek Keaslian Dokumen</h1>
            <p class="text-sm text-gray-500">{{ \App\Support\Instans::nama() }} — {{ \App\Support\Instans::subjudul() }}</p>
        </div>

        @if ($hasil)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-base font-bold text-emerald-800">✓ Dokumen ASLI</p>
                <p class="text-sm text-emerald-800">Berita Acara Clearance Pengunduran Diri nomor <b>{{ $hasil['nomor'] }}</b> tercatat di sistem dan isinya sesuai.</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <dl class="grid grid-cols-3 gap-y-1">
                    <dt class="text-gray-500">Santri</dt><dd class="col-span-2 font-semibold text-gray-900">{{ $hasil['nama'] }}</dd>
                    <dt class="text-gray-500">NIS</dt><dd class="col-span-2">{{ $hasil['nis'] ?: '—' }}</dd>
                    <dt class="text-gray-500">Jenjang</dt><dd class="col-span-2">{{ $hasil['jenjang'] }}</dd>
                    <dt class="text-gray-500">Status</dt><dd class="col-span-2">{{ $hasil['status'] }}</dd>
                    <dt class="text-gray-500">Dikeluarkan</dt><dd class="col-span-2">{{ $tgl($hasil['dikeluarkan']) }}</dd>
                </dl>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Persetujuan & clearance</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($hasil['tahap'] as $t)
                        <li class="flex justify-between gap-3"><span>{{ $t['label'] }}</span><span class="shrink-0 text-gray-600">{{ $tgl($t['pada']) }}</span></li>
                    @endforeach
                </ul>
            </div>
        @elseif ($hasil === false)
            <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                <p class="text-base font-bold text-red-800">✗ Tidak dapat diverifikasi</p>
                <p class="text-sm text-red-800">Nomor dokumen atau kode verifikasi tidak cocok dengan catatan sistem. Periksa ketikan Anda, atau hubungi pesantren.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('verifikasi_dokumen.cari') }}" data-no-confirm class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            @csrf
            <p class="text-sm text-gray-600">Ketik nomor dokumen dan kode verifikasi yang tercetak di bagian bawah berita acara.</p>
            @if (session('error'))<p class="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ session('error') }}</p>@endif
            <input name="nomor" value="{{ old('nomor', $hasil['nomor'] ?? '') }}" required maxlength="40" placeholder="Nomor dokumen, mis. PKD-2611-0001" class="w-full rounded-lg border-gray-300 text-sm">
            <input name="kode" value="{{ old('kode') }}" required maxlength="20" placeholder="Kode verifikasi, mis. AB12-CD34-EF56" class="w-full rounded-lg border-gray-300 font-mono text-sm">
            <button class="w-full rounded-lg bg-brand py-2 text-sm font-semibold text-white">Periksa</button>
        </form>
    </div>
</body>
</html>
