@extends('layouts.app')

@section('title', 'Reminder Tagihan Jatuh Tempo')

@php
    $labelSumber = [
        'tagihan_santri' => 'Tagihan Santri',
        'invoice_vendor' => 'Invoice Vendor',
        'angsuran_uang_pangkal' => 'Angsuran Uang Pangkal',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="grid gap-6 xl:grid-cols-2">
            {{-- Pengaturan --}}
            <form method="POST" action="{{ route('reminder_tagihan.update') }}"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
                  data-confirm="Simpan pengaturan reminder?">
                @csrf @method('PUT')

                <div>
                    <h2 class="text-base font-semibold text-gray-800">Pengaturan Reminder</h2>
                    <p class="text-sm text-gray-500">Notifikasi dikirim otomatis setiap hari untuk tagihan yang mendekati jatuh tempo.</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="aktif" value="0">
                    <input type="checkbox" name="aktif" value="1" @checked(old('aktif', $s->aktif))
                           class="rounded border-gray-300 text-brand focus:ring-brand">
                    Aktifkan reminder otomatis
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="hari_sebelum" label="Titik Pengingat (hari sebelum jatuh tempo)"
                             :value="$s->hari_sebelum" required
                             hint="Dipisah koma, mis. 7,3,1 — tiap titik dikirim sekali per tagihan. 0 = tepat hari jatuh tempo." />
                    <x-field name="jam_kirim" label="Jam Kirim Harian" type="time" :value="$s->jam_kirim" required
                             hint="Jam pengiriman otomatis oleh scheduler." />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="mb-2 text-sm font-medium text-gray-700">Sumber tagihan yang dipantau</p>
                        <div class="space-y-2">
                            @foreach ([
                                'sumber_tagihan_santri' => 'Tagihan santri (registrasi, SPP, uang pangkal, lainnya)',
                                'sumber_angsuran_uang_pangkal' => 'Termin angsuran uang pangkal',
                                'sumber_invoice_vendor' => 'Invoice vendor (hutang usaha)',
                            ] as $nama => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="{{ $nama }}" value="0">
                                    <input type="checkbox" name="{{ $nama }}" value="1" @checked(old($nama, $s->{$nama}))
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-sm font-medium text-gray-700">Penerima notifikasi</p>
                        <div class="space-y-2">
                            @foreach ([
                                'penerima_admin' => 'Administrator',
                                'penerima_tim_keuangan' => 'Tim Keuangan',
                                'penerima_akses_modul' => 'Pemegang akses modul terkait (pembayaran / angsuran / invoice)',
                            ] as $nama => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="{{ $nama }}" value="0">
                                    <input type="checkbox" name="{{ $nama }}" value="1" @checked(old($nama, $s->{$nama}))
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex justify-end border-t border-gray-100 pt-4">
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
                </div>
            </form>

            {{-- Kirim manual + riwayat --}}
            <div class="space-y-6">
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-800">Kirim Sekarang</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Jalankan pengiriman reminder tanpa menunggu jadwal. Titik pengingat yang sudah pernah
                        terkirim tidak dikirim ulang. Pengiriman otomatis butuh scheduler aktif
                        (<code class="rounded bg-gray-100 px-1">php artisan schedule:work</code>).
                    </p>
                    <form method="POST" action="{{ route('reminder_tagihan.kirim') }}" class="mt-4"
                          data-confirm="Kirim reminder tagihan sekarang ke semua penerima?">
                        @csrf
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                            📣 Kirim Reminder Sekarang
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-800">Reminder Terakhir Terkirim</h2>
                    @if ($terakhir->isEmpty())
                        <p class="mt-2 text-sm text-gray-500">Belum ada reminder terkirim.</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100">
                            @foreach ($terakhir as $n)
                                <li class="py-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-gray-800">{{ $n->judul }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">{{ optional($n->created_at)->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <p class="text-gray-600">{{ $n->pesan }}</p>
                                    <p class="text-xs text-gray-400">Ke: {{ $n->user?->nama ?? "pengguna #{$n->id_pengguna}" }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Pratinjau tagihan dalam jendela pengingat --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-800">Tagihan Mendekati / Lewat Jatuh Tempo</h2>
                <p class="text-sm text-gray-500">
                    Semua tagihan belum lunas dengan jatuh tempo ≤ H-{{ $hari[0] }} (titik pengingat: {{ implode(', ', array_map(fn ($h) => "H-{$h}", $hari)) }}).
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Sumber</th>
                            <th class="px-4 py-3">Tagihan</th>
                            <th class="px-4 py-3">Jatuh Tempo</th>
                            <th class="px-4 py-3 text-right">Sisa</th>
                            <th class="px-4 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($daftar as $d)
                            <tr>
                                <td class="px-4 py-2 text-gray-600">{{ $labelSumber[$d['sumber']] ?? $d['sumber'] }}</td>
                                <td class="px-4 py-2 text-gray-800">{{ $d['label'] }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $d['jatuh_tempo']->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-800">@rp($d['sisa'])</td>
                                <td class="px-4 py-2 text-center">
                                    @if ($d['hari_tersisa'] < 0)
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Terlambat {{ abs($d['hari_tersisa']) }} hari</span>
                                    @elseif ($d['hari_tersisa'] === 0)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Hari ini</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">H-{{ $d['hari_tersisa'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">
                                    Tidak ada tagihan dalam jendela pengingat. 🎉
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
