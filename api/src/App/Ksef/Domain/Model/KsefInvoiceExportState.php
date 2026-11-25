<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/** Where a package export stands (`GET /invoices/exports/{referenceNumber}`, ADR 0039 §4, §6). */
enum KsefInvoiceExportState
{
    /** 100: KSeF is still building the package; a later run asks again. */
    case InProgress;
    /** 200: the package can be downloaded. */
    case Ready;
    /** 210 or 410 Gone: the package or its status is no longer kept; a new export is needed. */
    case Expired;
    /** 420: the range starts after the high-water mark, so there is nothing to export yet. */
    case NothingToExport;
    /** 415, 500, 550 or another code: the export failed; it is dropped and the run records why. */
    case Failed;
}
