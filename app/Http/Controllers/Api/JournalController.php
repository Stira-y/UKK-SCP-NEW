<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JournalController extends Controller
{
    public function index(Request $request)
    {
        $query = Journal::with(['schedule.schoolClass', 'schedule.teacher', 'schedule.room', 'attendances.student']);

        if ($request->has('id_schedule')) {
            $query->where('id_schedule', $request->id_schedule);
        }

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->has('id_teacher') || $request->has('id_guru')) {
            $teacherId = $request->input('id_teacher') ?? $request->input('id_guru');
            $query->whereHas('schedule', function ($q) use ($teacherId) {
                $q->where('id_guru', $teacherId);
            });
        }

        if ($request->has('id_class')) {
            $query->whereHas('schedule', function ($q) use ($request) {
                $q->where('id_class', $request->id_class);
            });
        }

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        $journals = $query->latest('date')->latest('id_jurnal')->get();

        // Sinkronisasi otomatis status Izin pada presensi jurnal jika surat izin siswa sudah terverifikasi
        try {
            $approvedLeaves = LeaveRequest::whereRaw("LOWER(TRIM(COALESCE(status_approval, ''))) IN ('disetujui', 'di terima', 'diterima')")->get();
            if ($approvedLeaves->isNotEmpty()) {
                foreach ($journals as $j) {
                    $jDate = substr((string) $j->date, 0, 10);
                    if ($j->relationLoaded('attendances') && $j->attendances) {
                        foreach ($j->attendances as $att) {
                            $hasLeave = $approvedLeaves->first(function ($lv) use ($att, $jDate) {
                                if ((int) $lv->id_siswa !== (int) $att->id_siswa) return false;
                                $sDate = substr((string) $lv->start_date, 0, 10);
                                $eDate = substr((string) ($lv->end_date ?? $lv->start_date), 0, 10);
                                return $jDate >= $sDate && $jDate <= $eDate;
                            });
                            if ($hasLeave && strtolower(trim($att->status ?? '')) !== 'izin') {
                                $att->status = 'Izin';
                                if ($att->id_presensi) {
                                    Attendance::where('id_presensi', $att->id_presensi)->update(['status' => 'Izin']);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'status' => 'success',
            'data' => $journals
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_schedule'       => 'required|integer|exists:schedules,id_schedule',
            'date'              => 'required|date',
            'topic_description' => 'required|string',
            'photos.*'          => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        $uploadedPaths = [];
        if ($request->hasFile('photos')) {
            $totalSize = 0;
            foreach ($request->file('photos') as $file) {
                $totalSize += $file->getSize();
            }

            if ($totalSize > 5242880) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Total ukuran seluruh foto tidak boleh melebihi 5 MB!'
                ], 422);
            }

            foreach ($request->file('photos') as $file) {
                $path = $file->store('journals', 'public');
                $uploadedPaths[] = $path;
            }
        }

        $journal = Journal::updateOrCreate(
            [
                'id_schedule' => $validated['id_schedule'],
                'date'        => $validated['date'],
            ],
            [
                'topic_description' => $validated['topic_description'],
                'photo_path'        => !empty($uploadedPaths) ? json_encode($uploadedPaths) : null,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Draf jurnal berhasil disimpan, silakan lanjutkan ke presensi.',
            'data' => $journal
        ], 200);
    }

    public function show($id)
    {
        $journal = Journal::with(['schedule.schoolClass', 'schedule.teacher', 'schedule.room', 'attendances.student'])->find($id);

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

        // Cek batasan waktu 1 hari jika yang mengubah bukan Waka/Admin (Role 1)
        $user = $request->user();
        if ($user && (int) $user->id_role !== 1) {
            $journalDate = \Carbon\Carbon::parse($journal->date)->startOfDay();
            $today = \Carbon\Carbon::now()->startOfDay();
            if ($journalDate->diffInDays($today, false) > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Batas waktu edit jurnal telah berakhir (Maksimal 1 hari dari jadwal mengajar).'
                ], 403);
            }
        }

        $validated = $request->validate([
            'id_schedule'       => 'sometimes|required|exists:schedules,id_schedule',
            'date'              => 'sometimes|required|date',
            'topic_description' => 'sometimes|required|string',
            'photos.*'          => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        if ($request->hasFile('photos')) {
            $totalSize = 0;
            foreach ($request->file('photos') as $file) {
                $totalSize += $file->getSize();
            }

            if ($totalSize > 5242880) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Total ukuran seluruh foto tidak boleh melebihi 5 MB!'
                ], 422);
            }

            $uploadedPaths = [];
            foreach ($request->file('photos') as $file) {
                $path = $file->store('journals', 'public');
                $uploadedPaths[] = $path;
            }
            $validated['photo_path'] = json_encode($uploadedPaths);
        }

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

        // Cek batasan waktu 1 hari jika yang menghapus bukan Waka/Admin (Role 1)
        $user = request()->user();
        if ($user && (int) $user->id_role !== 1) {
            $journalDate = \Carbon\Carbon::parse($journal->date)->startOfDay();
            $today = \Carbon\Carbon::now()->startOfDay();
            if ($journalDate->diffInDays($today, false) > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Batas waktu hapus jurnal telah berakhir (Maksimal 1 hari dari jadwal mengajar).'
                ], 403);
            }
        }

        if ($journal->photo_path) {
            $paths = json_decode($journal->photo_path, true);
            if (is_array($paths)) {
                foreach ($paths as $p) {
                    Storage::disk('public')->delete($p);
                }
            } else {
                Storage::disk('public')->delete($journal->photo_path);
            }
        }

        $journal->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Jurnal berhasil dihapus'
        ], 200);
    }

    public function getTodayMonitoring(Request $request)
    {
        ScheduleController::ensureSpecialDummySchedules();

        $daysIndo = [
            'Sunday'    => 'Minggu',
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu'
        ];

        $now = Carbon::now('Asia/Jakarta');
        $todayName = $daysIndo[$now->format('l')] ?? 'Senin';
        $todayDate = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $cycle = $request->query('cycle_type', '1');

        $schedules = Schedule::with(['schoolClass', 'teacher', 'room'])
            ->where('day_of_week', $todayName)
            ->where('cycle_type', $cycle)
            ->orderBy('start_time', 'asc')
            ->get();

        $groupedByClass = $schedules->groupBy('id_class');
        $monitoringResults = [];

        foreach ($groupedByClass as $classId => $schedList) {
            $prevTeacherId = null;
            $prevJournalFilled = false;

            foreach ($schedList as $index => $sch) {
                $isConsecutiveSameTeacher = ($prevTeacherId === $sch->id_guru && $prevJournalFilled);

                $journal = Journal::where('id_schedule', $sch->id_schedule)
                    ->whereDate('date', $todayDate)
                    ->withCount('attendances')
                    ->first();

                $startTime = Carbon::parse($sch->start_time);
                $lateThreshold = Carbon::parse($sch->start_time)->addMinutes(15);
                $nowTime = Carbon::parse($currentTime);

                $statusJurnal = 'Belum Dimulai';
                $isLate = false;

                $hasCompletedAttendance = $journal && $journal->attendances_count > 0;

                if ($hasCompletedAttendance) {
                    $statusJurnal = 'Sudah Diisi';
                    $prevJournalFilled = true;
                } else {
                    if ($isConsecutiveSameTeacher) {
                        $statusJurnal = 'Lanjutan KBM';
                        $prevJournalFilled = true;
                    } else {
                        if ($nowTime->lt($startTime)) {
                            $statusJurnal = 'Belum Dimulai';
                        } elseif ($nowTime->between($startTime, $lateThreshold)) {
                            $statusJurnal = 'Sedang Berlangsung';
                        } else {
                            $statusJurnal = 'Terlambat';
                            $isLate = true;
                        }
                        $prevJournalFilled = false;
                    }
                }

                $prevTeacherId = $sch->id_guru;

                $className = '-';
                $tingkat = '-';
                if ($sch->schoolClass) {
                    $className = trim($sch->schoolClass->kelas_nomor . ' ' . $sch->schoolClass->nama_kelas . ' ' . ($sch->schoolClass->nomor_kelas ?? ''));
                    $tingkat = $sch->schoolClass->kelas_nomor;
                }

                $teacherName = '-';
                if ($sch->teacher) {
                    $teacherName = $sch->teacher->fullname ?? $sch->teacher->username;
                }

                $roomName = '-';
                if ($sch->room) {
                    $roomName = trim($sch->room->nama_room . ' ' . ($sch->room->nomor_room ?? ''));
                }

                $monitoringResults[] = [
                    'id_schedule'       => $sch->id_schedule,
                    'class_name'        => $className,
                    'tingkat'           => $tingkat,
                    'time_range'        => substr($sch->start_time, 0, 5) . ' - ' . substr($sch->end_time, 0, 5),
                    'mapel'             => $sch->mapel,
                    'teacher_name'      => $teacherName,
                    'teacher_id'        => $sch->id_guru,
                    'room_name'         => $roomName,
                    'status_jurnal'     => $statusJurnal,
                    'is_late'           => $isLate,
                    'filled_at'         => $journal ? $journal->created_at->format('H:i') : null,
                    'journal_id'        => $journal ? $journal->id_jurnal : null,
                    'topic_description' => $journal ? $journal->topic_description : null,
                    'photos'            => $journal && $journal->photo_path ? json_decode($journal->photo_path, true) : []
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'date'   => $todayDate,
            'day'    => $todayName,
            'data'   => $monitoringResults
        ], 200);
    }
}