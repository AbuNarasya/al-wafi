{{--
  Grafik garis: satu garis per JENJANG, sumbu X bulan Juli→Juni musim penerimaan
  tahun ajaran terpilih. Dipakai membandingkan pola pendaftaran antar jenjang.

  SVG dirender di server — tanpa pustaka grafik, tetap tampil bila JavaScript
  mati, dan ikut saat halaman dicetak.

  Aturan visual (dan alasannya):
   • Warna melekat pada JENJANG menurut urutan tetapnya di master, bukan pada
     tinggi-rendah angkanya — garis sebuah jenjang tak berpindah warna saat
     tahun ajaran diganti atau jenjang lain ditambah.
   • Palet sudah divalidasi untuk buta warna; legenda selalu ada dan titik data
     ditandai bulatan, jadi identitas garis tak pernah bergantung warna saja.
   • Satu sumbu Y saja. Semua seri satuannya sama (jumlah orang).
--}}
@props(['tren', 'judul', 'satuan' => 'Jumlah Pendaftar'])

@php
    $bulan = $tren['bulan'];
    // SEMUA jenjang digambar, termasuk yang totalnya masih 0 — garis datar di
    // angka nol memang jawaban yang benar untuk jenjang yang belum ada
    // pendaftarnya, dan menyembunyikannya membuat grafik terlihat kurang seri.
    $seri = collect($tren['seri'])->values();
    $palet = ['#0284c7', '#f59e0b', '#059669', '#7c3aed', '#e11d48', '#0d9488', '#ea580c', '#c026d3'];
    $indeks = collect($tren['seri'])->pluck('label')->values()->flip();

    $maks = 0;
    foreach ($seri as $s) {
        $maks = max($maks, max($s['nilai']));
    }
    // Skala dibulatkan ke atas ke angka "bulat" supaya label sumbu enak dibaca
    // (5/10/25/50/100…) dan puncak garis tak menyentuh tepi atas.
    $langkah = collect([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000])
        ->first(fn ($l) => $l * 4 >= max($maks, 1)) ?? 1000;
    $skala = max($langkah * 4, 4);

    $w = 900; $h = 300;
    $kiri = 46; $kanan = 14; $atas = 16; $bawah = 34;
    $plotW = $w - $kiri - $kanan;
    $plotH = $h - $atas - $bawah;
    $dx = $plotW / (count($bulan) - 1);
    $px = fn ($i) => $kiri + $i * $dx;
    $py = fn ($v) => $atas + $plotH - ($v / $skala) * $plotH;

    // Kurva halus MONOTONIK (Fritsch–Carlson). Sengaja bukan Catmull-Rom biasa:
    // kurva itu melengkung melewati titiknya, sehingga garis dari 3 ke 0 sempat
    // menukik DI BAWAH NOL — mustahil untuk data cacah orang. Interpolasi
    // monotonik menjamin kurva tak pernah keluar dari rentang dua titik yang
    // dihubungkannya.
    $jalur = function (array $nilai) use ($px, $py, $dx) {
        $n = count($nilai);
        $y = array_map(fn ($v) => $py($v), $nilai);

        $delta = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $delta[$i] = ($y[$i + 1] - $y[$i]) / $dx;
        }

        $m = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $m[$i] = $delta[0];
            } elseif ($i === $n - 1) {
                $m[$i] = $delta[$n - 2];
            } elseif ($delta[$i - 1] * $delta[$i] <= 0) {
                $m[$i] = 0; // titik balik → tangen datar, tak boleh melampaui
            } else {
                $m[$i] = ($delta[$i - 1] + $delta[$i]) / 2;
                $batas = 3 * min(abs($delta[$i - 1]), abs($delta[$i]));
                $m[$i] = max(-$batas, min($batas, $m[$i]));
            }
        }

        $d = sprintf('M %.1f %.1f', $px(0), $y[0]);
        for ($i = 0; $i < $n - 1; $i++) {
            $d .= sprintf(
                ' C %.1f %.1f, %.1f %.1f, %.1f %.1f',
                $px($i) + $dx / 3, $y[$i] + $m[$i] * $dx / 3,
                $px($i + 1) - $dx / 3, $y[$i + 1] - $m[$i + 1] * $dx / 3,
                $px($i + 1), $y[$i + 1],
            );
        }

        return $d;
    };
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
        <div class="text-sm font-semibold text-gray-700">{{ $judul }}</div>
        <div class="flex flex-wrap items-center gap-3">
            @foreach ($seri as $s)
                <span class="flex items-center gap-1.5 text-xs text-gray-600">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $palet[$indeks[$s['label']] % count($palet)] }}"></span>
                    {{ $s['label'] }}
                </span>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto p-3">
        @if ($seri->isEmpty())
            <p class="py-12 text-center text-sm text-gray-400">Belum ada data untuk digambarkan.</p>
        @else
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" style="min-width: 640px; height: {{ $h }}px"
                 role="img" aria-label="{{ $judul }} — satu garis per jenjang">
                @foreach ([0, 1, 2, 3, 4] as $k)
                    @php $ny = $py($k * $langkah); @endphp
                    <line x1="{{ $kiri }}" y1="{{ round($ny, 1) }}" x2="{{ $w - $kanan }}" y2="{{ round($ny, 1) }}"
                          stroke="#e5e7eb" stroke-width="1" />
                    <text x="{{ $kiri - 8 }}" y="{{ round($ny + 3, 1) }}" text-anchor="end" font-size="10" fill="#9ca3af">{{ $k * $langkah }}</text>
                @endforeach

                <text x="14" y="{{ $atas + $plotH / 2 }}" font-size="10" fill="#6b7280"
                      transform="rotate(-90 14 {{ $atas + $plotH / 2 }})" text-anchor="middle">{{ $satuan }}</text>

                @foreach ($bulan as $i => $namaBulan)
                    <text x="{{ round($px($i), 1) }}" y="{{ $h - 12 }}" text-anchor="middle" font-size="10" fill="#6b7280">{{ $namaBulan }}</text>
                @endforeach

                @foreach ($seri as $s)
                    @php $warna = $palet[$indeks[$s['label']] % count($palet)]; @endphp
                    <path d="{{ $jalur($s['nilai']) }}" fill="none" stroke="{{ $warna }}" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round" />
                    @foreach ($s['nilai'] as $i => $v)
                        {{-- Cincin putih 2px memisahkan titik yang saling menimpa. --}}
                        <circle cx="{{ round($px($i), 1) }}" cy="{{ round($py($v), 1) }}" r="4"
                                fill="{{ $warna }}" stroke="#ffffff" stroke-width="2">
                            <title>{{ $s['label'] }} · {{ $bulan[$i] }}: {{ $v }}</title>
                        </circle>
                    @endforeach
                @endforeach

                <line x1="{{ $kiri }}" y1="{{ $atas + $plotH }}" x2="{{ $w - $kanan }}" y2="{{ $atas + $plotH }}"
                      stroke="#d1d5db" stroke-width="1" />
            </svg>
        @endif
    </div>
</div>
