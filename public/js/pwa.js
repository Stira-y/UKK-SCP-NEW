// PWA Helper & ServiceWorker Registration for SCP SMKN 1 Bantul
(function() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
                .then(function(reg) {
                    console.log('PWA ServiceWorker ready on scope:', reg.scope);
                })
                .catch(function(err) {
                    console.warn('PWA registration error:', err);
                });
        });
    }

    // Pastikan konten halaman tidak tertutup tombol navigasi HP (kotak, bulat, segitiga)
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.getElementById('pwa-safe-area-style')) {
            const style = document.createElement('style');
            style.id = 'pwa-safe-area-style';
            style.textContent = `
                main {
                    padding-bottom: calc(env(safe-area-inset-bottom, 24px) + 6rem) !important;
                    scroll-padding-bottom: 6rem !important;
                    box-sizing: border-box !important;
                }
                @media (min-width: 768px) {
                    main {
                        padding-bottom: 2rem !important;
                        scroll-padding-bottom: 2rem !important;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    });
})();

// ========================================================
// UNIVERSAL MODERN POPUP MODAL SYSTEM (SCP BEAUTIFUL MODAL)
// Menggantikan window.alert & window.confirm bawaan browser
// ========================================================
(function() {
    let popupContainer = null;
    let currentResolver = null;
    let isModalOpen = false;
    const modalQueue = [];
    let isCurrentPrompt = false;

    const ICONS = {
        success: {
            bg: 'bg-emerald-100 text-emerald-600',
            btn: 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`
        },
        error: {
            bg: 'bg-rose-100 text-rose-600',
            btn: 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>`
        },
        warning: {
            bg: 'bg-amber-100 text-amber-600',
            btn: 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>`
        },
        confirm: {
            bg: 'bg-indigo-100 text-indigo-600',
            btn: 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`
        },
        delete: {
            bg: 'bg-rose-100 text-rose-600',
            btn: 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>`
        },
        prompt: {
            bg: 'bg-indigo-100 text-indigo-600',
            btn: 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>`
        },
        info: {
            bg: 'bg-blue-100 text-blue-600',
            btn: 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-200',
            svg: `<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`
        }
    };

    function ensurePopupDom() {
        if (popupContainer && document.body && document.body.contains(popupContainer)) return popupContainer;
        if (!document.body) return null;

        popupContainer = document.createElement('div');
        popupContainer.id = 'scpGlobalPopupModal';
        popupContainer.className = 'fixed inset-0 z-[999999] flex items-center justify-center p-4 transition-all duration-200 opacity-0 pointer-events-none';
        popupContainer.innerHTML = `
            <div id="scpPopupBackdrop" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200"></div>
            <div id="scpPopupCard" class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm sm:max-w-md overflow-hidden p-6 text-center transform transition-all duration-200 scale-95 border border-slate-100 z-10">
                <div id="scpPopupIconContainer" class="w-14 h-14 rounded-full mx-auto flex items-center justify-center mb-4 transition-colors"></div>
                <h3 id="scpPopupTitle" class="text-base sm:text-lg font-bold text-slate-800 mb-2 leading-snug"></h3>
                <div id="scpPopupMessage" class="text-xs sm:text-sm text-slate-600 mb-4 leading-relaxed max-h-64 overflow-y-auto whitespace-pre-line px-1 text-center break-words"></div>
                <div id="scpPopupInputContainer" class="hidden mb-5 text-left">
                    <input type="text" id="scpPopupInput" class="w-full px-4 py-2.5 text-xs sm:text-sm bg-slate-50 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition text-slate-800 font-medium shadow-xs">
                </div>
                <div id="scpPopupActions" class="flex items-center justify-center gap-3">
                    <button type="button" id="scpPopupBtnCancel" class="hidden w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs sm:text-sm font-bold transition">Batal</button>
                    <button type="button" id="scpPopupBtnConfirm" class="w-full py-2.5 rounded-xl text-xs sm:text-sm font-bold transition shadow-md">Mengerti</button>
                </div>
            </div>
        `;

        document.body.appendChild(popupContainer);

        const btnCancel = popupContainer.querySelector('#scpPopupBtnCancel');
        const btnConfirm = popupContainer.querySelector('#scpPopupBtnConfirm');
        const inputEl = popupContainer.querySelector('#scpPopupInput');
        const backdrop = popupContainer.querySelector('#scpPopupBackdrop');

        btnCancel.addEventListener('click', () => closePopup(isCurrentPrompt ? null : false));
        btnConfirm.addEventListener('click', () => {
            if (isCurrentPrompt) {
                closePopup(inputEl.value);
            } else {
                closePopup(true);
            }
        });

        backdrop.addEventListener('click', () => {
            closePopup(isCurrentPrompt ? null : false);
        });

        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                closePopup(inputEl.value);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closePopup(null);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (!popupContainer || popupContainer.classList.contains('pointer-events-none') || !isModalOpen) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closePopup(isCurrentPrompt ? null : false);
            } else if (e.key === 'Enter' && !isCurrentPrompt) {
                e.preventDefault();
                closePopup(true);
            }
        });

        return popupContainer;
    }

    function closePopup(result) {
        if (!popupContainer) return;
        popupContainer.classList.remove('opacity-100', 'pointer-events-auto');
        popupContainer.classList.add('opacity-0', 'pointer-events-none');
        const card = popupContainer.querySelector('#scpPopupCard');
        if (card) {
            card.classList.remove('scale-100');
            card.classList.add('scale-95');
        }
        const resolver = currentResolver;
        currentResolver = null;
        isModalOpen = false;
        if (typeof resolver === 'function') {
            resolver(result);
        }
        if (modalQueue.length > 0) {
            setTimeout(processQueue, 200);
        }
    }

    function processQueue() {
        if (isModalOpen || modalQueue.length === 0) return;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', processQueue, { once: true });
            return;
        }

        const item = modalQueue.shift();
        const { message, title, type, isConfirm = false, isPrompt = false, defaultValue = '', placeholder = '', confirmText = 'Mengerti', cancelText = 'Batal' } = item.config;
        currentResolver = item.resolve;
        isModalOpen = true;
        isCurrentPrompt = isPrompt;

        const container = ensurePopupDom();
        if (!container) {
            isModalOpen = false;
            currentResolver = null;
            item.resolve(isPrompt ? null : true);
            return;
        }

        const iconBox = container.querySelector('#scpPopupIconContainer');
        const titleEl = container.querySelector('#scpPopupTitle');
        const msgEl = container.querySelector('#scpPopupMessage');
        const inputContainer = container.querySelector('#scpPopupInputContainer');
        const inputEl = container.querySelector('#scpPopupInput');
        const btnConfirm = container.querySelector('#scpPopupBtnConfirm');
        const btnCancel = container.querySelector('#scpPopupBtnCancel');
        const card = container.querySelector('#scpPopupCard');

        const iconDef = ICONS[type] || ICONS.info;

        iconBox.className = `w-14 h-14 rounded-full mx-auto flex items-center justify-center mb-4 transition-colors ${iconDef.bg}`;
        iconBox.innerHTML = iconDef.svg;

        titleEl.textContent = title;
        msgEl.textContent = message || '';

        if (isPrompt) {
            inputContainer.classList.remove('hidden');
            inputEl.value = defaultValue || '';
            inputEl.placeholder = placeholder || 'Ketik di sini...';
            btnCancel.classList.remove('hidden');
            btnCancel.textContent = cancelText;
            btnConfirm.className = `w-full py-2.5 rounded-xl text-xs sm:text-sm font-bold transition shadow-md bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-200`;
            btnConfirm.textContent = confirmText || 'Simpan';
        } else {
            inputContainer.classList.add('hidden');
            btnConfirm.className = `w-full py-2.5 rounded-xl text-xs sm:text-sm font-bold transition shadow-md ${iconDef.btn}`;
            btnConfirm.textContent = confirmText;

            if (isConfirm) {
                btnCancel.classList.remove('hidden');
                btnCancel.textContent = cancelText;
            } else {
                btnCancel.classList.add('hidden');
            }
        }

        container.classList.remove('opacity-0', 'pointer-events-none');
        container.classList.add('opacity-100', 'pointer-events-auto');
        setTimeout(() => {
            if (card) {
                card.classList.remove('scale-95');
                card.classList.add('scale-100');
            }
            if (isPrompt && inputEl) {
                inputEl.focus();
                inputEl.select();
            } else if (btnConfirm) {
                btnConfirm.focus();
            }
        }, 50);
    }

    function openModal(config) {
        return new Promise((resolve) => {
            modalQueue.push({ config, resolve });
            processQueue();
        });
    }

    function detectTypeAndTitle(message, customTitle, customType) {
        if (customType && ICONS[customType]) {
            return { type: customType, title: customTitle || (customType === 'success' ? 'Berhasil' : customType === 'error' ? 'Pemberitahuan Kendala' : 'Pemberitahuan') };
        }
        const text = String(message || '').toLowerCase();
        if (text.includes('berhasil') || text.includes('sukses') || text.includes('selesai') || text.includes('terkirim') || text.includes('dipulihkan')) {
            return { type: 'success', title: customTitle || 'Berhasil!' };
        }
        if (text.includes('gagal') || text.includes('error') || text.includes('salah') || text.includes('ditolak') || text.includes('terdapat kendala') || text.includes('rusak') || text.includes('tidak terdaftar') || text.includes('tidak ditemukan')) {
            return { type: 'error', title: customTitle || 'Pemberitahuan Kendala' };
        }
        if (text.includes('perhatian') || text.includes('peringatan') || text.includes('wajib') || text.includes('kosong') || text.includes('catatan') || text.includes('kedaluwarsa')) {
            return { type: 'warning', title: customTitle || 'Peringatan' };
        }
        return { type: 'info', title: customTitle || 'Pemberitahuan' };
    }

    // Public API
    window.showCustomAlert = function(message, customTitle, customType) {
        const { type, title } = detectTypeAndTitle(message, customTitle, customType);
        return openModal({ message, title, type, isConfirm: false, confirmText: 'Mengerti' });
    };

    window.showCustomConfirm = function(message, customTitle, options = {}) {
        return openModal({
            message,
            title: customTitle || 'Konfirmasi Tindakan',
            type: options.type || 'confirm',
            isConfirm: true,
            confirmText: options.confirmText || 'Ya, Lanjutkan',
            cancelText: options.cancelText || 'Batal'
        });
    };

    window.showCustomPrompt = function(message, customTitle, defaultValue = '', placeholder = '') {
        return openModal({
            message,
            title: customTitle || 'Input Data',
            type: 'prompt',
            isConfirm: true,
            isPrompt: true,
            defaultValue,
            placeholder,
            confirmText: 'Simpan',
            cancelText: 'Batal'
        });
    };

    // Override bawaan browser window.alert, window.confirm, & window.prompt secara global
    window.alert = function(message) {
        return window.showCustomAlert(message);
    };

    window.confirm = function(message) {
        return window.showCustomConfirm(message);
    };

    window.prompt = function(message, defaultValue) {
        return window.showCustomPrompt(message, 'Input Data', defaultValue || '');
    };

    window.showSuccess = (msg, title) => window.showCustomAlert(msg, title || 'Berhasil!', 'success');
    window.showError = (msg, title) => window.showCustomAlert(msg, title || 'Terjadi Kendala', 'error');
    window.showWarning = (msg, title) => window.showCustomAlert(msg, title || 'Peringatan', 'warning');

    // ========================================================
    // UNIVERSAL LOGOUT HANDLER & GLOBAL CLICK INTERCEPTOR
    // Menjamin tombol logout berfungsi stabil & pop up modern di semua role
    // ========================================================
    let isLogoutInProgress = false;
    window.performLogout = async function() {
        if (isLogoutInProgress) return;
        isLogoutInProgress = true;

        try {
            let confirmed = false;
            if (window.showCustomConfirm) {
                confirmed = await window.showCustomConfirm('Apakah Anda yakin ingin keluar dari akun ini?', 'Konfirmasi Keluar', {
                    confirmText: 'Ya, Keluar',
                    cancelText: 'Batal',
                    type: 'confirm'
                });
            } else {
                confirmed = true;
            }

            if (confirmed) {
                try {
                    localStorage.removeItem('auth_token');
                    localStorage.removeItem('user_data');
                    localStorage.removeItem('login_timestamp');
                    localStorage.removeItem('waka_dropdown_open');
                    sessionStorage.clear();
                } catch (e) {
                    console.warn('Gagal membersihkan storage:', e);
                }

                const path = window.location.pathname.toLowerCase();
                const targetUrl = path.includes('/role') ? '../login/login.html' : '/login/login.html';
                window.location.replace(targetUrl);
            }
        } catch (err) {
            console.error('Logout error:', err);
            try {
                localStorage.clear();
                sessionStorage.clear();
            } catch (e) {}
            window.location.replace('../login/login.html');
        } finally {
            isLogoutInProgress = false;
        }
    };

    window.handleLogout = window.performLogout;

    // Delegated click listener pada fasa capture: mencegat sebelum listener lokal lain bentrok
    document.addEventListener('click', function(e) {
        const btn = e.target && e.target.closest && e.target.closest('#btnLogout, .btn-logout-trigger');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            window.performLogout();
        }
    }, true);
})();


