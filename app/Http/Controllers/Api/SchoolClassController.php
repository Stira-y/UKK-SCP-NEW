<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\Request;

class SchoolClassController extends Controller
{
    public function index()
    {
        $classes = SchoolClass::all();
        return response()->json(['status' => 'success', 'data' => $classes], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'kelas_nomor' => 'required|in:X,XI,XII',
            'nama_kelas' => 'required|string|max:50',
        ]);

        $class = SchoolClass::create($request->all());
        return response()->json(['status' => 'success', 'message' => 'Kelas berhasil ditambahkan', 'data' => $class], 201);
    }

    public function show($id)
    {
        $class = SchoolClass::find($id);
        if (!$class) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $class], 200);
    }

    public function update(Request $request, $id)
    {
        $class = SchoolClass::find($id);
        if (!$class) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }

        $request->validate([
            'kelas_nomor' => 'sometimes|in:X,XI,XII',
            'nama_kelas' => 'sometimes|string|max:50',
        ]);

        $class->update($request->all());
        return response()->json(['status' => 'success', 'message' => 'Kelas berhasil diperbarui', 'data' => $class], 200);
    }

    public function destroy($id)
    {
        $class = SchoolClass::find($id);
        if (!$class) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
        }

        $class->delete();
        return response()->json(['status' => 'success', 'message' => 'Kelas berhasil dihapus'], 200);
    }
}