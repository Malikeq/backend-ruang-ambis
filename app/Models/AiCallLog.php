<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiCallLog extends Model {
    protected $fillable = ['user_id','fitur','model','token_in','token_out','cost_idr','cached'];
    protected $casts = ['cached' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }
}
