<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/CommercialProjectFinanceRepository.php';

final class CommercialProjectFinanceService
{
    public function __construct(private CommercialProjectFinanceRepository $repository) {}

    public function registerPurchaseOrder(array $input, int $userId): array
    {
        $devisId = (int) ($input['devis_id'] ?? 0);
        $quote = $this->repository->quote($devisId);
        if (!$quote) {
            throw new DomainException('Devis introuvable.');
        }
        $number = trim((string) ($input['order_number'] ?? ''));
        $amount = round((float) ($input['amount'] ?? 0), 2);
        if ($number === '' || $amount <= 0) {
            throw new DomainException('Numéro et montant du bon de commande client obligatoires.');
        }
        $quoteAmount = round((float) $quote['total_ttc'], 2);
        $difference = round($amount - $quoteAmount, 2);
        $id = $this->repository->createPurchaseOrder([
            'order_number' => $number,
            'offre_id' => (int) $quote['offre_id'],
            'devis_id' => $devisId,
            'client_id' => (int) $quote['client_id'],
            'amount' => $amount,
            'quote_amount' => $quoteAmount,
            'difference' => $difference,
            'currency' => strtoupper((string) ($input['currency'] ?? 'XOF')),
            'order_date' => ($input['order_date'] ?? '') ?: null,
            'expected_start_date' => ($input['expected_start_date'] ?? '') ?: null,
            'document_path' => ($input['document_path'] ?? '') ?: null,
            'status' => 'ACTIVE',
            'user_id' => $userId ?: null,
        ]);
        return [
            'id' => $id,
            'amount_difference' => $difference,
            'has_amount_warning' => abs($difference) >= 0.01,
            'warning' => abs($difference) >= 0.01
                ? 'Le montant du BC client diffère du TTC du devis. L’enregistrement reste autorisé.'
                : null,
        ];
    }

    public function linkToChantier(int $chantierId, int $purchaseOrderId, int $userId): void
    {
        $this->repository->linkPurchaseOrderToChantier($chantierId, $purchaseOrderId, $userId);
    }

    public function enrichQuotes(array $quotes): array
    {
        $indicators = $this->repository->indicators(array_column($quotes, 'id'));
        foreach ($quotes as &$quote) {
            $state = $indicators[(int) $quote['id']] ?? [];
            $quote['has_chantier'] = !empty($state['has_chantier']);
            $quote['has_invoice'] = !empty($state['has_invoice']);
            $quote['invoice_count'] = (int) ($state['invoice_count'] ?? 0);
            $quote['cancelable_invoice_count'] = (int) ($state['cancelable_invoice_count'] ?? 0);
            $quote['paid_invoice_count'] = (int) ($state['paid_invoice_count'] ?? 0);
            $quote['has_disbursement_sheet'] = !empty($state['has_disbursement_sheet']);
            $quote['is_archived'] = !empty($state['archived']);
            $quote['is_cleanable_draft'] = !empty($state['is_draft']) && !$quote['has_chantier']
                && !$quote['has_invoice'] && !$quote['has_disbursement_sheet'];
            $quote['is_archivable'] = !$quote['has_chantier'] && !$quote['has_invoice'] && !$quote['has_disbursement_sheet'];
        }
        unset($quote);
        return $quotes;
    }

    public function archiveQuote(int $devisId, int $userId, string $reason): void
    {
        $eligibility = $this->repository->archiveEligibility($devisId);
        if (!$eligibility || !empty($eligibility['has_chantier'])
            || !empty($eligibility['has_invoice']) || !empty($eligibility['has_disbursement_sheet'])) {
            throw new DomainException(!empty($eligibility['has_chantier'])
                ? 'Archivage refusé : ce devis appartient à un chantier actif. Archivez d’abord le chantier.'
                : 'Archivage refusé : ce devis possède déjà une facture ou une fiche de décaissement.');
        }
        $this->repository->archive($devisId, $userId, $reason ?: 'Devis devenu inutile', $eligibility);
    }

    public function restoreQuote(int $devisId, int $userId, string $note): void
    {
        $this->repository->restore($devisId, $userId, $note ?: 'Restauration manuelle');
        $this->repository->unmask($devisId);
    }

    public function treasury(int $forecastDays = 30, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->repository->treasurySummary($forecastDays, $dateFrom, $dateTo);
    }

    public function saveLoan(array $input, int $userId): void
    {
        $amount=round((float)($input['principal_amount']??0),2);$lender=trim((string)($input['lender_name']??''));$received=(string)($input['received_date']??'');
        if($amount<=0||$lender===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$received)) throw new DomainException('Prêteur, montant et date de réception sont obligatoires.');
        $this->repository->saveLoan(['lender'=>$lender,'type'=>'external','reference'=>trim((string)($input['reference']??''))?:null,'amount'=>$amount,'received'=>$received,'due'=>trim((string)($input['due_date']??''))?:null,'rate'=>max(0,(float)($input['interest_rate']??0)),'note'=>trim((string)($input['note']??''))?:null,'user'=>$userId?:null]);
    }

    public function borrowVatCredit(array $input,int $userId): void
    {
        $amount=round((float)($input['principal_amount']??0),2);$received=(string)($input['received_date']??date('Y-m-d'));$due=(string)($input['due_date']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$received)||$received>date('Y-m-d'))throw new DomainException('La date d’utilisation doit être valide et ne peut pas être future.');
        $available=round((float)($this->repository->treasurySummary(30,'2000-01-01',$received)['vat_credit_available']??0),2);
        if($amount<=0)throw new DomainException('Le montant du crédit TVA doit être supérieur à zéro.');
        if($amount>$available)throw new DomainException(sprintf('Montant refusé : %.0f FCFA demandés pour seulement %.0f FCFA de réserve TVA disponible.',$amount,$available));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||$due<$received)throw new DomainException('La date de remboursement est obligatoire et doit être égale ou postérieure à la date d’utilisation.');
        $this->repository->saveLoan(['lender'=>'Réserve TVA','type'=>'vat_credit','reference'=>trim((string)($input['reference']??''))?:null,'amount'=>$amount,'received'=>$received,'due'=>$due,'rate'=>0,'note'=>trim((string)($input['note']??''))?:'Utilisation temporaire du solde TVA','user'=>$userId?:null]);
    }

    public function loans(): array { return $this->repository->listLoans(); }
    public function repayLoan(int $id,float $amount): void { if($id<=0||$amount<=0)throw new DomainException('Prêt et montant de remboursement invalides.');$this->repository->repayLoan($id,round($amount,2)); }

    public function saveOpeningBalance(string $date, float $amount, int $userId, string $note): void
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Date de solde d’ouverture invalide.');
        }
        $this->repository->saveOpeningBalance($date, round($amount, 2), $userId, $note);
    }

    public function saveVatRemittance(array $input,int $userId): void
    {
        $amount=round((float)($input['amount']??0),2);foreach(['period_start','period_end','payment_date'] as $key)if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($input[$key]??'')))throw new DomainException('Les dates du reversement TVA sont obligatoires.');if($amount<=0)throw new DomainException('Le montant reversé doit être positif.');
        $this->repository->saveVatRemittance(['period_start'=>$input['period_start'],'period_end'=>$input['period_end'],'payment_date'=>$input['payment_date'],'amount'=>$amount,'reference'=>trim((string)($input['reference']??''))?:null,'document_path'=>trim((string)($input['document_path']??''))?:null,'note'=>trim((string)($input['note']??''))?:null,'user'=>$userId?:null]);
    }
    public function vatRemittances(string $from,string $to): array { return $this->repository->listVatRemittances($from,$to); }
    public function saveFixedCharge(array $input,int $userId): void
    {
        $amount=round((float)($input['amount']??0),2);$label=trim((string)($input['label']??''));$due=(string)($input['due_date']??'');if($label===''||$amount<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))throw new DomainException('Libellé, montant et échéance de la charge sont obligatoires.');$paid=!empty($input['paid']);$payment=$paid?(string)($input['payment_date']??date('Y-m-d')):null;
        $this->repository->saveFixedCharge(['label'=>$label,'category'=>trim((string)($input['category']??''))?:null,'amount'=>$amount,'due_date'=>$due,'payment_date'=>$payment,'status'=>$paid?'paid':'planned','supplier'=>trim((string)($input['supplier_name']??''))?:null,'reference'=>trim((string)($input['reference']??''))?:null,'document_path'=>trim((string)($input['document_path']??''))?:null,'note'=>trim((string)($input['note']??''))?:null,'user'=>$userId?:null]);
    }
    public function fixedCharges(string $from,string $to): array { return $this->repository->listFixedCharges($from,$to); }
    public function recurringCharges(string $from,string $to): array { return $this->repository->listRecurringCharges($from,$to); }
    public function forecastFebDetails(string $from,string $to): array { return $this->repository->listForecastFebDetails($from,$to); }
    public function payFixedCharge(int $id,string $date): void { if($id<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new DomainException('Charge ou date de paiement invalide.');$this->repository->payFixedCharge($id,$date); }
}
