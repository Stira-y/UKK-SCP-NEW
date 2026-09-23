<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function index(Request $request)
    {
        $query = Journal::with(['schedule.schoolClass', 'schedule.teacher', 'schedule.room', 'attendances']);

        if ($request->has('id_schedule')) {
            $query->where('id_schedule', $request->id_schedule);
        }

        $journals = $query->latest('date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $journals
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_schedule'       => 'required|integer', 
            'date'              => 'required|date',
            'topic_description' => 'required|string',
            'photo_path'        => 'nullable|string',
        ]);

        $journal = Journal::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Jurnal harian berhasil ditambahkan',
            'data' => $journal
        ], 201);
    }

    public function show($id)
    {
        $journal = Journal::with(['schedule.schoolClass', 'schedule.teacher', 'schedule.room', 'attendances'])->find($id);

        if (!$journal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jurnal tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $journal
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $journal = Journal::find($id);

        if (!$journal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jurnal tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'id_schedule'       => 'sometimes|required|exists:schedules,id_schedule',
            'date'              => 'sometimes|required|date',
            'topic_description' => 'sometimes|required|string',
            'photo_path'        => 'nullable|string',
        ]);

        $journal->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Jurnal berhasil diperbarui',
            'data' => $journal->load('schedule')
        ], 200);
    }

    public function destroy($id)
    {
        $journal = Journal::find($id);

        if (!$journal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jurnal tidak ditemukan'
            ], 404);
        }

        $journal->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Jurnal berhasil dihapus'
        ], 200);
    }
}