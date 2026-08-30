<?php

namespace App\Services\Billing;

use App\Models\PaymentTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Generate an invoice PDF for a payment transaction.
     * Saves to storage and returns the PDF content string, or null on failure.
     */
    public function generate(PaymentTransaction $transaction): ?string
    {
        try {
            $transaction->loadMissing(['user', 'subscription.plan', 'coupon']);

            $data = [
                'transaction' => $transaction,
                'user' => $transaction->user,
                'plan' => $transaction->subscription?->plan,
                'coupon' => $transaction->coupon,
                'app_name' => config('app.name'),
                'support_email' => config('saas.support_email'),
                'issued_at' => $transaction->created_at->format('F j, Y'),
                'invoice_number' => 'INV-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
            ];

            $pdf = Pdf::loadView('pdf.invoice', $data);
            $content = $pdf->output();

            $path = 'invoices/' . $transaction->id . '.pdf';
            Storage::put($path, $content);

            $transaction->update(['invoice_path' => $path]);

            return $content;
        } catch (\Throwable $e) {
            Log::error('InvoiceService::generate failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
