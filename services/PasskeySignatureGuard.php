<?php

require_once __DIR__ . '/../model/Database.php';

final class PasskeySignatureGuard
{
    /**
     * Consomme une preuve WebAuthn de signature à usage unique.
     *
     * Le contexte est optionnel pour préserver la compatibilité des anciens
     * workflows, mais les nouveaux circuits doivent toujours le fournir afin
     * d'empêcher qu'une preuve obtenue pour un document soit réutilisée pour
     * signer un autre document ou une autre étape.
     */
    public static function requireVerified(int $userId, ?string $expectedContext = null): array
    {
        $proof = $_SESSION['passkey_signature_proof'] ?? null;
        unset($_SESSION['passkey_signature_proof']);

        if (!is_array($proof)
            || (int)($proof['user_id'] ?? 0) !== $userId
            || (int)($proof['expires_at'] ?? 0) < time()
            || (int)($proof['credential_id'] ?? 0) < 1) {
            throw new RuntimeException('Confirmez cette signature avec votre Passkey avant de continuer.');
        }

        // Une révocation doit invalider aussi une preuve délivrée quelques
        // secondes auparavant, pas seulement les futures cérémonies WebAuthn.
        $pdo = Database::getConnection();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE id=:credential_id AND user_id=:user_id AND revoked_at IS NULL');
        $statement->execute([
            'credential_id' => (int)$proof['credential_id'],
            'user_id' => $userId,
        ]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('La Passkey ayant confirmé cette signature a été révoquée.');
        }

        $expectedContext = self::normalizeContext($expectedContext);
        $proofContext = self::normalizeContext((string)($proof['context'] ?? ''));
        if ($expectedContext !== '' && !hash_equals($expectedContext, $proofContext)) {
            throw new RuntimeException('La confirmation Passkey ne correspond pas au document ou à l’étape à signer.');
        }

        return $proof;
    }

    public static function normalizeContext(?string $context): string
    {
        $context = trim((string)$context);
        if ($context === '') {
            return '';
        }

        if (strlen($context) > 190 || !preg_match('/^[a-zA-Z0-9:_\-.]+$/', $context)) {
            throw new InvalidArgumentException('Contexte de signature invalide.');
        }

        return $context;
    }
}
