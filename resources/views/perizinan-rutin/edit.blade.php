@extends('layouts.app')

@section('title', 'Ubah Jadwal '.$j->tipe->nama)

@section('content')
    <a href="{{ route('perizinan_rutin.jadwal') }}" class="text-sm text-brand hover:underline">&larr; Jadwal Perizinan Rutin</a>
    <form method="POST" action="{{ route('perizinan_rutin.update', $j->id) }}" class="mt-3 max-w-2xl space-y-3 rounded-xl border border-gray-200 bg-white p-6 text-sm shadow-sm">
        @csrf @method('PUT')
        <p class="text-gray-500">Menggeser waktu ikut menggeser pengajuan yang belum keluar. Wali melihat waktu baru di portal.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">Mulai<input type="datetime-local" name="mulai" required value="{{ old('mulai', $j->mulai->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="block">Selesai<input type="datetime-local" name="selesai" required value="{{ old('selesai', $j->selesai->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
        </div>
        <div><p class="font-medium text-gray-700">Cakupan</p>
            @include('perizinan-rutin._cakupan', ['pilih' => $j->cakupan->map(fn ($c) => $c->kode_jenjang.'|'.$c->jenis_kelamin)->all()])</div>
        <input name="catatan" maxlength="500" value="{{ old('catatan', $j->catatan) }}" placeholder="Catatan untuk wali (opsional)" class="w-full rounded-lg border-gray-300 text-sm">
        <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Simpan</button>
    </form>
@endsection
