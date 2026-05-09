<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Pembahasan extends Model {
    protected $table = 'pembahasan';
    protected $fillable = ['soal_id','langkah_langkah','locked_by_tier'];
    protected $casts = ['langkah_langkah' => 'array'];
    public function soal() { return $this->belongsTo(Soal::class); }
}
