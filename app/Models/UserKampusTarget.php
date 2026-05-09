<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UserKampusTarget extends Model
{
    protected $table = 'user_kampus_targets';
    protected $fillable = ['user_id', 'kampus_id', 'jurusan_id', 'priority'];
    public function user()    { return $this->belongsTo(User::class); }
    public function kampus()  { return $this->belongsTo(Kampus::class); }
    public function jurusan() { return $this->belongsTo(Jurusan::class); }
}
