/**
 * pw-waka.js
 * Modul Universal Pengelolaan Kolom Password & Verifikasi Kode OTP Waka
 */

const PW_ICON_EYE_SLASH = `
    <svg class="w-5 h-5 text-slate-400 hover:text-indigo-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"></path>
    </svg>
`;

const PW_ICON_EYE_OPEN = `
    <svg class="w-5 h-5 text-indigo-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
    </svg>
`;

function checkPwCriteria(pwd) {
    if (!pwd) return false;
    const minLength = pwd.length >= 8;
    const hasUpper = /[A-Z]/.test(pwd);
    const hasLower = /[a-z]/.test(pwd);
    const hasNum = /[0-9]/.test(pwd);
    const hasSymbol = /[\W_]/.test(pwd);
    const noSpace = !/\s/.test(pwd);
    return minLength && hasUpper && hasLower && hasNum && hasSymbol && noSpace;
}

window.validatePasswordRules = checkPwCriteria;

window.initPwWaka = function (inputId, toggleBtnId, errorElemId) {
    const input = document.getElementById(inputId);
    const toggleBtn = document.getElementById(toggleBtnId);
    const errorElem = document.getElementById(errorElemId);

    if (!input) return;

    if (errorElem) {
        errorElem.className = "text-xs text-red-500 italic mt-1 hidden";
        errorElem.textContent = "Password harus minimal 8 karakter, mengandung huruf besar, huruf kecil, angka, simbol, dan tanpa spasi.";
    }

    if (toggleBtn) {
        toggleBtn.innerHTML = PW_ICON_EYE_SLASH;
        toggleBtn.setAttribute("type", "button");

        toggleBtn.onclick = function (e) {
            e.preventDefault();
            e.stopPropagation();
            const isHidden = input.getAttribute("type") === "password";
            if (isHidden) {
                input.setAttribute("type", "text");
                toggleBtn.innerHTML = PW_ICON_EYE_OPEN;
            } else {
                input.setAttribute("type", "password");
                toggleBtn.innerHTML = PW_ICON_EYE_SLASH;
            }
        };
    }

    input.addEventListener("keydown", function (e) {
        if (e.key === " " || e.code === "Space" || e.keyCode === 32) {
            e.preventDefault();
        }
    });

    input.addEventListener("input", function () {
        if (/\s/.test(input.value)) {
            input.value = input.value.replace(/\s+/g, "");
        }

        const val = input.value;
        if (!val) {
            if (errorElem) errorElem.classList.add("hidden");
            return;
        }

        if (!checkPwCriteria(val)) {
            if (errorElem) errorElem.classList.remove("hidden");
        } else {
            if (errorElem) errorElem.classList.add("hidden");
        }
    });
};

window.resetPwField = function (inputId, toggleBtnId, errorElemId) {
    const input = document.getElementById(inputId);
    const toggleBtn = document.getElementById(toggleBtnId);
    const errorElem = document.getElementById(errorElemId);

    if (input) {
        input.value = "";
        input.setAttribute("type", "password");
    }
    if (toggleBtn) {
        toggleBtn.innerHTML = PW_ICON_EYE_SLASH;
    }
    if (errorElem) {
        errorElem.classList.add("hidden");
    }
};

let otpActiveConfig = null;
let otpCooldownInterval = null;
let otpIsSending = false;
let otpIsVerifying = false;
const OTP_BASE_COOLDOWN = 30;

function ensureOtpModalInDOM() {
    let modal = document.getElementById("wakaOtpModal");
    if (!modal) {
        const modalHTML = `
            <div id="wakaOtpModal" class="fixed inset-0 bg-slate-900/60 z-[70] hidden flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden flex flex-col transform transition-all p-6 text-center">
                    <div class="flex justify-between items-center mb-4">
                        <h3 id="otpModalTitle" class="text-lg font-bold text-slate-800 w-full text-center pl-6">Verifikasi Kode OTP</h3>
                        <button type="button" id="btnCloseOtp" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
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
        `;
        document.body.insertAdjacentHTML("beforeend", modalHTML);
        modal = document.getElementById("wakaOtpModal");
    }
    bindOtpEvents();
    return modal;
}

function bindOtpEvents() {
    const modal = document.getElementById("wakaOtpModal");
    if (!modal || modal.dataset.boundEvents === "true") return;
    modal.dataset.boundEvents = "true";

    const otpInputs = Array.from(modal.querySelectorAll(".otp-input"));
    const btnCloseOtp = modal.querySelector("#btnCloseOtp");
    const btnResendOtp = modal.querySelector("#btnResendOtp");
    const btnManualRetryOtp = modal.querySelector("#btnManualRetryOtp");

    function distributeOtpDigits(rawValue) {
        const digits = String(rawValue || "").replace(/\D/g, "").slice(0, 6);
        if (!digits) return;

        otpInputs.forEach((inp, idx) => {
            inp.value = digits[idx] || "";
        });

        const nextIdx = Math.min(digits.length, otpInputs.length - 1);
        if (otpInputs[nextIdx]) otpInputs[nextIdx].focus();

        if (digits.length === 6) {
            submitOtpVerification(digits);
        }
    }

    otpInputs.forEach((input, index) => {
        input.addEventListener("paste", function (e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData("text");
            distributeOtpDigits(pasteData);
        });

        input.addEventListener("input", function (e) {
            const digits = String(e.target.value || "").replace(/\D/g, "");
            if (digits.length > 1) {
                distributeOtpDigits(digits);
                return;
            }
            e.target.value = digits.slice(0, 1);
            if (e.target.value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }

            const fullCode = otpInputs.map(inp => inp.value).join("");
            if (fullCode.length === 6) {
                submitOtpVerification(fullCode);
            }
        });

        input.addEventListener("keydown", function (e) {
            if (e.key === "Backspace") {
                if (!e.target.value && index > 0) {
                    otpInputs[index - 1].focus();
                    otpInputs[index - 1].value = "";
                }
            } else if (e.key === "ArrowLeft" && index > 0) {
                otpInputs[index - 1].focus();
            } else if (e.key === "ArrowRight" && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
        });
    });

    if (btnCloseOtp) {
        btnCloseOtp.addEventListener("click", function () {
            modal.classList.add("hidden");
            if (otpActiveConfig && typeof otpActiveConfig.onCancel === "function") {
                otpActiveConfig.onCancel();
            }
        });
    }

    if (btnResendOtp) {
        btnResendOtp.addEventListener("click", function (e) {
            e.preventDefault();
            if (!btnResendOtp.disabled && !otpIsSending) {
                triggerOtpSendRequest();
            }
        });
    }

    if (btnManualRetryOtp) {
        btnManualRetryOtp.addEventListener("click", function (e) {
            e.preventDefault();
            if (!btnManualRetryOtp.disabled && !otpIsSending) {
                triggerOtpSendRequest();
            }
        });
    }
}

function updateOtpTimerUI(secondsLeft) {
    const modal = document.getElementById("wakaOtpModal");
    if (!modal) return;
    const btnResendOtp = modal.querySelector("#btnResendOtp");
    const btnManualRetryOtp = modal.querySelector("#btnManualRetryOtp");
    const timerText = modal.querySelector("#timerText");

    if (secondsLeft <= 0) {
        clearInterval(otpCooldownInterval);
        if (btnResendOtp) {
            btnResendOtp.disabled = false;
            btnResendOtp.innerText = "Kirim ulang via email";
            btnResendOtp.className = "text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline cursor-pointer focus:outline-none text-center";
        }
        if (btnManualRetryOtp) {
            btnManualRetryOtp.disabled = false;
            btnManualRetryOtp.classList.remove("opacity-50", "cursor-not-allowed");
        }
        if (timerText) timerText.textContent = "0s";
    } else {
        if (btnResendOtp) {
            btnResendOtp.disabled = true;
            btnResendOtp.innerText = "Kirim ulang via email";
            btnResendOtp.className = "text-xs font-medium text-slate-400 cursor-not-allowed focus:outline-none text-center";
        }
        if (btnManualRetryOtp) {
            btnManualRetryOtp.disabled = true;
            btnManualRetryOtp.classList.add("opacity-50", "cursor-not-allowed");
        }
        if (timerText) timerText.textContent = `${secondsLeft}s`;
    }
}

function startOtpCooldown(duration = OTP_BASE_COOLDOWN) {
    clearInterval(otpCooldownInterval);
    let left = duration;
    updateOtpTimerUI(left);
    otpCooldownInterval = setInterval(() => {
        left--;
        updateOtpTimerUI(left);
        if (left <= 0) {
            clearInterval(otpCooldownInterval);
        }
    }, 1000);
}

async function triggerOtpSendRequest() {
    if (!otpActiveConfig || otpIsSending) return;
    otpIsSending = true;

    const modal = document.getElementById("wakaOtpModal");
    const otpErrorMessage = modal ? modal.querySelector("#otpErrorMessage") : null;
    const otpInputs = modal ? Array.from(modal.querySelectorAll(".otp-input")) : [];
    if (otpErrorMessage) otpErrorMessage.classList.add("hidden");
    otpInputs.forEach(i => i.value = "");

    try {
        const payload = {
            current_user_id: otpActiveConfig.currentUserId,
            type: otpActiveConfig.mode
        };

        if (otpActiveConfig.mode === "new_email") {
            payload.email = otpActiveConfig.email;
            payload.new_email = otpActiveConfig.email;
        }

        // Endpoint terpisah khusus Waka mengelola guru/siswa
        let sendEndpoint = `${otpActiveConfig.apiBaseUrl}/waka/students/send-otp`;
        if (otpActiveConfig.context === 'teacher') {
            sendEndpoint = `${otpActiveConfig.apiBaseUrl}/waka/teachers/send-otp`;
        }

        const response = await fetch(sendEndpoint, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${otpActiveConfig.token}`,
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify(payload)
        });

        const res = await response.json();
        if (response.ok) {
            if (modal) modal.classList.remove("hidden");
            startOtpCooldown(OTP_BASE_COOLDOWN);
            if (otpInputs[0]) otpInputs[0].focus();
        } else {
            clearInterval(otpCooldownInterval);
            if (otpActiveConfig && typeof otpActiveConfig.onError === "function") {
                if (modal) modal.classList.add("hidden");
                otpActiveConfig.onError(res.message || "Gagal mengirimkan kode OTP.");
            } else {
                alert(res.message || "Gagal mengirimkan kode OTP.");
                updateOtpTimerUI(0);
            }
        }
    } catch (err) {
        clearInterval(otpCooldownInterval);
        if (otpActiveConfig && typeof otpActiveConfig.onError === "function") {
            if (modal) modal.classList.add("hidden");
            otpActiveConfig.onError("Terjadi kesalahan koneksi saat mengirim kode OTP.");
        } else {
            alert("Terjadi kesalahan koneksi saat mengirim kode OTP.");
            updateOtpTimerUI(0);
        }
    } finally {
        otpIsSending = false;
    }
}

async function submitOtpVerification(code) {
    if (!otpActiveConfig || otpIsVerifying) return;
    otpIsVerifying = true;

    const modal = document.getElementById("wakaOtpModal");
    const otpErrorMessage = modal.querySelector("#otpErrorMessage");

    try {
        let verifyEndpoint = `${otpActiveConfig.apiBaseUrl}/waka/students/verify-otp`;
        if (otpActiveConfig.context === 'teacher') {
            verifyEndpoint = `${otpActiveConfig.apiBaseUrl}/waka/teachers/verify-otp`;
        }

        const response = await fetch(verifyEndpoint, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${otpActiveConfig.token}`,
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify({
                otp: String(code).trim(),
                type: otpActiveConfig.mode,
                current_user_id: otpActiveConfig.currentUserId
            })
        });

        const res = await response.json();

        if (response.ok) {
            if (otpErrorMessage) otpErrorMessage.classList.add("hidden");
            modal.classList.add("hidden");
            clearInterval(otpCooldownInterval);

            if (typeof otpActiveConfig.onSuccess === "function") {
                otpActiveConfig.onSuccess(String(code).trim(), otpActiveConfig.mode);
            }
        } else {
            if (otpErrorMessage) {
                otpErrorMessage.textContent = res.message || "Kode verifikasi OTP salah";
                otpErrorMessage.classList.remove("hidden");
            }
        }
    } catch (err) {
        if (otpErrorMessage) {
            otpErrorMessage.textContent = "Terjadi kendala koneksi server.";
            otpErrorMessage.classList.remove("hidden");
        }
    } finally {
        otpIsVerifying = false;
    }
}

window.openOtpWaka = function (config) {
    otpActiveConfig = Object.assign({
        apiBaseUrl: "https://ukk-scp-yudhis.my.id/api",
        token: localStorage.getItem("auth_token") || "",
        mode: "password",
        context: "teacher",
        currentUserId: null
    }, config);

    const modal = ensureOtpModalInDOM();
    const otpModalTitle = modal.querySelector("#otpModalTitle");
    const otpModalSubtext = modal.querySelector("#otpModalSubtext");
    const otpErrorMessage = modal.querySelector("#otpErrorMessage");
    const otpInputs = Array.from(modal.querySelectorAll(".otp-input"));

    if (otpModalTitle) {
        otpModalTitle.textContent = otpActiveConfig.mode === "new_email" ? "Verifikasi Email Baru" : "Verifikasi Kode OTP";
    }
    if (otpModalSubtext) {
        otpModalSubtext.textContent = "Kode verifikasi 6 angka telah dikirim via email Waka Kurikulum.";
    }

    if (otpErrorMessage) {
        otpErrorMessage.classList.add("hidden");
        otpErrorMessage.textContent = "Kode verifikasi OTP salah";
    }

    otpInputs.forEach(i => i.value = "");
    if (otpActiveConfig.mode !== "new_email") {
        modal.classList.remove("hidden");
        if (otpInputs[0]) otpInputs[0].focus();
    }

    triggerOtpSendRequest();
};