<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SesiLatihan extends Model {
    protected $table    = 'sesi_latihan';
    protected $fillable = [
        'user_id', 'tipe', 'soal_ids', 'mulai', 'selesai',
        'skor_raw', 'skor_irt', 'skor_akhir',
    ];
    protected $casts = [
        'soal_ids' => 'array',
        'mulai'    => 'datetime',
        'selesai'  => 'datetime',  // timestamp, null = in-progress
    ];

    public function user()     { return $this->belongsTo(User::class); }
    public function attempts() { return $this->hasMany(UserAttempt::class); }

    /** Mapels derived from the soal in this session (via attempts or soal table). */
    public function mapels() {
        return $this->belongsToMany(Mapel::class, 'soal', 'id', 'mapel_id')
                    ->whereIn('soal.id', $this->soal_ids ?? []);
    }
}
