<p align="center">
  <img src="public/icons/icon-192.png" width="100" height="100" alt="Logo SCP Sekolah" style="border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
</p>

<h1 align="center">Sistem SCP Sekolah</h1>
<h3 align="center">Sistem Catatan Presensi, Pelanggaran, Jurnal Mengajar & Penjadwalan Terpadu</h3>

<p align="center">
  <em>Platform manajemen kegiatan belajar mengajar berbasis web & Progressive Web App (PWA) untuk menyederhanakan administrasi akademik, pemantauan kehadiran siswa real-time, pengisian jurnal guru, serta penjadwalan pelajaran otomatis anti-bentrok.</em>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white" alt="Tailwind CSS">
  <img src="https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white" alt="PWA">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License">
</p>

---

## 📑 Daftar Isi
1. [Tentang Sistem](#-tentang-sistem)
2. [Fitur Unggulan](#-fitur-unggulan)
3. [Peran Pengguna (Role-Based Access Control)](#-peran-pengguna-role-based-access-control)
4. [Teknologi & Arsitektur (Tech Stack)](#-teknologi--arsitektur-tech-stack)
5. [Struktur Direktori Proyek](#-struktur-direktori-proyek)
6. [Skema & Entitas Database](#-skema--entitas-database)
7. [Panduan Instalasi Lokal](#-panduan-instalasi-lokal)
8. [Panduan Deployment cPanel / Shared Hosting](#-panduan-deployment-cpanel--shared-hosting)
9. [Standar UI/UX & Interaksi Sistem](#-standar-uiux--interaksi-sistem)
10. [Lisensi & Pengembang](#-lisensi--pengembang)

---

## 🏫 Tentang Sistem

**Sistem SCP Sekolah** adalah solusi sistem informasi akademik digital yang dirancang untuk mendukung operasional sekolah modern (khususnya SMK/SMA). Sistem ini mengintegrasikan seluruh lini kegiatan belajar mengajar: mulai dari perancangan jadwal pelajaran per putaran, absensi harian siswa oleh guru pengajar, pelaporan jurnal materi pembelajaran, pengajuan surat izin mandiri oleh siswa, hingga pengawasan perilaku dan kehadiran oleh Wali Kelas serta Guru BK (Bimbingan Konseling).

Aplikasi dibangun dengan arsitektur **decoupled**: Backend bertindak sebagai penyedia **RESTful API** berbasis Laravel dengan autentikasi **Laravel Sanctum**, sedangkan Frontend dibangun menggunakan pendekatan modern JavaScript ES6+, Tailwind CSS, serta kemampuan **Progressive Web App (PWA)** sehingga dapat diakses cepat melalui peramban desktop maupun ponsel tanpa perlu mengunduh aplikasi terpisah dari app store.

---

## ⚡ Fitur Unggulan

- **Anti-Bentrok Auto-Schedule Generator**: Algoritma penjadwalan cerdas untuk menyusun jam mengajar per kelas dan per putaran waktu secara otomatis tanpa terjadi tabrakan ruang atau guru.
- **Smart Excel Importer with Batching**: Impor massal data ratusan guru dan siswa dalam hitungan detik. Menggunakan SheetJS dengan deteksi baris header otomatis dan pemrosesan chunk (batching) untuk mencegah server timeout.
- **Real-time Attendance & Student Excuse Tracking**: Presensi digital per jam pelajaran dengan kalkulasi status kehadiran (Hadir, Sakit, Izin, Alpa/Bolos). Siswa dapat mengunggah bukti surat izin secara online untuk diverifikasi oleh Wali Kelas/BK.
- **Digital Teaching Journal (Jurnal Mengajar)**: Dokumentasi materi pembelajaran, pencapaian kompetensi, dan catatan evaluasi harian kelas yang transparan dan dapat dipantau langsung oleh Waka Kurikulum.
- **PWA (Progressive Web App)**: Mendukung penginstalan di layar beranda (Add to Home Screen), caching aset otomatis via Service Worker, dan antarmuka yang sangat responsif di perangkat seluler.
- **Email OTP Verification**: Konfirmasi pembaruan data sensitif pengguna (seperti penggantian alamat surel atau kata sandi) wajib melalui verifikasi kode OTP berbasis email demi keamanan tingkat tinggi.
- **Custom Confirmation & Notification Modals**: Antarmuka bersih tanpa dialog bawaan browser (`alert`/`confirm`), dilengkapi sistem modal konfirmasi "Yakin" & "Tidak" yang aman dan ramah pengguna.

---

## 👥 Peran Pengguna (Role-Based Access Control)

Sistem membagi hak akses ke dalam 4 (empat) peran utama:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        SISTEM SCP SEKOLAH                              │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
       ┌─────────────────┬──────────┴──────────┬─────────────────┐
       ▼                 ▼                     ▼                 ▼
 ┌──────────┐      ┌──────────┐          ┌──────────┐      ┌──────────┐
 │  ROLE 1  │      │  ROLE 2  │          │  ROLE 3  │      │  ROLE 4  │
 │   WAKA   │      │   GURU   │          │ WALIKELAS│      │  SISWA   │
 │ KURIKULUM│      │ PENGAJAR │          │   & BK   │      │          │
 └──────────┘      └──────────┘          └──────────┘      └──────────┘
```

### 1. Role 1 — Waka Kurikulum & Administrator
- **Dashboard Statistik**: Memantau metrik total guru, siswa, kelas, persentase kehadiran sekolah hari ini, dan status putaran jadwal.
- **Kelola Jadwal Pelajaran**:
  - Generator jadwal otomatis anti-bentrok.
  - Impor jadwal via Excel atau PDF.
  - Penguncian jadwal (*Lock/Unlock Schedule*), pengaturan putaran aktif, dan ekspor jadwal gabungan.
- **Kelola Data Guru & Pegawai**:
  - Penambahan guru, edit jabatan (Guru Normada, Guru Produktif, Wali Kelas, Guru BK), dan mapel.
  - Impor data guru dari format Excel.
  - Pengarsipan dan pemensiunan guru.
- **Kelola Data Siswa**:
  - Manajemen siswa per rombel/kelas, impor siswa dari Excel.
  - Fitur **Naikkan Kelas Otomatis** (Promote) dan kelulusan siswa tingkat akhir.
  - Pengarsipan siswa yang dikeluarkan/pindah.
- **Kelola Ruangan & Kelas**: Manajemen ruang teori, laboratorium, bengkel, dan master data rombel.
- **Monitoring Jurnal & Rekap Presensi**: Pemantauan jurnal mengajar guru dan rekap kehadiran komprehensif seluruh tingkatan kelas.

### 2. Role 2 — Guru Pengajar
- **Dashboard Guru**: Melihat jadwal mengajar aktif hari ini, ruang kelas, dan jam pelajaran berikutnya.
- **Presensi Siswa**: Melakukan checklist absensi siswa (Hadir, Sakit, Izin, Alpa) pada jam pelajarannya.
- **Pengisian Jurnal Mengajar**: Mencatat kompetensi dasar, topik bahasan, jumlah siswa hadir, dan kendala kelas.
- **Rekap Riwayat**: Melihat riwayat presensi dan jurnal yang pernah diisi sebelumnya.

### 3. Role 3 — Wali Kelas & Guru BK (Kesiswaan)
- **Dashboard Pembinaan**: Memantau tingkat kehadiran kelas binaan dan daftar siswa yang sering tidak hadir (alpa berturut-turut).
- **Persetujuan Surat Izin**: Memverifikasi pengajuan surat izin dan surat sakit yang dikirim siswa beserta bukti fotonya.
- **Presensi & Jurnal Kelas Binaan**: Mengisi absensi dan catatan konseling/bimbingan siswa.
- **Rekap Kehadiran Siswa**: Rekapitulasi absensi bulanan untuk pelaporan wali murid.

### 4. Role 4 — Siswa
- **Dashboard Siswa**: Melihat jadwal pelajaran kelas harian dan rekap persentase kehadiran pribadi.
- **Pengajuan Izin Online**: Mengajukan izin tidak masuk sekolah (Sakit / Izin Acara) dengan melampirkan foto surat dokter atau surat orang tua.
- **Pelacakan Status Izin**: Notifikasi real-time apakah pengajuan izin berstatus *Menunggu*, *Disetujui*, atau *Ditolak*.

---

## 🛠 Teknologi & Arsitektur (Tech Stack)

### Backend
- **Framework**: Laravel 11 / 13 (PHP 8.2+)
- **API Security & Auth**: Laravel Sanctum (Bearer Token)
- **Database**: MySQL 8.0+ / MariaDB 10.4+
- **ORM**: Eloquent ORM dengan relasi model terindeks
- **Mailing**: SMTP Mailer untuk pengiriman kode OTP verifikasi

### Frontend
- **Markup & Styling**: HTML5, Tailwind CSS, FontAwesome 6
- **Scripting**: Modern Vanilla JavaScript (Async/Await, Fetch API, ES Modules)
- **Data & Excel Processing**: SheetJS (`xlsx.full.min.js`)
- **PDF Generation**: HTML2Canvas & jsPDF untuk ekspor cetak
- **Offline & Caching**: Service Worker (PWA Manifest)

---

## 📁 Struktur Direktori Proyek

```plaintext
ukk-scp-new/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/                 # Controller REST API Terpusat
│   │   │       ├── AttendanceController.php      # Logika presensi & rekap absensi
│   │   │       ├── AuthController.php            # Login, registrasi, sesi, logout
│   │   │       ├── ForgotPasswordController.php  # Reset password via email
│   │   │       ├── JournalController.php         # Jurnal mengajar harian guru
│   │   │       ├── LeaveRequestController.php    # Pengajuan & verifikasi surat izin
│   │   │       ├── RoomController.php            # Manajemen ruangan & lab
│   │   │       ├── ScheduleController.php        # Generator jadwal anti-bentrok & import
│   │   │       ├── SchoolClassController.php     # Manajemen kelas & rombel
│   │   │       └── UserController.php            # Manajemen guru, siswa, profil, OTP
│   │   └── Middleware/              # Sanctum & API Middleware
│   └── Models/                      # Model Eloquent (User, Schedule, Attendance, dll)
├── database/
│   ├── migrations/                  # Skema migrasi database
│   └── seeders/                     # Seeder data awal (Role, Admin, Kelas)
├── public/                          # Direktori Publik Web & UI Frontend
│   ├── icons/                       # Aset logo & ikon PWA
│   ├── js/                          # Pustaka skrip frontend bersama
│   │   ├── global-layout.js         # Header, sidebar, avatar, dan profil dinamis
│   │   ├── pwa.js                   # Modal kustom pop-up & registrasi service worker
│   │   └── pw-waka.js               # Modal verifikasi OTP & ganti credential
│   ├── login/                       # Halaman autentikasi login terpusat
│   │   └── login.html
│   ├── role 1 waka/                 # Panel Antarmuka Waka Kurikulum & Admin
│   │   ├── dashboard1.html
│   │   ├── kelola data user.html    # Manajemen & impor siswa
│   │   ├── kelola guru.html         # Manajemen & impor guru
│   │   ├── kelola jadwal.html       # Penjadwalan & auto generator
│   │   ├── kelola jurnal.html       # Monitoring jurnal guru
│   │   ├── kelola kelas.html        # Manajemen rombel
│   │   ├── kelola ruangan.html      # Manajemen ruang kelas / lab
│   │   ├── kelulusan.html           # Proses kelulusan siswa tingkat akhir
│   │   └── rekap kehadiran siswa.html
│   ├── role 2 guru/                 # Panel Antarmuka Guru Pengajar
│   │   ├── dashboard2.html
│   │   ├── jurnal1.html             # Pengisian jurnal KBM
│   │   ├── presensi1.html           # Checklist absensi siswa
│   │   ├── rekap jurnal1.html
│   │   └── rekap kehadiran siswa1.html
│   ├── role 3 walikelas&bk/         # Panel Antarmuka Wali Kelas & Guru BK
│   │   ├── dashboard3.html
│   │   ├── persetujuan-izin.html    # Verifikasi pengajuan izin siswa
│   │   ├── presensi2.html
│   │   ├── jurnal2.html
│   │   └── rekap kehadiran siswa2.html
│   ├── role 4 siswa/                # Panel Antarmuka Siswa
│   │   ├── dashboard4.html          # Jadwal & riwayat kehadiran siswa
│   │   └── suratizin.html           # Form upload pengajuan izin/sakit
│   ├── service-worker.js            # PWA Service Worker script
│   └── manifest.json                # PWA App Manifest
├── routes/
│   ├── api.php                      # Seluruh rute REST API
│   └── web.php                      # Rute fallback web
├── .env.example                     # Template variabel lingkungan
├── composer.json                    # Dependensi paket PHP
├── README.md                        # Dokumentasi resmi proyek ini
└── ukk-scp-new.sql                  # Dump database MySQL awal
```

---

## 🗄 Skema & Entitas Database

Sistem ini didukung oleh relasi tabel terstruktur:

| Tabel | Keterangan |
| :--- | :--- |
| `users` | Menyimpan kredensial, identitas (NIP/NIS/NISN), role (`id_role`), mapel, foto avatar, dan bio. |
| `roles` | Daftar 4 role sistem: `1` (Waka), `2` (Guru), `3` (Kesiswaan/BK/Wali Kelas), `4` (Siswa). |
| `classes` | Data rombongan belajar (Tingkat: X, XI, XII; Jurusan; Nomor Rombel). |
| `rooms` | Master data ruangan pembelajaran, laboratorium, atau bengkel kejuruan. |
| `schedules` | Jadwal KBM per putaran, hari, jam pelajaran, kelas, guru pengampu, mapel, dan ruang. |
| `attendances` | Catatan kehadiran siswa per sesi pelajaran (`Hadir`, `Sakit`, `Izin`, `Alpa`). |
| `journals` | Catatan jurnal mengajar guru per sesi (topik, materi, ringkasan, evaluasi). |
| `leave_requests` | Data permohonan izin/sakit siswa, tanggal, alasan, lampiran foto surat, dan status persetujuan. |
| `counselor_classes`| Relasi penugasan Guru BK atau Wali Kelas terhadap kelas-kelas binaannya. |
| `personal_access_tokens` | Token autentikasi bearer Laravel Sanctum untuk sesi login aktif. |

---

## 🚀 Panduan Instalasi Lokal

### 1. Prasyarat Sistem
- **PHP** minimal versi **8.2** (disarankan **8.3+**) dengan ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`.
- **MySQL** / MariaDB.
- **Composer** (versi 2.x).
- **Web Server**: Apache / Nginx (Laragon sangat disarankan di Windows).

### 2. Langkah Instalasi
1. **Clone Repositori**:
   ```bash
   git clone https://github.com/Stira-y/UKK-SCP-NEW.git
   cd ukk-scp-new
   ```

2. **Instal Dependensi Composer**:
   ```bash
   composer install
   ```

3. **Konfigurasi Environment**:
   Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
   Buka file `.env` dan sesuaikan koneksi database serta konfigurasi aplikasi:
   ```ini
   APP_NAME="Sistem SCP Sekolah"
   APP_ENV=local
   APP_KEY=
   APP_DEBUG=true
   APP_URL=http://127.0.0.1:8000

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=ukk_scp_new
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. **Generate Application Key**:
   ```bash
   php artisan key:generate
   ```

5. **Migrasi Database & Seeder**:
   Buat database baru di MySQL bernama `ukk_scp_new`, lalu jalankan:
   ```bash
   php artisan migrate --seed
   ```
   *(Atau impor file `ukk-scp-new.sql` jika ingin menggunakan database yang telah terisi data uji coba).*

6. **Tautkan Storage (Storage Link)**:
   ```bash
   php artisan storage:link
   ```

7. **Jalankan Server Pengembangan**:
   ```bash
   php artisan serve
   ```
   Akses antarmuka aplikasi melalui peramban:
   ```
   http://127.0.0.1:8000/login/login.html
   ```

---

## 🌐 Panduan Deployment cPanel / Shared Hosting

Untuk mendistribusikan aplikasi pada shared hosting cPanel:

1. **Struktur Folder Terpisah (Keamanan Standar)**:
   - Letakkan seluruh file Laravel (folder `app`, `bootstrap`, `config`, `database`, `routes`, `vendor`, dll.) di folder **luar** `public_html`, misalnya di direktori `/home/username/laravel_scp/`.
   - Salin seluruh isi folder `public/` ke dalam direktori `/home/username/public_html/`.
2. **Penyesuaian `public_html/index.php`**:
   Ubah path pemanggilan autoload dan bootstrap agar merujuk ke folder inti Laravel:
   ```php
   require __DIR__.'/../laravel_scp/vendor/autoload.php';
   $app = require_once __DIR__.'/../laravel_scp/bootstrap/app.php';
   ```
3. **Konfigurasi Database di `.env`**:
   Isi nama database, user cPanel, dan password yang telah dibuat pada cPanel MySQL Database Wizard.
4. **Symbolic Link Storage**:
   Buat symlink dari folder `storage/app/public` ke `public_html/storage` melalui terminal cPanel atau via script PHP cron.
5. **Konfigurasi SSL (HTTPS)**:
   Pastikan sertifikat Let's Encrypt / AutoSSL aktif agar komunikasi REST API Sanctum berjalan aman melalui protokol HTTPS.

---

## 🎨 Standar UI/UX & Interaksi Sistem

Aplikasi ini menerapkan aturan antarmuka ketat untuk menjaga kenyamanan dan konsistensi pengguna:
1. **Aturan Konfirmasi Dua Arah ("Yakin" & "Tidak")**:
   Setiap aksi yang mengubah data (simpan, hapus, naikkan kelas, batalkan, impor massal) wajib memicu pop-up konfirmasi `window.showCustomConfirm`.
   - **Yakin**: Memproses aksi ke server API.
   - **Tidak**: Membatalkan aksi dan mengembalikan posisi tampilan ke modal semula tanpa menutup form secara mendadak.
2. **Pesan Validasi Form**:
   Pesan kesalahan validasi input ditampilkan tepat di bawah kolom terkait dengan gaya teks berwarna merah (*red*) dan cetak miring (*italic*).
3. **Bebas Alert Bawaan Browser**:
   Seluruh notifikasi sukses, peringatan, dan kegagalan menggunakan pop-up modal modern dari `pwa.js` (`window.showSuccess`, `window.showWarning`, `window.showError`).

---

## 📄 Lisensi & Pengembang

Proyek ini dikembangkan untuk kebutuhan **Uji Kompetensi Keahlian (UKK)** dan sistem manajemen sekolah kejuruan. Dirilis di bawah lisensi terbuka [MIT License](LICENSE).

<p align="center">
  Dibuat dengan dedikasi untuk efisiensi pendidikan dan digitalisasi sekolah di Indonesia. 🇮🇩
</p>
