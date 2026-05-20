<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPeluangLolos extends Model
{
    protected $table = 'user_peluang_lolos';

    protected $fillable = [
        'user_id', 'jurusan_id', 'kode_prodi',
        'skor_user', 'status_lolos', 'probabilitas_persen',
        'gap_skor', 'catatan_ai', 'dihitung_pada',
    ];

    protected $casts = [
        'skor_user'           => 'float',
        'probabilitas_persen' => 'float',
        'gap_skor'            => 'float',
        'dihitung_pada'       => 'datetime',
    ];

    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function jurusan(): BelongsTo  { return $this->belongsTo(Jurusan::class, 'jurusan_id'); }

    public function getStatusColorAttribute(): string
    {
        return match($this->status_lolos) {
            'AMAN'   => '#10B981',
            'KUNING' => '#F59E0B',
            'MERAH'  => '#EF4444',
            default  => '#6B7280',
        };
    }

    public function getStatusEmojiAttribute(): string
    {
        return match($this->status_lolos) {
            'AMAN'   => '🟢',
            'KUNING' => '🟡',
            'MERAH'  => '🔴',
            default  => '⚪',
        };
    }
}
