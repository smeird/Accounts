// Fetches and displays the current application version.
document.addEventListener('DOMContentLoaded', () => {
  const target = document.getElementById('version');
  if (!target) return;
  fetch('../php_backend/public/version.php')
    .then((response) => response.json())
    .then((data) => {
      const version = data.version || 'unknown';
      const behind = data.behind;
      let text = `Version: ${version}`;
      if (typeof behind === 'number') {
        text += ` (${behind} behind)`;
      }
      target.textContent = text;
    })
    .catch(() => {
      target.textContent = 'Version: unknown';
    });
});
