<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['nama', 'harga_idr', 'durasi_hari', 'fitur_json', 'is_active'];

    // Laravel 10: must be a property, not a method
    protected $casts = ['fitur_json' => 'array', 'is_active' => 'boolean'];
}
