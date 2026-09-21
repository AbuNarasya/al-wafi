@extends('layouts.app')

@section('title', 'Jadwal Pengingat Penerbitan')

@section('content')
    <a href="{{ route('batch_tagihan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:underline">&larr; Batch Tagihan</a>

    <p class="mb-1 text-sm text-gray-500">
        Pengingat berulang untuk petugas yang menerbitkan tagihan. Ia <b>tidak menerbitkan apa pun</b> &mdash;
        hanya menepuk bahu agar draftnya disusun pada waktunya.
    </p>
    <p class="mb-5 text-xs text-gray-400">
        Notifikasinya berjenis <b>tugas</b>: tak bisa didiamkan dengan &ldquo;tandai dibaca&rdquo;. Ia reda hanya bila
        petugas menekan <b>Sudah saya kerjakan</b>, atau bila batch untuk periode itu memang sudah diotorisasi.
    </p>

    @if ($jadwal->isEmpty())
        <div class="mb-6 rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500">
            Belum ada jadwal pengingat.
        </div>
    @else
        <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Pengingat</th>
                        <th class="px-4 py-3">Modul</th>
                        <th class="px-4 py-3">Irama</th>
                        <th class="px-4 py-3">Berbunyi lagi</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($jadwal as $j)
                        <tr class="hover:bg-gray-50 {{ $j->aktif ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ $j->judul }}</div>
                                @if ($j->catatan)
                                    <div class="text-xs text-gray-500">{{ $j->catatan }}</div>
                                @endif
                                @unless ($j->aktif)
                                    <span class="mt-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">nonaktif</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $j->labelModul() }}
                                @if ($j->jenis)
                                    <div class="text-xs text-gray-500">{{ $j->jenis->nama }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $j->labelIrama() }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $berikutnya[$j->id] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('pengingat_terbit.destroy', $j->id) }}"
                                      data-confirm="Hapus jadwal pengingat &quot;{{ $j->judul }}&quot;?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="POST" action="{{ route('pengingat_terbit.store') }}"
          x-data="{ modul: '{{ old('modul', 'tagihan_lain') }}', irama: '{{ old('irama', 'bulanan') }}' }"
          class="max-w-3xl rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        @csrf
        <div class="mb-4 text-sm font-semibold text-gray-700">Tambah pengingat</div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Modul <span class="text-red-500">*</span></label>
                <select name="modul" x-model="modul" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiModul as $kode => $nama)
                        <option value="{{ $kode }}">{{ $nama }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="modul === 'tagihan_lain'" x-cloak>
                <label class="mb-1 block text-xs font-medium text-gray-600">Jenis biaya <span class="text-red-500">*</span></label>
                <select name="kode_jenis" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="">— pilih —</option>
                    @foreach ($opsiJenisLain as $j)
                        <option value="{{ $j->kode }}" @selected(old('kode_jenis') === $j->kode)>{{ $j->nama }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-gray-600">Judul pengingat <span class="text-red-500">*</span></label>
                <input type="text" name="judul" value="{{ old('judul') }}" required
                       placeholder="Contoh: Tagihan laundry bulanan"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-gray-600">Catatan untuk petugas</label>
                <input type="text" name="catatan" value="{{ old('catatan') }}"
                       placeholder="Muncul di isi notifikasinya"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Irama <span class="text-red-500">*</span></label>
                <select name="irama" x-model="irama" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="bulanan">Tiap bulan</option>
                    <option value="tahunan">Tiap tahun</option>
                </select>
            </div>

            <div x-show="irama === 'tahunan'" x-cloak>
                <label class="mb-1 block text-xs font-medium text-gray-600">Bulan <span class="text-red-500">*</span></label>
                <select name="bulan" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach (range(1, 12) as $m)
                        <option value="{{ $m }}" @selected((int) old('bulan') === $m)>
                            {{ \Illuminate\Support\Carbon::create(null, $m, 1)->translatedFormat('F') }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tanggal <span class="text-red-500">*</span></label>
                <select name="tanggal" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    <option value="0" @selected(old('tanggal') === '0')>Hari terakhir bulan</option>
                    @foreach (range(1, 31) as $d)
                        <option value="{{ $d }}" @selected((int) old('tanggal', 1) === $d)>{{ $d }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">
                    Tanggal yang melewati panjang bulan dijatuhkan ke hari terakhir, bukan dilewatkan.
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Ditepuk berapa hari sebelumnya</label>
                <input type="number" name="hari_sebelum" min="0" max="60" value="{{ old('hari_sebelum', 0) }}"
                       class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-gray-400">0 = tepat pada harinya.</p>
            </div>

            <div class="sm:col-span-2">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="aktif" value="0">
                    <input type="checkbox" name="aktif" value="1" checked
                           class="rounded border-gray-300 text-brand focus:ring-brand">
                    Aktif
                </label>
            </div>
        </div>

        <div class="mt-5">
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                Simpan Pengingat
            </button>
        </div>
    </form>
@endsection
