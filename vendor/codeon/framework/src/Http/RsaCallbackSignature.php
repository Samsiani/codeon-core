<?php

declare(strict_types=1);

namespace CodeOn\Framework\Http;

use CodeOn\Framework\Logging\Logger;

/**
 * Generic RSA-SHA256 callback signature verifier.
 *
 * Used by any CodeOn payment plugin whose acquirer signs callback bodies
 * with an RSA private key and exposes the public key for verification.
 * The Bank of Georgia Payments API (`api.bog.ge/payments/v1/...`) is the
 * first consumer; the same primitive is reused by future plugins whose
 * banks adopt the same pattern.
 *
 * Contract — fail-closed by design:
 *
 *   verify($body, $signatureBase64) returns TRUE if and only if:
 *     - the configured PEM parses as a valid public key, AND
 *     - $signatureBase64 is non-empty AND base64-decodable, AND
 *     - openssl_verify($body, $decoded, $key, OPENSSL_ALGO_SHA256) === 1.
 *
 *   Returns FALSE on every other path, including:
 *     - empty / malformed PEM (logs error — this is a deployment bug),
 *     - empty / malformed signature header (logs warning),
 *     - mismatched signature (no log: this is the attack surface we
 *       defend against; quiet refusal prevents the log from becoming a
 *       free oracle for an attacker probing variants).
 *
 *   Never throws. Never returns true on error. Never falls back to
 *   "skip verification" behaviour. The verifier is the gate; if the
 *   gate is broken, every callback is rejected.
 *
 * Algorithm is hard-coded to SHA-256 because the only cryptographically
 * meaningful choice today; if a bank ships a different scheme, a sibling
 * class is the right scope, not a polymorphic constructor argument.
 */
final class RsaCallbackSignature
{
    public function __construct(
        private readonly string $publicKeyPem,
        private readonly Logger $logger,
        private readonly string $headerName = 'Callback-Signature',
    ) {
    }

    /**
     * Verify a signature against the raw HTTP request body.
     *
     * Pass `$rawBody` exactly as it arrived on the wire — every byte,
     * unmodified. JSON re-encoding the parsed body will fail
     * verification because key order, whitespace, and escaping
     * differ between encoders.
     *
     * @param string $rawBody         The exact bytes the bank signed.
     * @param string $signatureBase64 Value of the signature header
     *                                (e.g. `Callback-Signature`).
     */
    public function verify(string $rawBody, string $signatureBase64): bool
    {
        if ($this->publicKeyPem === '') {
            $this->logger->error('callback verify: empty public key configured');
            return false;
        }

        if ($signatureBase64 === '') {
            $this->logger->warning('callback verify: empty signature header', [
                'header' => $this->headerName,
            ]);
            return false;
        }

        $decoded = base64_decode($signatureBase64, true);
        if ($decoded === false || $decoded === '') {
            $this->logger->warning('callback verify: signature is not valid base64', [
                'header'      => $this->headerName,
                'header_size' => strlen($signatureBase64),
            ]);
            return false;
        }

        $key = openssl_pkey_get_public($this->publicKeyPem);
        if ($key === false) {
            $err = $this->collectOpenSslErrors();
            $this->logger->error('callback verify: public key did not parse', [
                'openssl_errors' => $err,
            ]);
            return false;
        }

        $result = openssl_verify($rawBody, $decoded, $key, OPENSSL_ALGO_SHA256);

        if ($result === 1) {
            return true;
        }

        // Quiet refusal — see class doc. We deliberately do not log the
        // payload or signature on mismatch; both could be attacker-chosen,
        // and we don't want the log to become a probing oracle.
        if ($result === -1) {
            $this->logger->error('callback verify: openssl_verify error', [
                'openssl_errors' => $this->collectOpenSslErrors(),
            ]);
        }

        return false;
    }

    public function headerName(): string
    {
        return $this->headerName;
    }

    /**
     * Drain OpenSSL's per-thread error queue. We surface these only on
     * "this is a config bug" paths (empty / malformed key, openssl_verify
     * returning -1) — never on legitimate signature mismatches.
     *
     * @return list<string>
     */
    private function collectOpenSslErrors(): array
    {
        $out = [];
        while (($e = openssl_error_string()) !== false) {
            $out[] = $e;
        }
        return $out;
    }
}
