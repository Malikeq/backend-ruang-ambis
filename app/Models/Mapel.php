<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Mapel extends Model {
    protected $table = 'mapel';
    protected $fillable = ['nama','kode','snbt_weight'];
    public function subMateri() { return $this->hasMany(SubMateri::class); }
    public function soal()      { return $this->hasMany(Soal::class); }
}
