<?php
// Minimal, self-contained WebAuthn verifier for discoverable ES256 passkeys.

class WebAuthnCborDecoder {
    private $data = '';
    private $offset = 0;
    private $length = 0;

    public function decode(string $data) {
        $this->data = $data;
        $this->offset = 0;
        $this->length = strlen($data);
        $value = $this->readValue();
        if ($this->offset !== $this->length) {
            throw new RuntimeException('Unexpected trailing CBOR data.');
        }
        return $value;
    }

    public function decodePrefix(string $data, int &$consumed) {
        $this->data = $data;
        $this->offset = 0;
        $this->length = strlen($data);
        $value = $this->readValue();
        $consumed = $this->offset;
        return $value;
    }

    private function readValue() {
        $initial = ord($this->readBytes(1));
        $major = $initial >> 5;
        $additional = $initial & 31;
        $length = $this->readLength($additional);

        if ($major === 0) return $length;
        if ($major === 1) return -1 - $length;
        if ($major === 2 || $major === 3) return $this->readBytes($length);
        if ($major === 4) {
            $items = [];
            for ($i = 0; $i < $length; $i++) $items[] = $this->readValue();
            return $items;
        }
        if ($major === 5) {
            $map = [];
            for ($i = 0; $i < $length; $i++) {
                $key = $this->readValue();
                if (!is_int($key) && !is_string($key)) {
                    throw new RuntimeException('Unsupported CBOR map key.');
                }
                if (array_key_exists($key, $map)) {
                    throw new RuntimeException('Duplicate CBOR map key.');
                }
                $map[$key] = $this->readValue();
            }
            return $map;
        }
        if ($major === 6) return $this->readValue();
        if ($major === 7) {
            if ($additional === 20) return false;
            if ($additional === 21) return true;
            if ($additional === 22 || $additional === 23) return null;
        }
        throw new RuntimeException('Unsupported CBOR value.');
    }

    private function readLength(int $additional): int {
        if ($additional < 24) return $additional;
        if ($additional === 24) return ord($this->readBytes(1));
        if ($additional === 25) return unpack('nvalue', $this->readBytes(2))['value'];
        if ($additional === 26) return (int)unpack('Nvalue', $this->readBytes(4))['value'];
        if ($additional === 27) {
            $parts = unpack('Nhigh/Nlow', $this->readBytes(8));
            $value = ((int)$parts['high'] * 4294967296) + (int)$parts['low'];
            if ($value > PHP_INT_MAX) throw new RuntimeException('CBOR integer is too large.');
            return (int)$value;
        }
        throw new RuntimeException('Indefinite CBOR values are not accepted.');
    }

    private function readBytes(int $length): string {
        if ($length < 0 || $this->offset + $length > $this->length) {
            throw new RuntimeException('Truncated CBOR data.');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }
}

class WebAuthn {
    const CHALLENGE_TTL = 300;

    public static function base64urlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $value): string {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new RuntimeException('Invalid base64url value.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false) throw new RuntimeException('Invalid base64url value.');
        return $decoded;
    }

    public static function challenge(): string {
        return self::base64urlEncode(random_bytes(32));
    }

    /** @return array{origin:string,rp_id:string} */
    public static function requestContext(): array {
        $configuredOrigin = trim((string)(getenv('PASSKEY_ORIGIN') ?: ''));
        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        $origin = $configuredOrigin !== '' ? rtrim($configuredOrigin, '/') : ($https ? 'https://' : 'http://') . $hostHeader;
        $originHost = (string)parse_url($origin, PHP_URL_HOST);
        $rpId = trim((string)(getenv('PASSKEY_RP_ID') ?: $originHost));
        if ($originHost === '' || $rpId === '' || !self::rpIdMatchesHost($rpId, $originHost)) {
            throw new RuntimeException('The passkey origin or relying-party ID is invalid.');
        }
        return ['origin' => $origin, 'rp_id' => strtolower($rpId)];
    }

    public static function challengeRecord(string $challenge, array $context, array $extra = []): array {
        return array_merge([
            'challenge' => $challenge,
            'origin' => $context['origin'],
            'rp_id' => $context['rp_id'],
            'issued_at' => time(),
        ], $extra);
    }

    public static function assertChallengeRecord($record): array {
        if (!is_array($record)
            || empty($record['challenge'])
            || empty($record['origin'])
            || empty($record['rp_id'])
            || empty($record['issued_at'])
            || time() - (int)$record['issued_at'] > self::CHALLENGE_TTL) {
            throw new RuntimeException('The passkey request expired. Please try again.');
        }
        return $record;
    }

    /**
     * Verify an attestation-none ES256 registration response.
     *
     * @return array{credential_id:string,public_key:string,sign_count:int,backup_eligible:int,backed_up:int}
     */
    public static function verifyRegistration(array $payload, array $expected): array {
        self::assertCredentialEnvelope($payload);
        $response = $payload['response'] ?? null;
        if (!is_array($response)) throw new RuntimeException('Missing registration response.');
        $clientDataJson = self::decodeField($response, 'clientDataJSON', 65536);
        self::verifyClientData($clientDataJson, 'webauthn.create', $expected);

        $attestationBytes = self::decodeField($response, 'attestationObject', 131072);
        $decoder = new WebAuthnCborDecoder();
        $attestation = $decoder->decode($attestationBytes);
        if (!is_array($attestation)
            || ($attestation['fmt'] ?? null) !== 'none'
            || !array_key_exists('authData', $attestation)
            || !is_string($attestation['authData'])) {
            throw new RuntimeException('Unsupported passkey attestation format.');
        }
        if (!isset($attestation['attStmt']) || !is_array($attestation['attStmt']) || count($attestation['attStmt']) !== 0) {
            throw new RuntimeException('Unexpected passkey attestation statement.');
        }

        $auth = self::parseAuthenticatorData($attestation['authData'], $expected['rp_id'], true);
        $credentialId = self::base64urlEncode($auth['credential_id_raw']);
        $rawId = self::normaliseEncodedId((string)($payload['rawId'] ?? $payload['id'] ?? ''));
        if (!hash_equals($credentialId, $rawId)) {
            throw new RuntimeException('The passkey credential ID did not match.');
        }

        return [
            'credential_id' => $credentialId,
            'public_key' => self::coseEs256ToPem($auth['credential_public_key']),
            'sign_count' => $auth['sign_count'],
            'backup_eligible' => $auth['backup_eligible'] ? 1 : 0,
            'backed_up' => $auth['backed_up'] ? 1 : 0,
        ];
    }

    /** @return array{sign_count:int,backed_up:int} */
    public static function verifyAuthentication(array $payload, array $expected, array $credential): array {
        self::assertCredentialEnvelope($payload);
        $response = $payload['response'] ?? null;
        if (!is_array($response)) throw new RuntimeException('Missing authentication response.');
        $credentialId = self::normaliseEncodedId((string)($payload['rawId'] ?? $payload['id'] ?? ''));
        if (!hash_equals((string)$credential['credential_id'], $credentialId)) {
            throw new RuntimeException('Unknown passkey credential.');
        }
        $clientDataJson = self::decodeField($response, 'clientDataJSON', 65536);
        self::verifyClientData($clientDataJson, 'webauthn.get', $expected);
        $authenticatorData = self::decodeField($response, 'authenticatorData', 65536);
        $auth = self::parseAuthenticatorData($authenticatorData, $expected['rp_id'], false);
        $signature = self::decodeField($response, 'signature', 4096);
        $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
        $verified = openssl_verify($signedData, $signature, (string)$credential['public_key'], OPENSSL_ALGO_SHA256);
        if ($verified !== 1) throw new RuntimeException('The passkey signature was invalid.');

        $storedBackupEligible = !empty($credential['backup_eligible']);
        if ($storedBackupEligible !== $auth['backup_eligible']) {
            throw new RuntimeException('The passkey backup state was inconsistent.');
        }
        $storedCount = (int)($credential['sign_count'] ?? 0);
        if ($storedCount > 0 && $auth['sign_count'] > 0 && $auth['sign_count'] <= $storedCount) {
            throw new RuntimeException('The passkey signature counter did not advance.');
        }
        $expectedHandle = (string)($credential['user_handle'] ?? '');
        $returnedHandle = (string)($response['userHandle'] ?? '');
        if ($expectedHandle === '' || $returnedHandle === '' || !hash_equals($expectedHandle, self::normaliseEncodedId($returnedHandle))) {
            throw new RuntimeException('The passkey user handle did not match.');
        }
        return ['sign_count' => $auth['sign_count'], 'backed_up' => $auth['backed_up'] ? 1 : 0];
    }

    private static function assertCredentialEnvelope(array $payload): void {
        if (($payload['type'] ?? '') !== 'public-key') throw new RuntimeException('Invalid credential type.');
        if (empty($payload['id']) && empty($payload['rawId'])) throw new RuntimeException('Missing credential ID.');
        if (!empty($payload['id']) && !empty($payload['rawId'])
            && !hash_equals(self::normaliseEncodedId((string)$payload['id']), self::normaliseEncodedId((string)$payload['rawId']))) {
            throw new RuntimeException('The passkey credential identifiers did not match.');
        }
    }

    private static function verifyClientData(string $json, string $type, array $expected): void {
        $client = json_decode($json, true);
        if (!is_array($client) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid passkey client data.');
        }
        if (($client['type'] ?? '') !== $type) throw new RuntimeException('Invalid passkey ceremony type.');
        if (!isset($client['challenge']) || !hash_equals((string)$expected['challenge'], self::normaliseEncodedId((string)$client['challenge']))) {
            throw new RuntimeException('The passkey challenge did not match.');
        }
        if (!isset($client['origin']) || !hash_equals((string)$expected['origin'], (string)$client['origin'])) {
            throw new RuntimeException('The passkey origin did not match.');
        }
        if (!empty($client['crossOrigin'])) throw new RuntimeException('Cross-origin passkey requests are not accepted.');
    }

    private static function parseAuthenticatorData(string $data, string $rpId, bool $registration): array {
        if (strlen($data) < 37) throw new RuntimeException('Authenticator data was truncated.');
        if (!hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new RuntimeException('The passkey relying-party ID did not match.');
        }
        $flags = ord($data[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new RuntimeException('The passkey did not verify the user.');
        }
        $backupEligible = ($flags & 0x08) !== 0;
        $backedUp = ($flags & 0x10) !== 0;
        if ($backedUp && !$backupEligible) throw new RuntimeException('Invalid passkey backup flags.');
        $counter = (int)unpack('Ncount', substr($data, 33, 4))['count'];
        $result = [
            'sign_count' => $counter,
            'backup_eligible' => $backupEligible,
            'backed_up' => $backedUp,
        ];
        if (!$registration) return $result;
        if (($flags & 0x40) === 0 || strlen($data) < 55) {
            throw new RuntimeException('Missing attested credential data.');
        }
        $credentialLength = unpack('nlength', substr($data, 53, 2))['length'];
        $credentialStart = 55;
        $keyStart = $credentialStart + $credentialLength;
        if ($credentialLength < 16 || $keyStart >= strlen($data)) {
            throw new RuntimeException('Invalid passkey credential data.');
        }
        $decoder = new WebAuthnCborDecoder();
        $consumed = 0;
        $cose = $decoder->decodePrefix(substr($data, $keyStart), $consumed);
        if (!is_array($cose) || $consumed < 1) throw new RuntimeException('Invalid passkey public key.');
        $result['credential_id_raw'] = substr($data, $credentialStart, $credentialLength);
        $result['credential_public_key'] = $cose;
        return $result;
    }

    private static function coseEs256ToPem(array $key): string {
        if (($key[1] ?? null) !== 2 || ($key[3] ?? null) !== -7 || ($key[-1] ?? null) !== 1) {
            throw new RuntimeException('Only ES256 passkeys are supported.');
        }
        $x = $key[-2] ?? null;
        $y = $key[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Invalid ES256 public key coordinates.');
        }
        $der = hex2bin('3059301306072A8648CE3D020106082A8648CE3D03010703420004') . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function decodeField(array $source, string $name, int $maximum): string {
        if (!isset($source[$name]) || !is_string($source[$name]) || strlen($source[$name]) > ($maximum * 2)) {
            throw new RuntimeException('Missing or oversized passkey field.');
        }
        $decoded = self::base64urlDecode($source[$name]);
        if (strlen($decoded) > $maximum) throw new RuntimeException('Oversized passkey field.');
        return $decoded;
    }

    private static function normaliseEncodedId(string $value): string {
        return self::base64urlEncode(self::base64urlDecode($value));
    }

    private static function rpIdMatchesHost(string $rpId, string $host): bool {
        $rpId = strtolower(rtrim($rpId, '.'));
        $host = strtolower(rtrim($host, '.'));
        return $rpId === $host || substr($host, -strlen('.' . $rpId)) === '.' . $rpId;
    }
}
?>
