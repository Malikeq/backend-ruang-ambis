<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserAttempt extends Model {
    protected $fillable = ['user_id','soal_id','sesi_latihan_id','jawaban_id','is_correct','waktu_ms'];
    protected $casts = ['is_correct' => 'boolean'];
    public function soal()   { return $this->belongsTo(Soal::class); }
    public function jawaban(){ return $this->belongsTo(PilihanJawaban::class, 'jawaban_id'); }
}
