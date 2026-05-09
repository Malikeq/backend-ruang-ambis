<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PointsTransaction extends Model
{
    protected $table = 'points_transactions';
    protected $fillable = ['user_id', 'jumlah', 'tipe', 'alasan'];
    public function user() { return $this->belongsTo(User::class); }
}
