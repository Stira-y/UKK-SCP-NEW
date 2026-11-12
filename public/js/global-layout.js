/**
 * global-layout.js
 * Modul Universal Layout (Navbar, Sidebar, Profil, Ganti Email, OTP Cooldown & Crop Foto)
 * Kompatibel otomatis untuk Lingkungan Lokal (Laragon) & Hosting Produksi
 */

// 1. DEFINISI GLOBAL SEBELUM DOMContentLoaded (Agar langsung dikenali di seluruh file HTML)
var isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
var API_BASE_URL = window.API_BASE_URL || (isLocalhost 
    ? 'http://localhost/ukk-scp-new/public/api' 
    : `${window.location.origin}/api`);

window.API_BASE_URL = API_BASE_URL;

function initUniversalGlobalLayout() {
    // 0. Auto-Login & Validasi Batas Sesi 30 Hari
    const loginTimestamp = localStorage.getItem('login_timestamp');
    if (loginTimestamp) {
        const THIRTY_DAYS_MS = 30 * 24 * 60 * 60 * 1000;
        if (Date.now() - Number(loginTimestamp) > THIRTY_DAYS_MS) {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user_data');
            localStorage.removeItem('login_timestamp');
            alert('Sesi masuk Anda telah kedaluwarsa (30 hari). Silakan masuk kembali.');
            window.location.href = '../login/login.html';
            return;
        }
    }

    // 0.1 PWA Dynamic Meta & Service Worker Registration
    if (!document.querySelector('link[rel="manifest"]')) {
        const linkManifest = document.createElement('link');
        linkManifest.rel = 'manifest';
        linkManifest.href = '/manifest.json';
        document.head.appendChild(linkManifest);

        const metaTheme = document.createElement('meta');
        metaTheme.name = 'theme-color';
        metaTheme.content = '#4f46e5';
        document.head.appendChild(metaTheme);

        const linkIcon = document.createElement('link');
        linkIcon.rel = 'icon';
        linkIcon.type = 'image/png';
        linkIcon.href = '/icons/icon-192.png';
        document.head.appendChild(linkIcon);

        const linkApple = document.createElement('link');
        linkApple.rel = 'apple-touch-icon';
        linkApple.href = '/icons/icon-192.png';
        document.head.appendChild(linkApple);
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }

    const token = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
    const userData = JSON.parse(localStorage.getItem('user_data') || sessionStorage.getItem('user_data') || '{}');
    const role = Number(userData.id_role); // 1: Waka, 2: Guru, 3: Walikelas/BK/Kesiswaan, 4: Siswa
    const userId = userData.id_user || userData.id || userData.email || 'default';

    const currentPath = decodeURIComponent(window.location.pathname).toLowerCase();
    const isKelolaUserPage = currentPath.includes('kelola data user.html') || currentPath.includes('kelola guru.html') || currentPath.includes('kelola title.html') || currentPath.includes('kelulusan.html');

    let isDropdownOpen = localStorage.getItem('waka_dropdown_open') === 'true' || isKelolaUserPage;

    // Helper: Ambil nama depan saja
    function getFirstName(fullName) {
        if (!fullName) return 'User';
        const clean = String(fullName).trim();
        const parts = clean.split(/\s+/);
        return parts[0] || clean;
    }

    // Helper: Pangkas NIP menjadi 5 digit + ...
    function formatNipOrNis(identity, isStudent) {
        if (!identity || identity === '-') return '-';
        const str = String(identity).trim();
        if (!isStudent && str.length > 5) {
            return str.substring(0, 5) + '...';
        }
        return str;
    }

    if (!document.getElementById('cropper-css')) {
        const link = document.createElement('link');
        link.id = 'cropper-css';
        link.rel = 'stylesheet';
        link.href = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css';
        document.head.appendChild(link);
    }
    if (!window.Cropper) {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js';
        document.head.appendChild(script);
    }

    // 1. Suntikkan Navbar ke placeholder
    const navPlaceholder = document.getElementById('app-navbar');
    if (navPlaceholder) {
        const rawIdentity = role === 4 ? (userData.nis || '-') : (userData.nip || '-');
        const identityVal = formatNipOrNis(rawIdentity, role === 4);
        const displayName = getFirstName(userData.fullname || userData.username);

        navPlaceholder.innerHTML = `
            <nav class="relative z-40 bg-indigo-600 text-white px-4 md:px-6 py-3 flex justify-between items-center shadow-md shrink-0">
                <div class="flex items-center space-x-3 md:space-x-4">
                    <button id="toggleSidebarNav" class="p-2 text-indigo-100 hover:text-white hover:bg-indigo-700/50 rounded-lg focus:outline-none transition md:pointer-events-none md:opacity-50 md:cursor-not-allowed" aria-label="Toggle Navigation">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div id="btnOpenProfile" class="flex items-center space-x-2 bg-indigo-700/60 hover:bg-indigo-700/90 px-3 py-1.5 rounded-full border border-indigo-400/30 cursor-pointer transition">
                        <div class="w-7 h-7 rounded-full bg-indigo-200 text-indigo-800 flex items-center justify-center font-bold text-xs shadow-sm overflow-hidden flex-shrink-0">
                            <img id="navAvatarImg" src="" class="w-full h-full object-cover hidden" alt="Avatar">
                            <svg id="navAvatarSvg" class="w-5 h-5 text-indigo-600 mt-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        </div>
                        <div class="text-xs md:text-sm font-medium flex items-center space-x-1.5 leading-none truncate max-w-[150px]">
                            <span id="navFullname" class="font-semibold truncate leading-none">${displayName}</span>
                            <span class="text-indigo-300">|</span>
                            <span id="navRoleIdentity" class="text-indigo-200">${identityVal}</span>
                        </div>
                    </div>
                    <h1 id="navTitle" class="hidden lg:block text-base md:text-lg font-bold border-l border-indigo-400/40 pl-4">SCP Sekolah - Panel</h1>
                </div>
                <div class="flex items-center space-x-2">
                    <button id="btnLogout" onclick="event.preventDefault(); window.performLogout ? window.performLogout() : null;" class="bg-red-500 hover:bg-red-600 px-3.5 py-1.5 rounded-lg text-sm font-semibold transition cursor-pointer">Keluar</button>
                </div>
            </nav>
        `;
    }

    // 2. Suntikkan Sidebar di bawah Navbar
    const sidebarPlaceholder = document.getElementById('app-sidebar');
    if (sidebarPlaceholder) {
        let menuHTML = '';
        let panelTitle = 'Panel Pengguna';

        if (role === 1) {
            panelTitle = 'Panel Waka / Admin';
            const isKelolaJurnalActive = currentPath.includes('kelola jurnal.html');
            const isJadwalActive = currentPath.includes('kelola jadwal.html');
            const isRekapActive = currentPath.includes('rekap kehadiran siswa.html');
            const dashboardActive = !isKelolaUserPage && !isJadwalActive && !isKelolaJurnalActive && !isRekapActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const kelolaJurnalActive = isKelolaJurnalActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const jadwalActive = isJadwalActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const rekapActive = isRekapActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            
            const dropdownUserMenuClass = isDropdownOpen ? '' : 'hidden';
            const arrowRotateClass = isDropdownOpen ? 'rotate-180' : '';
            
            const isSiswaActive = currentPath.includes('kelola data user.html');
            const isGuruActive = currentPath.includes('kelola guru.html') || currentPath.includes('kelola title.html');
            const isLulusActive = currentPath.includes('kelulusan.html');

            const siswaSubActive = isSiswaActive ? 'bg-indigo-100/80 text-indigo-600 font-bold' : 'text-slate-600 hover:bg-indigo-50 font-semibold';
            const guruSubActive = isGuruActive ? 'bg-indigo-100/80 text-indigo-600 font-bold' : 'text-slate-600 hover:bg-indigo-50 font-semibold';
            const lulusSubActive = isLulusActive ? 'bg-indigo-100/80 text-indigo-600 font-bold' : 'text-slate-600 hover:bg-indigo-50 font-semibold';

            menuHTML = `
                <a href="../role 1 waka/dashboard1.html" class="flex items-center space-x-3 px-4 py-3 ${dashboardActive} rounded-xl transition">
                    <span>Dashboard</span>
                </a>
                <a href="../role 1 waka/kelola jurnal.html" class="flex items-center space-x-3 px-4 py-3 ${kelolaJurnalActive} rounded-xl transition">
                    <span>Kelola Jurnal</span>
                </a>
                <a href="../role 1 waka/kelola jadwal.html" class="flex items-center space-x-3 px-4 py-3 ${jadwalActive} rounded-xl transition">
                    <span>Kelola Jadwal</span>
                </a>
                <div>
                    <button id="btnDropdownUser" type="button" class="w-full flex items-center justify-between px-4 py-3 text-slate-600 hover:bg-slate-50 font-medium rounded-xl transition">
                        <span>Kelola Data User</span>
                        <svg id="arrowUserDropdown" class="w-4 h-4 transform transition-transform duration-200 ${arrowRotateClass}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="dropdownUserMenu" class="${dropdownUserMenuClass} pl-6 pr-2 py-2 space-y-1">
                        <a href="../role 1 waka/kelola data user.html" class="block px-3 py-2 text-xs ${siswaSubActive} rounded-lg transition">
                            Kelola User Siswa
                        </a>
                        <a href="../role 1 waka/kelola guru.html" class="block px-3 py-2 text-xs ${guruSubActive} rounded-lg transition">
                            Kelola User Guru
                        </a>
                        <a href="../role 1 waka/kelulusan.html" class="block px-3 py-2 text-xs ${lulusSubActive} rounded-lg transition">
                            Data Arsip
                        </a>
                    </div>
                </div>
                <a href="../role 1 waka/rekap kehadiran siswa.html" class="flex items-center space-x-3 px-4 py-3 ${rekapActive} rounded-xl transition">
                    <span>Rekap Kehadiran</span>
                </a>
            `;
        } else if (role === 2) {
            panelTitle = 'Panel Guru';
            const isDashboard = currentPath.includes('dashboard2.html');
            const isRekapJurnal = currentPath.includes('rekap jurnal1.html');
            const isJurnal = !isRekapJurnal && (currentPath.includes('jurnal1.html') || currentPath.includes('presensi1.html'));
            const isRekap = currentPath.includes('rekap kehadiran siswa1.html');

            const dashActive = isDashboard ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const jurnalActive = isJurnal ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const rekapJurnalActive = isRekapJurnal ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const rekapActive = isRekap ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';

            menuHTML = `
                <a href="../role 2 guru/dashboard2.html" class="flex items-center space-x-3 px-4 py-3 ${dashActive} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 001 1m-6 0h6"/></svg>
                    <span>Dashboard Guru</span>
                </a>
                <a href="../role 2 guru/jurnal1.html" class="flex items-center space-x-3 px-4 py-3 ${jurnalActive} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    <span>Jurnal Mengajar</span>
                </a>
                <a href="../role 2 guru/rekap jurnal1.html" class="flex items-center space-x-3 px-4 py-3 ${rekapJurnalActive} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    <span>Rekap Jurnal</span>
                </a>
                <a href="../role 2 guru/rekap kehadiran siswa1.html" class="flex items-center space-x-3 px-4 py-3 ${rekapActive} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Rekap Kehadiran Siswa</span>
                </a>
            `;
        } else if (role === 3) {
            const userTitle = (userData.title || '').replace(/\s+/g, '').toLowerCase();
            const isWaliKelas = userTitle === 'walikelas';
            const isGuruBk = userTitle === 'gurubk' || userTitle === 'gurubkpengajar';

            let classLabel = '';
            if (userData.school_class) {
                const sc = userData.school_class;
                classLabel = `${sc.kelas_nomor || ''} ${sc.nama_kelas} ${sc.nomor_kelas || ''}`.trim();
            } else if (userData.kelas) {
                classLabel = userData.kelas;
            }

            if (isWaliKelas) {
                panelTitle = classLabel ? `Panel Wali Kelas | ${classLabel}` : `Panel Wali Kelas`;
            } else if (isGuruBk) {
                panelTitle = `Panel Guru BK`;
            } else {
                panelTitle = `Panel ${userData.title || 'Kesiswaan'}`;
            }

            const isDashboard = currentPath.includes('dashboard3.html');
            const isRekapJurnal = currentPath.includes('rekap jurnal2.html');
            const isUploadJurnal = !isRekapJurnal && (currentPath.includes('jurnal2.html') || currentPath.includes('presensi2.html'));
            const isPersetujuan = currentPath.includes('persetujuan-izin.html');
            const isRekap = currentPath.includes('rekap kehadiran siswa2.html');

            const dashClass = isDashboard ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const jurnalClass = isUploadJurnal ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const rekapJurnalClass = isRekapJurnal ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const izinClass = isPersetujuan ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const rekapClass = isRekap ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';

            let uploadJurnalMenu = '';
            if (isWaliKelas || isGuruBk) {
                uploadJurnalMenu = `
                    <a href="../role 3 walikelas&bk/jurnal2.html" class="flex items-center space-x-3 px-4 py-3 ${jurnalClass} rounded-xl transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <span>Upload Jurnal</span>
                    </a>
                    <a href="../role 3 walikelas&bk/rekap jurnal2.html" class="flex items-center space-x-3 px-4 py-3 ${rekapJurnalClass} rounded-xl transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                        <span>Rekap Jurnal</span>
                    </a>
                `;
            }

            menuHTML = `
                <a href="../role 3 walikelas&bk/dashboard3.html" class="flex items-center space-x-3 px-4 py-3 ${dashClass} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 001 1m-6 0h6"/></svg>
                    <span>Dashboard</span>
                </a>
                ${uploadJurnalMenu}
                <a href="../role 3 walikelas&bk/persetujuan-izin.html" class="flex items-center space-x-3 px-4 py-3 ${izinClass} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Persetujuan Izin</span>
                </a>
                <a href="../role 3 walikelas&bk/rekap kehadiran siswa2.html" class="flex items-center space-x-3 px-4 py-3 ${rekapClass} rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    <span>Rekap Kehadiran Siswa</span>
                </a>
            `;
        } else if (role === 4) {
            panelTitle = 'Panel Siswa';
            const isDashboardActive = currentPath.includes('dashboard4.html');
            const isSuratIzinActive = currentPath.includes('suratizin.html');

            const dashClass = isDashboardActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';
            const izinClass = isSuratIzinActive ? 'bg-indigo-50 text-indigo-600 font-semibold' : 'text-slate-600 hover:bg-slate-50 font-medium';

            menuHTML = `
                <a href="../role 4 siswa/dashboard4.html" class="flex items-center space-x-3 px-4 py-3 ${dashClass} rounded-xl transition"><span>Dashboard</span></a>
                <a href="../role 4 siswa/suratizin.html" class="flex items-center space-x-3 px-4 py-3 ${izinClass} rounded-xl transition"><span>Surat Izin</span></a>
            `;
        }

        if (document.getElementById('navTitle')) {
            document.getElementById('navTitle').textContent = `SCP Sekolah - ${panelTitle}`;
        }

        sidebarPlaceholder.innerHTML = `
            <aside id="sidebar" class="fixed md:static bottom-0 left-0 w-64 bg-white border-r border-slate-200 p-5 flex flex-col justify-between transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out z-30 h-full shrink-0">
                <div class="space-y-6">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Navigasi Utama</span>
                    </div>
                    <nav class="space-y-2">${menuHTML}</nav>
                </div>
                <div class="border-t border-slate-100 pt-4 text-xs text-slate-400 text-center">Sistem SCP &copy; 2026</div>
            </aside>
        `;

        const btnDropdownUser = document.getElementById('btnDropdownUser');
        if (btnDropdownUser) {
            btnDropdownUser.addEventListener('click', () => {
                const dropdownMenu = document.getElementById('dropdownUserMenu');
                const arrowIcon = document.getElementById('arrowUserDropdown');
                dropdownMenu.classList.toggle('hidden');
                arrowIcon.classList.toggle('rotate-180');
                const isOpen = !dropdownMenu.classList.contains('hidden');
                localStorage.setItem('waka_dropdown_open', isOpen);
            });
        }
    }

    // Toggle Mobile Sidebar & Sinkronisasi Posisi di Bawah Navbar
    const toggleSidebarNav = document.getElementById('toggleSidebarNav');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function adjustSidebarMobilePosition() {
        const nav = document.querySelector('nav');
        if (nav && sidebar) {
            const navH = nav.offsetHeight || 60;
            if (window.innerWidth < 768) {
                sidebar.style.top = navH + 'px';
                sidebar.style.height = `calc(100vh - ${navH}px)`;
                if (sidebarOverlay) {
                    sidebarOverlay.style.top = navH + 'px';
                    sidebarOverlay.style.height = `calc(100vh - ${navH}px)`;
                }
            } else {
                sidebar.style.top = '';
                sidebar.style.height = '';
                if (sidebarOverlay) {
                    sidebarOverlay.style.top = '';
                    sidebarOverlay.style.height = '';
                }
            }
        }
    }
    adjustSidebarMobilePosition();

    if (toggleSidebarNav && sidebar) {
        toggleSidebarNav.addEventListener('click', () => {
            if (window.innerWidth >= 768) return;
            adjustSidebarMobilePosition();
            sidebar.classList.toggle('-translate-x-full');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
        });
    }
    if (sidebarOverlay && sidebar) {
        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });
    }

    window.addEventListener('resize', () => {
        adjustSidebarMobilePosition();
        if (window.innerWidth >= 768 && sidebar && sidebarOverlay) {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        }
    });

    // Modal Profil & Otentikasi
    const modalContainer = document.createElement('div');
    modalContainer.innerHTML = `
        <div id="profileModal" class="fixed inset-0 bg-slate-900/60 z-40 hidden flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col overflow-hidden transform transition-all">
                <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
                    <h3 class="font-bold text-lg">Profil Saya</h3>
                    <button id="btnCloseProfile" class="text-indigo-200 hover:text-white text-xl font-bold">&times;</button>
                </div>
                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div class="flex flex-col items-center justify-center">
                        <div class="relative group">
                            <div class="w-24 h-24 rounded-full bg-slate-200 border-4 border-indigo-100 flex items-center justify-center overflow-hidden shadow-md">
                                <img id="modalAvatarImg" src="" class="w-full h-full object-cover hidden" alt="Foto Profil">
                                <svg id="modalAvatarSvg" class="w-16 h-16 text-slate-400 mt-2" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            </div>
                            <label for="inputAvatar" class="absolute bottom-0 right-0 bg-indigo-600 hover:bg-indigo-700 text-white p-2 rounded-full cursor-pointer shadow-md transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3" stroke-width="2"/></svg>
                            </label>
                            <input type="file" id="inputAvatar" accept="image/*" class="hidden">
                        </div>
                        <span class="text-xs text-slate-400 mt-2">Klik ikon kamera untuk mengganti foto (Maks. 5 MB)</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase mb-1">Nama Lengkap</label>
                        <input type="text" id="modalFullname" readonly class="w-full px-3.5 py-2 rounded-lg bg-slate-100 border border-slate-300 text-slate-700 text-sm font-semibold outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label for="modalUsername" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Username</label>
                        <input type="text" id="modalUsername" placeholder="Masukkan username baru..." class="w-full px-3.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                        <p id="usernameError" class="text-xs text-red-500 italic mt-1 hidden"></p>
                    </div>
                    <div id="initEmailContainer" class="flex items-center justify-between border border-slate-200 p-3 rounded-xl bg-slate-50">
                        <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">EMAIL</span>
                        <button type="button" id="btnInitChangeEmail" class="w-32 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg transition shadow-sm flex items-center justify-center">Ganti Email</button>
                    </div>
                    <div id="initVerifyContainer" class="flex items-center justify-between border border-slate-200 p-3 rounded-xl bg-slate-50">
                        <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">PASSWORD</span>
                        <button type="button" id="btnInitChangePassword" class="w-32 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg transition shadow-sm flex items-center justify-center">Ganti Password</button>
                    </div>
                    <div id="passwordContainer" class="hidden space-y-1">
                        <div class="flex items-center justify-between mb-1">
                            <label for="modalPassword" class="block text-xs font-bold text-slate-600 uppercase">PASSWORD BARU</label>
                            <span class="text-[11px] text-indigo-600 font-semibold">OTP Terverifikasi</span>
                        </div>
                        <div class="relative">
                            <input type="password" id="modalPassword" autocomplete="new-password" placeholder="Biarkan kosong jika tidak diganti" class="w-full pl-3.5 pr-10 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                            <button type="button" id="togglePasswordBtn" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-indigo-600 focus:outline-none">
                                <svg id="eyeOffIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"></path></svg>
                                <svg id="eyeOnIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                        <p id="passwordHint" class="text-[11px] text-slate-400">Minimal 8 karakter, mengandung huruf besar, kecil, angka, simbol, & tanpa spasi.</p>
                        <p id="passwordError" class="text-xs text-red-500 italic hidden">Password harus minimal 8 karakter, mengandung huruf besar, huruf kecil, angka, simbol, dan tanpa spasi.</p>
                    </div>
                    <div>
                        <label id="identityLabel" class="block text-xs font-semibold text-slate-500 uppercase mb-1">NIP / NIS</label>
                        <input type="text" id="modalIdentityValue" readonly class="w-full px-3.5 py-2 rounded-lg bg-slate-100 border border-slate-300 text-slate-700 text-sm outline-none cursor-not-allowed">
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-3.5 flex justify-end space-x-2 border-t border-slate-200 shrink-0">
                    <button id="btnCancelProfile" class="px-4 py-2 bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition">Batal</button>
                    <button id="btnSaveProfile" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium transition shadow">Simpan</button>
                </div>
            </div>
        </div>

        <div id="emailGuideModal" class="fixed inset-0 bg-slate-900/60 z-50 hidden flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col transform transition-all">
                <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
                    <h3 class="font-bold text-lg">Ganti Email Akun</h3>
                    <button id="btnCloseEmailGuide" class="text-indigo-200 hover:text-white text-xl font-bold">&times;</button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-slate-700 uppercase">Masukkan Email Baru</label>
                        <input type="email" id="inputNewEmail" autocomplete="off" placeholder="contoh: user@gmail.com" class="w-full px-3.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                        <p id="newEmailError" class="text-xs text-red-500 italic mt-1 hidden"></p>
                        <div class="flex justify-end pt-3">
                            <button type="button" id="btnSubmitNewEmail" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition shadow">
                                Kirim Kode OTP
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="otpModal" class="fixed inset-0 bg-slate-900/60 z-50 hidden flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden flex flex-col transform transition-all p-6 text-center">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="otpModalTitle" class="text-lg font-bold text-slate-800 w-full text-center pl-6">Verifikasi Kode OTP</h3>
                    <button id="btnCloseOtp" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
                </div>
                <p id="otpModalSubtext" class="text-xs text-slate-500 mb-6 text-center">
                    Kode verifikasi 6 angka telah dikirim via email.
                </p>
                <div class="flex items-center justify-center gap-1.5 mb-2" id="otpContainer">
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <input type="text" maxlength="1" autocomplete="off" class="otp-input w-9 h-11 text-center text-lg font-bold border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition" />
                    <button type="button" id="btnManualRetryOtp" title="Kirim ulang kode verifikasi" class="w-9 h-11 flex items-center justify-center bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg border border-blue-200 transition shrink-0 focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <svg class="w-5 h-5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
                <p id="otpErrorMessage" class="text-xs text-red-500 text-center font-medium my-2 hidden">Kode verifikasi OTP salah</p>
                <div class="text-center mb-4 space-y-1">
                    <button type="button" id="btnResendOtp" disabled class="text-xs font-medium text-slate-400 cursor-not-allowed focus:outline-none text-center">
                        Kirim ulang via email
                    </button>
                    <p id="cooldownText" class="text-[11px] text-slate-400 text-center">
                        Cooldown pengiriman ulang: <span id="timerText" class="font-bold text-slate-600">30s</span>
                    </p>
                </div>
            </div>
        </div>

        <div id="cropModal" class="fixed inset-0 bg-slate-900/80 z-50 hidden flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[90vh]">
                <div class="bg-slate-800 px-6 py-4 flex justify-between items-center text-white">
                    <h3 class="font-bold text-base">Potong & Putar Foto</h3>
                    <button id="btnCloseCrop" class="text-slate-400 hover:text-white text-xl font-bold">&times;</button>
                </div>
                <div class="p-4 flex-1 overflow-hidden flex items-center justify-center bg-slate-950">
                    <div class="max-h-[60vh] w-full flex items-center justify-center">
                        <img id="cropImage" class="max-w-full max-h-[50vh] block" alt="Gambar Crop">
                    </div>
                </div>
                <div class="bg-slate-100 p-3 flex justify-center space-x-4 border-t border-slate-200">
                    <button id="btnRotateLeft" class="px-3.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold shadow-sm">Putar Kiri</button>
                    <button id="btnRotateRight" class="px-3.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold shadow-sm">Putar Kanan</button>
                </div>
                <div class="bg-white px-6 py-3.5 flex justify-end space-x-2 border-t border-slate-200">
                    <button id="btnCancelCrop" class="px-4 py-2 bg-slate-200 text-slate-700 rounded-lg text-sm">Batal</button>
                    <button id="btnApplyCrop" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Gunakan Foto Ini</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modalContainer);

    let cropper = null;
    let tempCroppedImageBase64 = null;
    let cooldownInterval = null;
    let isSendingOtp = false;
    let isVerifyingOtp = false;
    let otpMode = 'password';
    let pendingNewEmail = '';
    let verifiedOtpCode = '';

    const BASE_COOLDOWN_SECONDS = 30;

    function resetModalErrors() {
        const usernameError = document.getElementById('usernameError');
        const modalUsername = document.getElementById('modalUsername');
        const passwordError = document.getElementById('passwordError');
        const modalPassword = document.getElementById('modalPassword');

        if (usernameError) {
            usernameError.textContent = '';
            usernameError.classList.add('hidden');
        }
        if (modalUsername) {
            modalUsername.classList.remove('border-red-500');
            modalUsername.classList.add('border-slate-300');
        }
        if (passwordError) {
            passwordError.classList.add('hidden');
        }
        if (modalPassword) {
            modalPassword.classList.remove('border-red-500');
            modalPassword.classList.add('border-slate-300');
            modalPassword.setAttribute('type', 'password');
        }
        const eyeOffIcon = document.getElementById('eyeOffIcon');
        const eyeOnIcon = document.getElementById('eyeOnIcon');
        if (eyeOffIcon) eyeOffIcon.classList.remove('hidden');
        if (eyeOnIcon) eyeOnIcon.classList.add('hidden');
    }

    function showUsernameError(msg) {
        const usernameError = document.getElementById('usernameError');
        const modalUsername = document.getElementById('modalUsername');

        if (usernameError) {
            usernameError.textContent = msg;
            usernameError.classList.remove('hidden');
        }
        if (modalUsername) {
            modalUsername.classList.add('border-red-500');
            modalUsername.classList.remove('border-slate-300');
        }
    }

    function showPasswordError(msg) {
        const passwordError = document.getElementById('passwordError');
        const modalPassword = document.getElementById('modalPassword');
        if (passwordError) {
            passwordError.textContent = msg;
            passwordError.classList.remove('hidden');
        }
        if (modalPassword) {
            modalPassword.classList.add('border-red-500');
            modalPassword.classList.remove('border-slate-300');
        }
    }

    function dataURLtoBlob(dataurl) {
        if (!dataurl) return null;
        try {
            const arr = dataurl.split(',');
            const mimeMatch = arr[0].match(/:(.*?);/);
            const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
            const bstr = atob(arr[1]);
            let n = bstr.length;
            const u8arr = new Uint8Array(n);
            while (n--) {
                u8arr[n] = bstr.charCodeAt(n);
            }
            return new Blob([u8arr], { type: mime });
        } catch (e) {
            console.error('Konversi dataURL ke Blob gagal:', e);
            return null;
        }
    }

    function renderProfileData() {
        const currentData = JSON.parse(localStorage.getItem('user_data') || '{}');
        const fullname = currentData.fullname || currentData.username || 'User';
        const firstName = getFirstName(fullname);
        const rawIdentity = role === 4 ? (currentData.nis || '-') : (currentData.nip || '-');
        const identityDisplay = formatNipOrNis(rawIdentity, role === 4);
        
        let avatarSrc = currentData.avatar_url || null;
        if (!avatarSrc && currentData.avatar) {
            avatarSrc = `${API_BASE_URL.replace('/api', '')}/storage/${currentData.avatar}`;
        }

        if (document.getElementById('navFullname')) document.getElementById('navFullname').textContent = firstName;
        if (document.getElementById('navRoleIdentity')) document.getElementById('navRoleIdentity').textContent = identityDisplay;
        if (document.getElementById('userName')) document.getElementById('userName').textContent = firstName;
        if (document.getElementById('modalFullname')) document.getElementById('modalFullname').value = fullname;
        if (document.getElementById('modalUsername')) document.getElementById('modalUsername').value = currentData.username || '';
        if (document.getElementById('modalIdentityValue')) document.getElementById('modalIdentityValue').value = rawIdentity;
        if (document.getElementById('identityLabel')) document.getElementById('identityLabel').textContent = role === 4 ? 'NIS' : 'NIP';

        if (avatarSrc) {
            const navImg = document.getElementById('navAvatarImg');
            const navSvg = document.getElementById('navAvatarSvg');
            if (navImg) {
                navImg.src = avatarSrc;
                navImg.classList.remove('hidden');
                navImg.onerror = () => {
                    navImg.classList.add('hidden');
                    if (navSvg) navSvg.classList.remove('hidden');
                };
            }
            if (navSvg) navSvg.classList.add('hidden');

            const modalImg = document.getElementById('modalAvatarImg');
            const modalSvg = document.getElementById('modalAvatarSvg');
            if (modalImg) {
                modalImg.src = avatarSrc;
                modalImg.classList.remove('hidden');
                modalImg.onerror = () => {
                    modalImg.classList.add('hidden');
                    if (modalSvg) modalSvg.classList.remove('hidden');
                };
            }
            if (modalSvg) modalSvg.classList.add('hidden');
        }
    }

    renderProfileData();

    // Fungsi Render Banner Putaran Aktif (Putaran 1 = Hijau, Putaran 2 = Biru Muda)
    async function renderActiveCycleBanner() {
        const banner = document.getElementById('cycleBanner') || document.getElementById('bannerActiveCycle');
        const textEl = document.getElementById('cycleBannerText');
        if (!banner) return;

        let activeCycle = localStorage.getItem('active_cycle') || '1';

        try {
            const res = await fetch(`${API_BASE_URL}/system-config/active-cycle`, {
                headers: { 'Accept': 'application/json' }
            });
            if (res.ok) {
                const json = await res.json();
                if (json?.data?.active_cycle) {
                    activeCycle = String(json.data.active_cycle);
                    localStorage.setItem('active_cycle', activeCycle);
                }
            }
        } catch (e) {
            // fallback
        }

        banner.classList.remove('hidden');
        if (activeCycle === '2') {
            // Putaran 2 = Biru Muda
            banner.className = 'w-full py-3 px-6 rounded-2xl shadow-sm flex items-center justify-between transition-all duration-300 font-extrabold text-base md:text-lg tracking-wide uppercase bg-sky-400 text-white';
            if (textEl) {
                textEl.textContent = 'Putaran 2';
            } else {
                banner.innerHTML = `<div class="flex items-center space-x-2.5"><svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Putaran 2</span></div><span class="text-xs bg-white/20 backdrop-blur-xs px-3 py-1 rounded-full font-bold normal-case tracking-normal">Sedang Aktif</span>`;
            }
        } else {
            // Putaran 1 = Hijau
            banner.className = 'w-full py-3 px-6 rounded-2xl shadow-sm flex items-center justify-between transition-all duration-300 font-extrabold text-base md:text-lg tracking-wide uppercase bg-emerald-600 text-white';
            if (textEl) {
                textEl.textContent = 'Putaran 1';
            } else {
                banner.innerHTML = `<div class="flex items-center space-x-2.5"><svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Putaran 1</span></div><span class="text-xs bg-white/20 backdrop-blur-xs px-3 py-1 rounded-full font-bold normal-case tracking-normal">Sedang Aktif</span>`;
            }
        }
    }

    renderActiveCycleBanner();
    window.addEventListener('storage', (e) => {
        if (e.key === 'active_cycle') renderActiveCycleBanner();
    });

    function closeProfileModal() {
        resetModalErrors();
        const profileModal = document.getElementById('profileModal');
        const passwordContainer = document.getElementById('passwordContainer');
        const initVerifyContainer = document.getElementById('initVerifyContainer');
        if (passwordContainer) passwordContainer.classList.add('hidden');
        if (initVerifyContainer) initVerifyContainer.classList.remove('hidden');
        if (profileModal) profileModal.classList.add('hidden');
    }

    const btnOpenProfile = document.getElementById('btnOpenProfile');
    const profileModal = document.getElementById('profileModal');
    const btnCloseProfile = document.getElementById('btnCloseProfile');
    const btnCancelProfile = document.getElementById('btnCancelProfile');

    if (btnOpenProfile && profileModal) {
        btnOpenProfile.addEventListener('click', (e) => {
            e.preventDefault();
            tempCroppedImageBase64 = null;
            renderProfileData();
            closeProfileModal();
            profileModal.classList.remove('hidden');
        });
    }
    if (btnCloseProfile) btnCloseProfile.addEventListener('click', closeProfileModal);
    if (btnCancelProfile) btnCancelProfile.addEventListener('click', closeProfileModal);

    // Ganti Email Flow
    const btnInitChangeEmail = document.getElementById('btnInitChangeEmail');
    const emailGuideModal = document.getElementById('emailGuideModal');
    const btnCloseEmailGuide = document.getElementById('btnCloseEmailGuide');
    const inputNewEmail = document.getElementById('inputNewEmail');
    const btnSubmitNewEmail = document.getElementById('btnSubmitNewEmail');
    const newEmailError = document.getElementById('newEmailError');

    if (btnInitChangeEmail) {
        btnInitChangeEmail.addEventListener('click', (e) => {
            e.stopPropagation();
            if (inputNewEmail) inputNewEmail.value = '';
            if (newEmailError) {
                newEmailError.textContent = '';
                newEmailError.classList.add('hidden');
            }
            if (emailGuideModal) emailGuideModal.classList.remove('hidden');
            if (inputNewEmail) inputNewEmail.focus();
        });
    }

    if (btnCloseEmailGuide) {
        btnCloseEmailGuide.addEventListener('click', () => {
            if (emailGuideModal) emailGuideModal.classList.add('hidden');
        });
    }

    if (btnSubmitNewEmail) {
        btnSubmitNewEmail.addEventListener('click', async () => {
            const emailVal = inputNewEmail.value.trim().toLowerCase();
            const authToken = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');

            if (newEmailError) {
                newEmailError.textContent = '';
                newEmailError.classList.add('hidden');
            }

            const emailFormatRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailFormatRegex.test(emailVal)) {
                if (newEmailError) {
                    newEmailError.textContent = 'Silahkan masukkan format email (@...) yang benar';
                    newEmailError.classList.remove('hidden');
                }
                return;
            }

            try {
                btnSubmitNewEmail.disabled = true;
                btnSubmitNewEmail.textContent = 'Mengirim OTP...';

                const response = await fetch(`${API_BASE_URL}/profile/change-email`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ email: emailVal })
                });

                const result = await response.json();

                if (response.ok) {
                    if (emailGuideModal) emailGuideModal.classList.add('hidden');
                    otpMode = 'new_email';
                    pendingNewEmail = emailVal;

                    const otpModalTitle = document.getElementById('otpModalTitle');
                    const otpModalSubtext = document.getElementById('otpModalSubtext');
                    if (otpModalTitle) otpModalTitle.textContent = 'Verifikasi Kode OTP';
                    if (otpModalSubtext) otpModalSubtext.textContent = 'Kode verifikasi 6 angka telah dikirim ke email Anda.';

                    clearOtpInputs();
                    if (otpErrorMessage) {
                        otpErrorMessage.classList.add('hidden');
                        otpErrorMessage.textContent = 'Kode verifikasi OTP salah';
                    }

                    const otpModal = document.getElementById('otpModal');
                    if (otpModal) otpModal.classList.remove('hidden');
                    const otpInputs = Array.from(document.querySelectorAll('#otpModal .otp-input'));
                    if (otpInputs[0]) otpInputs[0].focus();

                    startCooldownTimer();
                } else {
                    if (newEmailError) {
                        newEmailError.textContent = result.message || 'Email sudah terdaftar/digunakan oleh akun lain di database.';
                        newEmailError.classList.remove('hidden');
                    }
                }
            } catch (error) {
                if (newEmailError) {
                    newEmailError.textContent = 'Terjadi kesalahan koneksi server.';
                    newEmailError.classList.remove('hidden');
                }
            } finally {
                btnSubmitNewEmail.disabled = false;
                btnSubmitNewEmail.textContent = 'Kirim Kode OTP';
            }
        });
    }

    // OTP & Cooldown Logic Modal Global Layout
    const otpInputs = Array.from(document.querySelectorAll('#otpModal .otp-input'));
    const otpModal = document.getElementById('otpModal');
    const btnCloseOtp = document.getElementById('btnCloseOtp');
    const btnResendOtp = document.getElementById('btnResendOtp');
    const btnManualRetryOtp = document.getElementById('btnManualRetryOtp');
    const otpErrorMessage = document.getElementById('otpErrorMessage');

    function clearOtpInputs() {
        otpInputs.forEach(input => { input.value = ''; });
    }

    function getFullOtp() {
        return otpInputs.map(input => input.value).join('');
    }

    async function sendOtpToEmail() {
        const authToken = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
        const endpoint = otpMode === 'new_email' ? `${API_BASE_URL}/profile/change-email` : `${API_BASE_URL}/profile/send-otp`;
        const payload = otpMode === 'new_email' ? { email: pendingNewEmail } : {};

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${authToken}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok) {
                alert(result.message || 'Gagal mengirim kode verifikasi.');
                return false;
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    async function verifyOtpCode(otpCode) {
        if (isVerifyingOtp) return;
        if (!/^\d{6}$/.test(String(otpCode))) return;

        isVerifyingOtp = true;
        const authToken = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
        const endpoint = otpMode === 'new_email' ? `${API_BASE_URL}/profile/verify-new-email-otp` : `${API_BASE_URL}/profile/verify-otp`;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${authToken}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ otp: String(otpCode).trim() })
            });

            const result = await response.json();

            if (response.ok) {
                verifiedOtpCode = String(otpCode).trim();
                if (otpErrorMessage) {
                    otpErrorMessage.classList.add('hidden');
                    otpErrorMessage.textContent = 'Kode verifikasi OTP salah';
                }
                if (otpModal) otpModal.classList.add('hidden');

                if (otpMode === 'new_email') {
                    alert('Email telah diperbaharui');
                    const currentData = JSON.parse(localStorage.getItem('user_data') || '{}');
                    currentData.email = pendingNewEmail;
                    localStorage.setItem('user_data', JSON.stringify(currentData));
                    renderProfileData();
                } else {
                    const passwordContainer = document.getElementById('passwordContainer');
                    const initVerifyContainer = document.getElementById('initVerifyContainer');
                    const modalPassword = document.getElementById('modalPassword');
                    if (passwordContainer) passwordContainer.classList.remove('hidden');
                    if (initVerifyContainer) initVerifyContainer.classList.add('hidden');
                    if (modalPassword) {
                        modalPassword.value = '';
                        modalPassword.focus();
                    }
                }
            } else {
                if (otpErrorMessage) {
                    otpErrorMessage.textContent = result.message || 'Kode verifikasi OTP salah';
                    otpErrorMessage.classList.remove('hidden');
                }
            }
        } catch (error) {
            if (otpErrorMessage) {
                otpErrorMessage.textContent = 'Terjadi kesalahan koneksi saat memverifikasi kode.';
                otpErrorMessage.classList.remove('hidden');
            }
        } finally {
            isVerifyingOtp = false;
        }
    }

    function updateTimerUI(secondsLeft) {
        const timerText = document.getElementById('timerText');
        if (secondsLeft <= 0) {
            clearInterval(cooldownInterval);
            if (btnResendOtp) {
                btnResendOtp.disabled = false;
                btnResendOtp.innerText = 'Kirim ulang via email';
                btnResendOtp.className = 'text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline cursor-pointer focus:outline-none text-center';
            }
            if (btnManualRetryOtp) {
                btnManualRetryOtp.disabled = false;
                btnManualRetryOtp.classList.remove('opacity-50', 'cursor-not-allowed');
            }
            if (timerText) timerText.textContent = '0s';
        } else {
            if (btnResendOtp) {
                btnResendOtp.disabled = true;
                btnResendOtp.innerText = 'Kirim ulang via email';
                btnResendOtp.className = 'text-xs font-medium text-slate-400 cursor-not-allowed focus:outline-none text-center';
            }
            if (btnManualRetryOtp) {
                btnManualRetryOtp.disabled = true;
                btnManualRetryOtp.classList.add('opacity-50', 'cursor-not-allowed');
            }
            if (timerText) timerText.textContent = `${secondsLeft}s`;
        }
    }

    function startCooldownTimer() {
        clearInterval(cooldownInterval);
        let seconds = BASE_COOLDOWN_SECONDS;
        updateTimerUI(seconds);
        cooldownInterval = setInterval(() => {
            seconds--;
            updateTimerUI(seconds);
            if (seconds <= 0) {
                clearInterval(cooldownInterval);
            }
        }, 1000);
    }

    async function triggerOtpRequest() {
        if (isSendingOtp) return;
        isSendingOtp = true;
        if (btnResendOtp) {
            btnResendOtp.disabled = true;
            btnResendOtp.innerText = 'Mengirim...';
        }
        if (btnManualRetryOtp) {
            btnManualRetryOtp.disabled = true;
            btnManualRetryOtp.classList.add('opacity-50', 'cursor-not-allowed');
        }

        const sent = await sendOtpToEmail();
        isSendingOtp = false;

        if (sent) {
            startCooldownTimer();
        } else {
            updateTimerUI(0);
        }
    }

    const btnInitChangePassword = document.getElementById('btnInitChangePassword');
    if (btnInitChangePassword) {
        btnInitChangePassword.addEventListener('click', (e) => {
            e.stopPropagation();
            otpMode = 'password';
            const otpModalTitle = document.getElementById('otpModalTitle');
            const otpModalSubtext = document.getElementById('otpModalSubtext');
            if (otpModalTitle) otpModalTitle.textContent = 'Verifikasi Kode OTP';
            if (otpModalSubtext) otpModalSubtext.textContent = 'Kode verifikasi 6 angka telah dikirim via email.';

            clearOtpInputs();
            if (otpErrorMessage) {
                otpErrorMessage.classList.add('hidden');
                otpErrorMessage.textContent = 'Kode verifikasi OTP salah';
            }
            if (otpModal) otpModal.classList.remove('hidden');
            if (otpInputs[0]) otpInputs[0].focus();

            triggerOtpRequest();
        });
    }

    if (btnResendOtp) {
        btnResendOtp.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!btnResendOtp.disabled && !isSendingOtp) triggerOtpRequest();
        });
    }

    if (btnManualRetryOtp) {
        btnManualRetryOtp.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!btnManualRetryOtp.disabled && !isSendingOtp) triggerOtpRequest();
        });
    }

    function distributeOtpDigits(rawValue) {
        const digits = String(rawValue || '').replace(/\D/g, '').slice(0, 6);
        if (!digits) return;

        otpInputs.forEach((otpInput, index) => {
            otpInput.value = digits[index] || '';
        });

        const nextIndex = Math.min(digits.length, otpInputs.length - 1);
        if (otpInputs[nextIndex]) otpInputs[nextIndex].focus();

        if (digits.length === 6) {
            verifyOtpCode(digits);
        }
    }

    otpInputs.forEach((input, index) => {
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const clipboard = e.clipboardData || window.clipboardData;
            const pastedData = clipboard ? clipboard.getData('text') : '';
            distributeOtpDigits(pastedData);
        });

        input.addEventListener('input', (e) => {
            const digits = String(e.target.value || '').replace(/\D/g, '');
            if (digits.length > 1) {
                distributeOtpDigits(digits);
                return;
            }
            e.target.value = digits.slice(0, 1);
            if (e.target.value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
            const fullOtp = getFullOtp();
            if (fullOtp.length === 6) {
                verifyOtpCode(fullOtp);
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace') {
                if (input.value) {
                    input.value = '';
                } else if (index > 0 && otpInputs[index - 1]) {
                    otpInputs[index - 1].focus();
                    otpInputs[index - 1].value = '';
                }
            } else if (e.key === 'ArrowLeft' && index > 0) {
                otpInputs[index - 1].focus();
            } else if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
        });
    });

    if (btnCloseOtp) {
        btnCloseOtp.addEventListener('click', (e) => {
            e.stopPropagation();
            if (otpModal) otpModal.classList.add('hidden');
        });
    }

    // Toggle Eye Password
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const modalPassword = document.getElementById('modalPassword');

    if (modalPassword) {
        modalPassword.addEventListener('keydown', (e) => {
            if (e.key === ' ' || e.code === 'Space') e.preventDefault();
        });
        modalPassword.addEventListener('input', () => {
            if (/\s/.test(modalPassword.value)) {
                modalPassword.value = modalPassword.value.replace(/\s+/g, '');
            }
            const val = modalPassword.value;
            const passwordError = document.getElementById('passwordError');
            if (val) {
                const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[^\s]{8,}$/;
                if (!regex.test(val)) {
                    if (passwordError) passwordError.classList.remove('hidden');
                } else {
                    if (passwordError) passwordError.classList.add('hidden');
                }
            } else {
                if (passwordError) passwordError.classList.add('hidden');
            }
        });
    }

    if (togglePasswordBtn && modalPassword) {
        togglePasswordBtn.addEventListener('click', () => {
            const eyeOffIcon = document.getElementById('eyeOffIcon');
            const eyeOnIcon = document.getElementById('eyeOnIcon');
            const isPassword = modalPassword.getAttribute('type') === 'password';
            modalPassword.setAttribute('type', isPassword ? 'text' : 'password');
            if (eyeOffIcon) eyeOffIcon.classList.toggle('hidden');
            if (eyeOnIcon) eyeOnIcon.classList.toggle('hidden');
        });
    }

    // Simpan Perubahan Profil
    const btnSaveProfile = document.getElementById('btnSaveProfile');
    if (btnSaveProfile) {
        btnSaveProfile.addEventListener('click', async () => {
            const authToken = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
            if (!authToken) {
                alert('Sesi anda telah berakhir. Silakan login kembali.');
                window.location.href = '../login/login.html';
                return;
            }

            resetModalErrors();

            const pwd = modalPassword ? modalPassword.value.trim() : '';
            const passwordContainer = document.getElementById('passwordContainer');
            const passwordError = document.getElementById('passwordError');
            if (passwordContainer && !passwordContainer.classList.contains('hidden') && pwd !== '') {
                const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[^\s]{8,}$/;
                if (!passwordRegex.test(pwd)) {
                    if (passwordError) passwordError.classList.remove('hidden');
                    return;
                }
            }

            const formData = new FormData();
            const modalUsername = document.getElementById('modalUsername');
            formData.append('username', modalUsername ? modalUsername.value.trim() : '');
            if (passwordContainer && !passwordContainer.classList.contains('hidden') && pwd !== '') {
                formData.append('password', pwd);
            }
            if (verifiedOtpCode) {
                formData.append('otp', verifiedOtpCode);
            }

            if (tempCroppedImageBase64) {
                const blob = dataURLtoBlob(tempCroppedImageBase64);
                if (blob) {
                    formData.append('avatar', blob, 'avatar.jpg');
                }
            } else if (inputAvatar && inputAvatar.files && inputAvatar.files[0]) {
                formData.append('avatar', inputAvatar.files[0]);
            }

            try {
                btnSaveProfile.disabled = true;
                btnSaveProfile.textContent = 'Menyimpan...';

                const response = await fetch(`${API_BASE_URL}/profile/update`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                let result = null;
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    result = await response.json();
                } else {
                    const text = await response.text();
                    try {
                        result = JSON.parse(text);
                    } catch {
                        result = { message: `Server mengembalikan status HTTP ${response.status} (${response.statusText})` };
                    }
                }

                if (response.ok && result && (result.status === 'success' || result.data)) {
                    const updatedUser = result.data || result.user || result;
                    localStorage.setItem('user_data', JSON.stringify(updatedUser));
                    tempCroppedImageBase64 = null;
                    renderProfileData();
                    closeProfileModal();
                    alert('Berhasil memperbaharui profil!');
                } else {
                    const msg = result?.message || result?.error || `Gagal memperbarui profil (HTTP ${response.status}).`;
                    if (result?.errors?.username) {
                        showUsernameError(result.errors.username[0] || 'Username ini sudah digunakan oleh pengguna lain.');
                    } else if (result?.errors?.avatar) {
                        alert(`Foto profil gagal diunggah: ${result.errors.avatar[0]}`);
                    } else if (result?.errors?.password) {
                        showPasswordError(result.errors.password[0]);
                    } else if (msg.toLowerCase().includes('username')) {
                        showUsernameError(msg);
                    } else {
                        alert(msg);
                    }
                }
            } catch (error) {
                console.error('Error updating profile:', error);
                alert(`Gagal memperbarui profil: ${error.message || 'Terjadi kesalahan jaringan atau koneksi ke server.'}`);
            } finally {
                btnSaveProfile.disabled = false;
                btnSaveProfile.textContent = 'Simpan';
            }
        });
    }

    const inputAvatar = document.getElementById('inputAvatar');
    if (inputAvatar) {
        inputAvatar.addEventListener('change', (e) => {
            const files = e.target.files;
            if (files && files.length > 0) {
                const file = files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('Ukuran file foto maksimal 5 MB!');
                    inputAvatar.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = (event) => {
                    const cropImage = document.getElementById('cropImage');
                    const cropModal = document.getElementById('cropModal');
                    if (cropImage) cropImage.src = event.target.result;
                    if (cropModal) cropModal.classList.remove('hidden');

                    if (cropper) cropper.destroy();
                    cropper = new Cropper(cropImage, {
                        aspectRatio: 1,
                        viewMode: 1,
                        background: false
                    });
                };
                reader.readAsDataURL(file);
            }
        });
    }

    const btnRotateLeft = document.getElementById('btnRotateLeft');
    const btnRotateRight = document.getElementById('btnRotateRight');
    if (btnRotateLeft) btnRotateLeft.addEventListener('click', () => { if (cropper) cropper.rotate(-90); });
    if (btnRotateRight) btnRotateRight.addEventListener('click', () => { if (cropper) cropper.rotate(90); });

    function closeCropModal() {
        const cropModal = document.getElementById('cropModal');
        if (cropModal) cropModal.classList.add('hidden');
        if (inputAvatar) inputAvatar.value = '';
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
    }

    const btnCloseCrop = document.getElementById('btnCloseCrop');
    const btnCancelCrop = document.getElementById('btnCancelCrop');
    const btnApplyCrop = document.getElementById('btnApplyCrop');
    if (btnCloseCrop) btnCloseCrop.addEventListener('click', closeCropModal);
    if (btnCancelCrop) btnCancelCrop.addEventListener('click', closeCropModal);

    if (btnApplyCrop) {
        btnApplyCrop.addEventListener('click', () => {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({ width: 300, height: 300 });
                tempCroppedImageBase64 = canvas.toDataURL('image/jpeg');
                const modalAvatarImg = document.getElementById('modalAvatarImg');
                const modalAvatarSvg = document.getElementById('modalAvatarSvg');
                if (modalAvatarImg) {
                    modalAvatarImg.src = tempCroppedImageBase64;
                    modalAvatarImg.classList.remove('hidden');
                }
                if (modalAvatarSvg) modalAvatarSvg.classList.add('hidden');
                closeCropModal();
            }
        });
    }

    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', (e) => {
            e.preventDefault();
            if (typeof window.performLogout === 'function') {
                window.performLogout();
            } else {
                try {
                    localStorage.removeItem('auth_token');
                    localStorage.removeItem('user_data');
                    localStorage.removeItem('login_timestamp');
                    sessionStorage.clear();
                } catch (err) {}
                window.location.replace('../login/login.html');
            }
        });
    }

    // PWA: Listener Prompt (Install App button removed as requested)
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPwaPrompt = e;
    });

    window.addEventListener('appinstalled', () => {
        window.deferredPwaPrompt = null;
    });
}

if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", initUniversalGlobalLayout);
} else {
    initUniversalGlobalLayout();
}