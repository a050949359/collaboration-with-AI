<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class GcpAccessTokenProvider
{
    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    private ?string $credentialsPath;

    private string $scope;

    /**
     * @param  string|null  $credentialsPath  SA 金鑰路徑；null 時用 Vertex 的設定（向下相容）
     * @param  string  $scope  OAuth scope；預設 cloud-platform（Vertex 原行為）
     */
    public function __construct(
        ?string $credentialsPath = null,
        string $scope = 'https://www.googleapis.com/auth/cloud-platform',
    ) {
        $this->credentialsPath = $credentialsPath;
        $this->scope = $scope;
    }

    public function getToken(): string
    {
        if ($this->token && now()->getTimestamp() < ($this->tokenExpiresAt - 60)) {
            return $this->token;
        }

        $credentials = $this->readCredentials();
        $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $jwt = $this->buildJwtAssertion($credentials, $tokenUri);

        $response = Http::asForm()
            ->timeout(20)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->ok()) {
            throw new AIServiceException('Failed to fetch GCP access token.');
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $expiresIn = is_array($payload) ? (int) ($payload['expires_in'] ?? 0) : 0;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new AIServiceException('GCP access token is missing from token response.');
        }

        $this->token = $accessToken;
        $this->tokenExpiresAt = now()->getTimestamp() + max($expiresIn, 300);

        return $this->token;
    }

    /**
     * @return array<string, mixed>
     */
    private function readCredentials(): array
    {
        $path = $this->credentialsPath ?? config('services.vertex_ai.credentials_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new AIServiceException('GCP_APPLICATION_CREDENTIALS path is invalid.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new AIServiceException('GCP credential JSON is invalid.');
        }

        if (! isset($decoded['client_email'], $decoded['private_key'])) {
            throw new AIServiceException('GCP credential JSON must include client_email and private_key.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function buildJwtAssertion(array $credentials, string $audience): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $issuedAt = now()->getTimestamp();
        $payload = [
            'iss' => $credentials['client_email'],
            'sub' => $credentials['client_email'],
            'aud' => $audience,
            'scope' => $this->scope,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        $privateKey = openssl_pkey_get_private((string) $credentials['private_key']);

        if ($privateKey === false) {
            throw new AIServiceException('Failed to load GCP private key.');
        }

        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new AIServiceException('Failed to sign GCP JWT assertion.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
