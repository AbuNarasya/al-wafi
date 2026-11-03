{{-- Lencana status Perizinan Insidental di portal wali. Status menunggu tingkat 1/2 cukup "Menunggu persetujuan" bagi wali. --}}
@php
    $label = match (true) {
        $z->terlambat() => 'Terlambat kembali',
        in_array($z->status, \App\Models\Perizinan::MENUNGGU, true) => 'Menunggu persetujuan',
        default => \App\Models\Perizinan::STATUS[$z->status] ?? $z->status,
    };
@endphp
<span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
    'bg-amber-100 text-amber-800' => in_array($z->status, \App\Models\Perizinan::MENUNGGU, true),
    'bg-sky-100 text-sky-800' => $z->status === 'disetujui',
    'bg-violet-100 text-violet-800' => $z->status === 'keluar' && ! $z->terlambat(),
    'bg-emerald-100 text-emerald-800' => $z->status === 'kembali' && ! $z->terlambat(),
    'bg-red-100 text-red-700' => $z->status === 'ditolak' || $z->terlambat(),
    'bg-slate-100 text-slate-600' => $z->status === 'dibatalkan'])>{{ __($label) }}</span>
