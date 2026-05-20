<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sekolah extends Model
{
    protected $table    = 'sekolah';
    protected $fillable = ['nama', 'slug', 'kota', 'provinsi', 'npsn'];

    /** Siswa yang terdaftar di sekolah ini */
    public function siswa()
    {
        return $this->hasMany(User::class, 'sekolah_id')
                    ->where('role', 'user');
    }

    /** Pengamat yang terhubung ke sekolah ini */
    public function pengamatLinks()
    {
        return $this->hasMany(PengamatSekolah::class);
    }

    public function pengamats()
    {
        return $this->hasManyThrough(
            User::class,
            PengamatSekolah::class,
            'sekolah_id',
            'id',
            'id',
            'pengamat_id'
        );
    }
}
