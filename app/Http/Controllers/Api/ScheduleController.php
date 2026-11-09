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
    public static function cleanupDummyTeachers(): void
    {
        try {
            $dummyIds = User::where('id_role', 2)
                ->where(function ($q) {
                    $q->whereNull('nip')
                      ->orWhere('nip', '')
                      ->orWhere('nip', '-');
                })
                ->where(function ($q) {
                    $q->where('email', 'like', '%@school.id')
                      ->orWhere('username', 'like', 'guru_%')
                      ->orWhere('title', 'Guru')
                      ->orWhereNull('title')
                      ->orWhere('fullname', '-');
                })
                ->pluck('id_user');

            if ($dummyIds->count() > 0) {
                Schedule::whereIn('id_guru', $dummyIds)->delete();
                User::whereIn('id_user', $dummyIds)->delete();
            }
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }
    }

    public static function ensureSpecialDummySchedules(): void
    {
        try {
            $daysIndo = [0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];
            $now = \Carbon\Carbon::now('Asia/Jakarta');
            $todayName = $daysIndo[$now->dayOfWeek] ?? 'Senin';
            $yesterdayName = $daysIndo[$now->copy()->subDay()->dayOfWeek] ?? 'Minggu';

            $room = Room::first();
            $idRoom = $room ? $room->id_room : 1;

            $targets = [
                [
                    'username' => 'dinda',
                    'kelas_nomor' => 'X',
                    'nama_kelas' => 'RPL',
                    'nomor_kelas' => '2',
                    'mapel' => 'Dasar Pemrograman Web',
                    'start_time' => '03:00:00',
                    'end_time' => '07:00:00',
                    'cleanup_starts' => ['18:00:00', '18:00']
                ],
                [
                    'username' => 'rosyida',
                    'kelas_nomor' => 'XI',
                    'nama_kelas' => 'RPL',
                    'nomor_kelas' => '2',
                    'mapel' => 'Pemrograman Perangkat Bergerak',
                    'start_time' => '18:00:00',
                    'end_time' => '23:59:00',
                    'cleanup_starts' => []
                ],
                [
                    'username' => 'rya',
                    'kelas_nomor' => 'XII',
                    'nama_kelas' => 'RPL',
                    'nomor_kelas' => '2',
                    'mapel' => 'Pengembangan Produk Kreatif & Kewirausahaan',
                    'start_time' => '18:00:00',
                    'end_time' => '23:59:00',
                    'cleanup_starts' => []
                ]
            ];

            // List of days to ensure schedules exist for (including today, yesterday, and Monday)
            $daysToSeed = array_unique([$todayName, $yesterdayName, 'Senin']);

            foreach ($targets as $t) {
                $guru = User::where('username', $t['username'])->first();
                $cls = SchoolClass::where('kelas_nomor', $t['kelas_nomor'])
                    ->where('nama_kelas', $t['nama_kelas'])
                    ->where(function ($q) use ($t) {
                        $q->where('nomor_kelas', $t['nomor_kelas'])
                          ->orWhereNull('nomor_kelas');
                    })->first();

                if (!$cls) {
                    $cls = SchoolClass::firstOrCreate(
                        ['kelas_nomor' => $t['kelas_nomor'], 'nama_kelas' => $t['nama_kelas'], 'nomor_kelas' => $t['nomor_kelas']]
                    );
                }

                if ($guru && $cls) {
                    // Clean up any obsolete schedules if specified
                    if (!empty($t['cleanup_starts'])) {
                        Schedule::where('id_guru', $guru->id_user)
                            ->whereIn('start_time', $t['cleanup_starts'])
                            ->delete();
                    }

                    foreach ($daysToSeed as $day) {
                        foreach (['1', '2'] as $cycle) {
                            $exists = Schedule::where('id_guru', $guru->id_user)
                                ->where('id_class', $cls->id_class)
                                ->where('day_of_week', $day)
                                ->where('start_time', $t['start_time'])
                                ->where('end_time', $t['end_time'])
                                ->where('cycle_type', $cycle)
                                ->exists();

                            if (!$exists) {
                                Schedule::create([
                                    'id_guru'     => $guru->id_user,
                                    'id_class'    => $cls->id_class,
                                    'id_room'     => $idRoom,
                                    'mapel'       => $t['mapel'],
                                    'day_of_week' => $day,
                                    'start_time'  => $t['start_time'],
                                    'end_time'    => $t['end_time'],
                                    'cycle_type'  => $cycle,
                                ]);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }

    public function index(Request $request)
    {
        self::cleanupDummyTeachers();
        self::ensureSpecialDummySchedules();

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

        // Pastikan id_guru pada tabel schedules dapat bernilai NULL (untuk Istirahat / Literasi / Upacara)
        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE schedules MODIFY id_guru BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // Abaikan jika sudah nullable
        }

        // Bersihkan guru dummy tanpa NIP sebelum import
        self::cleanupDummyTeachers();

        $classes = SchoolClass::all();
        $teachers = User::whereIn('id_role', [1, 2, 3])->get();
        $rooms = Room::all();

        $importedCount = 0;
        $errors = [];
        $now = now();
        $insertRows = [];

        $stripTitles = function ($name) {
            $s = preg_replace('/\b(LAB|RUANG)\s*\d+\b/i', '', (string)$name);
            $s = preg_replace('/\b(Drs|Dra|Ir|H|Hj|S\.Pd\.Si|S\.Pd\.I|S\.Pd|M\.Pd|M\.Acc|S\.Kom|S\.Si|S\.T|S\.Ag|S\.Sos|S\.S|S\.E|S\.Or|M\.Si|M\.Kom|Gr|SS|SPd|MPd)\b\.?/i', '', $s);
            $s = preg_replace('/[^a-zA-Z\s]/', ' ', $s);
            return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
        };

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

            $mapelName = trim($item['mapel']);
            if ($mapelName === '-x-' || empty($mapelName)) {
                continue;
            }

            $rawGuru = trim($item['guru']);
            $cleanGuruStr = preg_replace('/^"|\d+[\.,]\s*|"$/', '', $rawGuru);
            $cleanGuruStr = preg_replace('/\s+LAB\s+\d+$/i', '', $cleanGuruStr);
            $cleanGuruStr = trim($cleanGuruStr, '" \t\n\r');
            if (empty($cleanGuruStr)) $cleanGuruStr = $rawGuru;

            $matchedGuru = null;
            $isNonGuruSlot = ($cleanGuruStr === '-' || empty($cleanGuruStr) || in_array(strtoupper($mapelName), ['ISTIRAHAT', 'ISTIRAHAT I', 'ISTIRAHAT II', 'LITERASI', 'UPACARA', 'UPACARA BENDERA']));

            if (!$isNonGuruSlot) {
                $gPure = $stripTitles($cleanGuruStr);
                $gWords = array_values(array_filter(explode(' ', $gPure)));

                $matchedGuru = $teachers->first(function ($t) use ($cleanGuruStr, $gPure, $gWords, $stripTitles) {
                    if (strcasecmp(trim($t->fullname), $cleanGuruStr) === 0 || strcasecmp(trim($t->username), $cleanGuruStr) === 0) {
                        return true;
                    }
                    $tPure = $stripTitles($t->fullname);
                    if (!empty($gPure) && !empty($tPure)) {
                        if ($tPure === $gPure) return true;
                        if (strlen($gPure) >= 4 && (str_contains($tPure, $gPure) || str_contains($gPure, $tPure))) {
                            return true;
                        }
                        if (count($gWords) >= 2 && str_contains($tPure, $gWords[0] . ' ' . $gWords[1])) {
                            return true;
                        }
                        if (count($gWords) >= 1 && strlen($gWords[0]) >= 5 && str_contains($tPure, $gWords[0])) {
                            return true;
                        }
                    }
                    return false;
                });
            }

            $rawRoom = trim($item['ruangan']);
            [$normName, $normNum] = $normalizeRoom($rawRoom);
            $matchedRoom = null;
            $rawStripped = strtolower(preg_replace('/[^a-z0-9]/i', '', $rawRoom));

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
                // Keyword / Fuzzy matching (e.g. "Lapangan OR1" vs "or1")
                $dbStripped1 = strtolower(preg_replace('/[^a-z0-9]/i', '', $r->nama_room . $r->nomor_room));
                $dbStripped2 = strtolower(preg_replace('/[^a-z0-9]/i', '', $r->nama_room));
                if (!empty($rawStripped) && ($rawStripped === $dbStripped1 || $rawStripped === $dbStripped2)) {
                    $matchedRoom = $r;
                    break;
                }
                if (!empty($dbStripped1) && strlen($dbStripped1) >= 2 && str_contains($rawStripped, $dbStripped1)) {
                    $matchedRoom = $r;
                    break;
                }
                if (!empty($rawStripped) && strlen($rawStripped) >= 2 && str_contains($dbStripped1, $rawStripped)) {
                    $matchedRoom = $r;
                    break;
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
                'id_guru'     => $matchedGuru ? $matchedGuru->id_user : null,
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

    public function mergeImportPdf(Request $request)
    {
        $request->validate([
            'file_jadwal' => 'required|file|mimes:pdf',
            'file_ruang'  => 'required|file|mimes:pdf',
            'cycle_type'  => 'nullable|in:1,2',
            'is_upacara'  => 'nullable',
        ]);

        $cycleType = $request->input('cycle_type', '2');
        $isUpacara = filter_var($request->input('is_upacara', false), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

        $tempDir = storage_path('app/temp_pdf');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $timeTag = time() . '_' . rand(100, 999);
        $pathJadwal = $tempDir . DIRECTORY_SEPARATOR . "jadwal_{$timeTag}.pdf";
        $pathRuang  = $tempDir . DIRECTORY_SEPARATOR . "ruang_{$timeTag}.pdf";
        $pathJson   = $tempDir . DIRECTORY_SEPARATOR . "out_{$timeTag}.json";

        $request->file('file_jadwal')->move($tempDir, "jadwal_{$timeTag}.pdf");
        $request->file('file_ruang')->move($tempDir, "ruang_{$timeTag}.pdf");

        $scriptPath = base_path('app/Services/pdf_schedule_parser.py');
        $cmd = "python " . escapeshellarg($scriptPath) . " " . escapeshellarg($pathJadwal) . " " . escapeshellarg($pathRuang) . " " . escapeshellarg($cycleType) . " " . escapeshellarg($isUpacara) . " " . escapeshellarg($pathJson);

        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);

        if ($ret !== 0 || !file_exists($pathJson)) {
            @unlink($pathJadwal);
            @unlink($pathRuang);
            @unlink($pathJson);
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses file PDF melalui parser: ' . implode(' ', $out)
            ], 500);
        }

        $jsonData = json_decode(file_get_contents($pathJson), true);

        @unlink($pathJadwal);
        @unlink($pathRuang);
        @unlink($pathJson);

        if (!$jsonData || empty($jsonData['db_items'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data jadwal dari PDF tidak dapat diekstrak atau kosong.'
            ], 422);
        }

        // Hapus jadwal putaran target terlebih dahulu agar fresh
        Schedule::where('cycle_type', $cycleType)->delete();

        // Panggil importBatch logic menggunakan db_items yang dihasilkan
        $fakeRequest = new Request(['items' => $jsonData['db_items']]);
        $importResult = $this->importBatch($fakeRequest);

        return response()->json([
            'status' => 'success',
            'message' => "Berhasil memproses dan mengimpor {$jsonData['classes_count']} kelas ({$jsonData['total_db_items']} jam pelajaran) pada Putaran {$cycleType}!",
            'classes_count' => $jsonData['classes_count'],
            'total_items' => $jsonData['total_db_items'],
            'preview_url' => '/role 1 waka/jadwal_gabungan_preview.html',
            'pdf_url' => '/JADWAL_GABUNGAN_PUTARAN_2.pdf'
        ], 200);
    }
}