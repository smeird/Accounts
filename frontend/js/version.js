// Fetches and displays the current application version.
document.addEventListener('DOMContentLoaded', () => {
  const target = document.getElementById('version');
  if (!target) return;
  fetch('../php_backend/public/version.php')
    .then((response) => response.json())
    .then((data) => {
      if (data.version) {
        target.textContent = `Version: ${data.version}`;
      }
    })
    .catch(() => {
      target.textContent = 'Version: unknown';
    });
});
