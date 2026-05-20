<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdiStatistik extends Model
{
    protected $table = 'prodi_statistik';

    protected $fillable = [
        'kode_ptn', 'nama_ptn', 'kode_prodi', 'nama_prodi',
        'jenjang', 'kelompok_ujian', 'tahun',
        'kuota_snbt', 'peminat_snbt',
        'rerata_skor_diterima', 'skor_minimum_diterima', 'skor_maksimum_diterima',
        'keketatan_persen', 'kategori_keketatan',
        'skor_aman', 'skor_kuning',
        'kuota_snbp', 'peminat_snbp',
    ];

    protected $casts = [
        'rerata_skor_diterima'   => 'float',
        'skor_minimum_diterima'  => 'float',
        'skor_maksimum_diterima' => 'float',
        'keketatan_persen'       => 'float',
        'skor_aman'              => 'float',
        'skor_kuning'            => 'float',
    ];

    /** Label keketatan yang mudah dibaca */
    public function getKategoriLabelAttribute(): string
    {
        return match($this->kategori_keketatan) {
            'LONGGAR'      => '🟢 Longgar',
            'SEDANG'       => '🟡 Sedang',
            'KETAT'        => '🟠 Ketat',
            'SANGAT_KETAT' => '🔴 Sangat Ketat',
            default        => '⚪ -',
        };
    }

    /** Skor aman dihitung otomatis jika belum ada */
    public function getSkorAmanEffectiveAttribute(): ?float
    {
        if ($this->skor_aman) return $this->skor_aman;
        if ($this->rerata_skor_diterima) return round($this->rerata_skor_diterima * 1.05, 2);
        return null;
    }

    public function scopeTahunTerbaru($q)
    {
        return $q->where('tahun', ProdiStatistik::max('tahun'));
    }
}
