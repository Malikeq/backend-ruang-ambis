<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AiExplanation extends Model
{
    protected $table = 'ai_explanations';
    protected $fillable = ['soal_id', 'konten_cached', 'model_used', 'token_used', 'generated_by', 'hit_count'];
    public function soal() { return $this->belongsTo(Soal::class); }
}
