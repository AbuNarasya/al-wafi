{{--
  Satu sel filter kolom untuk daftar BERPAGINASI (pasangan <x-scol> dari
  <x-fcol> milik halaman master). Nilainya dikirim ke server sebagai query
  string, bukan disaring di browser.

    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" />
    <x-scol name="vendor" :options="$opsiVendor" :value="$filter['vendor']" />
    <x-scol name="nomor" type="text" :value="$filter['nomor']" />
    <x-scol type="blank" />        → kolom tanpa filter (mis. Aksi)

  Dropdown menyaring begitu dipilih; kotak teks menyaring sambil diketik
  (data-filter-auto → auto-submit ber-jeda di app.js).
--}}
@props([
    'name' => null,
    'options' => [],
    'value' => '',
    'type' => 'select',
    'placeholder' => 'Filter',
    'form' => null, // id form GET tujuan — lihat catatan pada komponen filter-server
])

<th {{ $attributes->merge(['class' => 'px-2 py-1']) }}>
    @if ($type === 'blank')
        {{-- kolom tanpa filter --}}
    @elseif ($type === 'text')
        <input type="text" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $placeholder }}"
               @if ($form) form="{{ $form }}" @endif
               data-filter-auto autocomplete="off"
               class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-brand focus:ring-brand">
    @else
        <select name="{{ $name }}" @if ($form) form="{{ $form }}" @endif onchange="this.form.submit()"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs focus:border-brand focus:ring-brand">
            <option value="">Semua</option>
            @foreach ($options as $kode => $label)
                <option value="{{ $kode }}" @selected((string) $value === (string) $kode)>{{ $label }}</option>
            @endforeach
        </select>
    @endif
</th>
