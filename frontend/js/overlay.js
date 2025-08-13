// Provides a simple overlay for displaying temporary messages.
(function(){
    // Build a small notification element anchored to the top bar
    function createOverlay(){
        const overlay = document.createElement('div');
        overlay.id = 'overlay';
        overlay.className = 'fixed top-2 right-4 z-50 px-4 py-2 rounded shadow text-white hidden';
        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.__overlay = createOverlay();
    });

    let hideTimer;

    // Display a temporary message in the top bar
    window.showMessage = function(msg, type = 'success'){
        const overlay = window.__overlay || document.getElementById('overlay') || createOverlay();
        overlay.textContent = msg;
        overlay.classList.remove('hidden', 'bg-green-600', 'bg-red-600');
        overlay.classList.add(type === 'error' ? 'bg-red-600' : 'bg-green-600');

        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => overlay.classList.add('hidden'), 2000);
    };
})();
