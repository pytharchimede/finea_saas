<?php

class AiTemplateService
{
    private AiTemplateRepository $templateRepository;

    private array $fallbackTemplates = [
        [
            'module' => 'RH',
            'task_type' => 'rediger_demande',
            'request_type' => 'absence',
            'tone' => 'administratif',
            'title' => 'Demande d\'absence',
            'template_text' => "Objet : Demande d'absence\n\nBonjour {{responsable}},\n\nJe sollicite une autorisation d'absence pour {{nom_employe}}, occupant le poste de {{poste}} au sein du service {{service}}, sur la periode suivante : {{periode}}.\n\nMotif : {{motif}}.\n\nCordialement,\n{{entreprise}}",
            'keywords' => ['absence', 'conge', 'permission'],
        ],
        [
            'module' => 'RH',
            'task_type' => 'rediger_demande',
            'request_type' => 'avance_salaire',
            'tone' => 'professionnel',
            'title' => 'Demande d\'avance sur salaire',
            'template_text' => "Objet : Demande d'avance sur salaire\n\nBonjour,\n\nJe sollicite par la présente une avance sur salaire d'un montant de {{montant}} FCFA.\n\nCette demande est motivée par : {{motif}}.\n\nJe reste disponible pour tout complément d'information.\n\nCordialement,\n{{nom_employe}}\n{{poste}}\n{{service}}\n{{entreprise}}",
            'keywords' => ['avance', 'salaire', 'avance salaire', 'avance_salaire', 'montant'],
        ],
        [
            'module' => 'RH',
            'task_type' => 'rediger_demande',
            'request_type' => 'heures_supp',
            'tone' => 'professionnel',
            'title' => 'Demande d\'heures supplementaires',
            'template_text' => "Objet : Demande d'heures supplémentaires\n\nBonjour,\n\nJe sollicite par la présente l'autorisation d'effectuer {{heures}} heure(s) supplémentaire(s) le {{date_effective}}.\n\nCette demande est motivée par : {{motif}}.\n\nJe reste disponible pour tout complément d'information.\n\nCordialement,\n{{nom_employe}}\n{{poste}}\n{{service}}\n{{entreprise}}",
            'keywords' => ['heures', 'supplementaires', 'heures_supp', 'date effective'],
        ],
        [
            'module' => 'RH',
            'task_type' => 'repondre_demande_explication',
            'request_type' => 'explication',
            'tone' => 'professionnel',
            'title' => 'Reponse a demande d\'explication',
            'template_text' => "Objet : Reponse a votre demande d'explication\n\nBonjour {{responsable}},\n\nSuite a votre demande du {{date}}, je vous transmets les elements concernant : {{motif}}.\n\nJe reste disponible pour tout complement.\n\nCordialement,\n{{nom_employe}}",
            'keywords' => ['explication', 'justification', 'retard'],
        ],
        [
            'module' => 'General',
            'task_type' => 'generer_note',
            'request_type' => 'note',
            'tone' => 'professionnel',
            'title' => 'Note interne',
            'template_text' => "Objet : Note interne - {{motif}}\n\nBonjour,\n\nNous portons a votre attention les elements suivants :\n\n{{motif}}\n\nService concerne : {{service}}.\nDate : {{date}}.\n\nCordialement,\n{{entreprise}}",
            'keywords' => ['note', 'information', 'interne'],
        ],
        [
            'module' => 'FEB',
            'task_type' => 'rediger_demande',
            'request_type' => 'feb',
            'tone' => 'administratif',
            'title' => 'Demande d\'achat / FEB',
            'template_text' => "Objet : Demande d'achat FEB\n\nBonjour,\n\nNous sollicitons une demande d'achat pour le besoin suivant : {{motif}}.\n\nService demandeur : {{service}}.\nPeriode ou urgence : {{periode}}.\nResponsable : {{responsable}}.\n\nCordialement,\n{{entreprise}}",
            'keywords' => ['achat', 'feb', 'fournisseur', 'commande'],
        ],
        [
            'module' => 'General',
            'task_type' => 'repondre_demande_explication',
            'request_type' => 'clarification',
            'tone' => 'direct',
            'title' => 'Demande de clarification',
            'template_text' => "Bonjour,\n\nMerci de clarifier le point suivant : {{motif}}.\n\nElements attendus : contexte, periode concernee, personnes impliquees et documents disponibles.\n\nCordialement,",
            'keywords' => ['clarification', 'precision', 'complement'],
        ],
        [
            'module' => 'General',
            'task_type' => 'resumer',
            'request_type' => 'compte_rendu',
            'tone' => 'professionnel',
            'title' => 'Compte rendu simple',
            'template_text' => "Compte rendu\n\nDate : {{date}}\nSujet : {{motif}}\nService concerne : {{service}}\n\nPoints principaux :\n- {{motif}}\n\nActions a suivre :\n- Confirmer les responsabilites.\n- Preciser les delais.\n\nResponsable : {{responsable}}",
            'keywords' => ['compte rendu', 'reunion', 'resume'],
        ],
        [
            'module' => 'RH',
            'task_type' => 'generer_formulaire',
            'request_type' => 'formulaire_rh',
            'tone' => 'administratif',
            'title' => 'Formulaire RH simple',
            'template_text' => "Formulaire RH\n\nNom : {{nom_employe}}\nPoste : {{poste}}\nService : {{service}}\nDate : {{date}}\nPeriode : {{periode}}\nMotif : {{motif}}\nResponsable : {{responsable}}\n\nSignature employe : ____________________\nSignature responsable : ____________________",
            'keywords' => ['formulaire', 'rh', 'fiche'],
        ],
    ];

    public function __construct(AiTemplateRepository $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }

    public function selectBestTemplate(string $module, string $taskType, string $tone, string $inputText, string $requestType = ''): array
    {
        $templates = array_merge($this->fallbackTemplates, $this->templateRepository->findActiveTemplates());

        $input = mb_strtolower($inputText);
        $requestType = $this->normalizeRequestType($requestType);
        if ($requestType !== '') {
            foreach ([
                ['module' => $module, 'request_type' => $requestType, 'tone' => $tone],
                ['module' => $module, 'request_type' => $requestType],
            ] as $criteria) {
                $matched = $this->bestTemplateByCriteria($templates, $criteria, $input);
                if ($matched) {
                    return $matched;
                }
            }
        }

        foreach ([
            ['module' => $module, 'task_type' => $taskType, 'tone' => $tone],
            ['module' => $module, 'task_type' => $taskType],
        ] as $criteria) {
            $matched = $this->bestTemplateByCriteria($templates, $criteria, $input);
            if ($matched) {
                return $matched;
            }
        }

        $general = $this->bestTemplateByCriteria($templates, ['module' => 'General'], $input);
        if ($general) {
            return $general;
        }

        return $this->fallbackTemplates[0];
    }

    private function bestTemplateByCriteria(array $templates, array $criteria, string $input): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($templates as $template) {
            foreach ($criteria as $key => $expected) {
                $actual = (string)($template[$key] ?? '');
                if ($key === 'request_type') {
                    $actual = $this->normalizeRequestType($actual);
                }
                if ($actual !== (string)$expected) {
                    continue 2;
                }
            }

            $score = 0;
            if (!empty($template['tone']) && isset($criteria['tone']) && (string)$template['tone'] === (string)$criteria['tone']) {
                $score += 20;
            }

            $keywords = $this->decodeJsonList($template['keywords_json'] ?? ($template['keywords'] ?? []));
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && mb_strpos($input, mb_strtolower($keyword)) !== false) {
                    $score += 8;
                }
            }

            if ($score > $bestScore) {
                $best = $template;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function renderTemplate(array $template, array $variables, string $inputText): string
    {
        $text = (string)($template['template_text'] ?? '');
        $variables = $this->withDefaultVariables($variables, $inputText);
        $roleDemandeur = (string)($variables['role_demandeur'] ?? 'self');
        if ($roleDemandeur === 'self') {
            $text = $this->adaptTemplateForSelfRequester($text);
        }

        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', (string)$value, $text);
        }

        return preg_replace('/{{[a-zA-Z0-9_]+}}/', '', $text) ?: $text;
    }

    public function createValidatedTemplate(array $data, int $userId): int
    {
        return $this->templateRepository->create([
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'request_type' => $this->normalizeRequestType((string)($data['request_type'] ?? '')),
            'tone' => $data['tone'],
            'title' => $data['title'] ?: 'Modele valide',
            'template_text' => $data['template_text'],
            'variables' => ['nom_employe', 'poste', 'service', 'date', 'motif', 'periode', 'responsable', 'entreprise', 'montant', 'matricule', 'request_type', 'request_title', 'heures', 'date_effective'],
            'keywords' => $this->extractKeywords((string)($data['template_text'] ?? '')),
            'is_validated' => true,
            'is_active' => true,
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    }

    public function correctionExamples(string $module, string $taskType): array
    {
        return $this->templateRepository->findValidatedCorrections($module, $taskType, 3);
    }

    private function withDefaultVariables(array $variables, string $inputText): array
    {
        $today = date('d/m/Y');
        $defaults = [
            'nom_employe' => '',
            'poste' => '',
            'service' => '',
            'date' => $today,
            'motif' => trim($inputText),
            'periode' => '',
            'responsable' => '',
            'entreprise' => 'FINEA',
            'role_demandeur' => 'self',
            'montant' => '',
            'matricule' => '',
            'request_type' => '',
            'request_title' => '',
            'heures' => '',
            'date_effective' => '',
        ];

        return array_merge($defaults, array_map(static fn($value): string => trim((string)$value), $variables));
    }

    private function decodeJsonList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function extractKeywords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{5,}/u', mb_strtolower($text), $matches);
        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 12);
    }

    private function adaptTemplateForSelfRequester(string $text): string
    {
        $replacements = [
            'Je sollicite une autorisation d\'absence pour {{nom_employe}}, occupant le poste de {{poste}} au sein du service {{service}}, sur la periode suivante : {{periode}}.' =>
                'Je sollicite une autorisation d\'absence sur la periode suivante : {{periode}}.',
            'Nom de l\'employe : {{nom_employe}}' => 'Nom : {{nom_employe}}',
            'Signature employe :' => 'Signature :',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
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
            'absence_permission' => 'absence',
            'permission_absence' => 'absence',
            'conge' => 'absence',
        ];

        return $aliases[$requestType] ?? $requestType;
    }
}
