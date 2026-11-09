@extends('layouts.mitra')

@section('title', 'Laporan')

@section('content')
<form method="GET" class="mb-3 grid grid-cols-5 items-end gap-2">
    <label class="col-span-2 text-xs text-gray-500">Dari<input type="date" name="dari" value="{{ $dari }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
    <label class="col-span-2 text-xs text-gray-500">Sampai<input type="date" name="sampai" value="{{ $sampai }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
    <button class="rounded-lg bg-brand py-2 text-sm font-semibold text-white">Lihat</button>
</form>
@include('mitra._laporan')
@endsection
