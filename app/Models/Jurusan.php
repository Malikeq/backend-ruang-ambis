<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Jurusan extends Model
{
    protected $table = 'jurusan';
    protected $fillable = ['kampus_id','nama','fakultas','passing_grade_estimate','peminat_tahun_lalu'];

    // Ensure numeric values are cast properly
    protected $casts = [
        'passing_grade_estimate' => 'float',
        'peminat_tahun_lalu'     => 'integer',
    ];

    public function kampus() { return $this->belongsTo(Kampus::class); }
}
