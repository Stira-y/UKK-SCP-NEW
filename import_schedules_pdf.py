#!/usr/bin/env python3
"""
Script Import Jadwal & Ruang PDF ke Sistem SCP Sekolah
Mendukung format FET Jadwal Kelas dan Jadwal Ruang SMK Negeri 1 Bantul.
"""

import sys
import os
import re
import json
import urllib.request
import pypdf

RUANG_CLASSES = [
    '10 RPL 1', '10 RPL 2', '10 TKJ 1', '10 TKJ 2', '10 DKV 1', '10 DKV 2', '10 AK 1', '10 AK 2', '10 AK 3', '10 LPS 1', '10 LPS 2', '10 MP 1', '10 MP 2', '10 BR 1', '10 BR 2', '10 BD',
    '11 RPL 1', '11 RPL 2', '11 TKJ 1', '11 TKJ 2', '11 DKV 1', '11 DKV 2', '11 AK 1', '11 AK 2', '11 AK 3', '11 LPS 1', '11 LPS 2', '11 MP 1', '11 MP 2', '11 BR 1', '11 BR 2', '11 BD',
    '12 RPL 1', '12 RPL 2', '12 TKJ 1', '12 TKJ 2', '12 DKV 1', '12 DKV 2', '12 AK 1', '12 AK 2', '12 AK 3', '12 AK 4', '12 LPS', '12 MP 1', '12 MP 2', '12 BR 1', '12 BR 2', '12 BD'
]

def clean_class_key(c):
    c = (c or '').upper().replace(' ', '')
    c = re.sub(r'^10', 'X', c)
    c = re.sub(r'^11', 'XI', c)
    c = re.sub(r'^12', 'XII', c)
    return c

RUANG_KEYS = [clean_class_key(c) for c in RUANG_CLASSES]

def normalize_room_str(raw):
    if not raw or raw == '-':
        return '-'
    tr = raw.strip()
    m_lab = re.match(r'^L\.(\d+)$', tr, re.IGNORECASE)
    if m_lab:
        return f"Lab {m_lab.group(1)}"
    m_lab_code = re.match(r'^L\.(.+)$', tr, re.IGNORECASE)
    if m_lab_code:
        return f"Lab {m_lab_code.group(1).upper()}"
    if re.match(r'^\d+$', tr):
        return f"Ruang {tr}"
    return tr

def parse_schedule_pdfs(ruang_pdf_path, jadwal_pdf_path, cycle_type="1"):
    # 1. Parse Ruang PDF
    reader_ruang = pypdf.PdfReader(ruang_pdf_path)
    room_grid = {"Senin": {}, "Selasa": {}, "Rabu": {}, "Kamis": {}, "Jumat": {}}
    day_pages = [
        [("Senin", 0, 131), ("Selasa", 132, 274)],
        [("Rabu", 0, 131), ("Kamis", 132, 268)],
        [("Jumat", 0, 145)]
    ]

    for p_idx, page in enumerate(reader_ruang.pages[:3]):
        lines = [l.strip() for l in page.extract_text().split('\n') if l.strip()]
        sections = day_pages[p_idx]
        for day_name, start_l, end_l in sections:
            sub_lines = lines[start_l:end_l]
            for line in sub_lines:
                m = re.match(r'^(\d+|IST)\s+(\d{2}[\.:]\d{2})\s*[-–]\s*(\d{2}[\.:]\d{2})\s+(.+)', line)
                if m:
                    jam_id = m.group(1)
                    t_start = m.group(2).replace('.', ':')
                    t_end = m.group(3).replace('.', ':')
                    tokens = m.group(4).split()
                    if len(tokens) >= len(RUANG_KEYS):
                        for c_idx, c_key in enumerate(RUANG_KEYS):
                            if c_key not in room_grid[day_name]:
                                room_grid[day_name][c_key] = {}
                            room_grid[day_name][c_key][jam_id] = {
                                'room': normalize_room_str(tokens[c_idx]),
                                'start': t_start,
                                'end': t_end
                            }

    # 2. Parse Jadwal PDF
    reader_jadwal = pypdf.PdfReader(jadwal_pdf_path)
    print(f"Membaca {len(reader_jadwal.pages)} halaman berkas jadwal...")

    TIME_SLOTS_ORDERED = ['07:15', '08:00', '08:45', '09:45', '10:30', '11:15', '12:30', '13:15', '14:00', '14:45']
    PERIOD_INDEX_MAP = {
        '07:15': 1, '08:00': 2, '08:45': 3,
        '09:45': 4, '10:30': 5, '11:15': 6,
        '12:30': 7, '13:15': 8, '14:00': 9, '14:45': 10
    }
    DEFAULT_BREAK_TIMES = {
        'Senin': {'ist1': '09:45 - 10:00', 'ist2': '11:45 - 12:45'},
        'Selasa': {'ist1': '09:30 - 09:45', 'ist2': '11:45 - 12:45'},
        'Rabu': {'ist1': '09:15 - 09:30', 'ist2': '11:30 - 12:30'},
        'Kamis': {'ist1': '09:15 - 09:30', 'ist2': '11:30 - 12:30'},
        'Jumat': {'ist1': '09:15 - 09:30', 'ist2': '11:25 - 12:40'}
    }

    all_items = []

    for p_num, page in enumerate(reader_jadwal.pages[1:], start=2):
        items = []
        def visitor(text, cm, tm, font_dict, font_size):
            t = text.strip()
            if t:
                items.append({'x': round(tm[4], 1), 'y': round(tm[5], 1), 'text': t})
        page.extract_text(visitor_text=visitor)

        # Detect class name
        detected_class = None
        for it in items:
            m_cls = re.match(r'^(X|XI|XII)\s+[A-Za-z0-9\-]+(\s*\d+)?$', it['text'], re.IGNORECASE)
            if m_cls:
                detected_class = m_cls.group(0).strip()
                break
        if not detected_class:
            continue

        c_key = clean_class_key(detected_class)
        day_x = [('Senin', 100), ('Selasa', 190), ('Rabu', 280), ('Kamis', 370), ('Jumat', 460)]
        time_y = [
            ('07:15', 103), ('08:00', 144), ('08:45', 185),
            ('09:45', 225), ('10:30', 266), ('11:15', 306),
            ('12:30', 347), ('13:15', 388), ('14:00', 428), ('14:45', 469)
        ]

        cells = {}
        for it in items:
            if it['y'] < 90 or it['x'] < 60:
                continue
            if 'SMK NEGERI' in it['text'] or 'Jadwal dihasilkan' in it['text'] or 'Kembali ke atas' in it['text']:
                continue
            d_name = min(day_x, key=lambda d: abs(it['x'] - d[1]))[0]
            t_name = min(time_y, key=lambda t: abs(it['y'] - t[1]))[0]
            k = (d_name, t_name)
            if k not in cells:
                cells[k] = []
            cells[k].append(it['text'])

        for d_name, _ in day_x:
            day_schedules = {}

            for t_start in TIME_SLOTS_ORDERED:
                k = (d_name, t_start)
                texts = cells.get(k, [])
                texts = [x for x in texts if x not in ['---', '-x-']]
                if not texts:
                    continue

                t_guru = '-'
                mapel_parts = []
                for txt in texts:
                    if '"' in txt:
                        t_guru = re.sub(r'^[",\s]+|[",\s]+$', '', txt)
                        t_guru = re.sub(r'^\d+[\.,]\s*', '', t_guru)
                    else:
                        mapel_parts.append(txt)

                mapel_name = " ".join(mapel_parts).strip() or "Pelajaran"
                p_idx = PERIOD_INDEX_MAP.get(t_start, 1)

                room_found = '-'
                day_rooms = room_grid.get(d_name, {}).get(c_key, {})
                if str(p_idx) in day_rooms:
                    room_found = day_rooms[str(p_idx)]['room']
                elif p_idx in day_rooms:
                    room_found = day_rooms[p_idx]['room']

                t_end = "08:00"
                if t_start == "07:15": t_end = "07:55"
                elif t_start == "08:00": t_end = "08:35"
                elif t_start == "08:45": t_end = "09:30"
                elif t_start == "09:45": t_end = "10:30"
                elif t_start == "10:30": t_end = "11:15"
                elif t_start == "11:15": t_end = "12:00"
                elif t_start == "12:30": t_end = "13:15"
                elif t_start == "13:15": t_end = "14:00"
                elif t_start == "14:00": t_end = "14:45"
                elif t_start == "14:45": t_end = "15:30"

                day_schedules[p_idx] = {
                    'kelas': detected_class,
                    'hari': d_name,
                    'waktu': f"{t_start} - {t_end}",
                    'mapel': mapel_name,
                    'guru': t_guru,
                    'ruangan': room_found,
                    'cycle_type': str(cycle_type)
                }

            # Break times
            b_times = DEFAULT_BREAK_TIMES.get(d_name, DEFAULT_BREAK_TIMES['Rabu'])
            p3 = day_schedules.get(3)
            p4 = day_schedules.get(4)
            ist1_room = p3['ruangan'] if (p3 and p4 and p3['ruangan'] == p4['ruangan']) else (p4['ruangan'] if p4 else (p3['ruangan'] if p3 else 'Ruang Kelas'))
            ist1_guru = p3['guru'] if (p3 and p4 and p3['guru'] == p4['guru']) else (p4['guru'] if p4 else (p3['guru'] if p3 else '-'))
            ist1_sched = {
                'kelas': detected_class,
                'hari': d_name,
                'waktu': b_times['ist1'],
                'mapel': 'Istirahat',
                'guru': ist1_guru,
                'ruangan': ist1_room,
                'cycle_type': str(cycle_type)
            }

            p6 = day_schedules.get(6)
            p7 = day_schedules.get(7)
            ist2_room = p6['ruangan'] if (p6 and p7 and p6['ruangan'] == p7['ruangan']) else (p7['ruangan'] if p7 else (p6['ruangan'] if p6 else 'Ruang Kelas'))
            ist2_guru = p6['guru'] if (p6 and p7 and p6['guru'] == p7['guru']) else (p7['guru'] if p7 else (p6['guru'] if p6 else '-'))
            ist2_sched = {
                'kelas': detected_class,
                'hari': d_name,
                'waktu': b_times['ist2'],
                'mapel': 'Istirahat',
                'guru': ist2_guru,
                'ruangan': ist2_room,
                'cycle_type': str(cycle_type)
            }

            for j in range(1, 4):
                if j in day_schedules: all_items.append(day_schedules[j])
            all_items.append(ist1_sched)
            for j in range(4, 7):
                if j in day_schedules: all_items.append(day_schedules[j])
            all_items.append(ist2_sched)
            for j in range(7, 11):
                if j in day_schedules: all_items.append(day_schedules[j])

    print(f"Total berhasil diekstrak: {len(all_items)} jadwal pelajaran dan istirahat.")
    return all_items

if __name__ == '__main__':
    ruang = 'C:/Users/PROBOOK/.gemini/antigravity/brain/6d13234a-7466-4c59-b5c6-9146cb7956e2/.user_uploaded/media_1794110276436_da6c5748.pdf'
    jadwal = 'C:/Users/PROBOOK/.gemini/antigravity/brain/6d13234a-7466-4c59-b5c6-9146cb7956e2/.user_uploaded/media_1794110276344_473477e5.pdf'
    if os.path.exists(ruang) and os.path.exists(jadwal):
        items = parse_schedule_pdfs(ruang, jadwal)
        with open('parsed_schedules.json', 'w', encoding='utf-8') as f:
            json.dump(items, f, indent=2, ensure_ascii=False)
        print("Tersimpan di parsed_schedules.json")
