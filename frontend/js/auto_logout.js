// Inactivity locks preserve the open document; drafts never enter browser storage.
(function () {
  const script = document.currentScript;
  const frontend = new URL('../', script.src);
  const endpoint = new URL('../php_backend/public/session_timeout.php', frontend);
  let timer;
  let deadline = 0;
  let duration = 0;
  let expired = false;
  function expire() {
    if (expired) return;
    expired = true;
    clearTimeout(timer);
    window.AppWeb?.lockSession();
  }
  function check() {
    if (!duration || expired) return;
    if (Date.now() >= deadline) expire();
    else timer = setTimeout(check, Math.min(deadline - Date.now(), 2147483647));
  }
  function activity() {
    if (!duration || expired) return;
    // Check the old deadline before accepting new activity after suspension.
    if (Date.now() >= deadline) { expire(); return; }
    deadline = Date.now() + duration;
    clearTimeout(timer);
    timer = setTimeout(check, Math.min(duration, 2147483647));
  }
  async function configure() {
    try {
      const response = await fetch(endpoint, { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      duration = Math.max(0, Number(data.minutes) || 0) * 60000;
      expired = false;
      deadline = Date.now() + duration;
      clearTimeout(timer);
      if (duration) timer = setTimeout(check, Math.min(duration, 2147483647));
    } catch (_) { /* Shared connection status supplies a retry action. */ }
  }
  ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(event => document.addEventListener(event, activity, { capture: true, passive: true }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  document.addEventListener('app:session-restored', configure);
  configure();
})();
