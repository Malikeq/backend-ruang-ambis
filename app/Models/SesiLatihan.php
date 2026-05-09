<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SesiLatihan extends Model {
    protected $table = 'sesi_latihan';
    protected $fillable = ['user_id','tipe','soal_ids','mulai','selesai','skor_raw','skor_irt'];
    protected $casts = ['soal_ids' => 'array', 'mulai' => 'datetime', 'selesai' => 'datetime'];
    public function user()    { return $this->belongsTo(User::class); }
    public function attempts(){ return $this->hasMany(UserAttempt::class); }
}
