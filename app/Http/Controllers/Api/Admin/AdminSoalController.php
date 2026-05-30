<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Models\Mapel;
use App\Models\SubMateri;
use App\Models\PilihanJawaban;
use App\Models\Pembahasan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSoalController extends Controller
{
    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $soal = Soal::with(['mapel', 'sub_materi'])
            ->when($request->search, fn($q, $s) => $q->where('konten', 'like', "%{$s}%"))
            ->when($request->mapel_id, fn($q, $m) => $q->where('mapel_id', $m))
            ->when($request->tipe, fn($q, $t) => $q->where('tipe', $t))
            ->when($request->status, function ($q, $s) {
                if ($s === 'published') $q->where('is_published', true);
                if ($s === 'draft')     $q->where('is_published', false);
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $soal]);
    }

    // ── Show single ───────────────────────────────────────────────────────────

    public function show(Soal $soal): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $soal->load(['mapel', 'sub_materi', 'pilihan_jawaban', 'pembahasan']),
        ]);
    }

    // ── Create manual ─────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mapel_id'          => ['required', 'exists:mapel,id'],
            'sub_materi_id'     => ['required', 'exists:sub_materi,id'],
            'konten'            => ['required', 'string'],
            'tipe'              => ['required', 'in:MC,BS,MJ'],
            'tingkat_kesulitan' => ['required', 'in:mudah,sedang,sulit'],
            'pilihan'           => ['required', 'array', 'min:4', 'max:5'],
            'pilihan.*.label'   => ['required', 'string', 'max:1'],
            'pilihan.*.konten'  => ['required', 'string'],
            'kunci'             => ['required', 'string', 'max:1'],
            'pembahasan'        => ['nullable', 'string'],
            'is_published'      => ['boolean'],
        ]);

        return DB::transaction(function () use ($data) {
            $soal = Soal::create([
                'mapel_id'          => $data['mapel_id'],
                'sub_materi_id'     => $data['sub_materi_id'],
                'konten'            => $data['konten'],
                'tipe'              => $data['tipe'],
                'tingkat_kesulitan' => $data['tingkat_kesulitan'],
                'is_published'      => $data['is_published'] ?? false,
                'is_ai_generated'   => false,
            ]);

            foreach ($data['pilihan'] as $p) {
                PilihanJawaban::create([
                    'soal_id'    => $soal->id,
                    'label'      => strtoupper($p['label']),
                    'konten'     => $p['konten'],
                    'is_correct' => strtoupper($p['label']) === strtoupper($data['kunci']),
                ]);
            }

            if (!empty($data['pembahasan'])) {
                Pembahasan::create([
                    'soal_id'        => $soal->id,
                    'langkah_langkah'=> [['teks' => $data['pembahasan']]],
                ]);
            }

            return response()->json([
                'success' => true,
                'data'    => $soal->load(['mapel', 'sub_materi', 'pilihan_jawaban', 'pembahasan']),
            ], 201);
        });
    }

    // ── Full update (replace pilihan) ─────────────────────────────────────────

    public function updateFull(Request $request, Soal $soal): JsonResponse
    {
        $data = $request->validate([
            'mapel_id'          => ['sometimes', 'exists:mapel,id'],
            'sub_materi_id'     => ['sometimes', 'exists:sub_materi,id'],
            'konten'            => ['sometimes', 'string'],
            'tipe'              => ['sometimes', 'in:MC,BS,MJ'],
            'tingkat_kesulitan' => ['sometimes', 'in:mudah,sedang,sulit'],
            'pilihan'           => ['sometimes', 'array', 'min:4', 'max:5'],
            'pilihan.*.label'   => ['required_with:pilihan', 'string', 'max:1'],
            'pilihan.*.konten'  => ['required_with:pilihan', 'string'],
            'kunci'             => ['required_with:pilihan', 'string', 'max:1'],
            'pembahasan'        => ['nullable', 'string'],
            'is_published'      => ['boolean'],
        ]);

        return DB::transaction(function () use ($data, $soal) {
            $soal->update(array_filter([
                'mapel_id'          => $data['mapel_id']          ?? null,
                'sub_materi_id'     => $data['sub_materi_id']     ?? null,
                'konten'            => $data['konten']            ?? null,
                'tipe'              => $data['tipe']              ?? null,
                'tingkat_kesulitan' => $data['tingkat_kesulitan'] ?? null,
                'is_published'      => $data['is_published']      ?? null,
            ], fn($v) => $v !== null));

            if (!empty($data['pilihan'])) {
                $soal->pilihan_jawaban()->delete();
                foreach ($data['pilihan'] as $p) {
                    PilihanJawaban::create([
                        'soal_id'    => $soal->id,
                        'label'      => strtoupper($p['label']),
                        'konten'     => $p['konten'],
                        'is_correct' => strtoupper($p['label']) === strtoupper($data['kunci']),
                    ]);
                }
            }

            if (array_key_exists('pembahasan', $data)) {
                $soal->pembahasan()->delete();
                if ($data['pembahasan']) {
                    Pembahasan::create([
                        'soal_id'         => $soal->id,
                        'langkah_langkah' => [['teks' => $data['pembahasan']]],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $soal->fresh(['mapel', 'sub_materi', 'pilihan_jawaban', 'pembahasan']),
            ]);
        });
    }

    // ── Patch (status/konten only) ────────────────────────────────────────────

    public function update(Request $request, Soal $soal): JsonResponse
    {
        $soal->update($request->validate([
            'konten'            => ['sometimes', 'string'],
            'tingkat_kesulitan' => ['sometimes', 'in:mudah,sedang,sulit'],
            'is_published'      => ['sometimes', 'boolean'],
        ]));

        return response()->json(['success' => true, 'data' => $soal]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Soal $soal): JsonResponse
    {
        $soal->delete();
        return response()->json(['success' => true, 'message' => 'Soal berhasil dihapus.']);
    }

    // ── Publish toggle ────────────────────────────────────────────────────────

    public function publish(Soal $soal): JsonResponse
    {
        $soal->update(['is_published' => !$soal->is_published]);
        return response()->json(['success' => true, 'data' => $soal]);
    }

    // ── Download CSV Template ─────────────────────────────────────────────────

    public function downloadTemplate()
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template_soal.csv"',
        ];

        $callback = function () {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header row
            fputcsv($out, [
                'mapel_kode', 'sub_materi_nama', 'tingkat_kesulitan', 'tipe',
                'konten', 'pilihan_A', 'pilihan_B', 'pilihan_C', 'pilihan_D', 'pilihan_E',
                'kunci', 'pembahasan',
            ]);

            // Example rows
            fputcsv($out, [
                'PU', 'Penalaran Umum', 'mudah', 'MC',
                'Jika a = 3 dan b = 4, maka a² + b² = ?',
                '5', '7', '12', '25', '50',
                'D', 'a² + b² = 9 + 16 = 25',
            ]);
            fputcsv($out, [
                'PM', 'Aljabar', 'sedang', 'MC',
                'Nilai x yang memenuhi 2x + 6 = 14 adalah?',
                '2', '3', '4', '5', '6',
                'C', '2x = 8, x = 4',
            ]);

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Bulk Import CSV ───────────────────────────────────────────────────────

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'file'          => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'auto_publish'  => ['boolean'],
        ]);

        $autoPublish = $request->boolean('auto_publish', false);
        $file        = $request->file('file');
        $handle      = fopen($file->getRealPath(), 'r');

        // Skip header row
        $header = fgetcsv($handle);
        if (!$header) {
            return response()->json(['success' => false, 'message' => 'File CSV kosong atau tidak valid.'], 422);
        }

        // Pre-load mapel + sub_materi lookups
        $mapelByKode  = Mapel::all()->keyBy(fn($m) => strtoupper(trim($m->kode)));
        $subMateriAll = SubMateri::all();

        $imported = 0;
        $failed   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 11) {
                $failed[] = ['row' => $rowNum, 'reason' => 'Kolom tidak lengkap'];
                continue;
            }

            [
                $mapelKode, $subMateriNama, $kesulitan, $tipe,
                $konten, $pA, $pB, $pC, $pD, $pE,
                $kunci, $pembahasan,
            ] = array_map('trim', array_pad($row, 12, ''));

            // Validate mapel
            $mapel = $mapelByKode[strtoupper($mapelKode)] ?? null;
            if (!$mapel) {
                $failed[] = ['row' => $rowNum, 'reason' => "Mapel '$mapelKode' tidak ditemukan"];
                continue;
            }

            // Find or create sub_materi
            $subMateri = $subMateriAll
                ->where('mapel_id', $mapel->id)
                ->first(fn($s) => strtolower(trim($s->nama)) === strtolower($subMateriNama));

            if (!$subMateri && $subMateriNama) {
                $subMateri = SubMateri::firstOrCreate(
                    ['mapel_id' => $mapel->id, 'nama' => $subMateriNama],
                    ['mapel_id' => $mapel->id, 'nama' => $subMateriNama],
                );
                $subMateriAll = $subMateriAll->push($subMateri);
            }

            if (!$subMateri) {
                $failed[] = ['row' => $rowNum, 'reason' => 'Sub-materi tidak ditemukan dan nama kosong'];
                continue;
            }

            if (!in_array(strtolower($kesulitan), ['mudah', 'sedang', 'sulit'])) {
                $failed[] = ['row' => $rowNum, 'reason' => "Kesulitan '$kesulitan' tidak valid (mudah/sedang/sulit)"];
                continue;
            }

            $tipe = strtoupper($tipe);
            if (!in_array($tipe, ['MC', 'BS', 'MJ'])) { $tipe = 'MC'; }

            if (empty(trim($konten))) {
                $failed[] = ['row' => $rowNum, 'reason' => 'Konten soal kosong'];
                continue;
            }

            $kunci = strtoupper(trim($kunci));
            $pilihan = array_filter([
                'A' => $pA, 'B' => $pB, 'C' => $pC, 'D' => $pD, 'E' => $pE,
            ], fn($v) => !empty(trim($v)));

            if (count($pilihan) < 4) {
                $failed[] = ['row' => $rowNum, 'reason' => 'Minimal 4 pilihan jawaban'];
                continue;
            }

            if (!isset($pilihan[$kunci])) {
                $failed[] = ['row' => $rowNum, 'reason' => "Kunci '$kunci' tidak ada di pilihan"];
                continue;
            }

            try {
                DB::transaction(function () use ($mapel, $subMateri, $konten, $tipe, $kesulitan, $pilihan, $kunci, $pembahasan, $autoPublish) {
                    $soal = Soal::create([
                        'mapel_id'          => $mapel->id,
                        'sub_materi_id'     => $subMateri->id,
                        'konten'            => $konten,
                        'tipe'              => $tipe,
                        'tingkat_kesulitan' => strtolower($kesulitan),
                        'is_published'      => $autoPublish,
                        'is_ai_generated'   => false,
                    ]);

                    foreach ($pilihan as $label => $kontenPilihan) {
                        PilihanJawaban::create([
                            'soal_id'    => $soal->id,
                            'label'      => $label,
                            'konten'     => trim($kontenPilihan),
                            'is_correct' => $label === $kunci,
                        ]);
                    }

                    if (!empty(trim($pembahasan))) {
                        Pembahasan::create([
                            'soal_id'         => $soal->id,
                            'langkah_langkah' => [['teks' => trim($pembahasan)]],
                        ]);
                    }
                });
                $imported++;
            } catch (\Exception $e) {
                $failed[] = ['row' => $rowNum, 'reason' => 'Error: ' . $e->getMessage()];
            }
        }

        fclose($handle);

        return response()->json([
            'success'  => true,
            'imported' => $imported,
            'failed'   => $failed,
            'message'  => "{$imported} soal berhasil diimport." . (count($failed) ? ' ' . count($failed) . ' baris gagal.' : ''),
        ]);
    }
}
