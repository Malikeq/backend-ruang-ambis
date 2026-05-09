<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPackageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Package::where('is_active', true)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama'        => ['required', 'string'],
            'harga_idr'   => ['required', 'integer', 'min:0'],
            'durasi_hari' => ['required', 'integer', 'min:1'],
            'fitur_json'  => ['required', 'array'],
        ]);

        $pkg = Package::create($data + ['is_active' => true]);
        return response()->json(['success' => true, 'data' => $pkg], 201);
    }

    public function update(Request $request, Package $pkg): JsonResponse
    {
        $pkg->update($request->validate([
            'nama'        => ['sometimes', 'string'],
            'harga_idr'   => ['sometimes', 'integer'],
            'durasi_hari' => ['sometimes', 'integer'],
            'fitur_json'  => ['sometimes', 'array'],
            'is_active'   => ['sometimes', 'boolean'],
        ]));

        return response()->json(['success' => true, 'data' => $pkg]);
    }

    public function destroy(Package $pkg): JsonResponse
    {
        $pkg->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Paket dinonaktifkan.']);
    }
}
