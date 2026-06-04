<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['nama', 'tier', 'deskripsi', 'harga_idr', 'durasi_hari', 'fitur_json', 'is_active'];

    protected $casts = ['fitur_json' => 'array', 'is_active' => 'boolean'];
}
