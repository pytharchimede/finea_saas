<?php

class AiExternalProviderService
{
    private array $config;
    private AiAnonymizerService $anonymizer;

    private string $systemPrompt = 'Tu es l’assistant rédactionnel intégré à l’ERP FINEA.

Ta mission est de transformer un texte brut en document administratif professionnel clair et structuré.

Tu dois :
- organiser les informations fournies
- reformuler de manière professionnelle
- structurer le texte (objet, introduction, contenu, conclusion)
- corriger les fautes
- améliorer la clarté

Tu peux reformuler librement MAIS :
- tu ne dois pas inventer d’informations non présentes
- tu dois utiliser uniquement les informations fournies

Tu dois produire directement le document final complet.

Regle metier critique sur le role du demandeur :
- Si le role du demandeur est "self", le texte doit etre redige comme si l employe ecrit lui-meme sa demande, a la premiere personne du singulier ("je").
- Si le role du demandeur est "tiers", le texte doit etre redige pour une autre personne.
- Ne jamais inverser les roles entre l employe, le responsable et le destinataire.
- Si le nom de l employe et le responsable sont identiques, considere par defaut que le demandeur ecrit pour lui-meme.

Regle metier critique sur le type reel de demande :
- Le type metier reel de la demande est prioritaire.
- Si request_type = avance_salaire, tu dois rediger une demande d avance sur salaire.
- Si request_type = absence, tu dois rediger une demande d absence.
- Si request_type = heures_supp, tu dois rediger une demande d heures supplementaires et utiliser les heures/la date effective si elles sont fournies.
- Ne transforme jamais une avance sur salaire en demande d absence.
- Ne mentionne jamais un montant si aucun montant n est fourni ou si le type metier ne concerne pas un montant.
- Le document final doit correspondre au type metier reel, pas seulement a l action IA demandee.

Tu ne dois pas poser de questions.
Tu ne dois pas demander d’informations supplémentaires.
Tu ne dois pas commenter ton travail.';

    public function __construct(array $config, AiAnonymizerService $anonymizer)
    {
        $this->config = $config;
        $this->anonymizer = $anonymizer;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['AI_EXTERNAL_ENABLED'])
            && trim((string)($this->config['AI_API_KEY'] ?? '')) !== ''
            && trim((string)($this->config['AI_ENDPOINT'] ?? '')) !== '';
    }

    public function providerName(): string
    {
        return trim((string)($this->config['AI_PROVIDER'] ?? 'external')) ?: 'external';
    }

    public function improve(string $text, array $context = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('API IA externe non configuree. Le mode interne reste disponible.');
        }

        $anonymized = $this->anonymizer->anonymize($text);
        $prompt = $this->buildPrompt($anonymized['text'], $context);
        $response = $this->callHttpApi($prompt);
        $restored = $this->anonymizer->restore($response, $anonymized['replacements']);

        return [
            'text' => $restored,
            'anonymized_text' => $anonymized['text'],
            'replacements' => $anonymized['replacements'],
            'provider' => $this->providerName(),
        ];
    }

    public function generateAdministrativeSection(string $targetLabel, string $contextText, array $context = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('API IA externe non configuree. Le mode interne reste disponible.');
        }

        $targetLabel = trim($targetLabel) !== '' ? trim($targetLabel) : 'section administrative';
        $contextText = trim($contextText);
        if ($contextText === '') {
            throw new RuntimeException('Contexte IA vide.');
        }

        $anonymized = $this->anonymizer->anonymize($contextText);
        $systemPrompt = 'Tu es l assistant redactionnel integre a l ERP FINEA. Tu aides a remplir une fiche d expression de besoin. Tu dois rediger uniquement la section demandee, sans titre, sans salutation, sans conclusion generale, sans liste de questions. Le texte doit sonner naturel, comme une personne qui explique simplement son besoin a son service, pas comme un texte auto-genere ou un rapport administratif. Utilise des phrases courtes a moyennes, un vocabulaire concret, et evite les formules lourdes comme "dans le cadre de", "il convient de", "afin de garantir une optimisation", "la presente demande vise a". Ne commence jamais par "Le module FEB a ete configure". Ne mentionne jamais le nom du demandeur, le matricule, le poste individuel, ni le nom du fournisseur, sauf si le contexte l autorise explicitement. Meme si un fournisseur est connu, parle plutot de "fournisseur pressenti" ou de "solution retenue" sans nommer l entite. Tu peux mobiliser tes connaissances metier pour rendre le texte clair, credible et professionnel, mais tu ne dois pas inventer de quantites, montants, dates, fournisseurs ou faits absents du contexte. Si une information manque, formule de maniere prudente.';
        $prompt = "Section a rediger : {$targetLabel}\n"
            . "Ton : " . ($context['tone'] ?? 'professionnel') . "\n"
            . "Module : FEB\n"
            . "Consignes : rediger un paragraphe naturel pret a coller dans le champ. Longueur cible : 55 a 120 mots. Eviter le ton robotique et les tournures trop institutionnelles.\n\n"
            . "Contexte formulaire et referentiel :\n" . $anonymized['text'];

        $response = $this->callHttpApi($prompt, $systemPrompt);
        $restored = $this->anonymizer->restore($response, $anonymized['replacements']);

        return [
            'text' => $restored,
            'anonymized_text' => $anonymized['text'],
            'replacements' => $anonymized['replacements'],
            'provider' => $this->providerName(),
        ];
    }

    private function buildPrompt(string $text, array $context): string
    {
        return "Module : " . ($context['module'] ?? 'General') . "\n"
            . "Action demandee : " . ($context['task_type'] ?? 'reformuler') . "\n"
            . "Type metier reel : " . ($context['request_type'] ?? ($context['variables']['request_type'] ?? '')) . "\n"
            . "Ton souhaite : " . ($context['tone'] ?? 'professionnel') . "\n"
            . "Role du demandeur : " . ($context['role_demandeur'] ?? 'self') . "\n\n"
            . "Informations :\n"
            . "- Nom : " . ($context['nom_employe'] ?? ($context['variables']['nom_employe'] ?? '')) . "\n"
            . "- Matricule : " . ($context['matricule'] ?? ($context['variables']['matricule'] ?? '')) . "\n"
            . "- Poste : " . ($context['poste'] ?? ($context['variables']['poste'] ?? '')) . "\n"
            . "- Service : " . ($context['service'] ?? ($context['variables']['service'] ?? '')) . "\n"
            . "- Periode : " . ($context['periode'] ?? ($context['variables']['periode'] ?? '')) . "\n"
            . $this->optionalPromptLine('Montant', $context['montant'] ?? ($context['variables']['montant'] ?? ''))
            . $this->optionalPromptLine('Heures', $context['heures'] ?? ($context['variables']['heures'] ?? ''))
            . $this->optionalPromptLine('Date effective', $context['date_effective'] ?? ($context['variables']['date_effective'] ?? ''))
            . "- Motif : " . ($context['motif'] ?? ($context['variables']['motif'] ?? '')) . "\n"
            . "- Responsable : " . ($context['responsable'] ?? ($context['variables']['responsable'] ?? '')) . "\n"
            . "- Entreprise : " . ($context['entreprise'] ?? ($context['variables']['entreprise'] ?? 'FINEA')) . "\n\n"
            . "Consigne type metier : le document final doit respecter le Type metier reel. Ne choisis pas un modele d absence si le type metier reel est avance_salaire.\n"
            . "Consigne metier : le texte doit etre redige comme si l employe ecrit lui-meme sa demande lorsque le role du demandeur vaut self.\n\n"
            . "Texte a ameliorer :\n" . $text;
    }

    private function optionalPromptLine(string $label, $value): string
    {
        $value = trim((string)$value);
        return $value !== '' ? "- {$label} : {$value}\n" : '';
    }

    private function callHttpApi(string $userPrompt, ?string $systemPrompt = null): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL PHP est indisponible sur cet hebergement.');
        }

        $payload = [
            'model' => (string)($this->config['AI_MODEL'] ?? ''),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt ?? $this->systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ];

        $ch = curl_init((string)$this->config['AI_ENDPOINT']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string)$this->config['AI_API_KEY'],
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(10, (int)($this->config['AI_TIMEOUT_SECONDS'] ?? 25)),
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false),
            // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false),
            curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../config/cacert.pem'),
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Appel IA externe impossible : ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->formatHttpError($status, (string)$raw));
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Reponse IA externe illisible.');
        }

        $text = $decoded['choices'][0]['message']['content']
            ?? $decoded['candidates'][0]['content']['parts'][0]['text']
            ?? $decoded['output_text']
            ?? $decoded['text']
            ?? '';

        $text = trim((string)$text);
        if ($text === '') {
            throw new RuntimeException('Reponse IA externe vide.');
        }

        return $text;
    }

    private function formatHttpError(int $status, string $raw): string
    {
        $decoded = json_decode($raw, true);
        $providerMessage = '';
        if (is_array($decoded)) {
            $providerMessage = (string)($decoded['error']['message'] ?? $decoded['message'] ?? '');
        }
        $providerMessage = trim($providerMessage);

        $message = match ($status) {
            401 => 'Authentification IA refusee : verifiez que la cle API correspond bien au fournisseur ' . $this->providerName() . ' et qu elle est active.',
            403 => 'Acces IA refuse : la cle API est valide mais le compte ou le modele n est pas autorise.',
            404 => 'Endpoint ou modele IA introuvable : verifiez AI_ENDPOINT et AI_MODEL.',
            429 => 'Quota ou limite IA atteinte : reessayez plus tard ou changez de modele.',
            default => 'API IA externe indisponible. Code HTTP ' . $status . '.',
        };

        return $providerMessage !== '' ? $message . ' Detail fournisseur : ' . $providerMessage : $message;
    }
}
