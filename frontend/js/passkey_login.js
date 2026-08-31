document.addEventListener('DOMContentLoaded', () => {
  const section = document.getElementById('passkey-login');
  const button = document.getElementById('passkey-login-button');
  const status = document.getElementById('passkey-login-status');
  const client = window.WebAuthnClient;
  if (!section || !button || !status || !client || !client.supported()) {
    if (section) section.hidden = true;
    return;
  }

  let activeRequest = 0;
  let conditionalController = null;
  let manualStarted = false;

  async function options() {
    const response = await fetch('php_backend/public/passkey_login_options.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}'
    });
    return client.json(response);
  }

  async function verify(credential) {
    const response = await fetch('php_backend/public/passkey_login_verify.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(client.authenticationPayload(credential))
    });
    return client.json(response);
  }

  async function authenticate(conditional) {
    const requestId = ++activeRequest;
    if (!conditional) {
      button.disabled = true;
      status.textContent = 'Waiting for your device…';
    }
    try {
      const optionsPayload = await options();
      if (requestId !== activeRequest) return;
      const credentialRequest = {
        publicKey: client.requestOptions(optionsPayload.publicKey)
      };
      if (conditional) {
        credentialRequest.mediation = 'conditional';
        if (typeof AbortController !== 'undefined') {
          conditionalController = new AbortController();
          credentialRequest.signal = conditionalController.signal;
        }
      }
      const credential = await navigator.credentials.get(credentialRequest);
      if (!credential || requestId !== activeRequest) return;
      button.disabled = true;
      status.textContent = 'Passkey recognised. Signing you in…';
      const result = await verify(credential);
      if (requestId !== activeRequest) return;
      status.textContent = 'Passkey verified. Opening your dashboard…';
      window.location.assign(result.redirect || 'frontend/index.html');
    } catch (error) {
      if (requestId !== activeRequest || (error && error.name === 'AbortError')) return;
      if (conditional && error && error.name === 'NotAllowedError') {
        status.textContent = 'Use a saved passkey from the username field, or continue with your password.';
      } else {
        status.textContent = client.friendlyError(error);
      }
    } finally {
      if (requestId === activeRequest) button.disabled = false;
    }
  }

  button.addEventListener('click', () => {
    manualStarted = true;
    activeRequest += 1;
    if (conditionalController) conditionalController.abort();
    conditionalController = null;
    authenticate(false);
  });

  if (document.getElementById('login-username')) {
    client.conditionalMediationAvailable().then(available => {
      if (!available || manualStarted) return;
      status.textContent = 'Choose a saved passkey when offered, or use your password.';
      authenticate(true);
    });
  }
});
