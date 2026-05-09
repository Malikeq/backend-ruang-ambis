<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromoCode extends Model {
    protected $fillable = ['kode','diskon_persen','max_uses','used_count','expired_at'];
    protected $casts = ['expired_at' => 'datetime'];
}
