// Logs out the user after a period of inactivity based on server settings.
document.addEventListener('DOMContentLoaded', () => {
  fetch('../php_backend/public/session_timeout.php')
    .then(r => (r.ok ? r.json() : Promise.reject()))
    .then(data => {
      const minutes = parseInt(data.minutes, 10);
      if (minutes > 0) {
        let timer;
        const reset = () => {
          clearTimeout(timer);
          timer = setTimeout(() => {
            window.location.href = '../logout.php';
          }, minutes * 60 * 1000);
        };
        ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(evt =>
          document.addEventListener(evt, reset, true)
        );
        reset();
      }
    })
    .catch(err => console.error('Session timeout check failed', err));
});
