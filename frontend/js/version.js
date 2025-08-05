// Fetches and displays the current application version.
document.addEventListener('DOMContentLoaded', () => {
  const target = document.getElementById('version');
  if (!target) return;
  fetch('../php_backend/public/version.php')
    .then((response) => response.json())
    .then((data) => {
      const version = data.version || 'unknown';
      target.textContent = `Version: ${version}`;
    })
    .catch(() => {
      target.textContent = 'Version: unknown';
    });
});
