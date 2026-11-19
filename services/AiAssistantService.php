<?php

class AiAssistantService
{
    private AiTemplateService $templateService;
    private AiExternalProviderService $externalProvider;
    private AiRequestRepository $requestRepository;
    private AiFeedbackRepository $feedbackRepository;
    private AiUsageLogRepository $usageLogRepository;
    private AiSensitiveReplacementRepository $replacementRepository;

    private array $modules = ['RH', 'FEB', 'Documents', 'Formulaires', 'General'];
    private array $taskTypes = ['rediger_demande', 'repondre_demande_explication', 'reformuler', 'resumer', 'generer_note', 'generer_formulaire'];
    private array $tones = ['professionnel', 'direct', 'administratif', 'cordial'];

    public function __construct(
        AiTemplateService $templateService,
        AiExternalProviderService $externalProvider,
        AiRequestRepository $requestRepository,
        AiFeedbackRepository $feedbackRepository,
        AiUsageLogRepository $usageLogRepository,
        AiSensitiveReplacementRepository $replacementRepository
    ) {
        $this->templateService = $templateService;
        $this->externalProvider = $externalProvider;
        $this->requestRepository = $requestRepository;
        $this->feedbackRepository = $feedbackRepository;
        $this->usageLogRepository = $usageLogRepository;
        $this->replacementRepository = $replacementRepository;
    }

    public function options(): array
    {
        return [
            'modules' => $this->modules,
            'task_types' => $this->taskTypes,
            'tones' => $this->tones,
            'external_ai_configured' => $this->externalProvider->isConfigured(),
        ];
    }

    public function generate(array $input, int $userId, array $server): array
    {
        $data = $this->sanitizeInput($input);
        $template = $this->templateService->selectBestTemplate($data['module'], $data['task_type'], $data['tone'], $data['input_text'], $data['request_type']);
        $templateVariables = array_merge($data['variables'], ['role_demandeur' => $data['role_demandeur']]);
        $generated = $this->templateService->renderTemplate($template, $templateVariables, $data['input_text']);
        $generated = $this->applyTaskRefinement($generated, $data);
        $generated = $this->appendUsefulExamples($generated, $data['module'], $data['task_type']);

        $requestId = $this->requestRepository->create([
            'user_id' => $userId > 0 ? $userId : null,
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'request_type' => $data['request_type'],
            'tone' => $data['tone'],
            'input_text' => $data['input_text'],
            'generated_text' => $generated,
            'provider' => 'internal',
            'used_external_ai' => false,
            'status' => 'generated',
        ]);

        $this->logUsage($userId, 'generate_internal', 'internal', $data, $server);

        return [
            'success' => true,
            'request_id' => $requestId,
            'generated_text' => $generated,
            'template_title' => (string)($template['title'] ?? 'Modele interne'),
            'external_ai_configured' => $this->externalProvider->isConfigured(),
        ];
    }

    public function improveWithExternalAi(array $input, int $userId, array $server): array
    {
        $data = $this->sanitizeInput($input);
        $baseText = trim((string)($input['current_text'] ?? $data['input_text']));
        $baseText = $this->limitText($baseText, 8000);
        if ($baseText === '') {
            throw new RuntimeException('Aucun texte a ameliorer.');
        }

        if (!$this->externalProvider->isConfigured()) {
            $this->logUsage($userId, 'improve_external_unconfigured', 'external', $data, $server);
            return [
                'success' => false,
                'message' => 'API IA externe non configuree. Le texte interne est conserve.',
                'generated_text' => $baseText,
                'external_ai_configured' => false,
            ];
        }

        try {
            $improved = $this->externalProvider->improve($baseText, $data);
            $requestId = $this->requestRepository->create([
                'user_id' => $userId > 0 ? $userId : null,
                'module' => $data['module'],
                'task_type' => $data['task_type'],
                'request_type' => $data['request_type'],
                'tone' => $data['tone'],
                'input_text' => $data['input_text'],
                'generated_text' => $improved['text'],
                'provider' => $improved['provider'],
                'used_external_ai' => true,
                'status' => 'improved',
            ]);
            $this->replacementRepository->createMany($requestId, $improved['replacements'] ?? []);
            $this->logUsage($userId, 'improve_external', $improved['provider'], $data, $server);

            return [
                'success' => true,
                'request_id' => $requestId,
                'generated_text' => $improved['text'],
                'provider' => $improved['provider'],
                'external_ai_configured' => true,
            ];
        } catch (Throwable $e) {
            $this->logUsage($userId, 'improve_external_error', $this->externalProvider->providerName(), $data, $server);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'generated_text' => $baseText,
                'external_ai_configured' => true,
            ];
        }
    }

    public function saveFeedback(array $input, int $userId, array $server): array
    {
        $requestId = (int)($input['request_id'] ?? 0);
        if ($requestId <= 0) {
            throw new RuntimeException('Generation introuvable pour le feedback.');
        }

        $rating = (int)($input['rating'] ?? 0);
        $rating = $rating >= 1 && $rating <= 5 ? $rating : null;
        $accepted = !empty($input['accepted']);
        $correctedText = $this->limitText((string)($input['corrected_text'] ?? ''), 12000);
        $comment = $this->limitText((string)($input['comment'] ?? ''), 1000);

        $request = $this->requestRepository->findById($requestId);
        if (!$request) {
            throw new RuntimeException('Generation introuvable.');
        }

        $this->feedbackRepository->create([
            'ai_request_id' => $requestId,
            'user_id' => $userId > 0 ? $userId : null,
            'rating' => $rating,
            'accepted' => $accepted,
            'corrected_text' => $correctedText,
            'comment' => $comment,
        ]);
        if ($correctedText !== '') {
            $this->requestRepository->updateFinalText($requestId, $correctedText, $accepted ? 'accepted' : 'corrected');
        }

        $this->logUsage($userId, 'feedback', (string)($request['provider'] ?? 'internal'), [
            'module' => (string)$request['module'],
            'task_type' => (string)$request['task_type'],
        ], $server);

        return ['success' => true, 'message' => 'Feedback enregistre.'];
    }

    public function saveValidatedTemplate(array $input, int $userId, array $server): array
    {
        $data = $this->sanitizeInput($input);
        $templateText = $this->limitText((string)($input['template_text'] ?? $input['current_text'] ?? ''), 12000);
        if ($templateText === '') {
            throw new RuntimeException('Le modele a enregistrer est vide.');
        }

        $id = $this->templateService->createValidatedTemplate([
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'request_type' => $data['request_type'],
            'tone' => $data['tone'],
            'title' => trim((string)($input['title'] ?? 'Modele valide')),
            'template_text' => $templateText,
        ], $userId);

        $this->logUsage($userId, 'save_template', 'internal', $data, $server);

        return ['success' => true, 'message' => 'Modele valide enregistre.', 'template_id' => $id];
    }

    public function generateFieldDraft(array $input, int $userId, array $server): array
    {
        $field = $this->normalizeChoice((string)($input['field'] ?? ''), ['feb_description', 'feb_impact'], 'feb_description');
        $context = $this->decodeContext((string)($input['context_json'] ?? ''));
        $targetLabel = $field === 'feb_impact'
            ? 'Impact de la non-satisfaction du besoin'
            : 'Details de la fiche d expression de besoin';
        $contextText = "Champ cible : {$field}\n" . $this->buildFebContextText($context);
        $examples = $this->requestRepository->recentAcceptedFieldExamples($field, 3);
        if ($examples) {
            $contextText .= "\n\nExemples internes valides a imiter sur le ton, sans recopier mot a mot :";
            foreach ($examples as $example) {
                $exampleText = trim((string)($example['final_text'] ?? ''));
                if ($exampleText === '') {
                    $exampleText = trim((string)($example['generated_text'] ?? ''));
                }
                if ($exampleText !== '') {
                    $contextText .= "\n- " . $this->limitText(preg_replace('/\s+/', ' ', $exampleText) ?: $exampleText, 700);
                }
            }
            $contextText = $this->limitText($contextText, 8000);
        }

        $provider = 'internal';
        $usedExternal = false;
        $message = '';
        $text = $this->buildInternalFebFieldDraft($field, $context);

        if ($this->externalProvider->isConfigured()) {
            try {
                $external = $this->externalProvider->generateAdministrativeSection($targetLabel, $contextText, [
                    'tone' => 'professionnel',
                    'module' => 'FEB',
                    'request_type' => 'feb',
                ]);
                $externalText = trim((string)($external['text'] ?? ''));
                if ($externalText !== '') {
                    $text = $externalText;
                    $provider = (string)($external['provider'] ?? $this->externalProvider->providerName());
                    $usedExternal = true;
                }
            } catch (Throwable $e) {
                $message = 'IA externe indisponible, proposition interne utilisee.';
            }
        }

        $requestId = $this->requestRepository->create([
            'user_id' => $userId > 0 ? $userId : null,
            'module' => 'FEB',
            'task_type' => 'generer_note',
            'request_type' => 'feb',
            'tone' => 'professionnel',
            'input_text' => $contextText,
            'generated_text' => $text,
            'provider' => $provider,
            'used_external_ai' => $usedExternal,
            'status' => 'generated',
        ]);

        $this->logUsage($userId, $field === 'feb_impact' ? 'generate_feb_impact' : 'generate_feb_details', $provider, [
            'module' => 'FEB',
            'task_type' => 'generer_note',
        ], $server);

        return [
            'success' => true,
            'request_id' => $requestId,
            'generated_text' => $text,
            'provider' => $provider,
            'used_external_ai' => $usedExternal,
            'message' => $message,
        ];
    }

    public function recentHistory(int $userId): array
    {
        return $userId > 0 ? $this->requestRepository->recentByUser($userId, 10) : [];
    }

    private function decodeContext(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildFebContextText(array $context): string
    {
        $lines = [];
        $aiOptions = is_array($context['ai_options'] ?? null) ? $context['ai_options'] : [];
        $labels = [
            'departement_label' => 'Service beneficiaire',
            'description' => 'Description deja saisie',
            'montant' => 'Montant estime',
            'date_livraison' => 'Date de livraison souhaitee',
            'impact' => 'Impact deja saisi',
            'frequence' => 'Frequence',
        ];

        if (!empty($aiOptions['identity'])) {
            $labels = array_merge([
                'demandeur' => 'Demandeur',
                'matricule' => 'Matricule',
            ], $labels);
        }

        if (!empty($aiOptions['job'])) {
            $labels = array_merge([
                'service_employe' => 'Service du demandeur',
            ], $labels);
        }

        foreach ($labels as $key => $label) {
            $value = trim((string)($context[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $label . ' : ' . $value;
            }
        }

        $besoins = is_array($context['besoins'] ?? null) ? $context['besoins'] : [];
        if ($besoins) {
            $lines[] = 'Besoins :';
            foreach ($besoins as $index => $besoin) {
                if (!is_array($besoin)) {
                    continue;
                }
                $bits = [];
                $needKeys = ['type', 'libelle', 'quantite'];
                if (!empty($aiOptions['supplier'])) {
                    $needKeys[] = 'fournisseur_prix';
                }
                foreach ($needKeys as $key) {
                    $value = trim((string)($besoin[$key] ?? ''));
                    if ($value !== '') {
                        $bits[] = str_replace('_', ' ', $key) . ' = ' . $value;
                    }
                }
                if ($bits) {
                    $lines[] = '- Besoin ' . ($index + 1) . ' : ' . implode('; ', $bits);
                }
            }
        }

        $availableSuppliers = is_array($context['available_suppliers'] ?? null) ? $context['available_suppliers'] : [];
        if (!empty($aiOptions['supplier']) && $availableSuppliers) {
            $names = [];
            foreach (array_slice($availableSuppliers, 0, 12) as $supplier) {
                if (!is_array($supplier)) {
                    continue;
                }
                $categories = trim((string)($supplier['categories'] ?? ''));
                if ($categories !== '') {
                    $names[] = $categories;
                }
            }
            if ($names) {
                $lines[] = 'Categories fournisseurs disponibles : ' . implode(', ', array_unique($names));
            }
        }

        return $this->limitText(implode("\n", $lines), 8000);
    }

    private function buildInternalFebFieldDraft(string $field, array $context): string
    {
        $department = trim((string)($context['departement_label'] ?? 'le service beneficiaire'));
        if ($department === '' || str_contains($department, 'Selectionner')) {
            $department = 'le service beneficiaire';
        }
        $amount = trim((string)($context['montant'] ?? ''));
        $date = trim((string)($context['date_livraison'] ?? ''));
        $frequency = trim((string)($context['frequence'] ?? ''));
        if ($frequency === '' || str_contains(mb_strtolower($frequency), 'selectionner')) {
            $frequency = '';
        }
        $description = trim((string)($context['description'] ?? ''));
        $besoins = is_array($context['besoins'] ?? null) ? $context['besoins'] : [];
        $needsText = $this->summarizeFebNeeds($besoins);

        if ($field === 'feb_impact') {
            $parts = [];
            $parts[] = "Sans ce besoin, {$department} risque de rencontrer des lenteurs dans son travail quotidien.";
            if ($needsText !== '') {
                $parts[] = "L'element demande ({$needsText}) aide a mieux organiser les activites et a eviter les interruptions inutiles.";
            }
            if ($date !== '') {
                $parts[] = "Si la mise a disposition intervient apres la date souhaitee ({$date}), certaines taches pourraient etre reportees ou traitees dans de moins bonnes conditions.";
            }
            $parts[] = "Valider cette demande permettrait donc de travailler plus proprement, avec moins de risques de blocage et une meilleure continuite du service.";
            return implode(' ', $parts);
        }

        $parts = [];
        $parts[] = "Le besoin concerne " . ($needsText !== '' ? $needsText : "des ressources utiles au bon fonctionnement de {$department}") . ".";
        if ($description !== '') {
            $parts[] = "Il repond au contexte suivant : {$description}";
        }
        $parts[] = "L'objectif est de disposer du materiel ou du service necessaire pour mieux organiser le travail et eviter les retards.";
        if ($amount !== '') {
            $parts[] = "Le montant estime est de {$amount} FCFA, sous reserve de verification des prix et des justificatifs.";
        }
        if ($frequency !== '') {
            $parts[] = "La frequence indiquee est {$frequency}.";
        }
        return implode(' ', $parts);
    }

    private function summarizeFebNeeds(array $besoins): string
    {
        $labels = [];
        foreach ($besoins as $besoin) {
            if (!is_array($besoin)) {
                continue;
            }
            $label = trim((string)($besoin['libelle'] ?? ''));
            $qty = trim((string)($besoin['quantite'] ?? ''));
            if ($label !== '') {
                $labels[] = $label . ($qty !== '' ? ' x' . $qty : '');
            }
        }

        return implode(', ', array_slice($labels, 0, 8));
    }

    private function sanitizeInput(array $input): array
    {
        $module = $this->normalizeChoice((string)($input['module'] ?? 'General'), $this->modules, 'General');
        $taskType = $this->normalizeChoice((string)($input['task_type'] ?? 'rediger_demande'), $this->taskTypes, 'rediger_demande');
        $requestType = $this->normalizeRequestType((string)($input['request_type'] ?? ''));
        $tone = $this->normalizeChoice((string)($input['tone'] ?? 'professionnel'), $this->tones, 'professionnel');
        $roleDemandeur = $this->normalizeChoice((string)($input['role_demandeur'] ?? 'self'), ['self', 'tiers'], 'self');
        $inputText = $this->limitText((string)($input['input_text'] ?? ''), 8000);

        if ($inputText === '') {
            throw new RuntimeException('Le texte ou contexte est obligatoire.');
        }

        $variables = [
            'nom_employe' => $this->limitText((string)($input['nom_employe'] ?? ''), 120),
            'poste' => $this->limitText((string)($input['poste'] ?? ''), 120),
            'service' => $this->limitText((string)($input['service'] ?? ''), 120),
            'date' => $this->limitText((string)($input['date'] ?? date('d/m/Y')), 40),
            'motif' => $this->limitText((string)($input['motif'] ?? $inputText), 2000),
            'periode' => $this->limitText((string)($input['periode'] ?? ''), 120),
            'responsable' => $this->limitText((string)($input['responsable'] ?? ''), 120),
            'entreprise' => $this->limitText((string)($input['entreprise'] ?? 'FINEA'), 120),
            'montant' => $this->formatAmount($this->limitText((string)($input['montant'] ?? ''), 80)),
            'matricule' => $this->limitText((string)($input['matricule'] ?? ''), 80),
            'request_type' => $requestType,
            'request_title' => $this->limitText((string)($input['request_title'] ?? ''), 180),
            'heures' => $this->limitText((string)($input['heures'] ?? ''), 40),
            'date_effective' => $this->limitText((string)($input['date_effective'] ?? ''), 40),
        ];

        return [
            'module' => $module,
            'task_type' => $taskType,
            'request_type' => $requestType,
            'tone' => $tone,
            'role_demandeur' => $roleDemandeur,
            'input_text' => $inputText,
            'variables' => $variables,
            'nom_employe' => $variables['nom_employe'],
            'poste' => $variables['poste'],
            'service' => $variables['service'],
            'date' => $variables['date'],
            'motif' => $variables['motif'],
            'periode' => $variables['periode'],
            'responsable' => $variables['responsable'],
            'entreprise' => $variables['entreprise'],
            'montant' => $variables['montant'],
            'matricule' => $variables['matricule'],
            'request_title' => $variables['request_title'],
            'heures' => $variables['heures'],
            'date_effective' => $variables['date_effective'],
        ];
    }

    private function applyTaskRefinement(string $text, array $data): string
    {
        if ($data['task_type'] === 'reformuler') {
            return "Version reformulee (" . $data['tone'] . ") :\n\n" . $data['input_text'];
        }
        if ($data['task_type'] === 'resumer' && mb_strlen($data['input_text']) > 400) {
            return "Resume propose :\n\n" . mb_substr($data['input_text'], 0, 650) . (mb_strlen($data['input_text']) > 650 ? '...' : '');
        }
        return $text;
    }

    private function appendUsefulExamples(string $text, string $module, string $taskType): string
    {
        $examples = $this->templateService->correctionExamples($module, $taskType);
        if (!$examples) {
            return $text;
        }

        $best = trim((string)($examples[0]['corrected_text'] ?? ''));
        if ($best === '') {
            return $text;
        }

        return $text . "\n\n---\nSuggestion inspiree d'une correction validee :\n" . mb_substr($best, 0, 1200);
    }

    private function normalizeChoice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function normalizeRequestType(string $requestType): string
    {
        $requestType = mb_strtolower(trim($requestType));
        $requestType = str_replace([' ', '-', '/', '\\'], '_', $requestType);
        $requestType = preg_replace('/[^a-z0-9_]/', '', $requestType) ?: '';

        $aliases = [
            'avance' => 'avance_salaire',
            'avance_sur_salaire' => 'avance_salaire',
            'salary_advance' => 'avance_salaire',
            'permission_absence' => 'absence',
            'conge' => 'absence',
        ];

        return $aliases[$requestType] ?? $requestType;
    }

    private function formatAmount(string $amount): string
    {
        $amount = trim($amount);
        if ($amount === '') {
            return '';
        }

        $digits = preg_replace('/[^\d]/', '', $amount);
        if ($digits !== '' && strlen($digits) >= 4) {
            return number_format((int)$digits, 0, ',', ' ');
        }

        return $amount;
    }

    private function limitText(string $text, int $max): string
    {
        $text = trim(str_replace("\0", '', $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private function logUsage(int $userId, string $action, string $provider, array $data, array $server): void
    {
        $this->usageLogRepository->log([
            'user_id' => $userId > 0 ? $userId : null,
            'action' => $action,
            'provider' => $provider,
            'module' => $data['module'] ?? null,
            'task_type' => $data['task_type'] ?? null,
            'ip_address' => $server['REMOTE_ADDR'] ?? '',
            'user_agent' => $server['HTTP_USER_AGENT'] ?? '',
        ]);
    }
}
