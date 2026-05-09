<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Subscription extends Model {
    protected $fillable = ['user_id','package_id','mulai','selesai','payment_id','status'];
    protected $casts = ['mulai' => 'datetime', 'selesai' => 'datetime'];
    public function user()    { return $this->belongsTo(User::class); }
    public function package() { return $this->belongsTo(Package::class); }
}
