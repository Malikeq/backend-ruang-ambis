<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = ['user_id', 'package_id', 'gross_amount', 'payment_method', 'midtrans_order_id', 'status'];
    public function user()    { return $this->belongsTo(User::class); }
    public function package() { return $this->belongsTo(Package::class); }
}
