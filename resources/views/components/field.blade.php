@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'required' => false,
    'options' => null,   // array [nilai => label] → render <select>
    'textarea' => false,
    'hint' => null,
])

@php
    $val = old($name, $value);

    // PAPAN KETIK PONSEL. Nomor telepon, NIS, NISN, dan NIK disimpan sebagai
    // TEKS — nol di depan dan tanda "+" harus utuh — sehingga `type="number"`
    // tak bisa dipakai. Tanpa `inputmode`, ponsel memunculkan papan ketik huruf
    // dan petugas menekan tombol angka satu per satu lewat tombol "?123".
    //
    // Ditentukan dari NAMA isian di satu tempat ini, bukan ditambahkan satu per
    // satu di tiap formulir — isian baru yang bernama sama ikut tertangani.
    // Bisa ditimpa dari pemakainya: `inputmode` yang dioper eksplisit menang.
    $modeKetik = match (true) {
        (bool) preg_match('/(telepon|no_hp|hp|wa|whatsapp)/i', $name) => 'tel',
        (bool) preg_match('/(nis|nisn|nik|kode_pos|tahun)/i', $name) => 'numeric',
        default => null,
    };
@endphp

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-gray-700">
        {{ $label }}
        @if ($required) <span class="text-red-500">*</span> @endif
    </label>

    @if ($options !== null)
        {{-- Dropdown SEARCHABLE (ketik untuk memfilter). Menggantikan <select> polos
             agar semua dropdown bisa dicari; nilai dikirim via input hidden. --}}
        <x-search-select :name="$name" :options="$options" :value="$val" :required="$required"
                         @class(['border-red-400' => $errors->has($name)]) />
    @elseif ($type === 'password')
        {{-- Isian sandi selalu dengan tombol mata: salah ketik yang tak terlihat
             adalah sebab tersering "sandi baru saya tidak bisa dipakai". --}}
        <div x-data="{ lihat: false }" class="relative">
            <input id="{{ $name }}" name="{{ $name }}" type="password" :type="lihat ? 'text' : 'password'"
                   autocomplete="off"
                   {{ $attributes->class(['w-full rounded-lg border px-3 py-2 pr-10 text-sm focus:ring-brand focus:border-brand', 'border-red-400' => $errors->has($name), 'border-gray-300' => ! $errors->has($name)]) }}>
            <button type="button" @click="lihat = ! lihat" tabindex="-1"
                    :title="lihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                    :aria-label="lihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                    class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600">
                <x-ikon-mata />
            </button>
        </div>
    @elseif ($textarea)
        <textarea id="{{ $name }}" name="{{ $name }}" rows="3"
                  {{ $attributes->class(['w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand focus:border-brand', 'border-red-400' => $errors->has($name), 'border-gray-300' => ! $errors->has($name)]) }}>{{ $val }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}"
               @if ($modeKetik && ! $attributes->has('inputmode')) inputmode="{{ $modeKetik }}" @endif
               {{ $attributes->class(['w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand focus:border-brand', 'border-red-400' => $errors->has($name), 'border-gray-300' => ! $errors->has($name)]) }}>
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
