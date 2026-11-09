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
            'date'  => 'required|date_format:Y-m-d',
            'title' => 'required|string|max:255',
        ]);

        $events = $this->readEvents();
        $date = $request->input('date');

        $isHoliday = (bool) $request->input('is_holiday', false);
        $category = $request->input('category', $isHoliday ? 'libur' : 'ujian');

        $newEvent = [
            'id'               => 'cal_' . time() . '_' . rand(100, 999),
            'date'             => $date,
            'title'            => $request->input('title'),
            'category'         => $category,
            'description'      => $request->input('description', ''),
            'is_holiday'       => $isHoliday,
            'cycle_type'       => $request->input('cycle_type', '1'),
            'target_tingkat'   => $request->input('target_tingkat', 'all'),
            'target_rombel'    => $request->input('target_rombel', 'all'),
            'presensi_mandiri' => [
                'active'     => (bool) $request->input('presensi_mandiri_active', false),
                'start_time' => $request->input('presensi_mandiri_start', '06:30'),
                'end_time'   => $request->input('presensi_mandiri_end', '07:30'),
            ],
            'created_at'       => Carbon::now()->toDateTimeString()
        ];

        // Gantikan jika ada event di tanggal yang sama, atau tambahkan
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

        $this->saveEvents($events);

        // Jika hari ini tanggalnya sama dengan event dan ada cycle_type, sinkronkan ke SystemConfiguration
        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();
        if ($date === $todayStr && !empty($newEvent['cycle_type'])) {
            try {
                $config = SystemConfiguration::first();
                if ($config) {
                    $config->active_cycle = (string) $newEvent['cycle_type'];
                    $config->save();
                }
            } catch (\Throwable $e) {}
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Agenda kalender berhasil disimpan.',
            'data'    => $newEvent
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

        $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);
        $isHoliday = $isWeekend || ($todayEvent && !empty($todayEvent['is_holiday']));

        $activeCycle = '1';
        try {
            $config = SystemConfiguration::first();
            if ($config) $activeCycle = (string) $config->active_cycle;
        } catch (\Throwable $e) {}

        if ($todayEvent && !empty($todayEvent['cycle_type'])) {
            $activeCycle = (string) $todayEvent['cycle_type'];
        }

        $presensiActive = false;
        $presensiStart = '06:30';
        $presensiEnd = '07:30';

        if ($todayEvent && isset($todayEvent['presensi_mandiri']) && !empty($todayEvent['presensi_mandiri']['active'])) {
            $presensiStart = $todayEvent['presensi_mandiri']['start_time'] ?? '06:30';
            $presensiEnd = $todayEvent['presensi_mandiri']['end_time'] ?? '07:30';
            $currentTime = $now->format('H:i');
            if ($currentTime >= $presensiStart && $currentTime <= $presensiEnd) {
                $presensiActive = true;
            }
        }

        return response()->json([
            'status'               => 'success',
            'date'                 => $todayStr,
            'is_holiday'           => $isHoliday,
            'holiday_reason'       => $isHoliday ? ($todayEvent['title'] ?? ($isWeekend ? 'Hari Libur Akhir Pekan' : 'Hari Libur')) : null,
            'is_journal_disabled'  => $isHoliday,
            'active_cycle'         => $activeCycle,
            'presensi_mandiri'     => [
                'is_scheduled'     => $todayEvent && !empty($todayEvent['presensi_mandiri']['active']),
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

