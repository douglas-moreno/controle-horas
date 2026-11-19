<?php

namespace App\Enums;

enum JustificationHistoryAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Canceled = 'canceled';
    case ManualPointCreated = 'manual_point_created';
    case ManualPointUpdated = 'manual_point_updated';
    case ManualPointRemoved = 'manual_point_removed';
    case DocumentAttached = 'document_attached';
    case DocumentReplaced = 'document_replaced';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Justificativa registrada',
            self::Updated => 'Justificativa alterada',
            self::Canceled => 'Justificativa cancelada',
            self::ManualPointCreated => 'Marcação manual criada',
            self::ManualPointUpdated => 'Marcação manual alterada',
            self::ManualPointRemoved => 'Marcação manual removida',
            self::DocumentAttached => 'Documento anexado',
            self::DocumentReplaced => 'Documento substituído',
        };
    }
}
