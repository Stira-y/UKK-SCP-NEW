/**
 * profile-role-siswa.js
 * Modul Manajemen Profil, Navbar, Sidebar, dan Footer untuk Siswa
 * Kompatibel otomatis untuk Lingkungan Lokal (Laragon) & Hosting Produksi
 */

// DEFINISI GLOBAL DI PALING ATAS
const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
var API_BASE_URL = isLocalhost 
    ? 'http://localhost/ukk-scp-new/public/api' 
    : `${window.location.origin}/api`;

window.API_BASE_URL = API_BASE_URL;

const token = localStorage.getItem('auth_token');
const userData = JSON.parse(localStorage.getItem('user_data') || '{}');
const userId = userData.id_user || userData.id || 'default';

// Cek Role Akses Siswa
if (!token || Number(userData.id_role) !== 4) {
    alert('Akses ditolak! Silakan login sebagai Siswa.');
    window.location.href = '../login/login.html';
}

let cropper = null;
let tempCroppedImageBase64 = null;
let cooldownInterval = null;
let isSendingOtp = false;
let isVerifyingOtp = false;
let otpMode = 'password';
let pendingNewEmail = '';

const BASE_COOLDOWN_SECONDS = 30;
const INCREMENT_COOLDOWN_SECONDS = 15;
const RESET_IDLE_TIME_MS = 10 * 60 * 1000;

// Helper: Ambil nama depan saja untuk tampilan navbar & sapaan
function getFirstName(fullName) {
    if (!fullName) return 'Siswa';
    const clean = String(fullName).trim();
    const parts = clean.split(/\s+/);
    return parts[0] || clean;
}

// Fungsi Global Logout
window.handleLogout = function() {
    if (confirm('Apakah Anda yakin ingin keluar dari akun ini?')) {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user_data');
        window.location.href = '../login/login.html';
    }
};

// Fungsi Global Buka Profil
window.openProfileModal = function() {
    tempCroppedImageBase64 = null;
    renderProfileData();
    resetModalErrors();

    const passwordContainer = document.getElementById('passwordContainer');
    const initVerifyContainer = document.getElementById('initVerifyContainer');
    const profileModal = document.getElementById('profileModal');

    if (passwordContainer) passwordContainer.classList.add('hidden');
    if (initVerifyContainer) initVerifyContainer.classList.remove('hidden');
    if (profileModal) profileModal.classList.remove('hidden');
};

function renderProfileData() {
    const currentData = JSON.parse(
        localStorage.getItem('user_data') || '{}'
    );

    const fullname = currentData.fullname || currentData.username || 'Siswa';
    const firstName = getFirstName(fullname);
    const username = currentData.username || '';
    const nis = currentData.nis || '-';
    const nisn = currentData.nisn || '-';

    let avatarSrc = currentData.avatar_url || null;

    if (!avatarSrc && currentData.avatar) {
        avatarSrc = `${API_BASE_URL.replace('/api', '')}/storage/${currentData.avatar}`;
    }

    const navFullname = document.getElementById('navFullname');
    const navNis = document.getElementById('navNis');
    const userNameDisplay = document.getElementById('userName');

    const modalFullname = document.getElementById('modalFullname');
    const modalUsername = document.getElementById('modalUsername');
    const modalPassword = document.getElementById('modalPassword');
    const modalNis = document.getElementById('modalNis');
    const modalNisn = document.getElementById('modalNisn');

    // Menampilkan nama depan di navbar dan sapaan dashboard
    if (navFullname) navFullname.textContent = firstName;
    if (navNis) navNis.textContent = nis;
    if (userNameDisplay) userNameDisplay.textContent = firstName;

    if (modalFullname) modalFullname.value = fullname;
    if (modalUsername) modalUsername.value = username;
    if (modalPassword) modalPassword.value = '';
    if (modalNis) modalNis.value = nis;
    if (modalNisn) modalNisn.value = nisn;

    // UPDATE NAVBAR TITLE SISWA DENGAN KELAS LENGKAP
    let classLabel = '';
    if (currentData.school_class) {
        const sc = currentData.school_class;
        classLabel = `${sc.kelas_nomor || ''} ${sc.nama_kelas || ''} ${sc.nomor_kelas || ''}`.replace(/\s+/g, ' ').trim();
    } else if (currentData.schoolClass) {
        const sc = currentData.schoolClass;
        classLabel = `${sc.kelas_nomor || ''} ${sc.nama_kelas || ''} ${sc.nomor_kelas || ''}`.replace(/\s+/g, ' ').trim();
    } else if (currentData.kelas) {
        classLabel = currentData.kelas;
    }

    const navHeading = document.querySelector('nav h1');
    if (navHeading) {
        navHeading.textContent = classLabel 
            ? `SCP Sekolah - Panel Siswa | ${classLabel}` 
            : `SCP Sekolah - Panel Siswa`;
    }

    updateAvatarUI(avatarSrc);
}

function updateAvatarUI(avatarSrc) {
    const navAvatarImg = document.getElementById('navAvatarImg');
    const navAvatarSvg = document.getElementById('navAvatarSvg');
    const modalAvatarImg = document.getElementById('modalAvatarImg');
    const modalAvatarSvg = document.getElementById('modalAvatarSvg');

    if (avatarSrc) {
        if (navAvatarImg) {
            navAvatarImg.src = avatarSrc;
            navAvatarImg.classList.remove('hidden');
        }

        if (navAvatarSvg) navAvatarSvg.classList.add('hidden');

        if (modalAvatarImg) {
            modalAvatarImg.src = avatarSrc;
            modalAvatarImg.classList.remove('hidden');
        }

        if (modalAvatarSvg) modalAvatarSvg.classList.add('hidden');
    } else {
        if (navAvatarImg) navAvatarImg.classList.add('hidden');
        if (navAvatarSvg) navAvatarSvg.classList.remove('hidden');

        if (modalAvatarImg) modalAvatarImg.classList.add('hidden');
        if (modalAvatarSvg) modalAvatarSvg.classList.remove('hidden');
    }
}

function closeProfileModal() {
    resetModalErrors();

    const passwordContainer = document.getElementById('passwordContainer');
    const initVerifyContainer = document.getElementById('initVerifyContainer');
    const profileModal = document.getElementById('profileModal');

    if (passwordContainer) passwordContainer.classList.add('hidden');
    if (initVerifyContainer) initVerifyContainer.classList.remove('hidden');
    if (profileModal) profileModal.classList.add('hidden');
}

function initProfileEvents() {
    renderProfileData();

    const btnOpenProfile = document.getElementById('btnOpenProfile');
    if (btnOpenProfile) {
        btnOpenProfile.addEventListener('click', (e) => {
            e.preventDefault();
            window.openProfileModal();
        });
    }

    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', (e) => {
            e.preventDefault();
            window.handleLogout();
        });
    }

    const btnCloseProfile = document.getElementById('btnCloseProfile');
    const btnCancelProfile = document.getElementById('btnCancelProfile');
    const btnSaveProfile = document.getElementById('btnSaveProfile');

    const btnInitChangeEmail = document.getElementById('btnInitChangeEmail');
    const btnCloseEmailGuide = document.getElementById('btnCloseEmailGuide');
    const inputNewEmail = document.getElementById('inputNewEmail');
    const btnSubmitNewEmail = document.getElementById('btnSubmitNewEmail');
    const newEmailError = document.getElementById('newEmailError');
    const emailGuideModal = document.getElementById('emailGuideModal');

    const btnInitChangePassword = document.getElementById('btnInitChangePassword');
    const modalPassword = document.getElementById('modalPassword');
    const modalUsername = document.getElementById('modalUsername');
    const passwordContainer = document.getElementById('passwordContainer');
    const initVerifyContainer = document.getElementById('initVerifyContainer');
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');

    const otpModal = document.getElementById('otpModal');
    const otpModalTitle = document.getElementById('otpModalTitle');
    const otpModalSubtext = document.getElementById('otpModalSubtext');
    const btnCloseOtp = document.getElementById('btnCloseOtp');
    const otpInputs = Array.from(document.querySelectorAll('.otp-input'));
    const btnResendOtp = document.getElementById('btnResendOtp');
    const btnManualRetryOtp = document.getElementById('btnManualRetryOtp');
    const otpErrorMessage = document.getElementById('otpErrorMessage');

    const cropModal = document.getElementById('cropModal');
    const cropImage = document.getElementById('cropImage');
    const btnCloseCrop = document.getElementById('btnCloseCrop');
    const btnCancelCrop = document.getElementById('btnCancelCrop');
    const btnApplyCrop = document.getElementById('btnApplyCrop');
    const btnRotateLeft = document.getElementById('btnRotateLeft');
    const btnRotateRight = document.getElementById('btnRotateRight');
    const inputAvatar = document.getElementById('inputAvatar');

    if (btnCloseProfile) {
        btnCloseProfile.addEventListener('click', closeProfileModal);
    }

    if (btnCancelProfile) {
        btnCancelProfile.addEventListener('click', closeProfileModal);
    }

    // Ganti Email
    if (btnInitChangeEmail) {
        btnInitChangeEmail.addEventListener('click', (e) => {
            e.stopPropagation();

            if (inputNewEmail) inputNewEmail.value = '';

            if (newEmailError) {
                newEmailError.textContent = '';
                newEmailError.classList.add('hidden');
            }

            if (emailGuideModal) {
                emailGuideModal.classList.remove('hidden');
            }

            if (inputNewEmail) inputNewEmail.focus();
        });
    }

    if (btnCloseEmailGuide) {
        btnCloseEmailGuide.addEventListener('click', () => {
            if (emailGuideModal) {
                emailGuideModal.classList.add('hidden');
            }
        });
    }

    if (btnSubmitNewEmail) {
        btnSubmitNewEmail.addEventListener('click', async () => {
            const emailVal = inputNewEmail.value.trim();
            const authToken = localStorage.getItem('auth_token');

            if (newEmailError) {
                newEmailError.textContent = '';
                newEmailError.classList.add('hidden');
            }

            const emailFormatRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!emailFormatRegex.test(emailVal)) {
                if (newEmailError) {
                    newEmailError.textContent =
                        'Silahkan masukkan format email (@...) yang benar';

                    newEmailError.classList.remove('hidden');
                }

                return;
            }

            try {
                btnSubmitNewEmail.disabled = true;
                btnSubmitNewEmail.textContent = 'Mengirim OTP...';

                const response = await fetch(
                    `${API_BASE_URL}/profile/change-email`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${authToken}`,
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            email: emailVal
                        })
                    }
                );

                const result = await response.json();

                if (response.ok) {
                    if (emailGuideModal) {
                        emailGuideModal.classList.add('hidden');
                    }

                    otpMode = 'new_email';
                    pendingNewEmail = emailVal;

                    if (otpModalTitle) {
                        otpModalTitle.textContent = 'Verifikasi Kode OTP';
                    }

                    if (otpModalSubtext) {
                        otpModalSubtext.textContent =
                            'Kode verifikasi 6 angka telah dikirim ke email Anda.';
                    }

                    clearOtpInputs();

                    if (otpErrorMessage) {
                        otpErrorMessage.classList.add('hidden');
                        otpErrorMessage.textContent = '';
                    }

                    if (otpModal) {
                        otpModal.classList.remove('hidden');
                    }

                    if (otpInputs[0]) {
                        otpInputs[0].focus();
                    }

                    const firstCooldownMs = BASE_COOLDOWN_SECONDS * 1000;
                    const now = Date.now();

                    localStorage.setItem(
                        `otp_cooldown_end_${otpMode}_${userId}`,
                        (now + firstCooldownMs).toString()
                    );

                    localStorage.setItem(
                        `otp_current_duration_${otpMode}_${userId}`,
                        BASE_COOLDOWN_SECONDS.toString()
                    );

                    localStorage.setItem(
                        `otp_last_sent_time_${otpMode}_${userId}`,
                        now.toString()
                    );

                    startCooldownTimer();
                } else {
                    if (newEmailError) {
                        newEmailError.textContent =
                            result.message || 'Gagal mengirim OTP ke email tersebut.';

                        newEmailError.classList.remove('hidden');
                    }
                }
            } catch (error) {
                if (newEmailError) {
                    newEmailError.textContent =
                        'Terjadi kesalahan koneksi server.';

                    newEmailError.classList.remove('hidden');
                }
            } finally {
                btnSubmitNewEmail.disabled = false;
                btnSubmitNewEmail.textContent = 'Kirim Kode OTP';
            }
        });
    }

    // Fungsi OTP
    function clearOtpInputs() {
        otpInputs.forEach((input) => {
            input.value = '';
        });
    }

    function getFullOtp() {
        return otpInputs.map((input) => input.value).join('');
    }

    function getStoredState() {
        return {
            cooldownEndTime: parseInt(
                localStorage.getItem(
                    `otp_cooldown_end_${otpMode}_${userId}`
                ) || '0',
                10
            ),

            currentDuration: parseInt(
                localStorage.getItem(
                    `otp_current_duration_${otpMode}_${userId}`
                ) || BASE_COOLDOWN_SECONDS.toString(),
                10
            ),

            lastSentTime: parseInt(
                localStorage.getItem(
                    `otp_last_sent_time_${otpMode}_${userId}`
                ) || '0',
                10
            )
        };
    }

    async function sendOtpToEmail() {
        const authToken = localStorage.getItem('auth_token');

        const endpoint = otpMode === 'new_email'
            ? `${API_BASE_URL}/profile/change-email`
            : `${API_BASE_URL}/profile/send-otp`;

        const payload = otpMode === 'new_email'
            ? { email: pendingNewEmail }
            : {};

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

        if (!/^\d{6}$/.test(String(otpCode))) {
            return;
        }

        isVerifyingOtp = true;

        const authToken = localStorage.getItem('auth_token');

        const endpoint = otpMode === 'new_email'
            ? `${API_BASE_URL}/profile/verify-new-email-otp`
            : `${API_BASE_URL}/profile/verify-otp`;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${authToken}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    otp: String(otpCode).trim()
                })
            });

            const result = await response.json();

            if (response.ok) {
                if (otpErrorMessage) {
                    otpErrorMessage.classList.add('hidden');
                    otpErrorMessage.textContent = '';
                }

                if (otpModal) {
                    otpModal.classList.add('hidden');
                }

                if (otpMode === 'new_email') {
                    alert('Email telah diperbaharui');

                    const currentData = JSON.parse(
                        localStorage.getItem('user_data') || '{}'
                    );

                    currentData.email = pendingNewEmail;

                    localStorage.setItem(
                        'user_data',
                        JSON.stringify(currentData)
                    );

                    renderProfileData();
                } else {
                    if (passwordContainer) {
                        passwordContainer.classList.remove('hidden');
                    }

                    if (initVerifyContainer) {
                        initVerifyContainer.classList.add('hidden');
                    }

                    if (modalPassword) {
                        modalPassword.focus();
                    }
                }
            } else {
                if (otpErrorMessage) {
                    otpErrorMessage.textContent =
                        result.message || 'Kode verifikasi salah';

                    otpErrorMessage.classList.remove('hidden');
                }
            }
        } catch (error) {
            if (otpErrorMessage) {
                otpErrorMessage.textContent =
                    'Terjadi kesalahan koneksi saat memverifikasi kode.';

                otpErrorMessage.classList.remove('hidden');
            }
        } finally {
            isVerifyingOtp = false;
        }
    }

    // Cooldown OTP
    function updateTimerUI() {
        const timerText = document.getElementById('timerText');
        const state = getStoredState();
        const now = Date.now();

        const secondsLeft = Math.ceil(
            (state.cooldownEndTime - now) / 1000
        );

        if (secondsLeft <= 0) {
            clearInterval(cooldownInterval);

            if (state.cooldownEndTime > 0) {
                localStorage.removeItem(
                    `otp_cooldown_end_${otpMode}_${userId}`
                );
            }

            if (btnResendOtp) {
                btnResendOtp.disabled = false;
                btnResendOtp.innerText = 'Kirim ulang via email';

                btnResendOtp.className =
                    'text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline cursor-pointer focus:outline-none';
            }

            if (btnManualRetryOtp) {
                btnManualRetryOtp.disabled = false;

                btnManualRetryOtp.classList.remove(
                    'opacity-50',
                    'cursor-not-allowed'
                );
            }

            if (timerText) {
                timerText.textContent = '0s';
            }
        } else {
            if (btnResendOtp) {
                btnResendOtp.disabled = true;
                btnResendOtp.innerText = 'Kirim ulang via email';

                btnResendOtp.className =
                    'text-xs font-medium text-slate-400 cursor-not-allowed focus:outline-none';
            }

            if (btnManualRetryOtp) {
                btnManualRetryOtp.disabled = true;

                btnManualRetryOtp.classList.add(
                    'opacity-50',
                    'cursor-not-allowed'
                );
            }

            if (timerText) {
                timerText.textContent = `${secondsLeft}s`;
            }
        }
    }

    function startCooldownTimer() {
        clearInterval(cooldownInterval);
        updateTimerUI();

        cooldownInterval = setInterval(updateTimerUI, 1000);
    }

    async function triggerOtpRequest() {
        if (isSendingOtp) return;

        const state = getStoredState();
        const now = Date.now();

        let nextDuration = BASE_COOLDOWN_SECONDS;

        if (
            state.lastSentTime > 0 &&
            (now - state.lastSentTime) > RESET_IDLE_TIME_MS
        ) {
            nextDuration = BASE_COOLDOWN_SECONDS;
        } else if (state.lastSentTime > 0) {
            nextDuration =
                state.currentDuration + INCREMENT_COOLDOWN_SECONDS;
        }

        if (state.cooldownEndTime > now) {
            startCooldownTimer();
            return;
        }

        isSendingOtp = true;

        if (btnResendOtp) {
            btnResendOtp.disabled = true;
            btnResendOtp.innerText = 'Mengirim...';
        }

        if (btnManualRetryOtp) {
            btnManualRetryOtp.disabled = true;

            btnManualRetryOtp.classList.add(
                'opacity-50',
                'cursor-not-allowed'
            );
        }

        const sent = await sendOtpToEmail();

        isSendingOtp = false;

        if (sent) {
            const nextEndTime =
                Date.now() + (nextDuration * 1000);

            localStorage.setItem(
                `otp_cooldown_end_${otpMode}_${userId}`,
                nextEndTime.toString()
            );

            localStorage.setItem(
                `otp_current_duration_${otpMode}_${userId}`,
                nextDuration.toString()
            );

            localStorage.setItem(
                `otp_last_sent_time_${otpMode}_${userId}`,
                Date.now().toString()
            );

            startCooldownTimer();
        } else {
            if (btnResendOtp) {
                btnResendOtp.disabled = false;
                btnResendOtp.innerText = 'Kirim ulang via email';

                btnResendOtp.className =
                    'text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline cursor-pointer focus:outline-none';
            }

            if (btnManualRetryOtp) {
                btnManualRetryOtp.disabled = false;

                btnManualRetryOtp.classList.remove(
                    'opacity-50',
                    'cursor-not-allowed'
                );
            }
        }
    }

    // Modal Verifikasi Password
    if (btnInitChangePassword) {
        btnInitChangePassword.addEventListener('click', (e) => {
            e.stopPropagation();

            otpMode = 'password';

            if (otpModalTitle) {
                otpModalTitle.textContent = 'Verifikasi Kode OTP';
            }

            if (otpModalSubtext) {
                otpModalSubtext.textContent =
                    'Kode verifikasi 6 angka telah dikirim via email.';
            }

            clearOtpInputs();

            if (otpErrorMessage) {
                otpErrorMessage.classList.add('hidden');
                otpErrorMessage.textContent = '';
            }

            if (otpModal) {
                otpModal.classList.remove('hidden');
            }

            if (otpInputs[0]) {
                otpInputs[0].focus();
            }

            const state = getStoredState();

            if (state.cooldownEndTime > Date.now()) {
                startCooldownTimer();
            } else {
                triggerOtpRequest();
            }
        });
    }

    if (btnResendOtp) {
        btnResendOtp.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            if (!btnResendOtp.disabled && !isSendingOtp) {
                triggerOtpRequest();
            }
        });
    }

    if (btnManualRetryOtp) {
        btnManualRetryOtp.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            if (!btnManualRetryOtp.disabled && !isSendingOtp) {
                triggerOtpRequest();
            }
        });
    }

    function distributeOtpDigits(rawValue) {
        const digits = String(rawValue || '')
            .replace(/\D/g, '')
            .slice(0, otpInputs.length);

        if (!digits) return;

        otpInputs.forEach((otpInput, index) => {
            otpInput.value = digits[index] || '';
        });

        const nextIndex = Math.min(
            digits.length,
            otpInputs.length - 1
        );

        if (otpInputs[nextIndex]) {
            otpInputs[nextIndex].focus();
        }

        if (
            digits.length === otpInputs.length &&
            /^\d{6}$/.test(digits)
        ) {
            verifyOtpCode(digits);
        }
    }

    otpInputs.forEach((input, index) => {
        input.addEventListener('paste', (e) => {
            e.preventDefault();

            const clipboard =
                e.clipboardData || window.clipboardData;

            const pastedData = clipboard
                ? clipboard.getData('text')
                : '';

            distributeOtpDigits(pastedData);
        });

        input.addEventListener('input', (e) => {
            const digits = String(e.target.value || '')
                .replace(/\D/g, '');

            if (digits.length > 1) {
                distributeOtpDigits(digits);
                return;
            }

            e.target.value = digits.slice(0, 1);

            if (
                e.target.value &&
                index < otpInputs.length - 1
            ) {
                otpInputs[index + 1].focus();
            }

            const fullOtp = getFullOtp();

            if (
                fullOtp.length === otpInputs.length &&
                /^\d{6}$/.test(fullOtp)
            ) {
                verifyOtpCode(fullOtp);
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace') {
                if (input.value) {
                    input.value = '';
                } else if (
                    index > 0 &&
                    otpInputs[index - 1]
                ) {
                    otpInputs[index - 1].focus();
                    otpInputs[index - 1].value = '';
                }
            } else if (
                e.key === 'ArrowLeft' &&
                index > 0
            ) {
                otpInputs[index - 1].focus();
            } else if (
                e.key === 'ArrowRight' &&
                index < otpInputs.length - 1
            ) {
                otpInputs[index + 1].focus();
            }
        });
    });

    if (btnCloseOtp) {
        btnCloseOtp.addEventListener('click', (e) => {
            e.stopPropagation();

            if (otpModal) {
                otpModal.classList.add('hidden');
            }
        });
    }

    // Toggle Password
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', () => {
            const eyeOffIcon = document.getElementById('eyeOffIcon');
            const eyeOnIcon = document.getElementById('eyeOnIcon');

            const isPassword =
                modalPassword.getAttribute('type') === 'password';

            modalPassword.setAttribute(
                'type',
                isPassword ? 'text' : 'password'
            );

            if (eyeOffIcon) {
                eyeOffIcon.classList.toggle('hidden');
            }

            if (eyeOnIcon) {
                eyeOnIcon.classList.toggle('hidden');
            }
        });
    }

    // Simpan Perubahan Profil
    if (btnSaveProfile) {
        btnSaveProfile.addEventListener('click', async () => {
            const authToken = localStorage.getItem('auth_token');

            if (!authToken) {
                alert(
                    'Sesi anda telah berakhir. Silakan login kembali.'
                );

                window.location.href = '../login/login.html';
                return;
            }

            resetModalErrors();

            const pwd = modalPassword
                ? modalPassword.value.trim()
                : '';

            if (
                passwordContainer &&
                !passwordContainer.classList.contains('hidden') &&
                pwd !== ''
            ) {
                const passwordRegex =
                    /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[^\s]{8,}$/;

                if (!passwordRegex.test(pwd)) {
                    showPasswordError(
                        'Password minimal 8 karakter, ada huruf besar, kecil, angka, & simbol!'
                    );

                    return;
                }
            }

            const formData = new FormData();

            formData.append(
                'username',
                modalUsername ? modalUsername.value.trim() : ''
            );

            if (
                passwordContainer &&
                !passwordContainer.classList.contains('hidden') &&
                pwd !== ''
            ) {
                formData.append('password', pwd);
            }

            if (tempCroppedImageBase64) {
                const responseBlob = await fetch(
                    tempCroppedImageBase64
                );

                const blob = await responseBlob.blob();

                formData.append('avatar', blob, 'avatar.jpg');
            }

            try {
                btnSaveProfile.disabled = true;
                btnSaveProfile.textContent = 'Menyimpan...';

                const response = await fetch(
                    `${API_BASE_URL}/profile/update`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${authToken}`,
                            'Accept': 'application/json'
                        },
                        body: formData
                    }
                );

                const result = await response.json();

                if (response.ok) {
                    const updatedUser =
                        result.data || result.user || result;

                    localStorage.setItem(
                        'user_data',
                        JSON.stringify(updatedUser)
                    );

                    renderProfileData();
                    closeProfileModal();

                    alert('Berhasil memperbaharui profil!');
                } else {
                    if (result.errors && result.errors.username) {
                        showUsernameError(
                            result.errors.username[0] ||
                            'Username sudah digunakan.'
                        );
                    } else if (
                        result.errors &&
                        result.errors.password
                    ) {
                        showPasswordError(
                            result.errors.password[0]
                        );
                    } else {
                        alert(
                            result.message ||
                            'Gagal memperbarui profil.'
                        );
                    }
                }
            } catch (error) {
                alert('Terjadi kesalahan koneksi ke server.');
            } finally {
                btnSaveProfile.disabled = false;
                btnSaveProfile.textContent = 'Simpan';
            }
        });
    }

    // Foto Profil & Cropper
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
                    if (cropImage) {
                        cropImage.src = event.target.result;
                    }

                    if (cropModal) {
                        cropModal.classList.remove('hidden');
                    }

                    if (cropper) {
                        cropper.destroy();
                    }

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

    if (btnRotateLeft) {
        btnRotateLeft.addEventListener('click', () => {
            if (cropper) cropper.rotate(-90);
        });
    }

    if (btnRotateRight) {
        btnRotateRight.addEventListener('click', () => {
            if (cropper) cropper.rotate(90);
        });
    }

    function closeCropModal() {
        if (cropModal) {
            cropModal.classList.add('hidden');
        }

        if (inputAvatar) {
            inputAvatar.value = '';
        }

        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
    }

    if (btnCloseCrop) {
        btnCloseCrop.addEventListener('click', closeCropModal);
    }

    if (btnCancelCrop) {
        btnCancelCrop.addEventListener('click', closeCropModal);
    }

    if (btnApplyCrop) {
        btnApplyCrop.addEventListener('click', () => {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({
                    width: 300,
                    height: 300
                });

                tempCroppedImageBase64 =
                    canvas.toDataURL('image/jpeg');

                const modalAvatarImg =
                    document.getElementById('modalAvatarImg');

                const modalAvatarSvg =
                    document.getElementById('modalAvatarSvg');

                if (modalAvatarImg) {
                    modalAvatarImg.src = tempCroppedImageBase64;
                    modalAvatarImg.classList.remove('hidden');
                }

                if (modalAvatarSvg) {
                    modalAvatarSvg.classList.add('hidden');
                }

                closeCropModal();
            }
        });
    }

    // Sidebar Responsive
    const toggleSidebarNav =
        document.getElementById('toggleSidebarNav');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (window.innerWidth >= 768) return;

        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }

        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('hidden');
        }
    }

    if (toggleSidebarNav) {
        toggleSidebarNav.addEventListener(
            'click',
            toggleSidebar
        );
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener(
            'click',
            toggleSidebar
        );
    }
}

function resetModalErrors() {
    const modalPassword =
        document.getElementById('modalPassword');

    const eyeOffIcon =
        document.getElementById('eyeOffIcon');

    const eyeOnIcon =
        document.getElementById('eyeOnIcon');

    const usernameError =
        document.getElementById('usernameError');

    const modalUsername =
        document.getElementById('modalUsername');

    const passwordError =
        document.getElementById('passwordError');

    const passwordHint =
        document.getElementById('passwordHint');

    if (modalPassword) {
        modalPassword.setAttribute('type', 'password');
    }

    if (eyeOffIcon) {
        eyeOffIcon.classList.remove('hidden');
    }

    if (eyeOnIcon) {
        eyeOnIcon.classList.add('hidden');
    }

    if (usernameError) {
        usernameError.textContent = '';
        usernameError.classList.add('hidden');
    }

    if (modalUsername) {
        modalUsername.classList.remove('border-red-500');
        modalUsername.classList.add('border-slate-300');
    }

    if (passwordError) {
        passwordError.textContent = '';
        passwordError.classList.add('hidden');
    }

    if (passwordHint) {
        passwordHint.classList.remove('hidden');
    }
}

function showUsernameError(msg) {
    const usernameError =
        document.getElementById('usernameError');

    const modalUsername =
        document.getElementById('modalUsername');

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
    const passwordError =
        document.getElementById('passwordError');

    const passwordHint =
        document.getElementById('passwordHint');

    const modalPassword =
        document.getElementById('modalPassword');

    if (passwordError) {
        passwordError.textContent = msg;
        passwordError.classList.remove('hidden');
    }

    if (passwordHint) {
        passwordHint.classList.add('hidden');
    }

    if (modalPassword) {
        modalPassword.classList.add('border-red-500');
        modalPassword.classList.remove('border-slate-300');
    }
}

initProfileEvents();