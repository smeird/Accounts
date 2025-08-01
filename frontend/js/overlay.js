(function(){
    function createOverlay(){
        const overlay = document.createElement('div');
        overlay.id = 'overlay';
        overlay.className = 'overlay';
        overlay.innerHTML = '<div class="overlay-content"></div>';
        overlay.addEventListener('click', () => overlay.classList.remove('show'));
        document.body.appendChild(overlay);
        return overlay;
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.__overlay = createOverlay();
    });

    let hideTimer;
    window.showMessage = function(msg){
        const overlay = window.__overlay || document.getElementById('overlay') || createOverlay();
        overlay.querySelector('.overlay-content').textContent = msg;
        overlay.classList.add('show');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => overlay.classList.remove('show'), 2000);
    };
})();
