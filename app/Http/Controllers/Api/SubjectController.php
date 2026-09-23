<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index()
    {
        $subjects = Subject::all();
        return response()->json([
            'status' => 'success',
            'data' => $subjects
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_mapel' => 'required|string|max:100',
            'kode_mapel' => 'required|string|max:20|unique:subjects,kode_mapel',
        ]);

        $subject = Subject::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil ditambahkan',
            'data' => $subject
        ], 201);
    }

    public function show($id)
    {
        $subject = Subject::find($id);
        if (!$subject) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mata pelajaran tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $subject
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $subject = Subject::find($id);
        if (!$subject) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mata pelajaran tidak ditemukan'
            ], 404);
        }

        $request->validate([
            'nama_mapel' => 'sometimes|string|max:100',
            'kode_mapel' => 'sometimes|string|max:20|unique:subjects,kode_mapel,' . $id . ',id_subject',
        ]);

        $subject->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil diperbarui',
            'data' => $subject
        ], 200);
    }

    public function destroy($id)
    {
        $subject = Subject::find($id);
        if (!$subject) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mata pelajaran tidak ditemukan'
            ], 404);
        }

        $subject->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil dihapus'
        ], 200);
    }
}