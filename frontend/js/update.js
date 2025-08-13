document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('update-btn');
  const output = document.getElementById('update-output');
  if (!btn || !output) return;

  btn.addEventListener('click', () => {
    btn.disabled = true;
    output.textContent = 'Updating...';
    fetch('../php_backend/public/git_pull.php')
      .then(r => r.json())
      .then(data => {
        output.textContent = data.output || '';
        btn.disabled = false;
        if (data.success) {
          fetch('../php_backend/public/version.php')
            .then(resp => resp.json())
            .then(v => {
              const target = document.getElementById('version');
              if (target) {
                const version = v.version || 'unknown';
                target.textContent = `Version: ${version}`;
              }
            });
        }
      })
      .catch(() => {
        output.textContent = 'Update failed';
        btn.disabled = false;
      });
  });
});
