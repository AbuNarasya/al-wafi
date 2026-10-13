@extends('layouts.portal')

@section('title', 'Isi Dompet Wali')
@section('kembali', route('portal.dompet'))

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#4f7a5c] text-white shadow-sm"><x-portal.ikon nama="isi" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">Isi saldo</h1>
            <p class="text-sm text-slate-500">Transfer ke rekening pesantren, lalu kirim buktinya.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('portal.dompet.isi') }}" enctype="multipart/form-data"
          data-confirm="Kirim bukti transfer ini? Saldo bertambah setelah keuangan memverifikasinya.">
        @csrf
        @include('portal._transfer')
        @if ($rekening !== [])
            <p class="mt-4 rounded-2xl bg-blue-50 px-4 py-3 text-xs text-slate-600">
                Transfer bank langsung diperiksa keuangan lebih dulu &mdash; saldo bertambah setelah diverifikasi,
                biasanya pada hari kerja yang sama.
            </p>
            <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim bukti transfer</button>
        @endif
    </form>
@endsection
