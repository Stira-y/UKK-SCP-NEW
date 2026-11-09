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
})();

