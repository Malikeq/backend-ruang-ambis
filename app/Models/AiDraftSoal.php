<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiDraftSoal extends Model {
    protected $table = 'ai_draft_soal';
    protected $fillable = ['upload_id','draft','status','reviewer_id','reviewed_at'];
    protected $casts = ['draft' => 'array', 'reviewed_at' => 'datetime'];
    public function upload()   { return $this->belongsTo(MaterialUpload::class, 'upload_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewer_id'); }
}
