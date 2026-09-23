<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::with(['journal', 'student']);

        if ($request->has('id_jurnal')) {
            $query->where('id_jurnal', $request->id_jurnal);
        }

        if ($request->has('id_siswa')) {
            $query->where('id_siswa', $request->id_siswa);
        }

        $attendances = $query->latest('date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $attendances
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_jurnal' => 'required|exists:journals,id_jurnal',
            'id_siswa'  => 'required|exists:users,id_user',
            'status'    => 'required|in:Hadir,Sakit,Izin,Alpa',
            'date'      => 'required|date',
        ]);

        $attendance = Attendance::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data presensi berhasil dicatat',
            'data' => $attendance->load(['journal', 'student'])
        ], 201);
    }

    public function show($id)
    {
        $attendance = Attendance::with(['journal', 'student'])->find($id);

        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data presensi tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $attendance
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $attendance = Attendance::find($id);

        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data presensi tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'id_jurnal' => 'sometimes|required|exists:journals,id_jurnal',
            'id_siswa'  => 'sometimes|required|exists:users,id_user',
            'status'    => 'sometimes|required|in:Hadir,Sakit,Izin,Alpa',
            'date'      => 'sometimes|required|date',
        ]);

        $attendance->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data presensi berhasil diperbarui',
            'data' => $attendance->load(['journal', 'student'])
        ], 200);
    }

    public function destroy($id)
    {
        $attendance = Attendance::find($id);

        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data presensi tidak ditemukan'
            ], 404);
        }

        $attendance->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data presensi berhasil dihapus'
        ], 200);
    }
}