<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JalurNonaktif;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TarifBiaya;
use App\Support\Money;
use App\Support\Referensi;
use Illuminate\Support\Facades\DB;

/**
 * GRID TARIF — sumber tunggal besaran biaya kesantrian.
 *
 * Menggantikan pencarian empat tingkat di `JenisBiaya::berlaku()` (jenjang+jalur
 * → jalur → jenjang → umum) yang tak bisa ditebak petugas dari layar. Di sini
 * tahun ajaran & jenjang WAJIB cocok persis, dan hanya tersisa SATU tingkat
 * pencarian: baris jalur → baris "Umum (semua jalur)".
 *
 * Hasil pencarian selalu menyebutkan ASAL angkanya, supaya pratinjau penagihan
 * bisa menampilkan "Tarif SMA · jalur Lanjutan Reguler · T.A 2027/2028" alih-alih
 * memunculkan angka yang entah dari mana.
 */
class TarifService
{
    /** Perilaku yang punya sel di grid. "lain" tidak: nominalnya diketik saat menagih. */
    public const PERILAKU = [
        'registrasi' => 'Registrasi',
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Perlengkapan',
        'daftar_ulang' => 'Daftar Ulang',
        'spp' => 'SPP',
    ];

    /**
     * Perilaku yang TIDAK bergantung jalur pendaftaran — hanya baris Umum yang
     * bisa diisi. Keduanya untuk santri yang sudah bersekolah; jalur hanya
     * bermakna saat ia masuk. Perbedaan per santri ditangani nominal khusus
     * (SPP) atau nominal yang bisa ditimpa saat menerbitkan (daftar ulang).
     */
    public const TANPA_JALUR = ['daftar_ulang', 'spp'];

    /**
     * Perilaku yang tarifnya dibedakan PER KENAIKAN TINGKAT.
     *
     * Daftar ulang ditagih kepada santri yang NAIK tingkat — 1→2, 2→3, dan
     * seterusnya. Jumlah selnya `jumlah_tingkat - 1`: SDTQ yang bertingkat 6
     * punya 5 sel, karena tingkat 1 bukan hasil kenaikan.
     *
     * `tingkat` pada `tarif_biaya` menyimpan tingkat **TUJUAN** (2 … N), bukan
     * asal. Sebabnya urutan kerja di pesantren ini: **daftar ulang ditagih SETELAH
     * kenaikan dieksekusi**, jadi saat tagihan dibuat tingkat santri sudah tingkat
     * yang baru. Dengan menyimpan tujuan, pencariannya langsung memakai
     * `santri.tingkat` apa adanya — tanpa aritmetika yang mudah salah arah.
     *
     * Yang TIDAK ditagih daftar ulang: santri di tingkat 1 (belum pernah naik) dan
     * santri pada TAHUN MASUKNYA — termasuk pindahan dari luar yang langsung masuk
     * ke tingkat 2 atau lebih. Keduanya membayar registrasi + uang pangkal +
     * perlengkapan.
     *
     * Cocoknya harus PERSIS: tak ada baris "semua tingkat" sebagai cadangan,
     * supaya tingkat yang lupa diisi berhenti dengan pesan alih-alih diam-diam
     * memakai angka tingkat lain.
     */
    public const PER_TINGKAT = ['daftar_ulang'];

    /** Tingkat terakhir sebuah jenjang (0 bila jumlah tingkatnya belum diisi). */
    public static function tingkatTerakhir(?string $kodeJenjang): int
    {
        return (int) (Jenjang::find((string) $kodeJenjang)?->tingkatAkhir() ?? 0);
    }

    /**
     * Tingkat TUJUAN yang punya sel daftar ulang: tingkat KEDUA jenjang ini
     * sampai tingkat terakhirnya. Tingkat pertama tak punya karena ia bukan
     * hasil kenaikan — santri yang baru masuk membayar registrasi + uang pangkal.
     *
     * Sejak penomorannya berkelanjutan, "tingkat pertama" bukan lagi selalu 1:
     * SMP mulai di 7, jadi selnya 8–9, bukan 2–3.
     *
     * @return list<int>
     */
    public static function tingkatKenaikan(?string $kodeJenjang): array
    {
        $jenjang = Jenjang::find((string) $kodeJenjang);
        if (! $jenjang || ! $jenjang->jumlah_tingkat) {
            return [];
        }
        $pertama = $jenjang->tingkatMulai();
        $terakhir = $jenjang->tingkatAkhir();

        return $terakhir <= $pertama ? [] : range($pertama + 1, $terakhir);
    }

    /**
     * Tarif yang berlaku untuk satu kombinasi.
     *
     * `asal`/`label` = kalimatnya sudah jadi, untuk pemanggil yang cuma butuh
     * teks (pesan galat, cetakan). `bagian` = bahan mentahnya, untuk layar yang
     * ingin menebalkan namanya sendiri — lihat <x-asal-tarif>. Markup TIDAK
     * dirakit di sini: nama jenjang & jalur adalah isian pemakai, jadi
     * penebalannya harus terjadi di Blade agar nilainya tetap ter-escape.
     * `bagian` null = kalimatnya bukan kalimat asal tarif (mis. tahun ajaran
     * santri kosong), jadi tak ada yang bisa ditebalkan.
     *
     * @return array{status:'ada'|'bebas'|'kosong', nominal:?string, asal:?string, label:string,
     *               bagian:?array{jenjang:?string,tingkat:?int,jalur:?string,tahun_ajaran:string,catatan:?string}}
     *         status "kosong" = selnya belum diisi — pemanggil WAJIB berhenti,
     *         jangan diperlakukan sama dengan "bebas".
     */
    public function cari(string $perilaku, ?string $tahunAjaran, ?string $kodeJenjang, ?string $kodeJalur, int|string|null $tingkat = null): array
    {
        $label = static::PERILAKU[$perilaku] ?? $perilaku;
        if (! $tahunAjaran) {
            return ['status' => 'kosong', 'nominal' => null, 'asal' => null, 'bagian' => null,
                'label' => 'Tahun ajaran santri belum terisi, jadi tarifnya tak bisa dicari.'];
        }

        // Perilaku bertingkat menuntut tingkatnya diketahui. Santri hasil impor
        // lama boleh belum bertingkat, dan itu harus dikatakan terus terang —
        // bukan diam-diam memakai tarif tingkat mana pun.
        $perTingkat = in_array($perilaku, static::PER_TINGKAT, true);
        $tingkat = ($tingkat === '' || $tingkat === null) ? null : (int) $tingkat;
        if ($perTingkat && $tingkat === null) {
            return ['status' => 'kosong', 'nominal' => null, 'asal' => null, 'bagian' => null,
                'label' => "Tarif {$label} dibedakan per tingkat, tetapi tingkat santri ini belum terisi. "
                    .'Isi tingkatnya dulu di data santri.'];
        }

        $baris = TarifBiaya::where('tahun_ajaran', $tahunAjaran)
            // Jenjang harus COCOK PERSIS — termasuk sama-sama kosong. Ini bukan
            // tingkat cadangan: santri berjenjang tak boleh diam-diam memakai
            // tarif "tanpa jenjang", dan sebaliknya.
            ->whereRaw('kode_jenjang IS NOT DISTINCT FROM ?', [$kodeJenjang])
            ->where('perilaku', $perilaku)
            // Tingkat juga cocok persis; perilaku yang tak bertingkat selalu
            // mencari baris bertingkat kosong.
            ->whereRaw('tingkat IS NOT DISTINCT FROM ?', [$perTingkat ? $tingkat : null])
            // Jalur COCOK PERSIS — biaya masuk tak lagi punya baris "Umum (semua
            // jalur)" sebagai cadangan. Jalur wajib diisi saat registrasi, jadi
            // cadangan itu tak pernah dibutuhkan untuk menemukan tarif; yang ia
            // lakukan hanyalah menagih diam-diam dari sel yang tampak kosong.
            //
            // SPP & daftar ulang lain perkara: keduanya memang tak mengenal
            // jalur, jadi jalur yang dikirim pemanggil DIABAIKAN dan barisnya
            // dicari dengan jalur kosong.
            ->whereRaw('kode_jalur IS NOT DISTINCT FROM ?', [
                in_array($perilaku, static::TANPA_JALUR, true) ? null : $kodeJalur,
            ])
            ->first();

        // Jenjang & jalur disebut lewat NAMA-nya. Kodenya (`J003`, `005`) tak
        // bercerita apa pun bagi petugas yang membaca kalimat asal tarif di
        // layar — dan kalimat ini memang ada supaya ia tak perlu menebak sel
        // mana yang terpakai. Kode dipakai sebagai cadangan bila baris masternya
        // sudah tak ada.
        $namaJenjang = $this->namaJenjang($kodeJenjang);
        $labelJenjang = $kodeJenjang ? "jenjang {$namaJenjang}" : 'tanpa jenjang';
        $labelTingkat = $perTingkat ? " tingkat {$tingkat}" : '';

        // Penanda "bebas uang pangkal" di master Jalur MENANG atas isi selnya.
        // Dulu penanda itu hanya mematikan sel di layar dan tak pernah diperiksa
        // saat menagih, sehingga jalur bertanda bebas tetap menagih puluhan juta
        // tanpa ada yang menyadarinya. Ditegakkan di sini — satu-satunya pintu
        // yang dilewati semua pemanggil.
        if ($perilaku === 'uang_pangkal' && JalurPendaftaran::bebasUangPangkal($kodeJalur)) {
            $asal = 'Jalur '.$this->namaJalur($kodeJalur).' · bebas uang pangkal · T.A '.$tahunAjaran;

            return ['status' => 'bebas', 'nominal' => null, 'asal' => $asal,
                'label' => $asal.' — bebas (tidak dipungut)',
                'bagian' => ['jenjang' => $namaJenjang, 'tingkat' => null,
                    'jalur' => $this->namaJalur($kodeJalur), 'tahun_ajaran' => $tahunAjaran,
                    'catatan' => 'Ditetapkan di master Jalur Pendaftaran, bukan di sel tarif.']];
        }
        if (! $baris) {
            return ['status' => 'kosong', 'nominal' => null, 'asal' => null, 'bagian' => null,
                'label' => "Tarif {$label} untuk {$labelJenjang}{$labelTingkat}"
                    .($kodeJalur ? ' jalur '.$this->namaJalur($kodeJalur) : '')." T.A {$tahunAjaran} belum diisi."];
        }

        $namaJalur = $baris->kode_jalur ? $this->namaJalur($baris->kode_jalur) : null;
        $asal = 'Tarif '.($namaJenjang ?: 'tanpa jenjang').$labelTingkat
            .' · '.($namaJalur ? 'jalur '.$namaJalur : 'baris Umum')
            .' · T.A '.$tahunAjaran;
        $bagian = [
            'jenjang' => $namaJenjang,
            'tingkat' => $perTingkat ? $tingkat : null,
            'jalur' => $namaJalur, // null = baris Umum
            'tahun_ajaran' => $tahunAjaran,
            'catatan' => null,
        ];

        if ($baris->bebas) {
            $catatan = '— bebas (tidak dipungut)';

            return ['status' => 'bebas', 'nominal' => null, 'asal' => $asal, 'label' => $asal.' '.$catatan,
                'bagian' => ['catatan' => $catatan] + $bagian];
        }
        if ($baris->nominal === null) {
            $catatan = 'ada, tapi nominalnya kosong dan tidak ditandai bebas.';

            return ['status' => 'kosong', 'nominal' => null, 'asal' => $asal, 'label' => $asal.' '.$catatan,
                'bagian' => ['catatan' => $catatan] + $bagian];
        }

        return ['status' => 'ada', 'nominal' => Money::of($baris->nominal), 'asal' => $asal,
            'label' => $asal, 'bagian' => $bagian];
    }

    /** @var array<string,string>|null peta kode→nama, dimemo PER INSTANCE */
    private ?array $petaJenjang = null;

    /** @var array<string,string>|null */
    private ?array $petaJalur = null;

    /**
     * Nama jenjang/jalur untuk kalimat asal tarif.
     *
     * Dimemo per INSTANCE, bukan statis: grid Tarif memanggil cari() puluhan kali
     * pada instance yang sama (satu kueri, bukan puluhan), sementara pemanggil
     * lain selalu `new TarifService` sehingga memonya tak pernah basi — termasuk
     * di test yang menambah jenjang atau jalur di tengah jalan.
     *
     * Kode dikembalikan apa adanya bila baris masternya sudah tak ada: lebih baik
     * menyebut `J003` daripada memutus kalimatnya.
     */
    private function namaJenjang(?string $kode): ?string
    {
        if (! $kode) {
            return null;
        }
        $this->petaJenjang ??= Jenjang::pluck('nama', 'kode')->all();

        return $this->petaJenjang[$kode] ?? $kode;
    }

    private function namaJalur(?string $kode): ?string
    {
        if (! $kode) {
            return null;
        }
        $this->petaJalur ??= JalurPendaftaran::pluck('nama', 'kode')->all();

        return $this->petaJalur[$kode] ?? $kode;
    }

    /**
     * Isi grid satu (T.A, jenjang): baris "Umum" lebih dulu, lalu tiap jalur aktif.
     *
     * @return array{jalur:list<array{kode:?string,nama:string,sel:array<string,array{nominal:?string,bebas:bool}>}>}
     */
    public function grid(string $tahunAjaran, ?string $kodeJenjang): array
    {
        $tersimpan = TarifBiaya::where('tahun_ajaran', $tahunAjaran)
            ->whereRaw('kode_jenjang IS NOT DISTINCT FROM ?', [$kodeJenjang])->get()
            ->keyBy(fn ($t) => ($t->kode_jalur ?? '-').'|'.$t->perilaku.'|'.($t->tingkat ?? '-'));

        $ambil = function (?string $jalur, string $perilaku, ?int $tingkat = null) use ($tersimpan) {
            $row = $tersimpan[($jalur ?? '-').'|'.$perilaku.'|'.($tingkat ?? '-')] ?? null;

            return [
                'nominal' => $row && $row->nominal !== null ? Money::of($row->nominal) : null,
                'bebas' => (bool) ($row?->bebas),
            ];
        };

        $nonaktif = JalurNonaktif::kodeUntuk($tahunAjaran, $kodeJenjang);
        $semuaJalur = JalurPendaftaran::where('status', 'aktif')->orderBy('kode')->get();

        // ---- Matriks per jalur: hanya perilaku BIAYA MASUK ----
        // Tanpa baris "Umum (semua jalur)": ia tak punya padanan di dropdown
        // registrasi — petugas diminta mengisi sesuatu yang tak pernah bisa
        // dipilihnya — dan tarifnya kini tersimpan lengkap di tiap jalur.
        $perJalur = array_values(array_diff(array_keys(static::PERILAKU), static::TANPA_JALUR));
        $baris = [];
        foreach ($semuaJalur as $j) {
            if (in_array($j->kode, $nonaktif, true)) {
                continue; // tak berlaku di jenjang & T.A ini
            }
            // Label mengkerut bila kode = nama (mis. jenjang SDTQ) — lihat Referensi::label().
            $baris[] = ['kode' => $j->kode, 'nama' => Referensi::label($j->kode, $j->nama), 'bebas_up' => (bool) $j->bebas_uang_pangkal];
        }
        $hasil = [];
        foreach ($baris as $b) {
            $sel = [];
            foreach ($perJalur as $p) {
                $sel[$p] = $ambil($b['kode'], $p);
            }
            $hasil[] = $b + ['sel' => $sel];
        }

        // ---- Biaya santri aktif: tak mengenal jalur; daftar ulang per KENAIKAN ----
        $tingkatKenaikan = static::tingkatKenaikan($kodeJenjang);
        $umum = [];
        foreach (static::TANPA_JALUR as $p) {
            if (in_array($p, static::PER_TINGKAT, true)) {
                $umum[$p] = [];
                // Tingkat TUJUAN: 2 … tingkat terakhir.
                foreach ($tingkatKenaikan as $t) {
                    $umum[$p][$t] = $ambil(null, $p, $t);
                }
            } else {
                $umum[$p] = $ambil(null, $p);
            }
        }

        return [
            'jalur' => $hasil,
            'umum' => $umum,
            'tingkat_kenaikan' => $tingkatKenaikan,
            'nonaktif' => $semuaJalur->whereIn('kode', $nonaktif)
                ->map(fn ($j) => ['kode' => $j->kode, 'nama' => $j->nama])->values()->all(),
        ];
    }

    /**
     * Jalur yang BERLAKU untuk satu (T.A, jenjang) — dipakai grid, dropdown form
     * pendaftaran, dan penjaga di SantriService.
     *
     * @return array<string,string> kode => nama
     */
    public function jalurBerlaku(?string $tahunAjaran, ?string $kodeJenjang): array
    {
        $nonaktif = JalurNonaktif::kodeUntuk($tahunAjaran, $kodeJenjang);

        return JalurPendaftaran::where('status', 'aktif')
            ->when($nonaktif !== [], fn ($q) => $q->whereNotIn('kode', $nonaktif))
            ->orderBy('kode')->pluck('nama', 'kode')->all();
    }

    /** Tandai satu jalur tidak berlaku di (T.A, jenjang). Sudah ditandai = diam saja. */
    public function nonaktifkanJalur(string $tahunAjaran, string $kodeJenjang, string $kodeJalur): void
    {
        $this->pastikanAda($tahunAjaran, $kodeJenjang);
        if (! JalurPendaftaran::find($kodeJalur)) {
            throw new AppException(422, "Jalur \"{$kodeJalur}\" tidak terdaftar.");
        }

        // Santri yang TERLANJUR memakai kombinasi ini dihalangi lebih dulu:
        // menonaktifkannya diam-diam akan membuat tarif mereka tak ketemu lagi
        // dan penagihannya buntu tanpa sebab yang jelas.
        $terpakai = Santri::where('tahun_ajaran', $tahunAjaran)
            ->where('kode_jenjang', $kodeJenjang)->where('jalur', $kodeJalur)->count();
        if ($terpakai > 0) {
            throw new AppException(422, "Masih ada {$terpakai} santri berjenjang {$kodeJenjang} jalur \"{$kodeJalur}\" "
                ."pada T.A {$tahunAjaran}. Pindahkan jalurnya dulu — menonaktifkan sekarang akan membuat tarif mereka tak ditemukan.");
        }

        JalurNonaktif::firstOrCreate([
            'tahun_ajaran' => $tahunAjaran, 'kode_jenjang' => $kodeJenjang, 'kode_jalur' => $kodeJalur,
        ]);

        // Sel tarif yang sudah telanjur diisi untuk jalur itu ikut dibuang supaya
        // grid & basis data tidak bercerita beda.
        TarifBiaya::where('tahun_ajaran', $tahunAjaran)
            ->whereRaw('kode_jenjang IS NOT DISTINCT FROM ?', [$kodeJenjang])
            ->where('kode_jalur', $kodeJalur)->delete();
    }

    /** Kembalikan jalur agar berlaku lagi di (T.A, jenjang). */
    public function aktifkanJalur(string $tahunAjaran, string $kodeJenjang, string $kodeJalur): void
    {
        JalurNonaktif::where('tahun_ajaran', $tahunAjaran)
            ->where('kode_jenjang', $kodeJenjang)->where('kode_jalur', $kodeJalur)->delete();
    }

    /**
     * Simpan satu grid sekali jalan. `$sel` = [kodeJalur|'-' => [perilaku => ['nominal'=>?string,'bebas'=>bool]]].
     *
     * Sel yang nominalnya kosong DAN tidak bertanda bebas berarti "belum diisi":
     * barisnya dihapus, bukan disimpan bernominal nol. Nol adalah angka yang sah
     * (gratis, tetap terbit) dan tak boleh tertukar dengan "tidak dipungut".
     */
    public function simpan(string $tahunAjaran, ?string $kodeJenjang, array $sel): int
    {
        $this->pastikanAda($tahunAjaran, $kodeJenjang);
        $jalurSah = JalurPendaftaran::pluck('kode')->flip();

        return DB::transaction(function () use ($tahunAjaran, $kodeJenjang, $sel, $jalurSah) {
            $tersentuh = 0;
            // Kunci "-" bukan lagi baris Umum melainkan ISI MASSAL: nilainya
            // dituliskan ke SETIAP jalur sebagai baris sungguhan. Bedanya
            // menentukan — yang tertulis di sel tetap sama dengan yang ditagih,
            // tak ada lagi jalur yang menagih dari baris yang tak kelihatan.
            $sel = $this->bentangkanKeSemuaJalur($sel, $jalurSah);

            foreach ($sel as $kunciJalur => $perilakuSel) {
                $kodeJalur = (string) $kunciJalur;
                if (! isset($jalurSah[$kodeJalur])) {
                    throw new AppException(422, "Jalur \"{$kodeJalur}\" tidak terdaftar.");
                }
                foreach ($perilakuSel as $perilaku => $isi) {
                    if (! isset(static::PERILAKU[$perilaku])) {
                        continue;
                    }
                    // Biaya santri aktif punya jalurnya sendiri (simpanUmum) — tak
                    // boleh menyelinap lewat matriks jalur walau kirimannya dipaksakan.
                    if (in_array($perilaku, static::TANPA_JALUR, true)) {
                        throw new AppException(422, static::PERILAKU[$perilaku].' bukan biaya masuk dan tidak dibedakan per jalur — '
                            .'isi tarifnya di bagian "Biaya santri aktif".');
                    }
                    $kunci = ['tahun_ajaran' => $tahunAjaran, 'kode_jenjang' => $kodeJenjang,
                        'kode_jalur' => $kodeJalur, 'perilaku' => $perilaku, 'tingkat' => null];

                    $tersentuh += $this->tulisSel($kunci, ...$this->bacaIsi($isi));
                }
            }

            return $tersentuh;
        });
    }

    /**
     * Terjemahkan kunci isi-massal ("-" / "") menjadi kiriman per jalur.
     *
     * Nilai massal dipasang LEBIH DULU, sehingga sel yang juga disebut per jalur
     * di kiriman yang sama tetap menang — persis urutan yang dulu berlaku antara
     * baris jalur dan baris Umum, tapi kini hasilnya tertulis di tiap sel.
     *
     * @param  array<string,mixed>  $sel
     * @param  \Illuminate\Support\Collection<string,int>  $jalurSah
     * @return array<string,mixed>
     */
    private function bentangkanKeSemuaJalur(array $sel, $jalurSah): array
    {
        $massal = $sel['-'] ?? $sel[''] ?? null;
        unset($sel['-'], $sel['']);
        if ($massal === null) {
            return $sel;
        }

        $hasil = [];
        foreach ($jalurSah->keys() as $kode) {
            $hasil[$kode] = $massal;
        }
        foreach ($sel as $kode => $isi) {
            $hasil[$kode] = array_merge($hasil[$kode] ?? [], $isi);
        }

        return $hasil;
    }

    /**
     * Simpan BIAYA SANTRI AKTIF: SPP (satu angka per jenjang) & daftar ulang (satu
     * angka per tingkat). Dipisah dari `simpan()` karena dimensinya berbeda —
     * keduanya tak mengenal jalur, dan daftar ulang justru mengenal tingkat.
     *
     * `$umum` = [perilaku => ['nominal'=>?string,'bebas'=>bool]] untuk yang tak
     * bertingkat, dan [perilaku => [tingkat => ['nominal'=>…,'bebas'=>…]]] untuk
     * yang bertingkat.
     */
    public function simpanUmum(string $tahunAjaran, ?string $kodeJenjang, array $umum): int
    {
        $this->pastikanAda($tahunAjaran, $kodeJenjang);
        $tingkatSah = static::tingkatKenaikan($kodeJenjang);

        return DB::transaction(function () use ($tahunAjaran, $kodeJenjang, $umum, $tingkatSah) {
            $tersentuh = 0;
            foreach ($umum as $perilaku => $isi) {
                if (! in_array($perilaku, static::TANPA_JALUR, true)) {
                    throw new AppException(422, (static::PERILAKU[$perilaku] ?? $perilaku)
                        .' adalah biaya masuk — isi tarifnya di matriks jalur, bukan di bagian "Biaya santri aktif".');
                }
                $kunci = ['tahun_ajaran' => $tahunAjaran, 'kode_jenjang' => $kodeJenjang,
                    'kode_jalur' => null, 'perilaku' => $perilaku];

                if (! in_array($perilaku, static::PER_TINGKAT, true)) {
                    $tersentuh += $this->tulisSel($kunci + ['tingkat' => null], ...$this->bacaIsi($isi));

                    continue;
                }

                foreach ($isi as $tingkat => $satu) {
                    $tingkat = (int) $tingkat;
                    // Tingkat TUJUAN kenaikan: 2 … tingkat terakhir. Tingkat 1 tak
                    // punya tarif daftar ulang karena ia bukan hasil kenaikan.
                    if ($tingkatSah !== [] && ! in_array($tingkat, $tingkatSah, true)) {
                        throw new AppException(422, "Tingkat {$tingkat} bukan hasil kenaikan di jenjang \"{$kodeJenjang}\""
                            .' (yang bertarif daftar ulang: tingkat '.implode(', ', $tingkatSah).').');
                    }
                    $tersentuh += $this->tulisSel($kunci + ['tingkat' => $tingkat], ...$this->bacaIsi($satu));
                }
            }

            return $tersentuh;
        });
    }

    /**
     * Satu sel ditulis, atau DIHAPUS bila nominalnya kosong & tak bertanda bebas —
     * "belum diisi" memang tak punya baris, dan menyimpannya bernominal nol akan
     * tertukar dengan gratis-tapi-tetap-terbit.
     */
    private function tulisSel(array $kunci, ?string $nominal, bool $bebas): int
    {
        if (! $bebas && $nominal === null) {
            TarifBiaya::where($kunci)->delete();

            return 0;
        }
        TarifBiaya::updateOrCreate($kunci, ['nominal' => $nominal, 'bebas' => $bebas]);

        return 1;
    }

    /** @return array{0:?string,1:bool} [nominal, bebas] dari satu kiriman sel. */
    private function bacaIsi(array $isi): array
    {
        $bebas = (bool) ($isi['bebas'] ?? false);
        $mentah = trim((string) ($isi['nominal'] ?? ''));
        $nominal = ($bebas || $mentah === '') ? null : Money::of($mentah);
        if ($nominal !== null && Money::lt($nominal, '0')) {
            throw new AppException(422, 'Nominal tarif tidak boleh negatif.');
        }

        return [$nominal, $bebas];
    }

    /**
     * Salin seluruh sel dari satu T.A ke T.A lain. Sel yang SUDAH ada di tujuan
     * dilewati (bukan ditimpa) supaya penyalinan bisa diulang tanpa menghapus
     * penyesuaian yang sudah dikerjakan petugas.
     *
     * @return array{disalin:int,dilewati:int,jalur_ditutup:int}
     */
    public function salin(string $taSumber, string $taTujuan, ?string $kodeJenjang = null): array
    {
        if ($taSumber === $taTujuan) {
            throw new AppException(422, 'Tahun ajaran sumber dan tujuan tidak boleh sama.');
        }
        foreach ([$taSumber, $taTujuan] as $ta) {
            if (! TahunAjaran::where('kode', $ta)->exists()) {
                throw new AppException(422, "Tahun ajaran \"{$ta}\" tidak terdaftar.");
            }
        }

        $sumber = TarifBiaya::where('tahun_ajaran', $taSumber)
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang))->get();
        if ($sumber->isEmpty()) {
            throw new AppException(422, "Tidak ada tarif yang bisa disalin dari T.A {$taSumber}.");
        }

        $adaDiTujuan = TarifBiaya::where('tahun_ajaran', $taTujuan)
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang))->get()
            ->keyBy(fn ($t) => $t->kode_jenjang.'|'.($t->kode_jalur ?? '-').'|'.$t->perilaku.'|'.($t->tingkat ?? '-'));

        // Penonaktifan jalur ikut disalin: ia disimpan per tahun ajaran, jadi
        // tanpa ini jalur yang sudah ditutup tahun lalu akan muncul lagi tiap
        // tahun baru dan harus ditutup ulang satu per satu.
        $nonaktifSumber = JalurNonaktif::where('tahun_ajaran', $taSumber)
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang))->get();

        return DB::transaction(function () use ($sumber, $adaDiTujuan, $taTujuan, $nonaktifSumber) {
            $disalin = 0;
            foreach ($sumber as $s) {
                if (isset($adaDiTujuan[$s->kode_jenjang.'|'.($s->kode_jalur ?? '-').'|'.$s->perilaku.'|'.($s->tingkat ?? '-')])) {
                    continue;
                }
                TarifBiaya::create([
                    'tahun_ajaran' => $taTujuan, 'kode_jenjang' => $s->kode_jenjang,
                    'kode_jalur' => $s->kode_jalur, 'perilaku' => $s->perilaku, 'tingkat' => $s->tingkat,
                    'nominal' => $s->nominal, 'bebas' => $s->bebas,
                    'keterangan' => "Disalin dari T.A {$s->tahun_ajaran}.",
                ]);
                $disalin++;
            }

            $jalurDitutup = 0;
            foreach ($nonaktifSumber as $n) {
                $baru = JalurNonaktif::firstOrCreate([
                    'tahun_ajaran' => $taTujuan, 'kode_jenjang' => $n->kode_jenjang, 'kode_jalur' => $n->kode_jalur,
                ], ['keterangan' => "Disalin dari T.A {$n->tahun_ajaran}."]);
                if ($baru->wasRecentlyCreated) {
                    $jalurDitutup++;
                }
            }

            return ['disalin' => $disalin, 'dilewati' => $sumber->count() - $disalin, 'jalur_ditutup' => $jalurDitutup];
        });
    }

    private function pastikanAda(string $tahunAjaran, ?string $kodeJenjang): void
    {
        if (! TahunAjaran::where('kode', $tahunAjaran)->exists()) {
            throw new AppException(422, "Tahun ajaran \"{$tahunAjaran}\" tidak terdaftar.");
        }
        if ($kodeJenjang !== null && ! Jenjang::find($kodeJenjang)) {
            throw new AppException(422, "Jenjang \"{$kodeJenjang}\" tidak terdaftar.");
        }
    }
}
