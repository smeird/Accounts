// Provides a simple overlay for displaying temporary messages.
(function(){
    function createOverlay(){
        const overlay = document.createElement('div');
        overlay.id = 'overlay';
        overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden';
        overlay.innerHTML = '<div class="bg-white p-4 rounded shadow"></div>';
        overlay.addEventListener('click', () => overlay.classList.add('hidden'));
        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.__overlay = createOverlay();
    });


    let hideTimer;

    window.showMessage = function(msg){
        const overlay = window.__overlay || document.getElementById('overlay') || createOverlay();
        overlay.querySelector('div').textContent = msg;
        overlay.classList.remove('hidden');

        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => overlay.classList.add('hidden'), 2000);

    };
})();
