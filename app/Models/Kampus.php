<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Kampus extends Model
{
    protected $table = 'kampus';
    protected $fillable = [
        'api_id', 'nama', 'akronim', 'kota', 'provinsi',
        'tipe', 'group', 'alamat', 'lat', 'lng', 'logo_url',
    ];

    public function jurusan() { return $this->hasMany(Jurusan::class); }
    public function targets() { return $this->hasMany(UserKampusTarget::class); }
}
