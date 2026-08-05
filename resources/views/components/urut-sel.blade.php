{{--
  Sel pegangan urutan pada satu baris data. Barisnya WAJIB membawa kodenya
  sendiri: <tr data-row data-kode="{{ $r->kode }}">.

  `draggable` dipasang pada pegangannya saja, bukan pada <tr>: baris yang bisa
  diseret seluruhnya membuat teks di dalamnya tak bisa lagi diblok/disalin.
--}}
@props(['boleh' => true])

<td class="px-2 py-3 align-middle">
    @if ($boleh)
        <div class="flex items-center gap-1" :class="terkunci ? 'opacity-30' : ''">
            <span data-seret draggable="true" title="Seret untuk memindahkan baris"
                  class="cursor-grab select-none text-base leading-none text-gray-400 hover:text-gray-600 active:cursor-grabbing">&#10287;</span>
            <span class="flex flex-col leading-none">
                <button type="button" @click="geser($el, -1)" title="Naikkan"
                        class="px-0.5 text-[9px] text-gray-400 hover:text-brand">&#9650;</button>
                <button type="button" @click="geser($el, 1)" title="Turunkan"
                        class="px-0.5 text-[9px] text-gray-400 hover:text-brand">&#9660;</button>
            </span>
        </div>
    @endif
</td>
