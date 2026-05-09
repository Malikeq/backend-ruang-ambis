<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\AiCallLog;
use App\Models\Soal;
use App\Models\SesiLatihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $thisMonth = now()->month;
        $thisYear  = now()->year;

        return response()->json([
            'success' => true,
            'data'    => [
                'total_users'           => User::count(),
                'total_premium'         => User::where('tier', '!=', 'free')->count(),
                'new_users_today'       => User::whereDate('created_at', today())->count(),
                'total_soal_published'  => Soal::where('is_published', true)->count(),
                'total_soal_ai'         => Soal::where('is_ai_generated', true)->where('is_published', true)->count(),
                'pending_drafts'        => \App\Models\AiDraftSoal::where('status', 'pending')->count(),
                'active_sessions_today' => SesiLatihan::whereDate('created_at', today())->count(),
                'revenue_bulan_ini'     => Transaction::where('status', 'paid')
                                               ->whereMonth('created_at', $thisMonth)
                                               ->whereYear('created_at',  $thisYear)
                                               ->sum('gross_amount'),
                'ai_cost_bulan_ini'     => AiCallLog::whereMonth('created_at', $thisMonth)
                                               ->whereYear('created_at',  $thisYear)
                                               ->sum('cost_idr'),
                'recent_transactions'   => Transaction::with(['user:id,name,email', 'package:id,nama'])
                                               ->latest()->limit(10)->get(),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = Transaction::with(['user', 'package'])
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $transactions]);
    }

    public function revenue(): JsonResponse
    {
        $data = Transaction::where('status', 'paid')
            ->selectRaw("DATE(created_at) as date, SUM(gross_amount) as revenue")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function aiCosts(): JsonResponse
    {
        $data = AiCallLog::selectRaw("DATE(created_at) as date, SUM(cost_idr) as cost, COUNT(*) as calls, SUM(cached) as cached_count")
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function queueHealth(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['status' => 'ok']]);
    }
}
