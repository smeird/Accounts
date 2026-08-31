document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('passkey-manager');
  const list = document.getElementById('passkey-list');
  const addButton = document.getElementById('add-passkey');
  const labelInput = document.getElementById('passkey-label');
  const support = document.getElementById('passkey-support');
  const client = window.WebAuthnClient;
  if (!root || !list || !addButton || !labelInput || !support) return;
  const apiBase = document.body.dataset.apiBase || 'php_backend/public';

  const formatDate = value => {
    if (!value) return 'Not used yet';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
  };

  const request = async (url, options) => client.json(await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {})));

  function render(items) {
    list.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('p');
      empty.className = 'passkey-empty';
      empty.textContent = 'No passkeys yet. Add one to sign in with Face ID, Touch ID, your device PIN, or a compatible security key.';
      list.appendChild(empty);
      return;
    }
    items.forEach(item => {
      const row = document.createElement('article');
      row.className = 'passkey-row';
      const icon = document.createElement('span');
      icon.className = 'passkey-row__icon';
      icon.innerHTML = '<i class="fas fa-key" aria-hidden="true"></i>';
      const copy = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = item.label || 'Passkey';
      const detail = document.createElement('span');
      const sync = item.backup_eligible ? (item.backed_up ? 'Synced passkey' : 'Sync capable') : 'Device or security key';
      detail.textContent = `${sync} · Added ${formatDate(item.created_at)} · Last used ${formatDate(item.last_used_at)}`;
      copy.append(title, detail);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'passkey-remove';
      remove.setAttribute('aria-label', `Remove ${item.label || 'passkey'}`);
      remove.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i><span>Remove</span>';
      remove.addEventListener('click', async () => {
        if (!window.confirm(`Remove “${item.label || 'Passkey'}”? Password login will remain available.`)) return;
        remove.disabled = true;
        try {
          await request(`${apiBase}/passkeys.php`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: item.id })
          });
          if (typeof showMessage === 'function') showMessage('Passkey removed.', 'success');
          await load();
        } catch (error) {
          remove.disabled = false;
          if (typeof showMessage === 'function') showMessage(error.message, 'error');
        }
      });
      row.append(icon, copy, remove);
      list.appendChild(row);
    });
  }

  async function load() {
    try {
      const payload = await request(`${apiBase}/passkeys.php`);
      render(payload.passkeys || []);
    } catch (error) {
      list.innerHTML = '<p class="passkey-empty">Passkey storage is not ready. Run Database Health or the passkey migration after deployment.</p>';
    }
  }

  if (!client || !client.supported()) {
    support.textContent = 'This browser does not support passkeys. You can still manage password and authenticator-app access.';
    addButton.disabled = true;
    load();
    return;
  }
  support.textContent = 'Supported on this device. Your private key stays in your device or password manager.';

  addButton.addEventListener('click', async () => {
    addButton.disabled = true;
    try {
      const optionsPayload = await request(`${apiBase}/passkey_registration_options.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
      });
      const credential = await navigator.credentials.create({
        publicKey: client.creationOptions(optionsPayload.publicKey)
      });
      const payload = client.registrationPayload(credential);
      payload.label = labelInput.value.trim() || 'Passkey';
      await request(`${apiBase}/passkey_registration_verify.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      });
      labelInput.value = '';
      if (typeof showMessage === 'function') showMessage('Passkey added. You can use it on the login screen.', 'success');
      await load();
    } catch (error) {
      if (typeof showMessage === 'function') showMessage(client.friendlyError(error), 'error');
    } finally {
      addButton.disabled = false;
    }
  });

  load();
});
