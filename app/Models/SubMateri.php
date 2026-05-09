<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubMateri extends Model {
    protected $table = 'sub_materi';
    protected $fillable = ['mapel_id','nama','deskripsi'];
    public function mapel()          { return $this->belongsTo(Mapel::class); }
    public function soal()           { return $this->hasMany(Soal::class); }
    public function weaknessReports(){ return $this->hasMany(WeaknessReport::class); }
}
