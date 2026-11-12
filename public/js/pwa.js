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
})();


