@extends('layouts.app')

@section('title', $baru ? 'Tambah Target Santri' : 'Ubah Target Santri')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('target_santri.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('target_santri.store') : route('target_santri.update', $row->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tahun_ajaran" label="Tahun Ajaran" :value="old('tahun_ajaran', $row->tahun_ajaran)"
                         :options="['' => '— pilih tahun ajaran —'] + (new \App\Services\Modules\TahunAjaranService)->opsiAktif() + ($row->tahun_ajaran ? [$row->tahun_ajaran => $row->tahun_ajaran] : [])" required />
                <x-field name="kode_jenjang" label="Jenjang" :value="old('kode_jenjang', $row->kode_jenjang)"
                         :options="['' => '— pilih jenjang —'] + \App\Support\Referensi::jenjang() + ($row->kode_jenjang ? [$row->kode_jenjang => $row->kode_jenjang] : [])" required />
            </div>
            {{-- Target dirinci per jenis kelamin; totalnya dihitung otomatis agar
                 tak pernah bertentangan dengan rinciannya di Dashboard PPSB.
                 Kosongkan L & P bila belum ingin dirinci — total tetap bisa diisi. --}}
            <div x-data="{
                    l: '{{ old('target_l', $row->target_l) }}',
                    p: '{{ old('target_p', $row->target_p) }}',
                    total: '{{ old('target', $row->target) }}',
                    hitung() { if (this.l !== '' || this.p !== '') this.total = (Number(this.l) || 0) + (Number(this.p) || 0); },
                 }" class="space-y-3 rounded-lg border border-gray-200 p-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Target Laki-laki</label>
                        <input type="number" min="0" name="target_l" x-model="l" @input="hitung()"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        @error('target_l')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Target Perempuan</label>
                        <input type="number" min="0" name="target_p" x-model="p" @input="hitung()"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        @error('target_p')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Total <span class="text-red-500">*</span></label>
                        <input type="number" min="0" name="target" x-model="total" required
                               class="w-full rounded-lg border border-gray-400 bg-gray-50 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        @error('target')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="text-xs text-gray-400">
                    Total terisi otomatis dari Laki-laki + Perempuan. Kosongkan keduanya bila target belum dirinci per jenis kelamin.
                </p>
            </div>
            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('target_santri.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
