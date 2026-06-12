<?php

namespace App\Enums;

enum PrintOrderStatus: string
{
    /** Created locally, waiting for the Mollie payment to complete. */
    case PendingPayment = 'pending_payment';

    /** Paid; the SubmitPrintOrder job is queued or running. */
    case Paid = 'paid';

    /** Accepted by Printdeal; production status updates arrive via webhook. */
    case Submitted = 'submitted';

    /** Submission to Printdeal failed after retries; needs manual attention. */
    case Failed = 'failed';

    /** Payment failed, expired, or was canceled before submission. */
    case Canceled = 'canceled';
}
