@extends('layouts.app')

@section('title', 'Setting Filter Termin Jatuh Tempo')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <form method="POST" action="{{ route('termin_filter.update') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
              data-confirm="Simpan setting filter termin?"
              x-data="{
                  pilihan: @js(old('pilihan_hari', $s->pilihan_hari)),
                  def: @js((string) old('default_hari', $s->default_hari)),
                  opsi() {
                      const hari = [...new Set(this.pilihan.split(',').map(x => x.trim())
                          .filter(x => /^\d+$/.test(x) && +x >= 1 && +x <= 365).map(Number))].sort((a, b) => a - b);
                      return [...hari.map(h => ({ v: String(h), l: `≤ ${h} hari + lewat` })), { v: '0', l: 'hanya yang lewat' }];
                  },
              }"
              x-init="$watch('pilihan', () => { if (!opsi().some(o => o.v === def)) def = opsi()[0].v })">
            @csrf @method('PUT')

            <div>
                <h2 class="text-base font-semibold text-gray-800">Setting Filter Termin Jatuh Tempo</h2>
                <p class="text-sm text-gray-500">
                    Mengatur dropdown "Termin jatuh tempo — perlu ditagih" pada halaman
                    <a href="{{ route('angsuran_uang_pangkal.index') }}" class="text-brand underline">Angsuran Uang Pangkal</a>.
                    Opsi "hanya yang lewat" selalu tersedia.
                </p>
            </div>

            <div>
                <label for="pilihan_hari" class="mb-1 block text-sm font-medium text-gray-700">
                    Pilihan Jendela Hari <span class="text-red-500">*</span>
                </label>
                <input id="pilihan_hari" name="pilihan_hari" x-model="pilihan" required
                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand focus:ring-brand">
                <p class="mt-1 text-xs text-gray-400">Dipisah koma, mis. 7,14,30 — tiap angka jadi satu pilihan "≤ n hari + lewat" (1–365 hari).</p>
                @error('pilihan_hari')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="default_hari" class="mb-1 block text-sm font-medium text-gray-700">
                    Pilihan Default Saat Halaman Dibuka <span class="text-red-500">*</span>
                </label>
                <select id="default_hari" name="default_hari" x-model="def"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-brand focus:ring-brand">
                    <template x-for="o in opsi()" :key="o.v">
                        <option :value="o.v" x-text="o.l" :selected="o.v === def"></option>
                    </template>
                </select>
                @error('default_hari')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- Pratinjau hasil dropdown --}}
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                <p class="mb-2 text-xs font-semibold uppercase text-gray-500">Pratinjau dropdown</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="o in opsi()" :key="'p' + o.v">
                        <span class="rounded-full px-3 py-1 text-xs font-medium"
                              :class="o.v === def ? 'bg-brand text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200'">
                            <span x-text="o.l"></span><span x-show="o.v === def"> · default</span>
                        </span>
                    </template>
                </div>
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
