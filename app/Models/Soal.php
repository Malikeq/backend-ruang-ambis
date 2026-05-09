<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Soal extends Model {
    protected $table = 'soal';
    protected $fillable = [
        'mapel_id','sub_materi_id','konten','tipe',
        'tingkat_kesulitan','irt_a','irt_b','irt_c',
        'sumber_tahun','is_ai_generated','is_published','source_chunk_ref',
    ];
    protected $casts = ['is_ai_generated' => 'boolean', 'is_published' => 'boolean'];
    public function mapel()           { return $this->belongsTo(Mapel::class); }
    public function sub_materi()      { return $this->belongsTo(SubMateri::class); }
    public function pilihan_jawaban() { return $this->hasMany(PilihanJawaban::class); }
    public function pembahasan()      { return $this->hasOne(Pembahasan::class); }
    public function aiExplanation()   { return $this->hasOne(AiExplanation::class); }
    public function scopePublished($q){ return $q->where('is_published', true); }
}
