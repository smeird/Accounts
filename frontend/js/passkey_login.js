document.addEventListener('DOMContentLoaded', () => {
  const section = document.getElementById('passkey-login');
  const button = document.getElementById('passkey-login-button');
  const status = document.getElementById('passkey-login-status');
  const client = window.WebAuthnClient;
  if (!section || !button || !status || !client || !client.supported()) {
    if (section) section.hidden = true;
    return;
  }

  button.addEventListener('click', async () => {
    button.disabled = true;
    status.textContent = 'Waiting for your device…';
    try {
      const optionsResponse = await fetch('php_backend/public/passkey_login_options.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: '{}'
      });
      const optionsPayload = await client.json(optionsResponse);
      const credential = await navigator.credentials.get({
        publicKey: client.requestOptions(optionsPayload.publicKey)
      });
      const verifyResponse = await fetch('php_backend/public/passkey_login_verify.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(client.authenticationPayload(credential))
      });
      const result = await client.json(verifyResponse);
      status.textContent = 'Passkey verified. Opening your dashboard…';
      window.location.assign(result.redirect || 'frontend/index.html');
    } catch (error) {
      status.textContent = client.friendlyError(error);
      button.disabled = false;
    }
  });
});
