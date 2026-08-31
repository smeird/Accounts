(function () {
  function fromBase64url(value) {
    const base64 = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64 + '='.repeat((4 - base64.length % 4) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
  }

  function toBase64url(value) {
    const bytes = new Uint8Array(value);
    let binary = '';
    bytes.forEach(byte => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  function creationOptions(options) {
    const copy = Object.assign({}, options, {
      challenge: fromBase64url(options.challenge),
      user: Object.assign({}, options.user, { id: fromBase64url(options.user.id) }),
      excludeCredentials: (options.excludeCredentials || []).map(item => Object.assign({}, item, { id: fromBase64url(item.id) }))
    });
    return copy;
  }

  function requestOptions(options) {
    return Object.assign({}, options, {
      challenge: fromBase64url(options.challenge),
      allowCredentials: (options.allowCredentials || []).map(item => Object.assign({}, item, { id: fromBase64url(item.id) }))
    });
  }

  function registrationPayload(credential) {
    const response = credential.response;
    return {
      id: credential.id,
      rawId: toBase64url(credential.rawId),
      type: credential.type,
      authenticatorAttachment: credential.authenticatorAttachment || null,
      clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {},
      response: {
        clientDataJSON: toBase64url(response.clientDataJSON),
        attestationObject: toBase64url(response.attestationObject),
        transports: response.getTransports ? response.getTransports() : []
      }
    };
  }

  function authenticationPayload(credential) {
    const response = credential.response;
    return {
      id: credential.id,
      rawId: toBase64url(credential.rawId),
      type: credential.type,
      authenticatorAttachment: credential.authenticatorAttachment || null,
      clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {},
      response: {
        clientDataJSON: toBase64url(response.clientDataJSON),
        authenticatorData: toBase64url(response.authenticatorData),
        signature: toBase64url(response.signature),
        userHandle: response.userHandle ? toBase64url(response.userHandle) : null
      }
    };
  }

  async function json(response) {
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || 'The passkey request failed.');
    return payload;
  }

  function friendlyError(error) {
    if (error && error.name === 'NotAllowedError') return 'Passkey sign-in was cancelled or timed out.';
    if (error && error.name === 'InvalidStateError') return 'That passkey is already registered.';
    if (error && error.name === 'NotSupportedError') return 'This browser or device cannot create the requested passkey.';
    return error && error.message ? error.message : 'The passkey request could not be completed.';
  }

  window.WebAuthnClient = {
    supported: () => !!(window.PublicKeyCredential && navigator.credentials),
    creationOptions,
    requestOptions,
    registrationPayload,
    authenticationPayload,
    json,
    friendlyError,
    fromBase64url,
    toBase64url
  };

  if (typeof module !== 'undefined') {
    module.exports = { fromBase64url, toBase64url, creationOptions, requestOptions };
  }
}());
