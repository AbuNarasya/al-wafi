<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** S&K umum berversi. */
class TermTemplate extends Model
{
    protected $table = 'term_template';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['versi' => 'integer', 'berlaku_mulai' => 'date'];
    }
}
