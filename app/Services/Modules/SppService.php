<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\JournalEntry;
use App\Models\MutasiDompet;
use App\Models\PrabayarSpp;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * SPP — akrual: diakui saat DITERBITKAN (D Piutang / K Pendapatan), jadi
 * sudah_akrual=true sejak lahir; pembayaran mengurangi piutang. Jenis & tarif
 * mengikuti JENJANG santri (nominal khusus hanya mengganti angka).
 */
class SppService
{
    /** Nominal & jenis SPP seorang santri (khusus menang atas tarif jenjang). */
    public function nominalSppSantri(int $idSantri): array
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if (! $santri->kode_jenjang) {
            throw new AppException(422, "Santri \"{$santri->nama}\" belum punya jenjang, jadi jenis & tarif SPP-nya tak bisa ditentukan.");
        }
        // Tarif dicari pada tahun yang SEDANG DIJALANI, bukan angkatan: santri
        // angkatan 2026 yang kini kelas 3 pada T.A 2028 harus memakai tarif 2028.
        ['jenis' => $jenis, 'tarif' => $tarif] = (new SantriService)
            ->komponen('spp', $santri->taBerjalan(), $santri->kode_jenjang, $santri->jalur);

        // Nominal khusus per santri menang atas grid — dan sengaja diperiksa
        // SEBELUM status tarif: santri bertarif khusus tetap bisa ditagih walau
        // sel grid jenjangnya belum diisi.
        if ($santri->nominal_spp !== null) {
            return ['nominal' => Money::of($santri->nominal_spp), 'asal' => 'khusus',
                'kode_jenis' => $jenis->kode, 'asal_label' => 'Nominal khusus santri', 'keterangan' => $santri->keterangan_spp];
        }
        if ($tarif['status'] === 'bebas') {
            throw new AppException(422, "Tarif SPP untuk jenjang \"{$santri->kode_jenjang}\" jalur \"{$santri->jalur}\" bertanda BEBAS, "
                .'jadi tak ada SPP yang bisa diterbitkan. Isi nominal khusus santri bila ia tetap harus membayar.');
        }
        if ($tarif['status'] !== 'ada') {
            throw new AppException(422, $tarif['label'].' Isi selnya di menu Setting Awal → Tarif.');
        }

        return ['nominal' => $tarif['nominal'], 'asal' => 'jenjang',
            'kode_jenis' => $jenis->kode, 'asal_label' => $tarif['asal'], 'keterangan' => null];
    }

    /** Identitas akuntansi SPP untuk sebuah jenjang (tarifnya ada di grid Tarif). */
    public function jenisSppSantri(string $kodeJenjang): ?JenisBiaya
    {
        return JenisBiaya::untuk('spp', $kodeJenjang);
    }

    /** Pratinjau: siapa ditagih berapa untuk periode. */
    public function pratinjau(string $periode): array
    {
        $santri = Santri::where('status', 'aktif')->orderBy('nama')
            ->get(['id', 'nama', 'nis', 'kode_jenjang', 'tahun_ajaran', 'tahun_ajaran_berjalan', 'nominal_spp', 'keterangan_spp']);
        $sudahAda = TagihanSantri::where('perilaku', 'spp')->where('periode', $periode)->pluck('id_santri')->all();

        $hasil = [];
        foreach ($santri as $s) {
            if (in_array($s->id, $sudahAda, true)) {
                $hasil[] = ['id' => $s->id, 'nama' => $s->nama, 'status' => 'sudah_ada', 'nominal' => null, 'kode_jenis' => null];

                continue;
            }
            try {
                $n = $this->nominalSppSantri($s->id);
                $hasil[] = ['id' => $s->id, 'nama' => $s->nama, 'nominal' => $n['nominal'], 'asal' => $n['asal'],
                    'asal_label' => $n['asal_label'], 'kode_jenis' => $n['kode_jenis'], 'status' => 'siap',
                    // Tagihan dicap tahun yang SEDANG DIJALANI, bukan angkatan —
                    // itulah yang membuat SPP tahun kedua tak menabrak tahun pertama.
                    'kode_jenjang' => $s->kode_jenjang, 'tahun_ajaran' => $s->taBerjalan()];
            } catch (AppException $e) {
                $hasil[] = ['id' => $s->id, 'nama' => $s->nama, 'status' => 'tanpa_tarif', 'nominal' => null, 'kode_jenis' => null, 'pesan' => $e->getMessage()];
            }
        }

        return $hasil;
    }

    /** Terbitkan tagihan SPP satu periode untuk seluruh santri aktif (satu jurnal per jenis). */
    public function generate(array $data, int $idPengguna): array
    {
        $rencana = array_values(array_filter($this->pratinjau($data['periode']), fn ($r) => $r['status'] === 'siap'));
        if (count($rencana) === 0) {
            throw new AppException(422, "Tidak ada tagihan SPP yang bisa diterbitkan untuk periode {$data['periode']}.");
        }

        $perJenis = [];
        foreach ($rencana as $r) {
            $perJenis[$r['kode_jenis']][] = $r;
        }
        $jenisMap = JenisBiaya::whereIn('kode', array_keys($perJenis))->get()->keyBy('kode');
        foreach ($jenisMap as $kode => $j) {
            if (! $j->kode_coa_piutang) {
                throw new AppException(422, "Jenis biaya \"{$j->nama}\" ({$kode}) belum punya akun piutang. SPP diakui akrual, jadi akun itu wajib.");
            }
        }
        $total = array_reduce($rencana, fn ($a, $r) => Money::add($a, $r['nominal']), '0');

        $hasil = DB::transaction(function () use ($data, $perJenis, $jenisMap, $total, $idPengguna) {
            $base = DocNumber::docBase('SPP', $data['tanggal']);
            $nomor = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $referensi = [];
            $now = now();

            foreach ($perJenis as $kode => $baris) {
                $jenis = $jenisMap[$kode];
                $subtotal = array_reduce($baris, fn ($a, $r) => Money::add($a, $r['nominal']), '0');
                $nomor = DocNumber::nextDocNumber($base, $nomor);
                $referensi[] = $nomor;

                PostingService::postJournal([
                    'referensi' => $nomor, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                    'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                    'keterangan' => "{$jenis->nama} periode {$data['periode']} — ".count($baris).' santri',
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $subtotal, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $subtotal],
                    ],
                ]);

                TagihanSantri::insert(array_map(fn ($r) => [
                    'id_santri' => $r['id'], 'kode_jenis' => $kode, 'periode' => $data['periode'],
                    'perilaku' => 'spp', 'kode_jenjang' => $r['kode_jenjang'], 'tahun_ajaran' => $r['tahun_ajaran'],
                    'nominal' => $r['nominal'], 'sisa' => $r['nominal'], 'sudah_akrual' => true, 'status' => 'belum_bayar',
                    'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => "{$jenis->nama} {$data['periode']} · akrual {$nomor}",
                    'created_at' => $now, 'updated_at' => $now,
                ], $baris));
            }

            // Saldo prabayar langsung dipakai untuk tagihan yang baru terbit.
            $terpakai = '0';
            foreach ($perJenis as $kode => $baris) {
                $t = $this->pakaiPrabayar(array_column($baris, 'id'), $data, $jenisMap[$kode], $idPengguna);
                $terpakai = Money::add($terpakai, $t);
            }

            return [
                'periode' => $data['periode'], 'terbit' => count($this->flatten($perJenis)), 'total' => $total,
                'referensi' => implode(', ', $referensi), 'prabayar_terpakai' => $terpakai,
            ];
        });

        $autoDebet = (new AutoDebetService)->jalankan($idPengguna, $data['tanggal']);

        return array_merge($hasil, ['auto_debet' => $autoDebet]);
    }

    /** Setoran prabayar: lunasi tunggakan tertua dulu, sisanya jadi saldo prabayar. */
    public function prabayar(array $data, int $idPengguna): array
    {
        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, 'Prabayar SPP hanya untuk santri aktif.');
        }
        $kodeJenis = $this->nominalSppSantri($data['id_santri'])['kode_jenis'];
        $jenis = JenisBiaya::findOrFail($kodeJenis);
        if (! $jenis->kode_coa_diterima_dimuka) {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" belum punya akun Pendapatan Diterima Dimuka.");
        }
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal setoran harus lebih dari nol.');
        }

        $dompet = null;
        if (($data['sumber'] ?? null) === 'dompet_wali') {
            $dompet = DompetWali::where('id_wali', $santri->id_wali)->first();
            if (! $dompet) {
                throw new AppException(422, 'Keluarga ini belum punya Dompet Wali.');
            }
            if (Money::lt($dompet->saldo, $nominal)) {
                throw new AppException(422, "Saldo Dompet Wali tidak cukup (tersedia {$dompet->saldo}).");
            }
            $akunDebet = DompetPolicy::COA_TITIPAN['wali'];
        } else {
            if (empty($data['kode_rekening'])) {
                throw new AppException(400, 'Kas/rekening penerima wajib dipilih.');
            }
            if (! BankAccount::find($data['kode_rekening'])) {
                throw new AppException(400, 'Kas/rekening penerima tidak ditemukan.');
            }
            $akunDebet = $data['kode_rekening'];
        }

        $tunggakan = TagihanSantri::where('id_santri', $data['id_santri'])->where('kode_jenis', $jenis->kode)
            ->whereIn('status', ['belum_bayar', 'sebagian'])->orderBy('periode')->orderBy('id')->get();

        return DB::transaction(function () use ($data, $idPengguna, $santri, $jenis, $nominal, $dompet, $akunDebet, $tunggakan) {
            $sisaSetoran = $nominal;
            $lines = [['kode_coa' => $akunDebet, 'debet' => $nominal, 'kredit' => '0']];
            $kePiutang = '0';
            foreach ($tunggakan as $t) {
                if (Money::lte($sisaSetoran, '0')) {
                    break;
                }
                $bayar = Money::lt($t->sisa, $sisaSetoran) ? Money::of($t->sisa) : $sisaSetoran;
                $sisaBaru = Money::sub($t->sisa, $bayar);
                $t->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
                $kePiutang = Money::add($kePiutang, $bayar);
                $sisaSetoran = Money::sub($sisaSetoran, $bayar);
            }
            if (Money::gtZero($kePiutang)) {
                $lines[] = ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $kePiutang];
            }
            if (Money::gtZero($sisaSetoran)) {
                $lines[] = ['kode_coa' => $jenis->kode_coa_diterima_dimuka, 'debet' => '0', 'kredit' => $sisaSetoran];
                $pra = PrabayarSpp::firstOrCreate(['id_santri' => $data['id_santri']], ['saldo' => '0']);
                $pra->update(['saldo' => Money::add($pra->saldo, $sisaSetoran)]);
            }

            $base = DocNumber::docBase('PRA', $data['tanggal']);
            $last = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $entry = PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                'keterangan' => "Setoran SPP {$santri->nama} — tunggakan {$kePiutang}, di muka {$sisaSetoran}",
                'lines' => $lines,
            ]);

            if ($dompet) {
                $dompet->update(['saldo' => Money::sub($dompet->saldo, $nominal)]);
                $baseM = DocNumber::docBase('DMP', $data['tanggal']);
                $lastM = MutasiDompet::where('nomor', 'like', $baseM.'%')->orderByDesc('nomor')->value('nomor');
                MutasiDompet::create([
                    'nomor' => DocNumber::nextDocNumber($baseM, $lastM), 'pemilik' => 'wali', 'id_dompet' => $dompet->id,
                    'jenis' => 'bayar_tagihan', 'nominal' => Money::sub('0', $nominal), 'saldo_setelah' => $dompet->saldo,
                    'tanggal' => $data['tanggal'], 'keterangan' => "Setoran SPP — {$santri->nama}", 'dicatat_oleh' => $idPengguna, 'journal_entry_id' => $entry->id,
                ]);
            }

            return ['referensi' => $nomor, 'dipakai_tunggakan' => $kePiutang, 'jadi_prabayar' => $sisaSetoran];
        });
    }

    public function saldoPrabayar(int $idSantri): array
    {
        $row = PrabayarSpp::where('id_santri', $idSantri)->first();

        return ['id_santri' => $idSantri, 'saldo' => Money::of($row->saldo ?? 0)];
    }

    private function flatten(array $perJenis): array
    {
        return array_merge(...array_values($perJenis));
    }

    /** Pakai saldo prabayar untuk tagihan baru terbit (D Diterima Dimuka / K Piutang). */
    private function pakaiPrabayar(array $idSantri, array $data, JenisBiaya $jenis, int $idPengguna): string
    {
        if (! $jenis->kode_coa_diterima_dimuka) {
            return '0';
        }
        $saldoList = PrabayarSpp::whereIn('id_santri', $idSantri)->where('saldo', '>', 0)->get();
        if ($saldoList->isEmpty()) {
            return '0';
        }
        $total = '0';
        foreach ($saldoList as $p) {
            $tagihan = TagihanSantri::where('id_santri', $p->id_santri)->where('kode_jenis', $jenis->kode)->where('periode', $data['periode'])->first();
            if (! $tagihan) {
                continue;
            }
            $pakai = Money::lt($p->saldo, $tagihan->sisa) ? Money::of($p->saldo) : Money::of($tagihan->sisa);
            if (Money::lte($pakai, '0')) {
                continue;
            }
            $sisaBaru = Money::sub($tagihan->sisa, $pakai);
            $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
            $p->update(['saldo' => Money::sub($p->saldo, $pakai)]);
            $total = Money::add($total, $pakai);
        }
        if (Money::gtZero($total)) {
            PostingService::postJournal([
                'referensi' => "{$data['periode']}-PRA", 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                'keterangan' => "Pemakaian saldo prabayar SPP periode {$data['periode']}",
                'lines' => [
                    ['kode_coa' => $jenis->kode_coa_diterima_dimuka, 'debet' => $total, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $total],
                ],
            ]);
        }

        return $total;
    }
}
