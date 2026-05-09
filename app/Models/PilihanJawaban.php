<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PilihanJawaban extends Model {
    protected $table = 'pilihan_jawaban';
    protected $fillable = ['soal_id','label','konten','is_correct'];
    protected $casts = ['is_correct' => 'boolean'];
    public function soal() { return $this->belongsTo(Soal::class); }
}
