import sys
import os
import re
import json
import pypdf
from reportlab.lib.pagesizes import letter, landscape, A4
from reportlab.lib import colors
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle

CLASSES = [
    "X RPL 1", "X RPL 2", "X TKJ 1", "X TKJ 2", "X DKV 1", "X DKV 2",
    "X AK 1", "X AK 2", "X AK 3", "X AK 4", "X LPS 1", "X LPS 2",
    "X MP 1", "X MP 2", "X BR 1", "X BR 2", "X BD",
    "XI RPL 1", "XI RPL 2", "XI TKJ 1", "XI TKJ 2", "XI DKV 1", "XI DKV 2",
    "XI AK 1", "XI AK 2", "XI AK 3", "XI AK 4", "XI LPS", "XI MP 1", "XI MP 2", "XI BR 1", "XI BR 2", "XI BD",
    "XII RPL 1", "XII RPL 2", "XII TKJ 1", "XII TKJ 2", "XII DKV 1", "XII DKV 2",
    "XII AK 1", "XII AK 2", "XII AK 3", "XII AK 4", "XII LPS", "XII MP 1", "XII MP 2", "XII BR 1", "XII BR 2", "XII BD"
]

def normalize_room(raw):
    raw = (raw or '').strip()
    if not raw or raw == '-':
        return 'Ruang Kelas'
    # Check L.XX or Lab XX
    m_lab = re.match(r'^L\.(\d+)$', raw, re.I)
    if m_lab:
        return f"Lab {m_lab.group(1)}"
    m_lab_txt = re.match(r'^L\.(.+)$', raw, re.I)
    if m_lab_txt:
        return f"Lab {m_lab_txt.group(1).upper()}"
    if raw.isdigit():
        return f"Ruang {raw}"
    if raw.upper() == 'OR' or raw.upper() == 'OR1':
        return 'Lapangan OR 1'
    if raw.upper() == 'OR2':
        return 'Lapangan OR 2'
    if raw.upper() == 'OR3':
        return 'Lapangan OR 3'
    if raw.upper() == 'HALL':
        return 'Hall'
    return raw

def parse_jadwal_ruangan(ruang_path):
    reader = pypdf.PdfReader(ruang_path)
    room_allocations = {
        'Senin': {c: {} for c in range(48)},
        'Selasa': {c: {} for c in range(48)},
        'Rabu': {c: {} for c in range(48)},
        'Kamis': {c: {} for c in range(48)},
        'Jumat': {c: {} for c in range(48)},
    }
    page_days = [
        ['Senin', 'Selasa'],
        ['Rabu', 'Kamis'],
        ['Jumat']
    ]
    for p_idx in range(min(3, len(reader.pages))):
        page = reader.pages[p_idx]
        lines = []
        def visitor(text, cm, tm, font_dict, font_size):
            if text.strip():
                lines.append((round(tm[5], 1), round(tm[4], 1), text.strip()))
        page.extract_text(visitor_text=visitor)
        
        # Group by Y (tolerance 2.5)
        grouped_y = {}
        for y, x, txt in lines:
            matched_k = None
            for k in grouped_y:
                if abs(k - y) <= 2.5:
                    matched_k = k
                    break
            if matched_k is None:
                matched_k = y
                grouped_y[matched_k] = []
            grouped_y[matched_k].append((x, txt))
            
        days_on_page = page_days[p_idx]
        cur_day_idx = 0
        cur_day = days_on_page[0]
        saw_jam_10 = False
        
        for y_val in sorted(grouped_y.keys(), reverse=True):
            row_items = sorted(grouped_y[y_val], key=lambda it: it[0])
            row_text = ' '.join([it[1] for it in row_items])
            
            if len(days_on_page) > 1 and cur_day_idx == 0 and saw_jam_10:
                if 'LITERASI' in row_text or re.search(r'\b1\s+\d{2}[\.:]\d{2}', row_text) or days_on_page[1] in row_text:
                    cur_day_idx = 1
                    cur_day = days_on_page[1]
                    saw_jam_10 = False
                    
            m_jam = re.search(r'\b([1-9]|10)\s+(\d{2}[\.:]\d{2})', row_text)
            if m_jam:
                jam_num = int(m_jam.group(1))
                if jam_num == 10:
                    saw_jam_10 = True
                    
                tokens = row_text.split()
                after_idx = 0
                for i, tok in enumerate(tokens):
                    if re.match(r'^\d{2}[\.:]\d{2}', tok) or re.match(r'^[-–]\d{2}', tok) or re.match(r'^\d{4}$', tok):
                        after_idx = i + 1
                room_tokens = tokens[after_idx:]
                if len(room_tokens) >= 48:
                    for c_idx in range(48):
                        room_allocations[cur_day][c_idx][jam_num] = normalize_room(room_tokens[c_idx])
                        
    return room_allocations

def parse_jadwal_kelas(kelas_path):
    reader = pypdf.PdfReader(kelas_path)
    class_schedules = []
    day_names = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat']
    
    for p_idx in range(1, len(reader.pages)):
        c_idx = p_idx - 1
        class_label = CLASSES[c_idx] if c_idx < len(CLASSES) else f'Kelas {c_idx+1}'
        page = reader.pages[p_idx]
        data = page.get_contents().get_data().decode('latin1')
        
        # Horizontal lines
        h_lines = []
        for m in re.finditer(r'([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+re', data):
            x, y, w, h = map(float, m.groups())
            if abs(h - 1) < 0.2 and w > 10:
                h_lines.append((x, y, w))
                
        # Time dividers (left column: x ~ 11, w ~ 41)
        time_dividers = sorted(list(set([round(hl[1], 1) for hl in h_lines if 35 <= hl[2] <= 45 and 5 <= hl[0] <= 15])))
        if len(time_dividers) < 22:
            continue
        jam_bounds = [(j, time_dividers[2 + (j-1)*2], time_dividers[3 + (j-1)*2]) for j in range(1, 11)]
        
        # Top 5 columns
        top_y = jam_bounds[0][1]
        top_cols = sorted([hl for hl in h_lines if abs(hl[1] - top_y) <= 4 and hl[0] >= 50 and hl[2] >= 50], key=lambda it: it[0])
        unique_cols = []
        for c in top_cols:
            if not unique_cols or c[0] - unique_cols[-1][0] > 10:
                unique_cols.append(c)
                
        items = []
        def visitor(text, cm, tm, font_dict, font_size):
            if text.strip():
                items.append((round(tm[4], 1), round(tm[5], 1), text.strip()))
        page.extract_text(visitor_text=visitor)
        
        sched = {}
        for idx, (col_x, col_y, col_w) in enumerate(unique_cols[:5]):
            d_name = day_names[idx]
            sched[d_name] = {}
            
            # Find dividers inside this specific column
            col_divs = set([round(hl[1], 0) for hl in h_lines if abs(hl[0] - col_x) <= 4 and abs(hl[2] - col_w) <= 6])
            col_divs.add(round(jam_bounds[0][1], 0))  # Top of Jam 1
            col_divs.add(round(jam_bounds[-1][2], 0)) # Bottom of Jam 10
            sorted_divs = sorted(list(col_divs))
            
            for c_i in range(len(sorted_divs) - 1):
                yt, yb = sorted_divs[c_i], sorted_divs[c_i + 1]
                if yb - yt < 15:
                    continue
                
                jams = [jb[0] for jb in jam_bounds if yt - 5 <= (jb[1] + jb[2]) / 2 <= yb + 5]
                if not jams:
                    continue
                
                cell_txts = [it[2] for it in items if col_x - 3 <= it[0] <= col_x + col_w + 3 and yt - 3 <= it[1] <= yb + 3 and it[2] not in day_names]
                
                mapel = []
                guru = []
                in_quotes = False
                for t in cell_txts:
                    if t == '-x-':
                        mapel.append('-x-')
                        continue
                    if '"' in t:
                        cleaned = t.replace('"', '').strip()
                        if cleaned:
                            guru.append(cleaned)
                        if t.count('"') % 2 == 1:
                            in_quotes = not in_quotes
                    elif in_quotes:
                        guru.append(t)
                    elif re.match(r'^(LAB|OR|HALL|RUANG|Lab)\b', t, re.I):
                        pass
                    else:
                        mapel.append(t)
                        
                mapel_str = ' '.join(mapel).strip()
                guru_str = ' '.join(guru).strip()
                guru_clean = re.sub(r'^\d+[\.,]\s*', '', guru_str).strip()
                
                for j in jams:
                    sched[d_name][j] = {
                        'mapel': mapel_str if mapel_str else ('-x-' if cell_txts == ['-x-'] else ''),
                        'guru': guru_clean if guru_clean else '-'
                    }
                    
        class_schedules.append({
            'class_index': c_idx,
            'class_name': class_label,
            'schedule': sched
        })
        
    return class_schedules

def main():
    if len(sys.argv) < 3:
        print("Usage: python pdf_schedule_parser.py <path_jadwal_kelas> <path_jadwal_ruang> [cycle_type] [is_upacara] [out_json_path]")
        sys.exit(1)
        
    kelas_path = sys.argv[1]
    ruang_path = sys.argv[2]
    cycle_type = sys.argv[3] if len(sys.argv) > 3 else "2"
    is_upacara = sys.argv[4].lower() in ['true', '1', 'yes'] if len(sys.argv) > 4 else False
    out_json = sys.argv[5] if len(sys.argv) > 5 else None
    
    room_allocs = parse_jadwal_ruangan(ruang_path)
    class_scheds = parse_jadwal_kelas(kelas_path)
    
    TIME_TABLES = {
        'reguler': [
            {'type': 'jam', 'jam': 1, 'waktu': '07:15 - 07:55'},
            {'type': 'jam', 'jam': 2, 'waktu': '07:55 - 08:35'},
            {'type': 'jam', 'jam': 3, 'waktu': '08:35 - 09:15'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT I', 'waktu': '09:15 - 09:30'},
            {'type': 'jam', 'jam': 4, 'waktu': '09:30 - 10:10'},
            {'type': 'jam', 'jam': 5, 'waktu': '10:10 - 10:50'},
            {'type': 'jam', 'jam': 6, 'waktu': '10:50 - 11:30'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT II', 'waktu': '11:30 - 12:30'},
            {'type': 'jam', 'jam': 7, 'waktu': '12:30 - 13:10'},
            {'type': 'jam', 'jam': 8, 'waktu': '13:10 - 13:50'},
            {'type': 'jam', 'jam': 9, 'waktu': '13:50 - 14:30'},
            {'type': 'jam', 'jam': 10, 'waktu': '14:30 - 15:10'},
        ],
        'senin_upacara': [
            {'type': 'special', 'label': 'UPACARA BENDERA', 'waktu': '07:00 - 08:00', 'ruangan': 'Lapangan Upacara'},
            {'type': 'jam', 'jam': 1, 'waktu': '08:00 - 08:35'},
            {'type': 'jam', 'jam': 2, 'waktu': '08:35 - 09:10'},
            {'type': 'jam', 'jam': 3, 'waktu': '09:10 - 09:45'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT I', 'waktu': '09:45 - 10:00'},
            {'type': 'jam', 'jam': 4, 'waktu': '10:00 - 10:35'},
            {'type': 'jam', 'jam': 5, 'waktu': '10:35 - 11:10'},
            {'type': 'jam', 'jam': 6, 'waktu': '11:10 - 11:45'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT II', 'waktu': '11:45 - 12:45'},
            {'type': 'jam', 'jam': 7, 'waktu': '12:45 - 13:25'},
            {'type': 'jam', 'jam': 8, 'waktu': '13:25 - 14:00'},
            {'type': 'jam', 'jam': 9, 'waktu': '14:00 - 14:35'},
            {'type': 'jam', 'jam': 10, 'waktu': '14:35 - 15:10'},
        ],
        'selasa': [
            {'type': 'special', 'label': 'LITERASI', 'waktu': '07:00 - 07:30', 'match_jam1': True},
            {'type': 'jam', 'jam': 1, 'waktu': '07:30 - 08:10'},
            {'type': 'jam', 'jam': 2, 'waktu': '08:10 - 08:50'},
            {'type': 'jam', 'jam': 3, 'waktu': '08:50 - 09:30'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT I', 'waktu': '09:30 - 09:45'},
            {'type': 'jam', 'jam': 4, 'waktu': '09:45 - 10:25'},
            {'type': 'jam', 'jam': 5, 'waktu': '10:25 - 11:05'},
            {'type': 'jam', 'jam': 6, 'waktu': '11:05 - 11:45'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT II', 'waktu': '11:45 - 12:45'},
            {'type': 'jam', 'jam': 7, 'waktu': '12:45 - 13:25'},
            {'type': 'jam', 'jam': 8, 'waktu': '13:25 - 14:00'},
            {'type': 'jam', 'jam': 9, 'waktu': '14:00 - 14:35'},
            {'type': 'jam', 'jam': 10, 'waktu': '14:35 - 15:10'},
        ],
        'jumat': [
            {'type': 'jam', 'jam': 1, 'waktu': '07:15 - 07:55'},
            {'type': 'jam', 'jam': 2, 'waktu': '07:55 - 08:35'},
            {'type': 'jam', 'jam': 3, 'waktu': '08:35 - 09:15'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT I', 'waktu': '09:15 - 09:30'},
            {'type': 'jam', 'jam': 4, 'waktu': '09:30 - 10:10'},
            {'type': 'jam', 'jam': 5, 'waktu': '10:10 - 10:50'},
            {'type': 'jam', 'jam': 6, 'waktu': '10:50 - 11:25'},
            {'type': 'istirahat', 'label': 'ISTIRAHAT II', 'waktu': '11:25 - 12:40'},
            {'type': 'jam', 'jam': 7, 'waktu': '12:40 - 13:15'},
            {'type': 'jam', 'jam': 8, 'waktu': '13:15 - 13:50'},
        ]
    }
    
    merged_data = []
    db_items = []
    
    for c_obj in class_scheds:
        c_idx = c_obj['class_index']
        c_name = c_obj['class_name']
        c_sched = c_obj['schedule']
        
        days_output = {}
        for day in ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat']:
            days_output[day] = []
            
            if day == 'Senin':
                slot_defs = TIME_TABLES['senin_upacara'] if is_upacara else TIME_TABLES['reguler']
            elif day == 'Selasa':
                slot_defs = TIME_TABLES['selasa']
            elif day == 'Jumat':
                slot_defs = TIME_TABLES['jumat']
            else:
                slot_defs = TIME_TABLES['reguler']
                
            jam1_room = room_allocs.get(day, {}).get(c_idx, {}).get(1, 'Ruang Kelas')
            
            for s_def in slot_defs:
                stype = s_def['type']
                if stype == 'istirahat':
                    days_output[day].append({
                        'type': 'istirahat',
                        'jam': '-',
                        'waktu': s_def['waktu'],
                        'mapel': s_def['label'],
                        'guru': '-',
                        'ruangan': 'Ruang Kelas'
                    })
                elif stype == 'special':
                    room = s_def.get('ruangan', 'Lapangan Upacara')
                    if s_def.get('match_jam1'):
                        room = jam1_room
                    days_output[day].append({
                        'type': 'special',
                        'jam': '-',
                        'waktu': s_def['waktu'],
                        'mapel': s_def['label'],
                        'guru': '-',
                        'ruangan': room
                    })
                elif stype == 'jam':
                    j = s_def['jam']
                    waktu = s_def['waktu']
                    room = room_allocs.get(day, {}).get(c_idx, {}).get(j, 'Ruang Kelas')
                    kbm_info = c_sched.get(day, {}).get(j, {'mapel': 'Pelajaran', 'guru': '-'})
                    
                    days_output[day].append({
                        'type': 'kbm',
                        'jam': j,
                        'waktu': waktu,
                        'mapel': kbm_info['mapel'],
                        'guru': kbm_info['guru'],
                        'ruangan': room
                    })
                    
                    if kbm_info['mapel'] and kbm_info['mapel'] != '-x-':
                        db_items.append({
                            'kelas': c_name,
                            'hari': day,
                            'waktu': waktu,
                            'mapel': kbm_info['mapel'],
                            'guru': kbm_info['guru'],
                            'ruangan': room,
                            'cycle_type': str(cycle_type)
                        })
                        
        merged_data.append({
            'class_index': c_idx,
            'class_name': c_name,
            'days': days_output
        })
        
    result_payload = {
        'status': 'success',
        'classes_count': len(merged_data),
        'total_db_items': len(db_items),
        'db_items': db_items,
        'merged_data': merged_data
    }
    
    if out_json:
        with open(out_json, 'w', encoding='utf-8') as f:
            json.dump(result_payload, f, ensure_ascii=False)
        print(f"OUTPUT_SAVED: {out_json}")
    else:
        print(json.dumps(result_payload, ensure_ascii=False))

if __name__ == '__main__':
    main()
