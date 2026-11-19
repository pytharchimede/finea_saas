<?php

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class PasskeyService
{
    private $serializer;
    private CeremonyStepManagerFactory $ceremonyFactory;

    public function __construct(private array $config)
    {
        $attestations = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer = (new WebauthnSerializerFactory($attestations))->create();
        $this->ceremonyFactory = new CeremonyStepManagerFactory();
        $this->ceremonyFactory->setAttestationStatementSupportManager($attestations);
        $this->ceremonyFactory->setAllowedOrigins([$config['origin']], false);
    }

    public function serializer()
    {
        return $this->serializer;
    }

    public function registrationOptions(array $user, string $userHandle, array $existing): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->config['rp_name'], $this->config['rp_id']),
            PublicKeyCredentialUserEntity::create(
                (string) $user['mail_pro'],
                $userHandle,
                trim((string) $user['prenom'] . ' ' . (string) $user['nom']) ?: (string) $user['mail_pro']
            ),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(null, 'required', 'required'),
            'none',
            array_map(fn (CredentialRecord $record) => $record->getPublicKeyCredentialDescriptor(), $existing),
            $this->config['timeout_ms'],
            null,
            ['client-device', 'hybrid', 'security-key']
        );
    }

    /** @param CredentialRecord[] $allowedRecords */
    public function authenticationOptions(array $allowedRecords = []): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->config['rp_id'],
            array_map(fn (CredentialRecord $record) => $record->getPublicKeyCredentialDescriptor(), $allowedRecords),
            'required',
            $this->config['timeout_ms'],
            null,
            ['client-device', 'hybrid', 'security-key']
        );
    }

    public function optionsJson(object $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    public function parseCredential(string $json): PublicKeyCredential
    {
        return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
    }

    public function verifyRegistration(PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Réponse WebAuthn invalide.');
        }
        return AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory->creationCeremony())
            ->check($credential->response, $options, $this->config['rp_id']);
    }

    public function verifyAuthentication(PublicKeyCredential $credential, CredentialRecord $record, PublicKeyCredentialRequestOptions $options): CredentialRecord
    {
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Réponse WebAuthn invalide.');
        }
        return AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory->requestCeremony())
            ->check($record, $credential->response, $options, $this->config['rp_id'], $credential->response->userHandle);
    }

    public function restoreRegistrationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        return $this->serializer->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    public function restoreAuthenticationOptions(string $json): PublicKeyCredentialRequestOptions
    {
        return $this->serializer->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }
}
