<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use Carbon\CarbonImmutable;
use OpenSSLCertificate;

class CertificateInspector
{
    /** certbot renews at 30 days, so anything under that means a renewal did not happen. */
    public const WARN_WITHIN_DAYS = 30;

    /**
     * When the service's TLS certificate expires, or null if it cannot be established.
     *
     * HttpProbe cannot supply this: Guzzle does not hand back the peer certificate. So
     * this opens its own short-lived TLS connection and reads the certificate the server
     * presents. Null on any failure, because the caller must never turn "we could not
     * look" into "expiring soon" (STAT-23).
     */
    public function expiresAt(Service $service): ?CarbonImmutable
    {
        $host = $service->tlsHost();

        if (! $service->usesTls() || $host === null) {
            return null;
        }

        $certificate = $this->fetchCertificate($host, $service->tlsPort(), $service->timeout_seconds);

        if ($certificate === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        return $parsed === false ? null : $this->expiryFromParsedCertificate($parsed);
    }

    /**
     * Pure, so the date handling is testable without opening a socket.
     *
     * Keyed array-key rather than string because that is what openssl_x509_parse()
     * is declared to return; the guard below is what makes the value safe to use.
     *
     * @param  array<array-key, mixed>  $parsed  the output of openssl_x509_parse()
     */
    public function expiryFromParsedCertificate(array $parsed): ?CarbonImmutable
    {
        $validTo = $parsed['validTo_time_t'] ?? null;

        if (! is_int($validTo) || $validTo <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($validTo);
    }

    private function fetchCertificate(string $host, int $port, int $timeout): ?OpenSSLCertificate
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // Verification is deliberately off. An already-expired certificate fails
                // the handshake when verifying, and an expired certificate is exactly the
                // case we most need to be able to report on.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        // capture_peer_cert only populates this when the handshake actually completed.
        $ssl = $params['options']['ssl'] ?? null;
        $certificate = is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;

        return $certificate instanceof OpenSSLCertificate ? $certificate : null;
    }
}
