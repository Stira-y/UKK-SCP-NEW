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

