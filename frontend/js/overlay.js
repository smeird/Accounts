// Provides a simple overlay for displaying temporary messages.
(function(){
    // Build the overlay element and insert it into the DOM
    function createOverlay(){
        const overlay = document.createElement('div');
        overlay.id = 'overlay';
        overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden';
        overlay.innerHTML = '<div class="p-6 rounded shadow text-white"></div>';
        overlay.addEventListener('click', () => overlay.classList.add('hidden'));
        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.__overlay = createOverlay();
    });


    let hideTimer;

    // Display a temporary message in the overlay
    window.showMessage = function(msg, type = 'success'){
        const overlay = window.__overlay || document.getElementById('overlay') || createOverlay();
        const box = overlay.querySelector('div');
        box.textContent = msg;
        box.className = 'p-6 rounded shadow text-white';
        if(type === 'error') {
            box.classList.add('bg-red-600');
        } else {
            box.classList.add('bg-green-600');
        }
        overlay.classList.remove('hidden');

        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => overlay.classList.add('hidden'), 2000);

    };
})();
