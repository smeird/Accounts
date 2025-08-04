// Dynamically loads the shared navigation menu into pages.
document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('menu');
  if (menu) {
    fetch('menu.html')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
      })
      .catch(err => console.error('Menu load failed', err));
  }
});
