<?php
/**
 * Mailpro Enterprise - In-App DKIM Cryptographic Signing Engine
 * Compliant with RFC 6376 (DomainKeys Identified Mail Signatures)
 * 
 * Implements:
 *  - Algorithm: rsa-sha256
 *  - Canonicalization: relaxed/relaxed (Header: relaxed, Body: relaxed)
 *  - Automated 2048-bit RSA Keypair Generation
 *  - Live DNS TXT Record Verification
 *  - Multi-Domain Key Resolution from Database
 */

class DkimSigner {

    /**
     * Headers to include in the DKIM signature if present (lowercase)
     */
    protected static array $signableHeaders = [
        'from',
        'to',
        'subject',
        'date',
        'message-id',
        'mime-version',
        'content-type',
        'reply-to'
    ];

    /**
     * Generate a new 2048-bit RSA key pair for DKIM signing.
     * Returns: ['private_key' => PEM, 'public_key' => PEM, 'dns_record' => TXT value]
     */
    public static function generateKeyPair(int $bits = 2048): array {
        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new Exception("OpenSSL failed to generate private key: " . openssl_error_string());
        }

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $publicKey = $details['key'] ?? '';

        // Extract clean base64 public key (strip PEM delimiters and whitespace)
        $cleanPubKey = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKey);
        $dnsRecord = "v=DKIM1; k=rsa; p=" . $cleanPubKey;

        return [
            'private_key' => $privateKey,
            'public_key'  => $publicKey,
            'clean_pub'   => $cleanPubKey,
            'dns_record'  => $dnsRecord
        ];
    }

    /**
     * Format the full DNS record host and value for display and copy.
     */
    public static function getDnsRecordInfo(string $domain, string $selector, string $publicKeyPem): array {
        $cleanPubKey = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKeyPem);
        return [
            'host'  => $selector . '._domainkey.' . ltrim($domain, '.'),
            'type'  => 'TXT',
            'value' => 'v=DKIM1; k=rsa; p=' . $cleanPubKey
        ];
    }

    /**
     * Live DNS TXT Record Verification.
     * Queries DNS for selector._domainkey.domain and verifies if p= matches the public key.
     */
    public static function verifyDnsRecord(string $domain, string $selector, ?string $expectedPublicKey = null): array {
        $host = $selector . '._domainkey.' . ltrim($domain, '.');
        $records = @dns_get_record($host, DNS_TXT);

        if (empty($records)) {
            return [
                'verified' => false,
                'message'  => "No TXT record found for host: {$host}",
                'found'    => null
            ];
        }

        $fullTxt = '';
        foreach ($records as $r) {
            $txt = $r['txt'] ?? ($r['entries'][0] ?? '');
            if (stripos($txt, 'v=DKIM1') !== false || stripos($txt, 'p=') !== false) {
                $fullTxt = $txt;
                break;
            }
        }

        if (!$fullTxt) {
            return [
                'verified' => false,
                'message'  => "TXT records exist for {$host}, but none contain 'v=DKIM1' or 'p='",
                'found'    => $records[0]['txt'] ?? ''
            ];
        }

        if ($expectedPublicKey) {
            $cleanExpected = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $expectedPublicKey);
            if (preg_match('/p=([a-zA-Z0-9+\/=]+)/', $fullTxt, $m)) {
                $foundPub = trim($m[1]);
                if ($foundPub === $cleanExpected) {
                    return [
                        'verified' => true,
                        'message'  => "DKIM DNS record is valid and matching!",
                        'found'    => $fullTxt
                    ];
                } else {
                    return [
                        'verified' => false,
                        'message'  => "DKIM TXT record found, but public key does not match local key",
                        'found'    => $fullTxt
                    ];
                }
            }
        }

        return [
            'verified' => true,
            'message'  => "DKIM DNS record exists and has valid DKIM format",
            'found'    => $fullTxt
        ];
    }

    /**
     * Relaxed Body Canonicalization (RFC 6376 §3.4.4)
     * - Ignore all whitespace at end of lines
     * - Reduce all sequences of WSP within a line to a single space
     * - Ignore all empty lines at the end of the message body
     * - Ensure body ends with CRLF if not empty
     */
    public static function canonicalizeBodyRelaxed(string $body): string {
        if ($body === '') return '';

        // Normalize newlines to CRLF
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        $out = [];
        foreach ($lines as $line) {
            // Reduce multiple spaces/tabs to single space
            $line = preg_replace('/[ \t]+/', ' ', $line);
            // Strip trailing whitespace
            $line = rtrim($line, " \t");
            $out[] = $line;
        }

        // Remove trailing empty lines
        while (!empty($out) && end($out) === '') {
            array_pop($out);
        }

        if (empty($out)) {
            return '';
        }

        return implode("\r\n", $out) . "\r\n";
    }

    /**
     * Relaxed Header Canonicalization (RFC 6376 §3.4.2)
     * - Convert header field name to lowercase
     * - Unfold continuation lines (replace CRLF followed by WSP with a single space)
     * - Convert sequences of WSP to a single space
     * - Strip leading and trailing WSP from header value
     */
    public static function canonicalizeHeaderRelaxed(string $name, string $value): string {
        $nameLower = strtolower(trim($name));
        // Unfold
        $value = preg_replace('/\r\n[ \t]+/', ' ', $value);
        // Squash WSP
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = trim($value, " \t\r\n");

        return $nameLower . ':' . $value;
    }

    /**
     * Parse raw headers string into an associative list of [name => [values]]
     */
    public static function parseHeaders(string $rawHeaders): array {
        $rawHeaders = str_replace(["\r\n", "\r"], "\n", $rawHeaders);
        $lines = explode("\n", $rawHeaders);

        $headers = [];
        $currentName = '';
        $currentValue = '';

        foreach ($lines as $line) {
            if ($line === '') continue;
            if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
                // Continuation line
                $currentValue .= "\r\n" . $line;
            } else {
                if ($currentName !== '') {
                    $headers[] = ['name' => $currentName, 'value' => $currentValue];
                }
                $colonPos = strpos($line, ':');
                if ($colonPos !== false) {
                    $currentName = substr($line, 0, $colonPos);
                    $currentValue = substr($line, $colonPos + 1);
                } else {
                    $currentName = '';
                    $currentValue = '';
                }
            }
        }
        if ($currentName !== '') {
            $headers[] = ['name' => $currentName, 'value' => $currentValue];
        }

        return $headers;
    }

    /**
     * Sign an RFC 5322 message and return the complete DKIM-Signature header string.
     * 
     * @param string $rawHeaders Raw headers string (before blank line)
     * @param string $rawBody Raw body string (after blank line)
     * @param string $domain Signing domain (d=)
     * @param string $selector Key selector (s=)
     * @param string $privateKeyPem OpenSSL PEM RSA Private Key
     * @return string Complete DKIM-Signature header ending with CRLF
     */
    public static function signMessage(
        string $rawHeaders,
        string $rawBody,
        string $domain,
        string $selector,
        string $privateKeyPem
    ): string {
        // 1. Body Hash (bh=)
        $canonBody = self::canonicalizeBodyRelaxed($rawBody);
        $bodyHash = base64_encode(hash('sha256', $canonBody, true));

        // 2. Parse and filter headers to sign
        $parsedHeaders = self::parseHeaders($rawHeaders);
        $signedHeadersList = [];
        $canonHeadersData = [];

        // In RFC 6376, sign in standard order for headers present
        foreach (self::$signableHeaders as $targetHeader) {
            foreach ($parsedHeaders as $h) {
                if (strtolower($h['name']) === $targetHeader) {
                    $signedHeadersList[] = $targetHeader;
                    $canonHeadersData[] = self::canonicalizeHeaderRelaxed($h['name'], $h['value']);
                    break; // sign first occurrence of each
                }
            }
        }

        $headersTag = implode(':', $signedHeadersList);
        $time = time();

        // 3. Construct DKIM-Signature header without signature
        $dkimHeaderData = [
            'v=1',
            'a=rsa-sha256',
            'c=relaxed/relaxed',
            'd=' . strtolower(trim($domain)),
            's=' . trim($selector),
            't=' . $time,
            'h=' . $headersTag,
            'bh=' . $bodyHash,
            'b='
        ];
        $dkimHeaderValue = implode('; ', $dkimHeaderData);

        // 4. Canonicalize DKIM-Signature header for signing (per RFC 6376 §3.5)
        $canonDkimHeader = self::canonicalizeHeaderRelaxed('DKIM-Signature', $dkimHeaderValue);

        // Concatenate all canonicalized headers + canonicalized DKIM header (without trailing CRLF on DKIM header)
        $dataToSign = implode("\r\n", $canonHeadersData) . "\r\n" . $canonDkimHeader;

        // 5. Cryptographic signature with OpenSSL
        $pk = openssl_pkey_get_private($privateKeyPem);
        if (!$pk) {
            throw new Exception("Invalid DKIM private key provided: " . openssl_error_string());
        }

        $signature = '';
        if (!openssl_sign($dataToSign, $signature, $pk, OPENSSL_ALGO_SHA256)) {
            throw new Exception("DKIM openssl_sign failed: " . openssl_error_string());
        }

        $base64Sig = base64_encode($signature);

        // Return the formatted header ending with CRLF
        $fullDkimHeader = "DKIM-Signature: " . $dkimHeaderValue . $base64Sig . "\r\n";
        return $fullDkimHeader;
    }

    /**
     * Resolve an active DKIM key for a given domain from the database.
     * Falls back to checking parent domain (e.g. sub.example.com -> example.com).
     */
    public static function getActiveKeyForDomain(string $domain, ?int $userId = null): ?array {
        if (!function_exists('db')) return null;

        try {
            $pdo = db();
            $domainClean = strtolower(trim($domain));
            if (!$domainClean) return null;

            // Generate domain variants (e.g. mail.domain.com -> domain.com)
            $candidates = [$domainClean];
            $parts = explode('.', $domainClean);
            if (count($parts) > 2) {
                $candidates[] = implode('.', array_slice($parts, 1));
            }

            foreach ($candidates as $candidate) {
                $sql = "SELECT id, user_id, domain, selector, private_key, public_key, dns_verified, is_active
                        FROM dkim_keys
                        WHERE LOWER(domain) = ? AND is_active = 1";
                $params = [$candidate];
                if ($userId !== null) {
                    $sql .= " AND (user_id = ? OR user_id = 1)";
                    $params[] = $userId;
                }
                $sql .= " ORDER BY user_id DESC, id DESC LIMIT 1";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $key = $stmt->fetch();
                if ($key && !empty($key['private_key'])) {
                    return $key;
                }
            }
        } catch (\Throwable $e) {
            error_log("[DKIM Resolver] Error: " . $e->getMessage());
        }

        return null;
    }
}
