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

@php $val = old($name, $value); @endphp

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
    @elseif ($textarea)
        <textarea id="{{ $name }}" name="{{ $name }}" rows="3"
                  {{ $attributes->class(['w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand focus:border-brand', 'border-red-400' => $errors->has($name), 'border-gray-300' => ! $errors->has($name)]) }}>{{ $val }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}"
               {{ $attributes->class(['w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand focus:border-brand', 'border-red-400' => $errors->has($name), 'border-gray-300' => ! $errors->has($name)]) }}>
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
