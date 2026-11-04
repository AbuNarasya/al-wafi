{{-- Pilihan cakupan jadwal: jenjang × Ikhwan/Akhwat (nilai "kode_jenjang|L"). --}}
<div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
    @foreach ($jenjang as $jj)
        @foreach (['L' => 'Ikhwan', 'P' => 'Akhwat'] as $jk => $l)
            <label class="flex items-center gap-1"><input type="checkbox" name="cakupan[]" value="{{ $jj->kode }}|{{ $jk }}" @checked(in_array($jj->kode.'|'.$jk, old('cakupan', $pilih), true)) class="rounded border-gray-300"> {{ $jj->nama }} {{ $l }}</label>
        @endforeach
    @endforeach
</div>
