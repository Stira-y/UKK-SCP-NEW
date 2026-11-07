#!/usr/bin/env python3
"""
scripts/parse_schedule_pdf.py
Script utilitas Python untuk mengekstrak jadwal KBM guru dari file PDF (format FET 5.44.9)
dan menyimpannya ke format JSON yang siap diimpor ke SCP Sekolah API.

Penggunaan:
  python scripts/parse_schedule_pdf.py [path_file.pdf] [output.json] [cycle_type: 1|2]
"""

import sys
import os
import re
import json

try:
    import pypdf
except ImportError:
    print("Error: Library 'pypdf' belum terpasang. Jalankan: pip install pypdf")
    sys.exit(1)

TIME_END_MAP = {
    '07:15': '08:00',
    '08:00': '08:45',
    '08:45': '09:30',
    '09:45': '10:30',
    '10:30': '11:15',
    '11:15': '12:00',
    '12:30': '13:15',
    '13:15': '14:00',
    '14:00': '14:45',
    '14:45': '15:30'
}

TIME_SLOTS_ORDERED = ['07:15', '08:00', '08:45', '09:45', '10:30', '11:15', '12:30', '13:15', '14:00', '14:45']

def parse_schedule_pdf(pdf_path, cycle_type='1'):
    if not os.path.exists(pdf_path):
        print(f"File '{pdf_path}' tidak ditemukan.")
        return []

    reader = pypdf.PdfReader(pdf_path)
    all_schedules = []

    for page_idx in range(2, len(reader.pages)):
        page = reader.pages[page_idx]
        items = []

        def visitor(text, cm, tm, fontDict, fontSize):
            t = text.strip()
            if t:
                items.append({
                    'x': tm[4],
                    'y': tm[5],
                    'text': t
                })

        page.extract_text(visitor_text=visitor)

        # 1. Deteksi nama guru
        teacher_name = None
        for it in items:
            m = re.search(r'^\s*\"?(\d+[\.\,][^\"]+)\"?\s*$', it['text'])
            if m:
                teacher_name = re.sub(r'^\d+[\.\,]\s*', '', m.group(1)).strip(' "')
                break

        if not teacher_name:
            continue

        # 2. Deteksi header hari
        day_labels = [it for it in items if it['text'].lower() in ['senin', 'selasa', 'rabu', 'kamis', 'jumat']]
        day_labels.sort(key=lambda it: it['x'])
        if len(day_labels) < 5:
            continue

        # 3. Deteksi label waktu
        time_labels = [it for it in items if re.match(r'^\d{2}:\d{2}$', it['text'])]
        time_labels.sort(key=lambda it: it['y'])
        if len(time_labels) < 5:
            continue

        # 4. Filter elemen isi tabel
        cell_items = []
        for it in items:
            t = it['text']
            if it['x'] < min(d['x'] for d in day_labels) - 40:
                continue
            if 'SMK NEGERI' in t or teacher_name in t or t.lower() in ['senin', 'selasa', 'rabu', 'kamis', 'jumat', '---', '-x-'] or 'Jadwal dihasilkan' in t or 'Kembali ke atas' in t:
                continue
            cell_items.append(it)

        # 5. Kelompokkan ke hari & jam terdekat
        cells = {}
        for it in cell_items:
            closest_day = min(day_labels, key=lambda d: abs(it['x'] - d['x']))
            closest_time = min(time_labels, key=lambda tl: abs(it['y'] - tl['y']))

            day_name = closest_day['text'].capitalize()
            t_start = closest_time['text']

            key = (day_name, t_start)
            if key not in cells:
                cells[key] = []
            cells[key].append(it)

        # 6. Susun jadwal per hari secara berurutan
        for day_label in day_labels:
            day_name = day_label['text'].capitalize()
            prev_record = None

            for t_start in TIME_SLOTS_ORDERED:
                key = (day_name, t_start)
                c_items = cells.get(key, [])
                c_items.sort(key=lambda it: it['y'])
                texts = [it['text'] for it in c_items if it['text'] not in ['---', '-x-']]
                if not texts:
                    prev_record = None
                    continue

                cls_raw = texts[0]
                room_raw = '-'
                mapel_raw = '-'

                is_continuation = bool(
                    prev_record and (
                        re.match(r'^(LAB|RUANG|R\.|BENGKEL|AULA|PERPUS)', cls_raw, re.IGNORECASE) or
                        cls_raw.endswith(')') or
                        not re.search(r'\b(X|XI|XII)\b', cls_raw, re.IGNORECASE)
                    )
                )

                if is_continuation:
                    if re.match(r'^(LAB|RUANG|R\.|BENGKEL|AULA|PERPUS)', cls_raw, re.IGNORECASE):
                        prev_record['ruangan'] = cls_raw
                        room_raw = cls_raw
                        mapel_raw = prev_record['mapel']
                    elif cls_raw.endswith(')'):
                        prev_record['mapel'] += ' ' + cls_raw
                        mapel_raw = prev_record['mapel']
                        if len(texts) > 1 and re.match(r'^(LAB|RUANG|R\.|BENGKEL|AULA|PERPUS)', texts[-1], re.IGNORECASE):
                            prev_record['ruangan'] = texts[-1]
                            room_raw = texts[-1]

                    cls_raw = prev_record['kelas']
                else:
                    if len(texts) > 2 and re.match(r'^(LAB|RUANG|R\.|BENGKEL|AULA|PERPUS)', texts[-1], re.IGNORECASE):
                        room_raw = texts[-1]
                        mapel_raw = ' '.join(texts[1:-1])
                    elif len(texts) > 1:
                        mapel_raw = ' '.join(texts[1:])
                    else:
                        mapel_raw = '-'

                t_end = TIME_END_MAP.get(t_start, '')
                waktu_str = f"{t_start} - {t_end}" if t_end else t_start

                record = {
                    'kelas': cls_raw,
                    'hari': day_name,
                    'waktu': waktu_str,
                    'mapel': mapel_raw,
                    'guru': teacher_name,
                    'ruangan': room_raw,
                    'cycle_type': str(cycle_type)
                }
                all_schedules.append(record)
                prev_record = record

    return all_schedules

if __name__ == '__main__':
    in_pdf = sys.argv[1] if len(sys.argv) > 1 else 'public/templates/TEMPLATE_JADWAL_GURU.pdf'
    out_json = sys.argv[2] if len(sys.argv) > 2 else 'schedules_parsed.json'
    c_type = sys.argv[3] if len(sys.argv) > 3 else '1'

    schedules = parse_schedule_pdf(in_pdf, c_type)
    with open(out_json, 'w', encoding='utf-8') as f:
        json.dump(schedules, f, indent=2, ensure_ascii=False)

    print(f"Selesai! Berhasil mengekstrak {len(schedules)} data jadwal ke '{out_json}'.")
