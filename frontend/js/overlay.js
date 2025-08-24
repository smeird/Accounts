// Provides a simple overlay for displaying temporary messages.
(function(){
    // Build a small notification element anchored to the utility bar
    function createOverlay(){
        const overlay = document.createElement('div');
        overlay.id = 'overlay';        overlay.className = 'fixed top-2 right-4 z-50 px-4 py-2 rounded shadow text-white hidden transform translate-x-full transition-transform duration-300';

        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.__overlay = createOverlay();
        window.__utilityBar = document.getElementById('utility-bar');
    });

    let hideTimer;


    // Display a temporary message in the utility bar with a barging animation
    window.showMessage = function(msg, type = 'success'){
        const overlay = window.__overlay || document.getElementById('overlay') || createOverlay();
        const rightBar = window.__utilityBar || document.getElementById('utility-bar');
        overlay.textContent = msg;
        overlay.classList.remove('hidden', 'bg-green-600', 'bg-red-600');
        overlay.classList.add(type === 'error' ? 'bg-red-600' : 'bg-green-600', 'translate-x-full');

        const width = overlay.offsetWidth;

        overlay.classList.remove('translate-x-full');
            if(rightBar) rightBar.style.transform = `translateX(-${width + 16}px)`;

        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            overlay.classList.add('translate-x-full');
            if(rightBar) rightBar.style.transform = '';
        }, 2000);

        overlay.addEventListener('transitionend', function handler(e){
            if(e.propertyName === 'transform' && overlay.classList.contains('translate-x-full')){
                overlay.classList.add('hidden');
                overlay.removeEventListener('transitionend', handler);
            }
        });

    };
})();
