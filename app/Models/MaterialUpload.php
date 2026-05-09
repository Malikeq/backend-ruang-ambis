<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MaterialUpload extends Model {
    protected $fillable = ['admin_id','filename','file_type','file_url','status','target_mapel_ids','jumlah_soal_target'];
    protected $casts = ['target_mapel_ids' => 'array'];
    public function admin()    { return $this->belongsTo(User::class, 'admin_id'); }
    public function drafts()   { return $this->hasMany(AiDraftSoal::class, 'upload_id'); }
}
