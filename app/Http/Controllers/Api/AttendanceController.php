<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::with(['journal.schedule', 'student.schoolClass']);

        if ($request->has('id_jurnal')) {
            $query->where('id_jurnal', $request->id_jurnal);
        }

        if ($request->has('id_siswa')) {
            $query->where('id_siswa', $request->id_siswa);
        }

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
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
            'status'    => 'required|in:Hadir,Sakit,Izin,Alpa,Terlambat',
            'date'      => 'required|date',
        ]);

        $attendance = Attendance::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data presensi berhasil dicatat',
            'data' => $attendance->load(['journal', 'student'])
        ], 201);
    }

    // Endpoint simpan batch presensi 1 kelas sekaligus dari form presensi guru
    public function storeBatch(Request $request)
    {
        $request->validate([
            'id_jurnal' => 'required|exists:journals,id_jurnal',
            'date'      => 'required|date',
            'items'     => 'required|array|min:1',
            'items.*.id_siswa' => 'required|exists:users,id_user',
            'items.*.status'   => 'required|in:Hadir,Sakit,Izin,Alpa,Terlambat',
        ]);

        // Cek batasan waktu 1 hari jika yang mengubah bukan Waka/Admin (Role 1)
        $user = $request->user();
        if ($user && (int) $user->id_role !== 1) {
            $journal = \App\Models\Journal::find($request->id_jurnal);
            if ($journal) {
                $journalDate = \Carbon\Carbon::parse($journal->date)->startOfDay();
                $today = \Carbon\Carbon::now()->startOfDay();
                if ($journalDate->diffInDays($today, false) > 1) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Batas waktu edit presensi telah berakhir (Maksimal 1 hari dari jadwal mengajar).'
                    ], 403);
                }
            }
        }

        $idJurnal = $request->id_jurnal;
        $date = $request->date;

        foreach ($request->items as $item) {
            Attendance::updateOrCreate(
                [
                    'id_jurnal' => $idJurnal,
                    'id_siswa'  => $item['id_siswa'],
                    'date'      => $date
                ],
                [
                    'status'    => $item['status']
                ]
            );
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Seluruh data presensi siswa berhasil disimpan.'
        ], 200);
    }

    // Endpoint untuk mengambil siswa per kelas lengkap dengan status izin yang disetujui hari ini
    public function getStudentsForAttendance(Request $request)
    {
        $request->validate([
            'id_class' => 'required|exists:classes,id_class',
            'date'     => 'required|date'
        ]);

        $classId = $request->id_class;
        $date = $request->date;

        $students = User::where('id_role', 4)
            ->where('id_class', $classId)
            ->orderBy('fullname', 'asc')
            ->get();

        $approvedLeaves = LeaveRequest::whereIn('status_approval', ['Disetujui', 'Di Terima', 'di terima', 'disetujui'])
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get()
            ->keyBy('id_siswa');

        $result = $students->map(function ($st) use ($approvedLeaves) {
            $leave = $approvedLeaves->get($st->id_user);
            
            $resolvedFile = null;
            if ($leave) {
                $rawFile = $leave->document_path 
                    ?? $leave->getRawOriginal('document_path')
                    ?? $leave->file_path 
                    ?? $leave->file 
                    ?? $leave->attachment 
                    ?? $leave->lampiran 
                    ?? $leave->photo_path 
                    ?? $leave->bukti 
                    ?? null;

                if (is_array($rawFile)) {
                    $resolvedFile = count($rawFile) > 0 ? $rawFile[0] : null;
                } elseif (is_string($rawFile) && !empty($rawFile)) {
                    $decoded = json_decode($rawFile, true);
                    if (is_array($decoded) && count($decoded) > 0) {
                        $resolvedFile = $decoded[0];
                    } else {
                        $resolvedFile = $rawFile;
                    }
                }
            }

            return [
                'id_user'          => $st->id_user,
                'nis'              => $st->nis ?? '-',
                'fullname'         => $st->fullname ?? $st->username,
                'has_leave'        => $leave !== null,
                'leave_reason'     => $leave ? $leave->reason : null,
                'locked_status'    => $leave !== null ? 'Izin' : null,
                'leave_details'    => $leave ? [
                    'id_leave'   => $leave->id_izin_keluar ?? $leave->id_leave ?? $leave->id,
                    'type'       => $leave->type ?? $leave->jenis_izin ?? 'Sakit',
                    'start_date' => $leave->start_date ? substr($leave->start_date, 0, 10) : '-',
                    'end_date'   => $leave->end_date ? substr($leave->end_date, 0, 10) : '-',
                    'reason'     => $leave->reason ?? $leave->alasan ?? '-',
                    'file_path'  => $resolvedFile,
                ] : null
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => $result
        ], 200);
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
        // Mendukung pencarian lewat id_presensi maupun fallback id
        $attendance = Attendance::where('id_presensi', $id)->first();
        if (!$attendance) {
            $attendance = Attendance::find($id);
        }

        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data presensi tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'id_jurnal' => 'nullable|exists:journals,id_jurnal',
            'id_siswa'  => 'nullable|exists:users,id_user',
            'status'    => 'required|in:Hadir,Sakit,Izin,Alpa,Terlambat',
            'date'      => 'nullable|date',
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
        $attendance = Attendance::where('id_presensi', $id)->first();
        if (!$attendance) {
            $attendance = Attendance::find($id);
        }

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