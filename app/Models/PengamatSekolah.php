<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengamatSekolah extends Model
{
    protected $table    = 'pengamat_sekolah';
    protected $fillable = ['pengamat_id', 'sekolah_id', 'jabatan', 'status', 'approved_at', 'approved_by', 'catatan'];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function pengamat()
    {
        return $this->belongsTo(User::class, 'pengamat_id');
    }

    public function sekolah()
    {
        return $this->belongsTo(Sekolah::class, 'sekolah_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isRejected(): bool  { return $this->status === 'rejected'; }
}
