@extends('layouts.app')

@section('title', 'Riwayat Pertanyaan Asisten')

@section('content')
@php $A = \App\Services\PortalWali\AsistenWali::class; @endphp
<div class="mx-auto max-w-5xl space-y-4">
    <div>
        <a href="{{ route('asisten_wali.edit') }}" class="text-sm text-brand hover:underline">&larr; Asisten Bantuan</a>
        <h2 class="mt-1 text-xl font-semibold text-gray-900">Riwayat pertanyaan</h2>
        <p class="text-sm text-gray-600">Pertanyaan tersimpan setelah disamarkan. Pertanyaan yang sering muncul tanpa artikel yang cocok = tanda perlu artikel baru.</p>
    </div>

    <div class="flex flex-wrap gap-2 text-sm">
        @php $saring = array_filter(['audiens' => $audiens]); @endphp
        @foreach (['' => 'Semua', 'groq' => 'Groq', 'gemini' => 'Gemini', 'artikel' => 'Pencarian artikel saja'] as $k => $label)
            <a href="{{ route('asisten_wali.riwayat', $saring + array_filter(['sumber' => $k])) }}"
               class="rounded-full px-3 py-1 {{ (string) $sumber === $k && ! request()->boolean('galat') ? 'bg-brand text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200' }}">{{ $label }}</a>
        @endforeach
        <a href="{{ route('asisten_wali.riwayat', $saring + ['galat' => 1]) }}"
           class="rounded-full px-3 py-1 {{ request()->boolean('galat') ? 'bg-red-600 text-white' : 'bg-white text-red-700 ring-1 ring-red-200' }}">Ada galat AI</a>
        @if ($portalWali)
            <span class="mx-1 text-gray-300">|</span>
            @foreach (['' => 'Wali & staf'] + $A::AUDIENS as $k => $label)
                <a href="{{ route('asisten_wali.riwayat', array_filter(['audiens' => $k, 'sumber' => $sumber])) }}"
                   class="rounded-full px-3 py-1 {{ (string) $audiens === $k ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200' }}">{{ $label }}</a>
            @endforeach
        @endif
    </div>

    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
        @forelse ($baris as $b)
            <details class="px-4 py-3">
                <summary class="cursor-pointer">
                    <span class="text-sm font-medium text-gray-900">{{ $b->pertanyaan }}</span>
                    <span class="mt-0.5 block text-xs text-gray-500">
                        {{ $b->audiens === 'staf' ? 'Staf' : 'Wali' }}: {{ $b->penanya ?: '—' }} ·
                        @if ($b->layar) di {{ $b->layar }} · @endif
                        {{ \Illuminate\Support\Carbon::parse($b->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} ·
                        {{ $b->sumber === 'artikel' ? 'pencarian artikel' : ($A::PENYEDIA[$b->sumber] ?? $b->sumber) }}
                        @if ($b->durasi_ms) · {{ number_format($b->durasi_ms / 1000, 1, ',', '.') }} dtk @endif
                    </span>
                </summary>
                <p class="mt-2 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ $b->jawaban }}</p>
                @if ($b->galat)
                    <p class="mt-1 text-xs text-red-700">Galat AI: {{ $b->galat }}</p>
                @endif
            </details>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500">Belum ada pertanyaan.</p>
        @endforelse
    </div>
    {{ $baris->links() }}
</div>
@endsection
