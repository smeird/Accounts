// Dynamically loads the shared navigation menu into pages and ensures icon support.
// Override fetch globally to bypass browser caching.
const originalFetch = window.fetch;
window.fetch = (input, init = {}) => {
  init = init || {};
  if (!init.cache) {
    init.cache = 'no-store';
  }
  return originalFetch(input, init);
};

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

  // Apply Tailwind card styling to all sections or wrap main content in a card
  document.querySelectorAll('main').forEach(main => {
    const sections = main.querySelectorAll('section');
    if (sections.length > 0) {
      sections.forEach(section => {
        section.classList.add('bg-white', 'p-6', 'rounded', 'shadow');
      });
    } else {
      const wrapper = document.createElement('section');
      wrapper.className = 'bg-white p-6 rounded shadow';
      while (main.firstChild) {
        wrapper.appendChild(main.firstChild);
      }
      main.appendChild(wrapper);
    }
  });

  // Load page help overlay on every page
  const helpScript = document.createElement('script');
  helpScript.src = 'js/page_help.js';
  document.body.appendChild(helpScript);
});
