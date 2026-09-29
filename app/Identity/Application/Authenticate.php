<?php

namespace App\Identity\Application;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\AccountRepository;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class Authenticate
{
    public function __construct(private AccountRepository $accounts, private UnitOfWork $transactions,
        private PasswordHasher $passwords, private Tokens $tokens, private AuditWriter $audit) {}

    public function login(string $login, string $password, string $device): array
    {
        $result = $this->transactions->run(function () use ($login, $password, $device) {
            $account = $this->accounts->byLogin($login, true);
            // User tak dikenal pun tetap cek hash (dummy) agar tak bisa ditebak dari timing.
            $hash = $account?->passwordHash ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = $this->passwords->check($password, $hash);
            if (! $account || ! $valid || ! $account->active) {
                $this->audit->record('auth.login_failed', null, null);

                return null;
            }
            $token = $this->tokens->issue($account->id, $device);
            $this->audit->record('auth.login_succeeded', $account->id, $account->id);

            return [...$token, 'user' => $account->publicData()];
        });

        // Lempar di luar transaksi agar login gagal tetap tercatat di audit.
        return $result ?? throw new BusinessRule('INVALID_CREDENTIALS', 'Username/email atau kata sandi salah, atau akun tidak aktif.', 401);
    }

    public function logout(string $id, ?string $tokenId, bool $all = false): void
    {
        $this->transactions->run(function () use ($id, $tokenId, $all) {
            $this->accounts->find($id, true);
            if ($all) {
                $this->tokens->revokeAll($id);
            } elseif ($tokenId) {
                $this->tokens->revokeCurrent($id, $tokenId);
            }
            $this->audit->record($all ? 'auth.tokens_revoked' : 'auth.logout', $id, $id);
        });
    }
}
