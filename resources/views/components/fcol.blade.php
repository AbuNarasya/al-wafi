{{--
  Satu sel filter kolom dalam baris filter (<tr>) di bawah header tabel.
    <x-fcol :col="0" />                 → input teks (substring)
    <x-fcol :col="3" type="select" />   → dropdown (opsi diturunkan dari data, cocok persis)
    <x-fcol type="blank" />             → sel kosong (kolom tanpa filter, mis. Aksi/#)
  `col` = indeks kolom 0-based yang cocok dengan urutan <td> baris data.
  Kelas ekstra bisa dioper (mis. text-right) via atribut biasa.
--}}
@props(['col' => null, 'type' => 'text', 'placeholder' => 'Filter'])
<th {{ $attributes->merge(['class' => 'px-2 py-1']) }}>
    @if ($type === 'select')
        <select data-col="{{ $col }}" @change="apply()"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs focus:border-brand focus:ring-brand">
            <option value="">Semua</option>
        </select>
    @elseif ($type === 'blank')
        {{-- kolom tanpa filter --}}
    @else
        <input type="text" data-col="{{ $col }}" @input="apply()" placeholder="{{ $placeholder }}"
               class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-brand focus:ring-brand">
    @endif
</th>
