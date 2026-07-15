<?php

namespace App\Services;

use Illuminate\Http\Request;

class MasterAdminSession
{
    public function attempt(string $password): bool
    {
        $configuredPassword = $this->configuredPassword();

        return $configuredPassword !== null
            && hash_equals($configuredPassword, $password);
    }

    public function authenticate(Request $request): void
    {
        $fingerprint = $this->fingerprint();

        if ($fingerprint === null) {
            return;
        }

        $request->session()->put([
            $this->fingerprintKey() => $fingerprint,
            $this->authenticatedAtKey() => time(),
        ]);
    }

    public function isAuthenticated(Request $request): bool
    {
        $fingerprint = $this->fingerprint();
        $storedFingerprint = $request->session()->get($this->fingerprintKey());
        $authenticatedAt = $request->session()->get($this->authenticatedAtKey());

        return $fingerprint !== null
            && is_string($storedFingerprint)
            && is_int($authenticatedAt)
            && $authenticatedAt >= time() - ($this->sessionMinutes() * 60)
            && hash_equals($fingerprint, $storedFingerprint);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            $this->fingerprintKey(),
            $this->authenticatedAtKey(),
        ]);
    }

    private function configuredPassword(): ?string
    {
        $password = config('checklist.admin_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function fingerprint(): ?string
    {
        $password = $this->configuredPassword();

        if ($password === null) {
            return null;
        }

        return hash_hmac('sha256', $password, (string) config('app.key', ''));
    }

    private function fingerprintKey(): string
    {
        return $this->sessionKey().'.fingerprint';
    }

    private function authenticatedAtKey(): string
    {
        return $this->sessionKey().'.authenticated_at';
    }

    private function sessionKey(): string
    {
        $key = config('checklist.admin_session_key', 'checklist.admin');

        return is_string($key) && $key !== '' ? $key : 'checklist.admin';
    }

    private function sessionMinutes(): int
    {
        return max(1, (int) config('checklist.admin_session_minutes', 120));
    }
}
