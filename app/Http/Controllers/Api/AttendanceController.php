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

        // Sinkronisasi otomatis status Izin jika surat izin siswa sudah terverifikasi (Disetujui / Di Terima)
        $approvedLeaves = LeaveRequest::whereRaw("LOWER(TRIM(COALESCE(status_approval, ''))) IN ('disetujui', 'di terima', 'diterima')")->get();
        if ($approvedLeaves->isNotEmpty()) {
            foreach ($attendances as $att) {
                $attDate = substr((string) $att->date, 0, 10);
                $hasLeave = $approvedLeaves->first(function ($lv) use ($att, $attDate) {
                    if ((int) $lv->id_siswa !== (int) $att->id_siswa) return false;
                    $sDate = substr((string) $lv->start_date, 0, 10);
                    $eDate = substr((string) ($lv->end_date ?? $lv->start_date), 0, 10);
                    return $attDate >= $sDate && $attDate <= $eDate;
                });
                if ($hasLeave && strtolower(trim($att->status ?? '')) !== 'izin') {
                    $att->status = 'Izin';
                    Attendance::where('id_attendance', $att->id_attendance)->update(['status' => 'Izin']);
                }
            }
        }

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

    // Endpoint simpan batch presensi 1 kelas sekaligus dari form presensi guru / rekap jurnal
    public function storeBatch(Request $request)
    {
        $idJurnal = $request->input('id_jurnal', $request->input('id_journal'));
        $date = $request->input('date');

        if (!$idJurnal && $request->filled('id_schedule') && $date) {
            $foundJournal = \App\Models\Journal::where('id_schedule', $request->id_schedule)
                ->whereDate('date', $date)
                ->first();
            if ($foundJournal) {
                $idJurnal = $foundJournal->id_jurnal;
            }
        }

        $rawItems = $request->input('items', $request->input('attendances', []));

        if (!$idJurnal || !$date || empty($rawItems)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data jurnal, tanggal, dan daftar presensi siswa wajib diisi.'
            ], 422);
        }

        // Cek batasan waktu 1 hari jika yang mengubah bukan Waka/Admin (Role 1)
        $user = $request->user();
        if ($user && (int) $user->id_role !== 1) {
            $journal = \App\Models\Journal::find($idJurnal);
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

        // Otomatis verifikasi sistem jika siswa memiliki surat izin yang telah Di Terima oleh Wali Kelas / BK
        $approvedLeaveStudentIds = LeaveRequest::whereRaw("LOWER(TRIM(COALESCE(status_approval, ''))) IN ('disetujui', 'di terima', 'diterima')")
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereDate('end_date', '>=', $date)
                  ->orWhereNull('end_date');
            })
            ->pluck('id_siswa')
            ->map(fn($id) => (int) $id)
            ->flip();

        foreach ($rawItems as $item) {
            $studentId = (int) ($item['id_siswa'] ?? $item['id_student'] ?? 0);
            if (!$studentId) continue;

            $status = $item['status'] ?? 'Hadir';
            if (isset($approvedLeaveStudentIds[$studentId])) {
                $status = 'Izin';
            }

            Attendance::updateOrCreate(
                [
                    'id_jurnal' => $idJurnal,
                    'id_siswa'  => $studentId,
                    'date'      => $date
                ],
                [
                    'status'    => $status
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
            ->where(function ($q) {
                $q->whereNull('title')
                  ->orWhereRaw("LOWER(TRIM(title)) NOT IN ('orang tua', 'dikeluarkan', 'alumni', 'lulus', 'pensiun')");
            })
            ->orderByRaw('CAST(nis AS UNSIGNED) ASC')
            ->orderBy('nis', 'asc')
            ->get();

        $approvedLeaves = LeaveRequest::whereRaw("LOWER(TRIM(COALESCE(status_approval, ''))) IN ('disetujui', 'di terima', 'diterima')")
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereDate('end_date', '>=', $date)
                  ->orWhereNull('end_date');
            })
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