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
let passwordTarget = 'siswa';

const BASE_COOLDOWN_SECONDS = 30;
const INCREMENT_COOLDOWN_SECONDS = 15;
const RESET_IDLE_TIME_MS = 10 * 60 * 1000;

// Helper: Dapatkan data siswa aktif (jika login sebagai Orang Tua, ambil siswa yang dipilih di dropdown)
window.getEffectiveStudentData = function() {
    const currentData = JSON.parse(localStorage.getItem('user_data') || '{}');
    if (currentData.title === 'Orang Tua') {
        const linked = currentData.linked_students || currentData.linkedStudents || [];
        if (linked.length > 0) {
            const savedStudentId = localStorage.getItem('active_parent_student_id');
            const found = linked.find(s => String(s.id_user) === String(savedStudentId));
            const chosen = found || linked[0];
            localStorage.setItem('active_parent_student_id', String(chosen.id_user));
            return chosen;
        }
    }
    return currentData;
};

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
    const isParent = currentData.title === 'Orang Tua';
    const effectiveStudent = window.getEffectiveStudentData();

    const fullname = currentData.fullname || currentData.username || 'Siswa';
    const firstName = getFirstName(fullname);
    const username = currentData.username || '';
    const phone = currentData.no_telp || '';
    const nis = isParent ? (effectiveStudent.nis || '-') : (currentData.nis || '-');
    const nisn = isParent ? (effectiveStudent.nisn || '-') : (currentData.nisn || '-');

    let avatarSrc = currentData.avatar_url || null;

    if (!avatarSrc && currentData.avatar) {
        avatarSrc = `${API_BASE_URL.replace('/api', '')}/storage/${currentData.avatar}`;
    }

    const navFullname = document.getElementById('navFullname');
    const navNis = document.getElementById('navNis');
    const userNameDisplay = document.getElementById('userName');

    const modalFullname = document.getElementById('modalFullname');
    const modalUsername = document.getElementById('modalUsername');
    const modalPhone = document.getElementById('modalPhone');
    const modalPassword = document.getElementById('modalPassword');
    const modalNis = document.getElementById('modalNis');
    const modalNisn = document.getElementById('modalNisn');

    const displayGreetingName = isParent
        ? `${getFirstName(effectiveStudent.fullname || effectiveStudent.username || fullname)} (Ortu: ${firstName})`
        : firstName;

    // Menampilkan nama depan di navbar dan sapaan dashboard
    if (navFullname) navFullname.textContent = isParent ? `${firstName} (Orang Tua)` : firstName;
    if (navNis) navNis.textContent = nis;
    if (userNameDisplay) userNameDisplay.textContent = displayGreetingName;

    if (modalFullname) modalFullname.value = fullname;
    if (modalUsername) modalUsername.value = username;
    if (modalPhone) modalPhone.value = phone;
    if (modalPassword) modalPassword.value = '';
    if (modalNis) modalNis.value = nis;
    if (modalNisn) modalNisn.value = nisn;

    // UPDATE NAVBAR TITLE SISWA DENGAN KELAS LENGKAP
    const classSource = isParent ? effectiveStudent : currentData;
    let classLabel = '';
    if (classSource.school_class) {
        const sc = classSource.school_class;
        classLabel = `${sc.kelas_nomor || ''} ${sc.nama_kelas || ''} ${sc.nomor_kelas || ''}`.replace(/\s+/g, ' ').trim();
    } else if (classSource.schoolClass) {
        const sc = classSource.schoolClass;
        classLabel = `${sc.kelas_nomor || ''} ${sc.nama_kelas || ''} ${sc.nomor_kelas || ''}`.replace(/\s+/g, ' ').trim();
    } else if (classSource.kelas) {
        classLabel = classSource.kelas;
    }

    const navHeading = document.querySelector('nav h1');
    if (navHeading) {
        const panelRoleText = isParent ? 'Panel Orang Tua' : 'Panel Siswa';
        navHeading.textContent = classLabel 
            ? `SCP Sekolah - ${panelRoleText} | ${classLabel}` 
            : `SCP Sekolah - ${panelRoleText}`;
    }

    renderParentStudentDropdown(currentData);
    updateAvatarUI(avatarSrc);
}

function renderParentStudentDropdown(currentData) {
    const wrapper = document.getElementById('parentStudentDropdownWrapper');
    const selector = document.getElementById('parentStudentSelector');
    if (!wrapper || !selector) return;

    if (currentData.title !== 'Orang Tua') {
        wrapper.classList.add('hidden');
        return;
    }

    const linked = currentData.linked_students || currentData.linkedStudents || [];
    if (linked.length === 0) {
        wrapper.classList.add('hidden');
        return;
    }

    wrapper.classList.remove('hidden');
    const activeId = localStorage.getItem('active_parent_student_id') || String(linked[0].id_user);

    selector.innerHTML = linked.map(st => {
        const sc = st.school_class || st.schoolClass;
        const clsStr = sc ? `${sc.kelas_nomor || ''} ${sc.nama_kelas || ''}${sc.nomor_kelas || ''}`.trim() : '';
        const label = `${st.fullname || st.username}${clsStr ? ' - ' + clsStr : ''}`;
        const selected = String(st.id_user) === String(activeId) ? 'selected' : '';
        return `<option value="${st.id_user}" class="text-slate-800 bg-white font-medium" ${selected}>${label}</option>`;
    }).join('');

    if (!selector.dataset.boundChange) {
        selector.dataset.boundChange = 'true';
        selector.addEventListener('change', (e) => {
            localStorage.setItem('active_parent_student_id', String(e.target.value));
            window.location.reload();
        });
    }
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
            navAvatarImg.onerror = () => {
                navAvatarImg.classList.add('hidden');
                if (navAvatarSvg) navAvatarSvg.classList.remove('hidden');
            };
        }

        if (navAvatarSvg) navAvatarSvg.classList.add('hidden');

        if (modalAvatarImg) {
            modalAvatarImg.src = avatarSrc;
            modalAvatarImg.classList.remove('hidden');
            modalAvatarImg.onerror = () => {
                modalAvatarImg.classList.add('hidden');
                if (modalAvatarSvg) modalAvatarSvg.classList.remove('hidden');
            };
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
        const effectiveStudent = window.getEffectiveStudentData();

        const endpoint = otpMode === 'new_email'
            ? `${API_BASE_URL}/profile/change-email`
            : `${API_BASE_URL}/profile/send-otp`;

        const payload = otpMode === 'new_email'
            ? { email: pendingNewEmail }
            : { target: passwordTarget, student_id: effectiveStudent?.id_user };

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
                    otp: String(otpCode).trim(),
                    target: passwordTarget
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
                        const lbl = passwordContainer.querySelector('label[for="modalPassword"]');
                        if (lbl) {
                            lbl.textContent = passwordTarget === 'ortu'
                                ? 'PASSWORD BARU (AKUN ORANG TUA)'
                                : 'PASSWORD BARU (AKUN SISWA)';
                        }
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

    // Fungsi mulai proses OTP setelah memilih target password (Siswa / Orang Tua)
    window.startPasswordOtpFlow = function(targetType) {
        passwordTarget = targetType || 'siswa';
        otpMode = 'password';

        const pwdChoiceModal = document.getElementById('passwordChoiceModal');
        if (pwdChoiceModal) pwdChoiceModal.classList.add('hidden');

        if (otpModalTitle) {
            otpModalTitle.textContent = passwordTarget === 'ortu'
                ? 'Verifikasi OTP (Password Orang Tua)'
                : 'Verifikasi OTP (Password Siswa)';
        }

        if (otpModalSubtext) {
            otpModalSubtext.textContent =
                'Kode verifikasi 6 angka telah dikirim ke email siswa.';
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
    };

    // Modal Pilihan Ganti Password (Siswa / Orang Tua)
    if (btnInitChangePassword) {
        btnInitChangePassword.addEventListener('click', (e) => {
            e.stopPropagation();
            const pwdChoiceModal = document.getElementById('passwordChoiceModal');
            if (pwdChoiceModal) {
                pwdChoiceModal.classList.remove('hidden');
            } else {
                window.startPasswordOtpFlow('siswa');
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
            const modalPhone = document.getElementById('modalPhone');
            const effectiveStudent = window.getEffectiveStudentData();

            formData.append(
                'username',
                modalUsername ? modalUsername.value.trim() : ''
            );

            if (modalPhone) {
                formData.append('no_telp', modalPhone.value.trim());
            }

            if (
                passwordContainer &&
                !passwordContainer.classList.contains('hidden') &&
                pwd !== ''
            ) {
                formData.append('password', pwd);
                formData.append('password_target', passwordTarget);
                if (effectiveStudent && effectiveStudent.id_user) {
                    formData.append('student_id', effectiveStudent.id_user);
                }
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
                    const updatedUser =
                        result.data || result.user || result;

                    localStorage.setItem(
                        'user_data',
                        JSON.stringify(updatedUser)
                    );

                    tempCroppedImageBase64 = null;
                    renderProfileData();
                    closeProfileModal();

                    alert('Berhasil memperbaharui profil!');
                } else {
                    const msg = result?.message || result?.error || `Gagal memperbarui profil (HTTP ${response.status}).`;
                    if (result?.errors?.username) {
                        showUsernameError(
                            result.errors.username[0] ||
                            'Username sudah digunakan.'
                        );
                    } else if (result?.errors?.avatar) {
                        alert(`Foto profil gagal diunggah: ${result.errors.avatar[0]}`);
                    } else if (
                        result?.errors?.password
                    ) {
                        showPasswordError(
                            result.errors.password[0]
                        );
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

// ============================================================================
// FITUR AKUN ORANG TUA & PILIHAN GANTI PASSWORD (SISWA / ORANG TUA)
// ============================================================================
let detectedExistingParent = null;
let pendingParentSaveMode = 'create'; // 'create' | 'link'
let fullnameCheckTimer = null;
let lastCheckedFullname = '';

function injectParentAndPasswordModals() {
    if (document.getElementById('createParentModal')) return;

    const modalHtml = `
    <!-- 1. MODAL BUAT AKUN ORANG TUA -->
    <div id="createParentModal" class="fixed inset-0 bg-slate-900/60 z-[60] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col transform transition-all">
            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-bold text-lg">Buat Akun Orang Tua</h3>
                <button type="button" id="btnCloseCreateParent" class="text-indigo-200 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[80vh]">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Full Name Orang Tua <span class="text-red-500">*</span></label>
                    <input type="text" id="parentFullnameInput" autocomplete="off" placeholder="Masukkan nama lengkap orang tua..." class="w-full px-3.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                    <p id="parentFullnameError" class="text-[11px] text-red-500 italic mt-1 hidden"></p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" id="parentUsernameInput" autocomplete="off" placeholder="Masukkan username orang tua..." class="w-full px-3.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                    <p id="parentUsernameError" class="text-[11px] text-red-500 italic mt-1 hidden"></p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Password <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" id="parentPasswordInput" placeholder="Masukkan password..." class="w-full pl-3.5 pr-10 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                        <button type="button" id="toggleParentPwdBtn" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-indigo-600 focus:outline-none">
                            <svg id="parentEyeOffIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"></path></svg>
                            <svg id="parentEyeOnIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                    </div>
                    <p id="parentPasswordHint" class="text-[11px] text-slate-400 mt-1">Minimal 8 karakter, huruf besar, huruf kecil, angka, simbol, tanpa spasi.</p>
                    <p id="parentPasswordError" class="text-[11px] text-red-500 italic mt-1 hidden"></p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Nomor Telepon <span class="text-red-500">*</span></label>
                    <input type="text" id="parentPhoneInput" autocomplete="off" placeholder="Contoh: 081234567890" class="w-full px-3.5 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                    <p id="parentPhoneError" class="text-[11px] text-red-500 italic mt-1 hidden"></p>
                </div>
            </div>
            <div class="bg-slate-50 px-6 py-3.5 flex justify-end space-x-2 border-t border-slate-200 shrink-0">
                <button type="button" id="btnCancelCreateParent" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-sm font-medium transition">Batal</button>
                <button type="button" id="btnSubmitCreateParent" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition shadow">Simpan</button>
            </div>
        </div>
    </div>

    <!-- 2. POP-UP DETEKSI AKUN ORANG TUA SUDAH DIBUAT -->
    <div id="parentExistsConfirmModal" class="fixed inset-0 bg-slate-950/70 z-[70] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden p-6 text-center transform transition-all">
            <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-600 mx-auto flex items-center justify-center mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 mb-2">Akun Orang Tua Terdeteksi</h3>
            <p class="text-xs text-slate-600 mb-6 leading-relaxed">
                Akun orang tua sudah di buat, lanjutkan buat akun orang tua?
            </p>
            <div class="flex items-center justify-center gap-3">
                <button type="button" id="btnParentExistsNo" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    Tidak
                </button>
                <button type="button" id="btnParentExistsYes" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-md">
                    Ya
                </button>
            </div>
        </div>
    </div>

    <!-- 3. MODAL MASUKKAN PASSWORD AKUN ORANG TUA YANG SUDAH ADA -->
    <div id="linkParentPasswordModal" class="fixed inset-0 bg-slate-900/60 z-[65] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col transform transition-all">
            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-bold text-lg">Hubungkan Akun Orang Tua</h3>
                <button type="button" id="btnCloseLinkParent" class="text-indigo-200 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase mb-1">Full Name Orang Tua</label>
                    <input type="text" id="linkParentFullnameDisplay" readonly class="w-full px-3.5 py-2 rounded-lg bg-slate-100 border border-slate-300 text-slate-700 text-sm font-semibold outline-none cursor-not-allowed">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Masukkan Password Akun Orang Tua <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" id="linkParentPasswordInput" placeholder="Masukkan password akun orang tua tersebut..." class="w-full pl-3.5 pr-10 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm transition">
                        <button type="button" id="toggleLinkParentPwdBtn" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-indigo-600 focus:outline-none">
                            <svg id="linkParentEyeOffIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"></path></svg>
                            <svg id="linkParentEyeOnIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        </button>
                    </div>
                    <p id="linkParentPasswordError" class="text-[11px] text-red-500 italic mt-1 hidden"></p>
                </div>
            </div>
            <div class="bg-slate-50 px-6 py-3.5 flex justify-end space-x-2 border-t border-slate-200 shrink-0">
                <button type="button" id="btnCancelLinkParent" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-sm font-medium transition">Batal</button>
                <button type="button" id="btnSubmitLinkParent" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition shadow">Simpan</button>
            </div>
        </div>
    </div>

    <!-- 4. POP-UP KONFIRMASI SIMPAN AKUN ORANG TUA (YAKIN & TIDAK) -->
    <div id="parentSaveConfirmModal" class="fixed inset-0 bg-slate-950/70 z-[75] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden p-6 text-center transform transition-all">
            <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 mx-auto flex items-center justify-center mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 mb-1">Konfirmasi Simpan</h3>
            <p class="text-xs text-slate-500 mb-6 leading-relaxed">
                Apakah Anda yakin ingin menyimpan akun orang tua ini?
            </p>
            <div class="flex items-center justify-center gap-3">
                <button type="button" id="btnParentConfirmNo" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    Tidak
                </button>
                <button type="button" id="btnParentConfirmYes" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-md">
                    Yakin
                </button>
            </div>
        </div>
    </div>

    <!-- 5. POP-UP SUKSES AKUN TELAH DIBUAT -->
    <div id="parentSuccessModal" class="fixed inset-0 bg-slate-950/70 z-[80] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden p-6 text-center transform transition-all">
            <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mx-auto flex items-center justify-center mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 mb-1">Akun telah di buat</h3>
            <p class="text-xs text-slate-500 mb-5">Akun orang tua berhasil dibuat dan dihubungkan.</p>
            <button type="button" id="btnCloseParentSuccess" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition shadow-md">
                OK
            </button>
        </div>
    </div>

    <!-- 6. POP-UP PILIHAN GANTI PASSWORD (SISWA vs ORANG TUA) -->
    <div id="passwordChoiceModal" class="fixed inset-0 bg-slate-950/70 z-[65] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden p-6 text-center transform transition-all relative">
            <button type="button" id="btnClosePwdChoice" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
            <h3 class="text-base font-bold text-slate-800 mb-1">Pilih Ganti Password</h3>
            <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                Pilih akun yang ingin diganti passwordnya. Kode OTP akan dikirim ke email siswa.
            </p>
            <div class="space-y-2.5">
                <button type="button" onclick="window.startPasswordOtpFlow('siswa')" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                    Ganti Password Siswa
                </button>
                <button type="button" onclick="window.startPasswordOtpFlow('ortu')" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                    Ganti Password Orang Tua
                </button>
            </div>
        </div>
    </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    const createParentModal = document.getElementById('createParentModal');
    const parentFullnameInput = document.getElementById('parentFullnameInput');
    const parentUsernameInput = document.getElementById('parentUsernameInput');
    const parentPasswordInput = document.getElementById('parentPasswordInput');
    const parentPhoneInput = document.getElementById('parentPhoneInput');

    const parentFullnameError = document.getElementById('parentFullnameError');
    const parentUsernameError = document.getElementById('parentUsernameError');
    const parentPasswordError = document.getElementById('parentPasswordError');
    const parentPasswordHint = document.getElementById('parentPasswordHint');
    const parentPhoneError = document.getElementById('parentPhoneError');

    const toggleParentPwdBtn = document.getElementById('toggleParentPwdBtn');
    const parentEyeOffIcon = document.getElementById('parentEyeOffIcon');
    const parentEyeOnIcon = document.getElementById('parentEyeOnIcon');

    const parentExistsConfirmModal = document.getElementById('parentExistsConfirmModal');
    const linkParentPasswordModal = document.getElementById('linkParentPasswordModal');
    const linkParentFullnameDisplay = document.getElementById('linkParentFullnameDisplay');
    const linkParentPasswordInput = document.getElementById('linkParentPasswordInput');
    const linkParentPasswordError = document.getElementById('linkParentPasswordError');
    const toggleLinkParentPwdBtn = document.getElementById('toggleLinkParentPwdBtn');
    const linkParentEyeOffIcon = document.getElementById('linkParentEyeOffIcon');
    const linkParentEyeOnIcon = document.getElementById('linkParentEyeOnIcon');

    const parentSaveConfirmModal = document.getElementById('parentSaveConfirmModal');
    const parentSuccessModal = document.getElementById('parentSuccessModal');
    const passwordChoiceModal = document.getElementById('passwordChoiceModal');

    function resetCreateParentErrors() {
        [parentFullnameError, parentUsernameError, parentPasswordError, parentPhoneError].forEach(el => {
            if (el) { el.textContent = ''; el.classList.add('hidden'); }
        });
        [parentFullnameInput, parentUsernameInput, parentPasswordInput, parentPhoneInput].forEach(el => {
            if (el) { el.classList.remove('border-red-500'); el.classList.add('border-slate-300'); }
        });
        if (parentPasswordHint) parentPasswordHint.classList.remove('hidden');
    }

    window.openCreateParentModal = function() {
        resetCreateParentErrors();
        detectedExistingParent = null;
        lastCheckedFullname = '';
        if (parentFullnameInput) parentFullnameInput.value = '';
        if (parentUsernameInput) parentUsernameInput.value = '';
        if (parentPasswordInput) {
            parentPasswordInput.value = '';
            parentPasswordInput.setAttribute('type', 'password');
        }
        if (parentEyeOffIcon) parentEyeOffIcon.classList.remove('hidden');
        if (parentEyeOnIcon) parentEyeOnIcon.classList.add('hidden');
        if (parentPhoneInput) parentPhoneInput.value = '';
        if (createParentModal) createParentModal.classList.remove('hidden');
    };

    function closeCreateParentModal() {
        if (createParentModal) createParentModal.classList.add('hidden');
    }

    document.getElementById('btnCloseCreateParent')?.addEventListener('click', closeCreateParentModal);
    document.getElementById('btnCancelCreateParent')?.addEventListener('click', closeCreateParentModal);
    document.getElementById('btnClosePwdChoice')?.addEventListener('click', () => {
        if (passwordChoiceModal) passwordChoiceModal.classList.add('hidden');
    });

    // Eye toggle di Modal Buat Akun Orang Tua (default mata tertutup = password hidden)
    toggleParentPwdBtn?.addEventListener('click', () => {
        const isPwd = parentPasswordInput.getAttribute('type') === 'password';
        parentPasswordInput.setAttribute('type', isPwd ? 'text' : 'password');
        parentEyeOffIcon?.classList.toggle('hidden');
        parentEyeOnIcon?.classList.toggle('hidden');
    });

    // Eye toggle di Modal Hubungkan Akun Orang Tua
    toggleLinkParentPwdBtn?.addEventListener('click', () => {
        const isPwd = linkParentPasswordInput.getAttribute('type') === 'password';
        linkParentPasswordInput.setAttribute('type', isPwd ? 'text' : 'password');
        linkParentEyeOffIcon?.classList.toggle('hidden');
        linkParentEyeOnIcon?.classList.toggle('hidden');
    });

    // Deteksi Otomatis Fullname Orang Tua walaupun kolom lain sudah diisi
    async function checkExistingParentByFullname(fullnameStr) {
        const cleanName = String(fullnameStr || '').trim();
        if (cleanName.length < 3) return false;
        const authToken = localStorage.getItem('auth_token');
        try {
            const res = await fetch(`${API_BASE_URL}/parent-account/check?fullname=${encodeURIComponent(cleanName)}`, {
                headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
            });
            if (!res.ok) return false;
            const data = await res.json();
            if (data.exists && !data.already_linked) {
                detectedExistingParent = { id: data.parent_id, fullname: data.fullname };
                parentExistsConfirmModal?.classList.remove('hidden');
                return true;
            }
        } catch (err) {}
        return false;
    }

    parentFullnameInput?.addEventListener('input', (e) => {
        parentFullnameError?.classList.add('hidden');
        parentFullnameInput.classList.remove('border-red-500');
        const val = e.target.value.trim();
        clearTimeout(fullnameCheckTimer);
        if (val.length >= 3 && val.toLowerCase() !== lastCheckedFullname) {
            fullnameCheckTimer = setTimeout(async () => {
                lastCheckedFullname = val.toLowerCase();
                await checkExistingParentByFullname(val);
            }, 450);
        }
    });

    parentFullnameInput?.addEventListener('blur', async (e) => {
        const val = e.target.value.trim();
        if (val.length >= 3 && val.toLowerCase() !== lastCheckedFullname) {
            lastCheckedFullname = val.toLowerCase();
            await checkExistingParentByFullname(val);
        }
    });

    // Klik Tidak / Ya pada pop up "Akun orang tua sudah di buat, lanjutkan buat akun orang tua?"
    document.getElementById('btnParentExistsNo')?.addEventListener('click', () => {
        parentExistsConfirmModal?.classList.add('hidden');
    });

    document.getElementById('btnParentExistsYes')?.addEventListener('click', () => {
        parentExistsConfirmModal?.classList.add('hidden');
        createParentModal?.classList.add('hidden');
        if (linkParentFullnameDisplay && detectedExistingParent) {
            linkParentFullnameDisplay.value = detectedExistingParent.fullname;
        }
        if (linkParentPasswordInput) {
            linkParentPasswordInput.value = '';
            linkParentPasswordInput.setAttribute('type', 'password');
            linkParentPasswordInput.classList.remove('border-red-500');
        }
        linkParentEyeOffIcon?.classList.remove('hidden');
        linkParentEyeOnIcon?.classList.add('hidden');
        if (linkParentPasswordError) {
            linkParentPasswordError.textContent = '';
            linkParentPasswordError.classList.add('hidden');
        }
        linkParentPasswordModal?.classList.remove('hidden');
    });

    function closeLinkParentModal() {
        linkParentPasswordModal?.classList.add('hidden');
    }

    document.getElementById('btnCloseLinkParent')?.addEventListener('click', closeLinkParentModal);
    document.getElementById('btnCancelLinkParent')?.addEventListener('click', closeLinkParentModal);

    // Validasi Password Orang Tua: simbol, angka, huruf besar, huruf kecil, no spasi
    const strongPwdRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[^\s]{8,}$/;

    // Klik Simpan pada Modal Buat Akun Orang Tua Baru
    document.getElementById('btnSubmitCreateParent')?.addEventListener('click', async () => {
        resetCreateParentErrors();
        const fullname = parentFullnameInput?.value.trim() || '';
        const username = parentUsernameInput?.value.trim() || '';
        const pwd = parentPasswordInput?.value || '';
        const phone = parentPhoneInput?.value.trim() || '';

        let hasErr = false;
        if (!fullname) {
            parentFullnameError.textContent = 'Full name wajib diisi.';
            parentFullnameError.classList.remove('hidden');
            parentFullnameInput.classList.add('border-red-500');
            hasErr = true;
        }
        if (!username) {
            parentUsernameError.textContent = 'Username wajib diisi.';
            parentUsernameError.classList.remove('hidden');
            parentUsernameInput.classList.add('border-red-500');
            hasErr = true;
        }
        if (!strongPwdRegex.test(pwd)) {
            parentPasswordError.textContent = 'Password wajib berisi huruf besar, huruf kecil, angka, simbol, minimal 8 karakter & tanpa spasi!';
            parentPasswordError.classList.remove('hidden');
            parentPasswordHint?.classList.add('hidden');
            parentPasswordInput.classList.add('border-red-500');
            hasErr = true;
        }
        if (!phone) {
            parentPhoneError.textContent = 'Nomor telepon wajib diisi.';
            parentPhoneError.classList.remove('hidden');
            parentPhoneInput.classList.add('border-red-500');
            hasErr = true;
        }

        if (hasErr) return;

        // Cek sekali lagi apakah fullname sudah ada
        const exists = await checkExistingParentByFullname(fullname);
        if (exists) return;

        pendingParentSaveMode = 'create';
        parentSaveConfirmModal?.classList.remove('hidden');
    });

    // Klik Simpan pada Modal Masukkan Password Akun Orang Tua yang Sudah Ada
    document.getElementById('btnSubmitLinkParent')?.addEventListener('click', () => {
        if (linkParentPasswordError) {
            linkParentPasswordError.textContent = '';
            linkParentPasswordError.classList.add('hidden');
        }
        linkParentPasswordInput?.classList.remove('border-red-500');

        const pwd = linkParentPasswordInput?.value || '';
        if (!pwd) {
            if (linkParentPasswordError) {
                linkParentPasswordError.textContent = 'Password akun orang tua wajib diisi!';
                linkParentPasswordError.classList.remove('hidden');
            }
            linkParentPasswordInput?.classList.add('border-red-500');
            return;
        }

        pendingParentSaveMode = 'link';
        parentSaveConfirmModal?.classList.remove('hidden');
    });

    // Klik Tidak / Yakin pada Pop-up Yakin & Tidak
    document.getElementById('btnParentConfirmNo')?.addEventListener('click', () => {
        parentSaveConfirmModal?.classList.add('hidden');
    });

    document.getElementById('btnParentConfirmYes')?.addEventListener('click', async () => {
        const btnYes = document.getElementById('btnParentConfirmYes');
        const authToken = localStorage.getItem('auth_token');
        if (btnYes) {
            btnYes.disabled = true;
            btnYes.textContent = 'Menyimpan...';
        }

        try {
            if (pendingParentSaveMode === 'create') {
                const res = await fetch(`${API_BASE_URL}/parent-account/create`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        fullname: parentFullnameInput.value.trim(),
                        username: parentUsernameInput.value.trim(),
                        password: parentPasswordInput.value,
                        no_telp: parentPhoneInput.value.trim()
                    })
                });
                const result = await res.json();
                parentSaveConfirmModal?.classList.add('hidden');

                if (res.ok) {
                    createParentModal?.classList.add('hidden');
                    await checkAndRenderParentStatus();
                    parentSuccessModal?.classList.remove('hidden');
                } else {
                    if (result.errors?.username) {
                        parentUsernameError.textContent = result.errors.username[0];
                        parentUsernameError.classList.remove('hidden');
                        parentUsernameInput.classList.add('border-red-500');
                    }
                    if (result.errors?.password) {
                        parentPasswordError.textContent = result.errors.password[0];
                        parentPasswordError.classList.remove('hidden');
                        parentPasswordHint?.classList.add('hidden');
                        parentPasswordInput.classList.add('border-red-500');
                    }
                    if (result.errors?.no_telp) {
                        parentPhoneError.textContent = result.errors.no_telp[0];
                        parentPhoneError.classList.remove('hidden');
                        parentPhoneInput.classList.add('border-red-500');
                    }
                    if (!result.errors) {
                        parentPasswordError.textContent = result.message || 'Gagal membuat akun orang tua.';
                        parentPasswordError.classList.remove('hidden');
                    }
                }
            } else if (pendingParentSaveMode === 'link') {
                const res = await fetch(`${API_BASE_URL}/parent-account/link`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        parent_id: detectedExistingParent?.id,
                        password: linkParentPasswordInput.value
                    })
                });
                const result = await res.json();
                parentSaveConfirmModal?.classList.add('hidden');

                if (res.ok) {
                    linkParentPasswordModal?.classList.add('hidden');
                    await checkAndRenderParentStatus();
                    parentSuccessModal?.classList.remove('hidden');
                } else {
                    const errMsg = result.errors?.password?.[0] || result.message || 'Password akun orang tua tidak sesuai!';
                    if (linkParentPasswordError) {
                        linkParentPasswordError.textContent = errMsg;
                        linkParentPasswordError.classList.remove('hidden');
                    }
                    linkParentPasswordInput?.classList.add('border-red-500');
                }
            }
        } catch (err) {
            parentSaveConfirmModal?.classList.add('hidden');
            alert('Terjadi kesalahan koneksi ke server.');
        } finally {
            if (btnYes) {
                btnYes.disabled = false;
                btnYes.textContent = 'Yakin';
            }
        }
    });

    document.getElementById('btnCloseParentSuccess')?.addEventListener('click', () => {
        parentSuccessModal?.classList.add('hidden');
    });
}

async function checkAndRenderParentStatus() {
    const authToken = localStorage.getItem('auth_token');
    if (!authToken) return;

    const banner = document.getElementById('parentAccountBanner');
    const profileParentBox = document.getElementById('initParentAccountContainer');

    try {
        const res = await fetch(`${API_BASE_URL}/parent-account/status`, {
            headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();

        const currentData = JSON.parse(localStorage.getItem('user_data') || '{}');

        if (data.is_parent) {
            if (banner) banner.classList.add('hidden');
            if (profileParentBox) profileParentBox.classList.add('hidden');

            if (Array.isArray(data.linked_students)) {
                currentData.linked_students = data.linked_students;
                localStorage.setItem('user_data', JSON.stringify(currentData));
                renderParentStudentDropdown(currentData);
            }
        } else {
            if (Array.isArray(data.parents)) {
                currentData.parents = data.parents;
                localStorage.setItem('user_data', JSON.stringify(currentData));
            }
            if (data.has_parent) {
                if (banner) banner.classList.add('hidden');
                if (profileParentBox) {
                    const parentName = data.parents?.[0]?.fullname || 'Terhubung';
                    profileParentBox.innerHTML = `
                        <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">AKUN ORANG TUA</span>
                        <span class="px-3 py-1 bg-emerald-100 text-emerald-700 text-xs font-bold rounded-lg border border-emerald-200 truncate max-w-[180px]" title="${parentName}">Sudah Dibuat (${parentName})</span>
                    `;
                }
            } else {
                if (banner) banner.classList.remove('hidden');
                if (profileParentBox) {
                    profileParentBox.classList.remove('hidden');
                    profileParentBox.innerHTML = `
                        <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">AKUN ORANG TUA</span>
                        <button type="button" id="btnProfileCreateParent" onclick="window.openCreateParentModal()" class="w-36 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg transition shadow-sm flex items-center justify-center">Buat Akun Ortu</button>
                    `;
                }
            }
        }
    } catch (e) {
        console.error('Gagal memeriksa status akun orang tua:', e);
    }
}

injectParentAndPasswordModals();
initProfileEvents();
checkAndRenderParentStatus();