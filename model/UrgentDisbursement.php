<?php

class UrgentDisbursement
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_DECAISSED = 'decaissed';
    public const STATUS_REGULARIZATION_PENDING = 'regularization_pending';
    public const STATUS_REGULARIZED = 'regularized';
    public const STATUS_REJECTED = 'rejected';

    public static function statusLabel(string $status): string
    {
        return [
            self::STATUS_SUBMITTED => 'En attente comptabilite',
            self::STATUS_DECAISSED => 'Decaissee - a regulariser',
            self::STATUS_REGULARIZATION_PENDING => 'Regularisation en cours',
            self::STATUS_REGULARIZED => 'Regularisee',
            self::STATUS_REJECTED => 'Refusee',
        ][$status] ?? ($status !== '' ? $status : 'Inconnu');
    }

    public static function statusClass(string $status): string
    {
        return [
            self::STATUS_SUBMITTED => 'bg-amber-100 text-amber-800 border-amber-200',
            self::STATUS_DECAISSED => 'bg-rose-100 text-rose-800 border-rose-200',
            self::STATUS_REGULARIZATION_PENDING => 'bg-sky-100 text-sky-800 border-sky-200',
            self::STATUS_REGULARIZED => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            self::STATUS_REJECTED => 'bg-slate-100 text-slate-700 border-slate-200',
        ][$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
    }
}
