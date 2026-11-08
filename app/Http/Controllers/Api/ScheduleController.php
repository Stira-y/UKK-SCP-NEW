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

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'cycle_type' => 'required',
        ]);

        $cycle = (string)$request->cycle_type;
        $query = Schedule::where('cycle_type', $cycle);

        if ($request->has('class_ids') && is_array($request->class_ids) && count($request->class_ids) > 0) {
            $query->whereIn('id_class', $request->class_ids);
        } elseif ($request->has('id_class') && !empty($request->id_class)) {
            $query->where('id_class', $request->id_class);
        }

        $deletedCount = $query->delete();

        return response()->json([
            'status'        => 'success',
            'message'       => "Berhasil menghapus {$deletedCount} data jadwal.",
            'deleted_count' => $deletedCount
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
        $now = now();
        $insertRows = [];

        // Helper untuk normalisasi ruangan
        $normalizeRoom = function ($raw) {
            $raw = trim($raw);
            if (empty($raw) || $raw === '-') return ['Ruang Kelas', null];
            if (preg_match('/^L\.(\d+)$/i', $raw, $m)) return ['Lab', $m[1]];
            if (preg_match('/^L\.(.+)$/i', $raw, $m)) return ['Lab ' . strtoupper($m[1]), null];
            if (preg_match('/^Lab\s+(\d+)$/i', $raw, $m)) return ['Lab', $m[1]];
            if (preg_match('/^Ruang\s+(\d+)$/i', $raw, $m)) return ['Ruang', $m[1]];
            if (is_numeric($raw)) return ['Ruang', (string)$raw];
            if (strcasecmp($raw, 'OR') === 0 || strcasecmp($raw, 'Ruang OR') === 0) return ['Ruang OR', null];
            if (strcasecmp($raw, 'HALL') === 0) return ['Hall', null];
            return [$raw, null];
        };

        foreach ($request->items as $idx => $item) {
            $rowNum = $idx + 2;

            $cleanClassStr = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $item['kelas']));
            $cleanClassStr = preg_replace('/^10/', 'X', $cleanClassStr);
            $cleanClassStr = preg_replace('/^11/', 'XI', $cleanClassStr);
            $cleanClassStr = preg_replace('/^12/', 'XII', $cleanClassStr);

            $matchedClass = null;

            foreach ($classes as $c) {
                $dbKey = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $c->kelas_nomor . $c->nama_kelas . ($c->nomor_kelas ?? '')));
                if ($cleanClassStr === $dbKey) {
                    $matchedClass = $c;
                    break;
                }
            }

            if (!$matchedClass) {
                $cleanClassRegular = preg_replace('/\s+/', ' ', trim($item['kelas']));
                foreach ($classes as $c) {
                    $fullC1 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ' ' . ($c->nomor_kelas ?? ''));
                    $fullC2 = trim($c->kelas_nomor . ' ' . $c->nama_kelas . ($c->nomor_kelas ?? ''));
                    if (strcasecmp($cleanClassRegular, $fullC1) === 0 || strcasecmp($cleanClassRegular, $fullC2) === 0) {
                        $matchedClass = $c;
                        break;
                    }
                }
            }

            if (!$matchedClass) {
                $errors[] = "Baris {$rowNum}: Kelas '{$item['kelas']}' tidak ditemukan di database.";
                continue;
            }

            $rawGuru = trim($item['guru']);
            $cleanGuruStr = preg_replace('/^"|\d+[\.,]\s*|"$/', '', $rawGuru);
            $cleanGuruStr = trim($cleanGuruStr, '" \t\n\r');
            if (empty($cleanGuruStr)) $cleanGuruStr = $rawGuru;

            $matchedGuru = $teachers->first(function ($t) use ($cleanGuruStr) {
                if (strcasecmp(trim($t->fullname), $cleanGuruStr) === 0 || strcasecmp(trim($t->username), $cleanGuruStr) === 0) {
                    return true;
                }
                $tLower = strtolower(trim($t->fullname));
                $gLower = strtolower($cleanGuruStr);
                if (!empty($gLower) && (str_contains($tLower, $gLower) || str_contains($gLower, $tLower))) {
                    return true;
                }
                $parts = explode(' ', $gLower);
                if (count($parts) >= 2 && str_contains($tLower, $parts[0] . ' ' . $parts[1])) {
                    return true;
                }
                return false;
            });

            if (!$matchedGuru) {
                $cleanUser = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(',', $cleanGuruStr)[0]));
                if (empty($cleanUser)) $cleanUser = 'guru_' . time() . '_' . rand(10, 99);
                $baseU = $cleanUser;
                $counter = 1;
                $existingUsernames = $teachers->pluck('username')->filter()->toArray();
                while (in_array($cleanUser, $existingUsernames)) {
                    $cleanUser = $baseU . $counter++;
                }
                $matchedGuru = User::create([
                    'username' => $cleanUser,
                    'fullname' => $cleanGuruStr,
                    'email'    => $cleanUser . '@school.id',
                    'password' => 'Peh_1234',
                    'id_role'  => 2,
                    'title'    => 'Guru',
                ]);
                $teachers->push($matchedGuru);
            }

            $rawRoom = trim($item['ruangan']);
            [$normName, $normNum] = $normalizeRoom($rawRoom);
            $matchedRoom = null;

            foreach ($rooms as $r) {
                $rFormat1 = trim($r->nama_room . ' ' . $r->nomor_room);
                $rFormat2 = trim($r->nama_room . ' (' . $r->nomor_room . ')');
                if (strcasecmp($rawRoom, $rFormat1) === 0 || strcasecmp($rawRoom, $rFormat2) === 0 || strcasecmp($rawRoom, $r->nama_room) === 0) {
                    $matchedRoom = $r;
                    break;
                }
                if ($normName) {
                    if ($normNum !== null) {
                        if (strcasecmp($r->nama_room, $normName) === 0 && (string)$r->nomor_room === (string)$normNum) {
                            $matchedRoom = $r;
                            break;
                        }
                    } else {
                        if (strcasecmp($r->nama_room, $normName) === 0 && empty($r->nomor_room)) {
                            $matchedRoom = $r;
                            break;
                        }
                    }
                }
            }

            if (!$matchedRoom && !empty($rawRoom) && $rawRoom !== '-') {
                $matchedRoom = Room::create([
                    'nama_room'  => $normName ?: $rawRoom,
                    'nomor_room' => $normNum,
                ]);
                $rooms->push($matchedRoom);
            }

            if (!$matchedRoom) {
                $matchedRoom = $rooms->first(function ($r) { return strcasecmp($r->nama_room, 'Ruang') === 0; }) ?? $rooms->first();
                if (!$matchedRoom) {
                    $matchedRoom = Room::create(['nama_room' => 'Ruang 1', 'nomor_room' => 1]);
                    $rooms->push($matchedRoom);
                }
            }

            $waktuParts = preg_split('/[\s]+[-–—~][\s]+|[-–—~]/u', str_replace('.', ':', $item['waktu']));
            if (count($waktuParts) !== 2) {
                $errors[] = "Baris {$rowNum}: Format waktu '{$item['waktu']}' tidak valid (Gunakan format JJ:MM - JJ:MM).";
                continue;
            }

            $startTime = trim($waktuParts[0]);
            $endTime = trim($waktuParts[1]);

            if (preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
                $startTime = str_pad($startTime, 5, '0', STR_PAD_LEFT) . ':00';
            } elseif (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $startTime)) {
                $startTime = str_pad($startTime, 8, '0', STR_PAD_LEFT);
            }

            if (preg_match('/^\d{1,2}:\d{2}$/', $endTime)) {
                $endTime = str_pad($endTime, 5, '0', STR_PAD_LEFT) . ':00';
            } elseif (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $endTime)) {
                $endTime = str_pad($endTime, 8, '0', STR_PAD_LEFT);
            }

            $day = $item['hari'];
            $cycle = $item['cycle_type'];
            $mapelName = trim($item['mapel']);

            $insertRows[] = [
                'id_class'    => $matchedClass->id_class,
                'id_guru'     => $matchedGuru->id_user,
                'id_room'     => $matchedRoom->id_room,
                'mapel'       => $mapelName,
                'day_of_week' => $day,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'cycle_type'  => $cycle,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (count($insertRows) > 0) {
            try {
                // Bulk insert secepat kilat (1 single SQL query)
                Schedule::insert($insertRows);
                $importedCount = count($insertRows);
            } catch (\Throwable $e) {
                \Log::warning('Schedule batch insert failed, fallback to individual rows: ' . $e->getMessage());
                foreach ($insertRows as $row) {
                    try {
                        Schedule::create($row);
                        $importedCount++;
                    } catch (\Throwable $rowErr) {
                        $errors[] = "Jadwal gagal disimpan ({$row['mapel']}): " . $rowErr->getMessage();
                    }
                }
            }
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