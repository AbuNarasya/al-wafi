@extends('layouts.app')

@section('title', 'Asisten Bantuan')

@section('content')
@php
    $bolehUbah = \App\Support\Akses::boleh('asisten-wali', 'ubah');
    $A = \App\Services\PortalWali\AsistenWali::class;
@endphp
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Asisten Bantuan</h2>
            <p class="mt-1 text-sm text-gray-600">
                @if ($portalWali) Wali (di portal) dan staf (tombol <b>?</b> di pojok kanan bawah setiap layar) @else Staf (tombol <b>?</b> di pojok kanan bawah setiap layar) @endif
                bertanya cara memakai aplikasi; AI menjawab <b>hanya dari artikel panduan</b> di bawah, tanpa melihat data. Angka panjang & email
                disamarkan sebelum dikirim. Bila AI tidak bisa dipakai (kunci kosong, kuota gratis habis, gangguan), penanya tetap mendapat hasil pencarian artikel.
            </p>
        </div>
        <a href="{{ route('asisten_wali.riwayat') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Riwayat pertanyaan</a>
    </div>

    {{-- Pengaturan --}}
    <form method="POST" action="{{ route('asisten_wali.update') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        @csrf @method('PUT')
        @if ($portalWali)
            <label class="flex cursor-pointer items-center justify-between gap-4">
                <div>
                    <div class="font-medium text-gray-900">Tampilkan menu Bantuan di portal wali</div>
                    <div class="text-xs text-gray-500">Mati = menu tidak muncul dan halaman tertutup bagi wali.</div>
                </div>
                <input type="hidden" name="aktif" value="0">
                <input type="checkbox" name="aktif" value="1" @checked(old('aktif', $atur->aktif)) class="h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
            </label>
        @endif
        <label class="flex cursor-pointer items-center justify-between gap-4">
            <div>
                <div class="font-medium text-gray-900">Tampilkan tombol bantuan untuk staf</div>
                <div class="text-xs text-gray-500">Untuk semua staf yang login di aplikasi ini (tidak tampil di layar kasir). Nama layar yang sedang dibuka ikut dikirim, isinya tidak.</div>
            </div>
            <input type="hidden" name="aktif_staf" value="0">
            <input type="checkbox" name="aktif_staf" value="1" @checked(old('aktif_staf', $atur->aktif_staf)) class="h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700">Penyedia AI</label>
                <select name="penyedia" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    @foreach ($A::PENYEDIA as $k => $label)
                        <option value="{{ $k }}" @selected(old('penyedia', $atur->penyedia) === $k)>{{ $label }}{{ $kunci[$k] ? '' : ' — kunci belum dipasang' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                @if ($portalWali)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Batas / keluarga / hari</label>
                        <input type="number" name="batas_harian" min="1" max="200" value="{{ old('batas_harian', $atur->batas_harian) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </div>
                @else
                    <input type="hidden" name="batas_harian" value="{{ $atur->batas_harian }}">
                @endif
                <div>
                    <label class="block text-sm font-medium text-gray-700">Batas / staf / hari</label>
                    <input type="number" name="batas_harian_staf" min="1" max="500" value="{{ old('batas_harian_staf', $atur->batas_harian_staf) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Model Groq</label>
                <input name="model_groq" value="{{ old('model_groq', $atur->model_groq) }}" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm">
                <p class="mt-1 text-xs {{ $kunci['groq'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $kunci['groq'] ? 'Kunci GROQ_API_KEY terpasang.' : 'GROQ_API_KEY belum diisi di .env server.' }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Model Gemini</label>
                <input name="model_gemini" value="{{ old('model_gemini', $atur->model_gemini) }}" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm">
                <p class="mt-1 text-xs {{ $kunci['gemini'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $kunci['gemini'] ? 'Kunci GEMINI_API_KEY terpasang.' : 'GEMINI_API_KEY belum diisi di .env server.' }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3">
            <p class="text-xs text-gray-500">
                Hari ini: {{ $hariIni->sum() }} pertanyaan
                @if ($hariIni->isNotEmpty())
                    ({{ $hariIni->map(fn ($n, $s) => ($A::AUDIENS[$s] ?? $s).' '.$n)->implode(', ') }})
                @endif
                @if ($diubahOleh)
                    · Terakhir diubah {{ $diubahOleh }}, {{ \Illuminate\Support\Carbon::parse($atur->updated_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}
                @endif
            </p>
            @if ($bolehUbah)
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
            @endif
        </div>
    </form>

    @if ($portalWali)
        <div class="flex gap-2 text-sm">
            @foreach ($A::AUDIENS as $k => $label)
                <a href="{{ route('asisten_wali.edit', ['audiens' => $k]) }}"
                   class="rounded-full px-3 py-1 {{ $audiens === $k ? 'bg-brand text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200' }}">Artikel {{ $label }} ({{ $jumlahArtikel[$k] ?? 0 }})</a>
            @endforeach
        </div>
    @endif

    {{-- Uji banding --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <h3 class="font-semibold text-gray-900">Uji banding Groq vs Gemini — pembaca: {{ $A::AUDIENS[$audiens] }}</h3>
        <p class="text-xs text-gray-500">Pertanyaan yang sama dikirim ke kedua penyedia dengan artikel & aturan yang sama seperti untuk pembacanya. Tidak dicatat dan tidak memakai batas harian.</p>
        <form method="POST" action="{{ route('asisten_wali.uji') }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
            @csrf
            <input type="hidden" name="audiens" value="{{ $audiens }}">
            <input name="pertanyaan" value="{{ old('pertanyaan', $uji['pertanyaan'] ?? '') }}" required maxlength="{{ $A::MAKS_PERTANYAAN }}"
                   placeholder="{{ $audiens === 'wali' ? 'mis. bagaimana cara bayar spp lewat transfer?' : 'mis. bagaimana cara menerbitkan spp bulan ini?' }}" class="flex-1 rounded-lg border-gray-300 text-sm">
            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Uji</button>
        </form>
        @if ($uji && ($uji['audiens'] ?? 'wali') === $audiens)
            <p class="mt-3 text-xs text-gray-500">Artikel rujukan: {{ $uji['artikel'] ? implode(' · ', $uji['artikel']) : 'tidak ada yang cocok' }}</p>
            <div class="mt-2 grid gap-3 md:grid-cols-2">
                @foreach ($uji['hasil'] as $p => $h)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
                            <span class="font-semibold text-gray-800">{{ $A::PENYEDIA[$p] }} <span class="font-mono font-normal">{{ $h['model'] }}</span></span>
                            <span>{{ number_format($h['durasi_ms'] / 1000, 1, ',', '.') }} dtk</span>
                        </div>
                        @if ($h['jawaban'] !== null)
                            <p class="whitespace-pre-line text-sm text-gray-800">{{ $h['jawaban'] }}</p>
                        @else
                            <p class="text-sm text-red-700">{{ $h['galat'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Artikel --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
            <div>
                <h3 class="font-semibold text-gray-900">Artikel panduan {{ strtolower($A::AUDIENS[$audiens]) }} ({{ $artikel->count() }})</h3>
                <p class="text-xs text-gray-500">Bahan jawaban AI sekaligus daftar panduan yang bisa dicari. Tulis dengan bahasa sederhana; jangan memuat tarif, nomor rekening, kata sandi, atau nama orang.</p>
            </div>
            @if (\App\Support\Akses::boleh('asisten-wali', 'buat'))
                <a href="{{ route('asisten_wali.artikel.create', ['audiens' => $audiens]) }}" class="shrink-0 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">+ Artikel</a>
            @endif
        </div>
        <div x-data="{ cari: '' }">
            @if ($artikel->count() > 10)
                <div class="border-b border-gray-100 px-4 py-2"><input x-model="cari" type="search" placeholder="Cari artikel…" class="w-full rounded-lg border-gray-300 text-sm"></div>
            @endif
            <div class="divide-y divide-gray-100">
                @forelse ($artikel as $a)
                    <div x-show="! cari || @js(mb_strtolower($a->judul.' '.$a->kata_kunci)).includes(cari.toLowerCase())" class="flex items-start justify-between gap-3 px-4 py-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium {{ $a->aktif ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $a->judul }}</p>
                            <p class="truncate text-xs text-gray-500">{{ $a->kata_kunci }}</p>
                        </div>
                        @if ($bolehUbah)
                            <a href="{{ route('asisten_wali.artikel.edit', $a->id) }}" class="shrink-0 text-sm text-brand hover:underline">Ubah</a>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500">Belum ada artikel. Impor draf dengan perintah <code>php artisan bantuan:impor-artikel</code> atau tambah satu per satu.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
