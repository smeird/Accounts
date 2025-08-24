// Logs out the user after a period of inactivity based on server settings.
document.addEventListener('DOMContentLoaded', () => {
  fetch('../php_backend/public/session_timeout.php')
    .then(r => (r.ok ? r.json() : Promise.reject()))
    .then(data => {
      const minutes = parseInt(data.minutes, 10);
      if (minutes > 0) {
        const meta = document.createElement('meta');
        meta.httpEquiv = 'refresh';
        document.head.appendChild(meta);
        let timer;
        const reset = () => {
          clearTimeout(timer);
          meta.content = `${minutes * 60};url=../logout.php?timeout=1`;
          timer = setTimeout(() => {
            window.location.href = '../logout.php?timeout=1';
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
