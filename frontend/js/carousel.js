// Cycles through images in the landing page carousel.
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('landing-carousel');
  if (!container) return;
  const images = container.querySelectorAll('img');
  if (images.length <= 1) return;
  let index = 0;
  setInterval(() => {
    const current = images[index];
    current.classList.remove('opacity-100', 'translate-x-0');
    current.classList.add('opacity-0', '-translate-x-full');
    index = (index + 1) % images.length;
    const next = images[index];
    next.classList.remove('opacity-0', 'translate-x-full', '-translate-x-full');
    next.classList.add('opacity-100', 'translate-x-0');
  }, 5000);
});
