<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'tier', 'points',
        'streak_days', 'last_active', 'is_banned',
        'onboarding_completed', 'diagnostic_completed', 'avatar_url',
        'referral_source', 'asal_sekolah', 'sekolah_id',
        'push_streak_reminder', 'push_weekly_report',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'last_active'          => 'datetime',
        'is_banned'            => 'boolean',
        'onboarding_completed' => 'boolean',
        'diagnostic_completed' => 'boolean',
        'push_streak_reminder' => 'boolean',
        'push_weekly_report'   => 'boolean',
    ];

    // ── Role helpers ───────────────────────────────────────
    public function isAdmin(): bool     { return $this->role === 'superadmin'; }
    public function isPengamat(): bool  { return $this->role === 'pengamat'; }
    public function isFree(): bool      { return $this->tier === 'free'; }
    public function isPremium(): bool   { return $this->tier === 'premium'; }
    public function isDailyPass(): bool { return $this->tier === 'daily_pass'; }
    public function hasAIAccess(): bool { return !$this->isFree(); }

    // ── Relationships ──────────────────────────────────────
    public function kampusTargets()      { return $this->hasMany(UserKampusTarget::class); }
    public function sesiLatihan()        { return $this->hasMany(SesiLatihan::class); }
    public function attempts()           { return $this->hasMany(UserAttempt::class); }
    public function weaknessReports()    { return $this->hasMany(WeaknessReport::class); }
    public function subscriptions()      { return $this->hasMany(Subscription::class); }
    public function pointsTransactions() { return $this->hasMany(PointsTransaction::class); }
    public function transactions()       { return $this->hasMany(Transaction::class); }
    public function pushTokens()         { return $this->hasMany(PushToken::class); }
    // Pengamat relationships
    public function sekolah()            { return $this->belongsTo(Sekolah::class); }
    public function pengamatSekolah()    { return $this->hasOne(PengamatSekolah::class, 'pengamat_id'); }
    public function activeSubscription() {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('selesai', '>', now());
    }

    // ── Points ─────────────────────────────────────────────
    public function addPoints(int $amount, string $reason = 'general'): void
    {
        $this->increment('points', $amount);
        PointsTransaction::create([
            'user_id' => $this->id,
            'jumlah'  => $amount,
            'tipe'    => 'earn',
            'alasan'  => $reason,
        ]);
    }

    // ── Streak ──────────────────────────────────────────────
    public function updateStreak(): void
    {
        $last = $this->last_active;
        if (!$last) { $this->update(['streak_days' => 1, 'last_active' => now()]); return; }

        $diff = $last->diffInDays(now());
        if ($diff === 1) $this->increment('streak_days');
        elseif ($diff > 1) $this->update(['streak_days' => 1]);
        $this->update(['last_active' => now()]);
    }
}
