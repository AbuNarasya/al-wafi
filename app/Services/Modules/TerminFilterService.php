<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\TerminFilterSetting;

/**
 * Pengaturan filter "Termin jatuh tempo — perlu ditagih" (Angsuran Uang
 * Pangkal). Daftar pilihan jendela hari + default-nya diatur admin lewat modul
 * termin-filter; opsi "hanya yang lewat" (0) selalu tersedia.
 */
class TerminFilterService
{
    private const ID = 1;

    public const PILIHAN_DEFAULT = [7, 14, 30];

    public const DEFAULT_HARI = 7;

    public function pengaturan(): TerminFilterSetting
    {
        return TerminFilterSetting::find(self::ID)
            ?? new TerminFilterSetting([
                'id' => self::ID,
                'pilihan_hari' => implode(',', self::PILIHAN_DEFAULT),
                'default_hari' => self::DEFAULT_HARI,
            ]);
    }

    public function simpan(array $data): TerminFilterSetting
    {
        $pilihan = self::parsePilihan($data['pilihan_hari'] ?? '');
        $default = (int) ($data['default_hari'] ?? self::DEFAULT_HARI);
        if ($default !== 0 && ! in_array($default, $pilihan, true)) {
            throw new AppException(422, 'Default filter harus salah satu dari daftar pilihan hari (atau "hanya yang lewat").');
        }

        return TerminFilterSetting::updateOrCreate(
            ['id' => self::ID],
            ['pilihan_hari' => implode(',', $pilihan), 'default_hari' => $default],
        );
    }

    /** @return list<int> pilihan hari valid (1–365), unik, terurut naik. */
    public static function parsePilihan(string $csv): array
    {
        $hari = array_filter(
            array_map(fn ($x) => trim($x), explode(',', $csv)),
            fn ($x) => $x !== '' && ctype_digit($x) && (int) $x >= 1 && (int) $x <= 365,
        );
        $hari = array_values(array_unique(array_map('intval', $hari)));
        sort($hari);

        return $hari === [] ? self::PILIHAN_DEFAULT : $hari;
    }

    /**
     * Opsi dropdown siap render, terurut naik + "hanya yang lewat" di akhir.
     *
     * @return array<int,string> hari => label
     */
    public function opsi(?TerminFilterSetting $s = null): array
    {
        $s ??= $this->pengaturan();
        $opsi = [];
        foreach (self::parsePilihan($s->pilihan_hari) as $h) {
            $opsi[$h] = "≤ {$h} hari + lewat";
        }
        $opsi[0] = 'hanya yang lewat';

        return $opsi;
    }

    /** Jendela hari terpakai: query string bila valid, selain itu default setting. */
    public function dalamHari(mixed $query, ?TerminFilterSetting $s = null): int
    {
        $s ??= $this->pengaturan();
        if ($query !== null && ctype_digit((string) $query) && array_key_exists((int) $query, $this->opsi($s))) {
            return (int) $query;
        }

        return $s->default_hari;
    }
}
