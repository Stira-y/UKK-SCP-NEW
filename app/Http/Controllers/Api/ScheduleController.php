<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Room;
use App\Models\User;
use App\Models\SchoolClass;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = Schedule::with(['teacher', 'schoolClass', 'room']);

        if ($request->has('id_class')) {
            $query->where('id_class', $request->id_class);
        }

        // Filter berdasarkan Guru Pengampu (agar tidak mengambil jadwal milik guru lain)
        if ($request->has('id_guru')) {
            $query->where('id_guru', $request->id_guru);
        }

        if ($request->has('cycle_type')) {
            $query->where('cycle_type', $request->cycle_type);
        }

        if ($request->has('kelas_nomor')) {
            $tingkat = $request->kelas_nomor;
            $query->whereHas('schoolClass', function ($q) use ($tingkat) {
                $q->where('kelas_nomor', $tingkat);
            });
        }

        $dayOrder = [
            'Senin' => 1,
            'Selasa' => 2,
            'Rabu' => 3,
            'Kamis' => 4,
            'Jumat' => 5,
            'Sabtu' => 6,
            'Minggu' => 7
        ];

        $schedules = $query->get()->sort(function ($a, $b) use ($dayOrder) {
            $orderA = $dayOrder[$a->day_of_week] ?? 99;
            $orderB = $dayOrder[$b->day_of_week] ?? 99;
            if ($orderA === $orderB) {
                return strcmp($a->start_time, $b->start_time);
            }
            return $orderA <=> $orderB;
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $schedules
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_guru'     => 'required|exists:users,id_user',
            'id_class'    => 'required|exists:classes,id_class',
            'id_room'     => 'required|exists:rooms,id_room',
            'mapel'       => 'required|string|max:100',
            'day_of_week' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'start_time'  => 'required',
            'end_time'    => 'required|after:start_time',
            'cycle_type'  => 'required|in:1,2',
        ]);

        $day   = $validated['day_of_week'];
        $cycle = $validated['cycle_type'];
        
        $start = substr(trim($validated['start_time']), 0, 5) . ':00';
        $end   = substr(trim($validated['end_time']), 0, 5) . ':00';
        $validated['start_time'] = $start;
        $validated['end_time']   = $end;

        // 1. Cek bentrok Kelas: overlap waktu (> 1 menit) pada hari & putaran yang sama
        $conflictClass = Schedule::with(['schoolClass', 'teacher'])
            ->where('id_class', $validated['id_class'])
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where(function ($timeQ) use ($start, $end) {
                $timeQ->where('start_time', '<', $end)
                      ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictClass) {
            $namaKelas = $conflictClass->schoolClass 
                ? ($conflictClass->schoolClass->kelas_nomor . ' ' . $conflictClass->schoolClass->nama_kelas . ' ' . ($conflictClass->schoolClass->nomor_kelas ?? '')) 
                : 'Kelas ini';
            $guruName = $conflictClass->teacher ? ($conflictClass->teacher->fullname ?? $conflictClass->teacher->username) : 'Guru lain';

            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! {$namaKelas} sudah memiliki mata pelajaran '{$conflictClass->mapel}' (Guru: {$guruName}) pada hari {$day} pukul " . substr($conflictClass->start_time, 0, 5) . " - " . substr($conflictClass->end_time, 0, 5)
            ], 422);
        }

        // 2. Cek bentrok Guru di kelas lain
        $conflictTeacher = Schedule::with('schoolClass')
            ->where('id_guru', $validated['id_guru'])
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where(function ($timeQ) use ($start, $end) {
                $timeQ->where('start_time', '<', $end)
                      ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictTeacher) {
            $namaKelasLain = $conflictTeacher->schoolClass 
                ? ($conflictTeacher->schoolClass->kelas_nomor . ' ' . $conflictTeacher->schoolClass->nama_kelas . ' ' . ($conflictTeacher->schoolClass->nomor_kelas ?? '')) 
                : 'kelas lain';
            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! Guru yang dipilih sudah dijadwalkan mengajar di {$namaKelasLain} pada hari {$day} pukul " . substr($conflictTeacher->start_time, 0, 5) . " - " . substr($conflictTeacher->end_time, 0, 5)
            ], 422);
        }

        // 3. Cek bentrok Ruangan
        $conflictRoom = Schedule::with(['schoolClass', 'room'])
            ->where('id_room', $validated['id_room'])
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                  ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictRoom) {
            $namaRuang = $conflictRoom->room ? ($conflictRoom->room->nama_room . ' ' . $conflictRoom->room->nomor_room) : 'Ruangan ini';
            $namaKelasLain = $conflictRoom->schoolClass 
                ? ($conflictRoom->schoolClass->kelas_nomor . ' ' . $conflictRoom->schoolClass->nama_kelas . ' ' . ($conflictRoom->schoolClass->nomor_kelas ?? '')) 
                : 'kelas lain';
            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! {$namaRuang} sedang digunakan oleh {$namaKelasLain} pada hari {$day} pukul " . substr($conflictRoom->start_time, 0, 5) . " - " . substr($conflictRoom->end_time, 0, 5)
            ], 422);
        }

        $schedule = Schedule::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Jadwal berhasil ditambahkan',
            'data' => $schedule->load(['teacher', 'schoolClass', 'room'])
        ], 201);
    }

    public function show($id)
    {
        $schedule = Schedule::with(['teacher', 'schoolClass', 'room'])->find($id);

        if (!$schedule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data jadwal tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $schedule
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $schedule = Schedule::find($id);

        if (!$schedule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data jadwal tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'id_guru'     => 'sometimes|required|exists:users,id_user',
            'id_class'    => 'sometimes|required|exists:classes,id_class',
            'id_room'     => 'sometimes|required|exists:rooms,id_room',
            'mapel'       => 'sometimes|required|string|max:100',
            'day_of_week' => 'sometimes|required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'start_time'  => 'sometimes|required',
            'end_time'    => 'sometimes|required',
            'cycle_type'  => 'sometimes|required|in:1,2',
        ]);

        $classId = $validated['id_class'] ?? $schedule->id_class;
        $guruId  = $validated['id_guru'] ?? $schedule->id_guru;
        $roomId  = $validated['id_room'] ?? $schedule->id_room;
        $day     = $validated['day_of_week'] ?? $schedule->day_of_week;
        $cycle   = $validated['cycle_type'] ?? $schedule->cycle_type;

        $start   = isset($validated['start_time']) ? substr(trim($validated['start_time']), 0, 5) . ':00' : substr($schedule->start_time, 0, 5) . ':00';
        $end     = isset($validated['end_time']) ? substr(trim($validated['end_time']), 0, 5) . ':00' : substr($schedule->end_time, 0, 5) . ':00';

        if (isset($validated['start_time'])) $validated['start_time'] = $start;
        if (isset($validated['end_time'])) $validated['end_time'] = $end;

        // 1. Cek bentrok Kelas selain jadwal yang sedang diedit
        $conflictClass = Schedule::with(['schoolClass', 'teacher'])
            ->where('id_class', $classId)
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where('id_schedule', '!=', $id)
            ->where(function ($timeQ) use ($start, $end) {
                $timeQ->where('start_time', '<', $end)
                      ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictClass) {
            $namaKelas = $conflictClass->schoolClass 
                ? ($conflictClass->schoolClass->kelas_nomor . ' ' . $conflictClass->schoolClass->nama_kelas . ' ' . ($conflictClass->schoolClass->nomor_kelas ?? '')) 
                : 'Kelas ini';
            $guruName = $conflictClass->teacher ? ($conflictClass->teacher->fullname ?? $conflictClass->teacher->username) : 'Guru lain';

            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! {$namaKelas} sudah memiliki mata pelajaran '{$conflictClass->mapel}' (Guru: {$guruName}) pada hari {$day} pukul " . substr($conflictClass->start_time, 0, 5) . " - " . substr($conflictClass->end_time, 0, 5)
            ], 422);
        }

        // 2. Cek bentrok Guru selain jadwal ini
        $conflictTeacher = Schedule::with('schoolClass')
            ->where('id_guru', $guruId)
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where('id_schedule', '!=', $id)
            ->where(function ($timeQ) use ($start, $end) {
                $timeQ->where('start_time', '<', $end)
                      ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictTeacher) {
            $namaKelasLain = $conflictTeacher->schoolClass 
                ? ($conflictTeacher->schoolClass->kelas_nomor . ' ' . $conflictTeacher->schoolClass->nama_kelas . ' ' . ($conflictTeacher->schoolClass->nomor_kelas ?? '')) 
                : 'kelas lain';
            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! Guru yang dipilih sudah dijadwalkan mengajar di {$namaKelasLain} pada hari {$day} pukul " . substr($conflictTeacher->start_time, 0, 5) . " - " . substr($conflictTeacher->end_time, 0, 5)
            ], 422);
        }

        // 3. Cek bentrok Ruangan selain jadwal ini
        $conflictRoom = Schedule::with(['schoolClass', 'room'])
            ->where('id_room', $roomId)
            ->where('day_of_week', $day)
            ->where('cycle_type', $cycle)
            ->where('id_schedule', '!=', $id)
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                  ->where('end_time', '>', $start);
            })
            ->first();

        if ($conflictRoom) {
            $namaRuang = $conflictRoom->room ? ($conflictRoom->room->nama_room . ' ' . $conflictRoom->room->nomor_room) : 'Ruangan ini';
            $namaKelasLain = $conflictRoom->schoolClass 
                ? ($conflictRoom->schoolClass->kelas_nomor . ' ' . $conflictRoom->schoolClass->nama_kelas . ' ' . ($conflictRoom->schoolClass->nomor_kelas ?? '')) 
                : 'kelas lain';
            return response()->json([
                'status'  => 'error',
                'message' => "Jadwal bentrok! {$namaRuang} sedang digunakan oleh {$namaKelasLain} pada hari {$day} pukul " . substr($conflictRoom->start_time, 0, 5) . " - " . substr($conflictRoom->end_time, 0, 5)
            ], 422);
        }

        $schedule->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Jadwal berhasil diperbarui',
            'data' => $schedule->load(['teacher', 'schoolClass', 'room'])
        ], 200);
    }

    public function destroy($id)
    {
        $schedule = Schedule::find($id);

        if (!$schedule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data jadwal tidak ditemukan'
            ], 404);
        }

        $schedule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Jadwal berhasil dihapus'
        ], 200);
    }

    public function importBatch(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.kelas'         => 'required|string',
            'items.*.hari'          => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'items.*.waktu'         => 'required|string',
            'items.*.mapel'         => 'required|string',
            'items.*.guru'          => 'required|string',
            'items.*.ruangan'       => 'required|string',
            'items.*.cycle_type'    => 'required|in:1,2',
        ]);

        $classes = SchoolClass::all();
        $teachers = User::whereIn('id_role', [2, 3])->get();
        $rooms = Room::all();

        $importedCount = 0;
        $errors = [];

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            $cleanClassStr = preg_replace('/\s+/', ' ', trim($item['kelas']));
            $matchedClass = null;

            foreach ($classes as $c) {
                $fullC1 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? ''));
                $fullC2 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? ''));
                if (strcasecmp($cleanClassStr, $fullC1) === 0 || strcasecmp($cleanClassStr, $fullC2) === 0) {
                    $matchedClass = $c;
                    break;
                }
            }

            if (!$matchedClass) {
                $errors[] = "Baris {$rowNum}: Kelas '{$item['kelas']}' tidak ditemukan di database.";
                continue;
            }

            $cleanGuruStr = trim($item['guru']);
            $matchedGuru = $teachers->first(function ($t) use ($cleanGuruStr) {
                return strcasecmp(trim($t->fullname), $cleanGuruStr) === 0 || strcasecmp(trim($t->username), $cleanGuruStr) === 0;
            });

            if (!$matchedGuru) {
                $errors[] = "Baris {$rowNum}: Guru '{$item['guru']}' tidak terdaftar di sistem.";
                continue;
            }

            $cleanRoomStr = trim($item['ruangan']);
            $matchedRoom = null;

            foreach ($rooms as $r) {
                $rFormat1 = trim($r->nama_room . ' ' . $r->nomor_room);
                $rFormat2 = trim($r->nama_room . ' (' . $r->nomor_room . ')');
                if (strcasecmp($cleanRoomStr, $rFormat1) === 0 || strcasecmp($cleanRoomStr, $rFormat2) === 0) {
                    $matchedRoom = $r;
                    break;
                }
            }

            if (!$matchedRoom) {
                $errors[] = "Baris {$rowNum}: Ruangan '{$item['ruangan']}' tidak ditemukan di sistem.";
                continue;
            }

            $waktuParts = preg_split('/[\-\–]/', str_replace('.', ':', $item['waktu']));
            if (count($waktuParts) !== 2) {
                $errors[] = "Baris {$rowNum}: Format waktu '{$item['waktu']}' tidak valid (Gunakan format JJ:MM - JJ:MM).";
                continue;
            }

            $startTime = trim($waktuParts[0]);
            $endTime = trim($waktuParts[1]);

            if (strlen($startTime) === 5) $startTime .= ':00';
            if (strlen($endTime) === 5) $endTime .= ':00';

            $day = $item['hari'];
            $cycle = $item['cycle_type'];

            $conflictClass = Schedule::with('schoolClass')
                ->where('id_class', $matchedClass->id_class)
                ->where('day_of_week', $day)
                ->where('cycle_type', $cycle)
                ->where(function ($timeQ) use ($startTime, $endTime) {
                    $timeQ->where('start_time', '<', $endTime)
                          ->where('end_time', '>', $startTime);
                })
                ->first();

            if ($conflictClass) {
                $cName = $conflictClass->schoolClass ? ($conflictClass->schoolClass->kelas_nomor . ' ' . $conflictClass->schoolClass->nama_kelas . ' ' . ($conflictClass->schoolClass->nomor_kelas ?? '')) : 'kelas ini';
                $errors[] = "Baris {$rowNum}: Bentrok! {$cName} sudah memiliki mata pelajaran '{$conflictClass->mapel}' pada {$day} ({$startTime} - {$endTime}).";
                continue;
            }

            $conflictTeacher = Schedule::with('schoolClass')
                ->where('id_guru', $matchedGuru->id_user)
                ->where('day_of_week', $day)
                ->where('cycle_type', $cycle)
                ->where(function ($timeQ) use ($startTime, $endTime) {
                    $timeQ->where('start_time', '<', $endTime)
                          ->where('end_time', '>', $startTime);
                })
                ->first();

            if ($conflictTeacher) {
                $cName = $conflictTeacher->schoolClass ? ($conflictTeacher->schoolClass->kelas_nomor . ' ' . $conflictTeacher->schoolClass->nama_kelas . ' ' . ($conflictTeacher->schoolClass->nomor_kelas ?? '')) : 'kelas lain';
                $errors[] = "Baris {$rowNum}: Bentrok! Guru {$cleanGuruStr} sudah mengajar di {$cName} pada {$day} ({$startTime} - {$endTime}).";
                continue;
            }

            $conflictRoom = Schedule::with('schoolClass')
                ->where('id_room', $matchedRoom->id_room)
                ->where('day_of_week', $day)
                ->where('cycle_type', $cycle)
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                })
                ->first();

            if ($conflictRoom) {
                $cName = $conflictRoom->schoolClass ? ($conflictRoom->schoolClass->kelas_nomor . ' ' . $conflictRoom->schoolClass->nama_kelas . ' ' . ($conflictRoom->schoolClass->nomor_kelas ?? '')) : 'kelas lain';
                $errors[] = "Baris {$rowNum}: Bentrok! {$cleanRoomStr} sedang dipakai oleh {$cName} pada {$day} ({$startTime} - {$endTime}).";
                continue;
            }

            Schedule::create([
                'id_class'    => $matchedClass->id_class,
                'id_guru'     => $matchedGuru->id_user,
                'id_room'     => $matchedRoom->id_room,
                'mapel'       => trim($item['mapel']),
                'day_of_week' => $day,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'cycle_type'  => $cycle,
            ]);

            $importedCount++;
        }

        if ($importedCount === 0 && count($errors) > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Seluruh baris gagal diimpor:',
                'errors'  => $errors
            ], 422);
        }

        return response()->json([
            'status'         => 'success',
            'message'        => "Berhasil mengimpor {$importedCount} data jadwal pelajaran.",
            'imported_count' => $importedCount,
            'errors'         => $errors
        ], 200);
    }
}