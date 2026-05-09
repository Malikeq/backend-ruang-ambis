<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kampus;
use App\Models\Jurusan;
use App\Models\UserKampusTarget;
use App\Models\Soal;
use App\Models\SesiLatihan;
use App\Models\UserAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /** GET /kampus — public */
    public function getKampus(Request $request): JsonResponse
    {
        $size = min((int) ($request->size ?? 100), 200);

        $kampus = Kampus::when(
            $request->search,
            fn($q, $s) => $q->where('nama', 'like', "%{$s}%")->orWhere('akronim', 'like', "%{$s}%")
        )
        ->when($request->provinsi, fn($q, $p) => $q->where('provinsi', $p))
        ->withCount('jurusan')
        ->select(['id','nama','akronim','kota','provinsi','tipe','group','logo_url'])
        ->orderBy('nama')
        ->limit($size)
        ->get();

        return response()->json(['success' => true, 'data' => $kampus]);
    }

    /** GET /user/targets — returns the logged-in user's kampus targets with full relations */
    public function getTargets(Request $request): JsonResponse
    {
        $targets = UserKampusTarget::with([
                'kampus:id,nama,akronim,kota,provinsi,logo_url',
                'jurusan:id,nama,fakultas,passing_grade_estimate,peminat_tahun_lalu',
            ])
            ->where('user_id', $request->user()->id)
            ->orderBy('priority')
            ->get()
            ->map(fn($t) => [
                'id'       => $t->id,
                'priority' => $t->priority,
                'kampus'   => $t->kampus,
                'jurusan'  => $t->jurusan,
            ]);

        return response()->json(['success' => true, 'data' => $targets]);
    }


    /** GET /kampus/{id}/jurusan — public */
    public function getJurusan(int $id): JsonResponse
    {
        $jurusan = Jurusan::where('kampus_id', $id)
            ->orderBy('passing_grade_estimate', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $jurusan]);
    }

    /** POST /onboarding/target */
    public function setTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targets'               => ['required', 'array', 'min:1', 'max:4'],
            'targets.*.kampus_id'   => ['required', 'integer', 'exists:kampus,id'],
            'targets.*.jurusan_id'  => ['required', 'integer', 'exists:jurusan,id'],
        ]);

        $user = $request->user();
        UserKampusTarget::where('user_id', $user->id)->delete();

        foreach ($data['targets'] as $i => $t) {
            UserKampusTarget::create([
                'user_id'    => $user->id,
                'kampus_id'  => $t['kampus_id'],
                'jurusan_id' => $t['jurusan_id'],
                'priority'   => $i + 1,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Target kampus berhasil disimpan.',
            'data'    => $user->kampusTargets()->with('kampus', 'jurusan')->get(),
        ]);
    }

    /** POST /onboarding/referral — stores where user heard about the platform */
    public function saveReferral(Request $request): JsonResponse
    {
        $data = $request->validate([
            'referral_source' => ['required', 'string', 'max:100'],
        ]);

        // Store if the column exists on users table; otherwise ignore gracefully
        try {
            $request->user()->update(['referral_source' => $data['referral_source']]);
        } catch (\Exception) {
            // Column may not exist yet — non-blocking
        }

        return response()->json(['success' => true]);
    }

    /** POST /onboarding/complete */
    public function complete(Request $request): JsonResponse
    {
        $request->user()->update([
            'onboarding_completed' => true,
            'diagnostic_completed' => true,
        ]);
        return response()->json(['success' => true, 'message' => 'Onboarding selesai! Selamat belajar 🎉']);
    }


    /** POST /onboarding/diagnostic/mulai */
    public function startDiagnostic(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->diagnostic_completed) {
            return response()->json(['success' => false, 'message' => 'Tes diagnostik sudah selesai.'], 400);
        }

        $soalIds = Soal::where('is_published', true)->inRandomOrder()->limit(20)->pluck('id')->toArray();

        if (empty($soalIds)) {
            return response()->json(['success' => false, 'message' => 'Bank soal masih kosong.'], 404);
        }

        $sesi = SesiLatihan::create([
            'user_id'  => $user->id,
            'tipe'     => 'diagnostic',
            'soal_ids' => $soalIds,
            'mulai'    => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['sesi_id' => $sesi->id, 'total_soal' => count($soalIds)],
        ]);
    }

    /** POST /onboarding/diagnostic/jawab */
    public function submitDiagnostic(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sesi_id' => ['required', 'integer', 'exists:sesi_latihan,id'],
            'jawaban' => ['required', 'array'],
        ]);

        $sesi = SesiLatihan::where('id', $data['sesi_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $totalBenar = 0;
        foreach ($data['jawaban'] as $soalId => $jawabanId) {
            $soal = Soal::find((int) $soalId);
            if (!$soal) continue;

            $isCorrect = $soal->pilihan_jawaban()
                ->where('id', (int) $jawabanId)
                ->where('is_correct', true)
                ->exists();

            if ($isCorrect) $totalBenar++;

            UserAttempt::create([
                'user_id'         => $request->user()->id,
                'soal_id'         => $soalId,
                'sesi_latihan_id' => $sesi->id,
                'jawaban_id'      => $jawabanId,
                'is_correct'      => $isCorrect,
                'waktu_ms'        => 0,
            ]);
        }

        $total   = count($data['jawaban']);
        $skorRaw = $total > 0 ? round(($totalBenar / $total) * 100, 2) : 0;
        $sesi->update(['selesai' => now(), 'skor_raw' => $skorRaw]);

        return response()->json([
            'success' => true,
            'data'    => ['skor_raw' => $skorRaw, 'total_benar' => $totalBenar, 'total_soal' => $total],
        ]);
    }
}
