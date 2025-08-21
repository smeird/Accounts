// Handles TOTP setup, verification, and disabling.
document.addEventListener('DOMContentLoaded', () => {
  const apiBase = document.body.dataset.apiBase || '../php_backend/public';

  const qrEl = document.getElementById('qr');

  const genForm = document.getElementById('generate-form');
  const verifyForm = document.getElementById('verify-form');
  const helpBtn = document.getElementById('help-btn');
  const disableBtn = document.getElementById('disable-2fa');

  if (genForm) {
    genForm.addEventListener('submit', async e => {
      e.preventDefault();
      const username = document.getElementById('gen-username').value;
      try {
        const res = await fetch(`${apiBase}/totp_generate.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username })
        });
        const data = await res.json();

        if (data.otpauth && qrEl) {
          qrEl.innerHTML = '';
          new QRCode(qrEl, {
            text: data.otpauth,
            width: 200,
            height: 200
          });
          showMessage('Scan the QR code with your authenticator.');

        } else {
          showMessage(data.error || 'Failed to generate secret', 'error');
        }
      } catch (err) {
        showMessage('Server error', 'error');
      }
    });
  }

  if (verifyForm) {
    verifyForm.addEventListener('submit', async e => {
      e.preventDefault();
      const username = document.getElementById('ver-username').value;
      const token = document.getElementById('token').value;
      try {
        const res = await fetch(`${apiBase}/totp_verify.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username, token })
        });
        const data = await res.json();
        showMessage(data.verified ? 'Code verified!' : 'Invalid code', data.verified ? 'success' : 'error');
      } catch (err) {
        showMessage('Server error', 'error');
      }
    });
  }

  if (disableBtn) {
    disableBtn.addEventListener('click', async e => {
      e.preventDefault();
      const username = document.getElementById('ver-username').value || document.getElementById('gen-username').value;
      try {
        const res = await fetch(`${apiBase}/totp_disable.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username })
        });
        const data = await res.json();
        if (data.disabled) {

          if (qrEl) qrEl.innerHTML = '';

          showMessage('2FA disabled', 'success');
        } else {
          showMessage(data.error || 'Failed to disable', 'error');
        }
      } catch (err) {
        showMessage('Server error', 'error');
      }
    });
  }

  if (helpBtn) {
    helpBtn.addEventListener('click', () => {
      showMessage('Generate a QR code to link an authenticator and verify codes here.');
    });
  }
});
