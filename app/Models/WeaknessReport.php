<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WeaknessReport extends Model {
    protected $fillable = ['user_id','sub_materi_id','mapel_id','attempt_count','correct_count','accuracy_rate','avg_waktu_ms','is_flagged','last_seen'];
    protected $casts = ['is_flagged' => 'boolean', 'last_seen' => 'datetime'];
    public function sub_materi() { return $this->belongsTo(SubMateri::class); }
    public function mapel()      { return $this->belongsTo(Mapel::class); }
}
