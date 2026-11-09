<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\SystemConfiguration;

class CalendarDemoController extends Controller
{
    private function getStoragePath()
    {
        $dir = storage_path('app/demo_data');
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/calendar_events.json';
    }

    private function getDefaultEvents()
    {
        return [
            [
                'id' => 'cal_init_1',
                'date' => '2026-11-09',
                'title' => 'UKK Mandiri',
                'category' => 'ujian',
                'description' => 'Uji Kompetensi Keahlian (UKK) Mandiri',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => [
                    'active' => true,
                    'start_time' => '06:00',
                    'end_time' => '23:59',
                ]
            ],
            [
                'id' => 'cal_init_2',
                'date' => '2026-11-10',
                'title' => 'UKK Mandiri',
                'category' => 'ujian',
                'description' => 'Uji Kompetensi Keahlian (UKK) Mandiri',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => [
                    'active' => true,
                    'start_time' => '06:30',
                    'end_time' => '07:30',
                ]
            ],
            [
                'id' => 'cal_init_3',
                'date' => '2026-11-11',
                'title' => 'UKK Mandiri',
                'category' => 'ujian',
                'description' => 'Uji Kompetensi Keahlian (UKK) Mandiri',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => [
                    'active' => true,
                    'start_time' => '06:30',
                    'end_time' => '07:30',
                ]
            ],
            [
                'id' => 'cal_init_4',
                'date' => '2026-11-12',
                'title' => 'UKK Mandiri',
                'category' => 'ujian',
                'description' => 'Uji Kompetensi Keahlian (UKK) Mandiri',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => [
                    'active' => true,
                    'start_time' => '06:30',
                    'end_time' => '07:30',
                ]
            ],
            [
                'id' => 'cal_init_5',
                'date' => '2026-11-13',
                'title' => 'UKK Mandiri',
                'category' => 'ujian',
                'description' => 'Uji Kompetensi Keahlian (UKK) Mandiri',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => [
                    'active' => true,
                    'start_time' => '06:30',
                    'end_time' => '07:30',
                ]
            ],
            [
                'id' => 'cal_init_6',
                'date' => '2026-11-16',
                'title' => 'Tes Susulan TKA & AN (Tentatif)',
                'category' => 'ujian',
                'description' => 'Tes Susulan Asesmen Nasional & TKA',
                'is_holiday' => false,
                'cycle_type' => '1',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_7',
                'date' => '2026-11-17',
                'title' => 'Tes Susulan TKA & AN (Tentatif)',
                'category' => 'ujian',
                'description' => 'Tes Susulan Asesmen Nasional & TKA',
                'is_holiday' => false,
                'cycle_type' => '1',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_8',
                'date' => '2026-11-18',
                'title' => 'Tes Susulan TKA & AN (Tentatif)',
                'category' => 'ujian',
                'description' => 'Tes Susulan Asesmen Nasional & TKA',
                'is_holiday' => false,
                'cycle_type' => '1',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_9',
                'date' => '2026-11-19',
                'title' => 'Tes Susulan TKA & AN (Tentatif)',
                'category' => 'ujian',
                'description' => 'Tes Susulan Asesmen Nasional & TKA',
                'is_holiday' => false,
                'cycle_type' => '1',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_10',
                'date' => '2026-11-25',
                'title' => 'Hari Guru Nasional',
                'category' => 'kegiatan',
                'description' => 'Peringatan Hari Guru Nasional',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_11',
                'date' => '2026-11-26',
                'title' => 'USEK & PSAS Ganjil',
                'category' => 'ujian',
                'description' => 'Ujian Sekolah & PSAS Ganjil',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_12',
                'date' => '2026-11-27',
                'title' => 'USEK & PSAS Ganjil',
                'category' => 'ujian',
                'description' => 'Ujian Sekolah & PSAS Ganjil',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
            [
                'id' => 'cal_init_13',
                'date' => '2026-11-28',
                'title' => 'USEK & PSAS Ganjil',
                'category' => 'ujian',
                'description' => 'Ujian Sekolah & PSAS Ganjil',
                'is_holiday' => false,
                'cycle_type' => '2',
                'target_tingkat' => 'all',
                'target_rombel' => 'all',
                'presensi_mandiri' => ['active' => false, 'start_time' => '06:30', 'end_time' => '07:30']
            ],
        ];
    }

    private function readEvents()
    {
        $path = $this->getStoragePath();
        if (!file_exists($path)) {
            $defaults = $this->getDefaultEvents();
            $this->saveEvents($defaults);
            return $defaults;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || empty($data)) {
            $defaults = $this->getDefaultEvents();
            $this->saveEvents($defaults);
            return $defaults;
        }
        return $data;
    }

    private function saveEvents(array $events)
    {
        $path = $this->getStoragePath();
        file_put_contents($path, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request)
    {
        $events = $this->readEvents();
        $month = $request->query('month');
        $year = $request->query('year');
        $category = $request->query('category');
        $tingkat = $request->query('tingkat');
        $rombel = $request->query('rombel');

        if ($month && $year) {
            $monthPad = str_pad($month, 2, '0', STR_PAD_LEFT);
            $prefix = "{$year}-{$monthPad}";
            $events = array_values(array_filter($events, function($e) use ($prefix) {
                return isset($e['date']) && strpos($e['date'], $prefix) === 0;
            }));
        }

        if ($category && $category !== 'all') {
            $events = array_values(array_filter($events, function($e) use ($category) {
                return isset($e['category']) && $e['category'] === $category;
            }));
        }

        if ($tingkat && $tingkat !== 'all') {
            $events = array_values(array_filter($events, function($e) use ($tingkat) {
                return !isset($e['target_tingkat']) || $e['target_tingkat'] === 'all' || $e['target_tingkat'] === $tingkat;
            }));
        }

        if ($rombel && $rombel !== 'all') {
            $events = array_values(array_filter($events, function($e) use ($rombel) {
                return !isset($e['target_rombel']) || $e['target_rombel'] === 'all' || $e['target_rombel'] === $rombel;
            }));
        }

        return response()->json([
            'status' => 'success',
            'data'   => $events
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $events = $this->readEvents();
        $dates = $request->input('dates');
        if (!is_array($dates) || empty($dates)) {
            $singleDate = $request->input('date');
            if (!$singleDate) {
                return response()->json(['status' => 'error', 'message' => 'Tanggal wajib dipilih.'], 422);
            }
            $dates = [$singleDate];
        }

        $color = $request->input('color', 'biru');
        $isHoliday = ($color === 'merah') || (bool) $request->input('is_holiday', false);
        $cycleType = $request->input('cycle_type', '1');
        $presensiActive = (bool) $request->input('presensi_mandiri_active', false);
        $presensiStart = $request->input('presensi_mandiri_start', '06:30');
        $presensiEnd = $request->input('presensi_mandiri_end', '08:00');
        $description = $request->input('description', '');

        $savedEvents = [];
        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();

        foreach ($dates as $date) {
            $newEvent = [
                'id'               => 'cal_' . time() . '_' . rand(100, 999),
                'date'             => $date,
                'title'            => $request->input('title'),
                'color'            => $color,
                'description'      => $description,
                'is_holiday'       => $isHoliday,
                'cycle_type'       => $cycleType,
                'presensi_mandiri' => [
                    'active'     => $presensiActive,
                    'start_time' => $presensiStart,
                    'end_time'   => $presensiEnd,
                ],
                'created_at'       => Carbon::now()->toDateTimeString()
            ];

            $found = false;
            foreach ($events as $k => $item) {
                if (isset($item['date']) && $item['date'] === $date) {
                    $newEvent['id'] = $item['id'];
                    $events[$k] = $newEvent;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $events[] = $newEvent;
            }

            if ($date === $todayStr && !empty($cycleType)) {
                try {
                    $config = SystemConfiguration::first();
                    if ($config) {
                        $config->active_cycle = (string) $cycleType;
                        $config->save();
                    }
                } catch (\Throwable $e) {}
            }

            $savedEvents[] = $newEvent;
        }

        $this->saveEvents($events);

        return response()->json([
            'status'  => 'success',
            'message' => 'Agenda kalender berhasil disimpan (' . count($savedEvents) . ' tanggal).',
            'data'    => $savedEvents[0] ?? null
        ], 200);
    }

    public function applyCyclePattern(Request $request)
    {
        $events = $this->readEvents();
        $startDateStr = $request->input('start_date', '2026-07-01');
        $endDateStr = $request->input('end_date', '2027-06-30');

        $start = Carbon::parse($startDateStr)->startOfWeek();
        $end = Carbon::parse($endDateStr)->endOfWeek();

        // 2 minggu Putaran 1, 2 minggu Putaran 2, berulang
        $curr = $start->copy();
        $weekIndex = 0;

        $cycleMap = [];
        while ($curr->lte($end)) {
            $cycle = (intdiv($weekIndex, 2) % 2 === 0) ? '1' : '2';
            $cycleMap[$curr->toDateString()] = $cycle;
            $curr->addDay();
            if ($curr->dayOfWeek === 1) { // Setiap hari Senin
                $weekIndex++;
            }
        }

        foreach ($events as $k => $e) {
            $d = $e['date'] ?? null;
            if ($d && isset($cycleMap[$d])) {
                $events[$k]['cycle_type'] = $cycleMap[$d];
            }
        }

        $this->saveEvents($events);

        // Update active_cycle jika hari ini ada di cycleMap
        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();
        if (isset($cycleMap[$todayStr])) {
            try {
                $config = SystemConfiguration::first();
                if ($config) {
                    $config->active_cycle = (string) $cycleMap[$todayStr];
                    $config->save();
                }
            } catch (\Throwable $e) {}
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pola putaran otomatis 2 minggu Putaran 1 & 2 minggu Putaran 2 berhasil diterapkan!',
            'active_cycle_today' => $cycleMap[$todayStr] ?? '1'
        ], 200);
    }

    public function destroy($id)
    {
        $events = $this->readEvents();
        $filtered = array_values(array_filter($events, function($e) use ($id) {
            return isset($e['id']) && $e['id'] != $id;
        }));

        $this->saveEvents($filtered);

        return response()->json([
            'status'  => 'success',
            'message' => 'Agenda berhasil dihapus.'
        ], 200);
    }

    public function todayConfig()
    {
        $now = Carbon::now('Asia/Jakarta');
        $todayStr = $now->toDateString();
        $dayOfWeek = $now->dayOfWeek; // 0 = Sunday, 6 = Saturday

        $events = $this->readEvents();
        $todayEvent = null;
        foreach ($events as $e) {
            if (isset($e['date']) && $e['date'] === $todayStr) {
                $todayEvent = $e;
                break;
            }
        }

        $isSunday = ($dayOfWeek === 0);
        $isSaturday = ($dayOfWeek === 6);
        $isWeekend = ($isSunday || $isSaturday);

        $hasHolidayEvent = $todayEvent && (!empty($todayEvent['is_holiday']) || ($todayEvent['color'] ?? '') === 'merah');
        $isHoliday = $isWeekend || $hasHolidayEvent;

        $holidayReason = null;
        if ($hasHolidayEvent) {
            $holidayReason = $todayEvent['title'] ?? 'Tanggal Merah (Kalender Indonesia)';
        } elseif ($isSunday) {
            $holidayReason = 'Hari Libur Akhir Pekan (Minggu)';
        } elseif ($isSaturday) {
            $holidayReason = 'Hari Libur Akhir Pekan (Sabtu)';
        }

        $activeCycle = '1';
        try {
            $config = SystemConfiguration::first();
            if ($config) $activeCycle = (string) $config->active_cycle;
        } catch (\Throwable $e) {}

        if ($todayEvent && !empty($todayEvent['cycle_type'])) {
            $activeCycle = (string) $todayEvent['cycle_type'];
        }

        $presensiScheduled = $todayEvent && !empty($todayEvent['presensi_mandiri']['active']);
        $presensiActive = false;
        $presensiStart = '06:30';
        $presensiEnd = '08:00';

        if ($presensiScheduled) {
            $presensiStart = $todayEvent['presensi_mandiri']['start_time'] ?? '06:30';
            $presensiEnd = $todayEvent['presensi_mandiri']['end_time'] ?? '08:00';
            $currentTime = $now->format('H:i');
            if ($currentTime >= $presensiStart && $currentTime <= $presensiEnd) {
                $presensiActive = true;
            }
        }

        // Aturan 7 & 14:
        // - Hari Sabtu, Minggu, atau Tanggal Merah -> input jurnal otomatis non-aktif
        // - Saat presensi mandiri diaktifkan hari ini -> input jurnal otomatis non-aktif
        $isJournalDisabled = $isHoliday || $presensiScheduled;
        if ($presensiScheduled && !$isHoliday) {
            $holidayReason = 'Presensi Mandiri Siswa Sedang Diaktifkan Hari Ini';
        }

        return response()->json([
            'status'               => 'success',
            'date'                 => $todayStr,
            'is_holiday'           => $isHoliday,
            'holiday_reason'       => $holidayReason,
            'is_journal_disabled'  => $isJournalDisabled,
            'active_cycle'         => $activeCycle,
            'presensi_mandiri'     => [
                'is_scheduled'     => $presensiScheduled,
                'is_active_now'    => $presensiActive,
                'start_time'       => $presensiStart,
                'end_time'         => $presensiEnd
            ],
            'today_event'          => $todayEvent
        ], 200);
    }

    public function togglePresensiToday(Request $request)
    {
        $now = Carbon::now('Asia/Jakarta');
        $todayStr = $now->toDateString();
        $events = $this->readEvents();
        $found = false;
        $active = (bool) $request->input('active', true);
        $startTime = $request->input('start_time', '06:00');
        $endTime = $request->input('end_time', '23:59');

        foreach ($events as $k => $e) {
            if (isset($e['date']) && $e['date'] === $todayStr) {
                $events[$k]['presensi_mandiri'] = [
                    'active'     => $active,
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                ];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $events[] = [
                'id'               => 'cal_' . time() . '_' . rand(100, 999),
                'date'             => $todayStr,
                'title'            => 'KBM & Presensi Mandiri',
                'category'         => 'kbm',
                'description'      => 'Aktivasi Presensi Mandiri Siswa',
                'is_holiday'       => false,
                'cycle_type'       => '2',
                'target_tingkat'   => 'all',
                'target_rombel'    => 'all',
                'presensi_mandiri' => [
                    'active'     => $active,
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                ],
                'created_at'       => $now->toDateTimeString()
            ];
        }

        $this->saveEvents($events);

        return response()->json([
            'status'  => 'success',
            'message' => $active ? 'Presensi Mandiri hari ini berhasil DIAKTIFKAN!' : 'Presensi Mandiri hari ini DINONAKTIFKAN.',
            'active'  => $active,
            'today'   => $todayStr
        ], 200);
    }
}

