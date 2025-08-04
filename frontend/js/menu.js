// Dynamically loads the shared navigation menu into pages and ensures icon support.
document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('menu');
  if (menu) {
    // Load Font Awesome for menu icons if not already loaded
    if (!document.getElementById('fa-icons')) {
      const link = document.createElement('link');
      link.id = 'fa-icons';
      link.rel = 'stylesheet';
      link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
      document.head.appendChild(link);
    }

    fetch('menu.html')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
      })
      .catch(err => console.error('Menu load failed', err));
  }
});
