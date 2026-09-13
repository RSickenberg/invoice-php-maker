<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\QrBill;

use RSickenberg\InvoicePhpMaker\Config\AppConfig;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Pdf\Translations;
use Sprain\SwissQrBill as QrBillLib;

/**
 * Builds the sprain/swiss-qr-bill QrBill from our config and our invoice.
 * Standard IBAN decided (no QR-IBAN): the reference is therefore TYPE_NON,
 * as in the library's "minimal" use case.
 */
final class QrBillFactory
{
    public function build(AppConfig $config, Invoice $invoice): QrBillLib\QrBill
    {
        $qrBill = QrBillLib\QrBill::create();

        $qrBill->setCreditor(
            QrBillLib\DataGroup\Element\StructuredAddress::createWithStreet(
                $config->creditor->name,
                $config->creditor->street,
                $config->creditor->houseNumber,
                $config->creditor->postalCode,
                $config->creditor->city,
                $config->creditor->country,
            )
        );

        $qrBill->setCreditorInformation(
            QrBillLib\DataGroup\Element\CreditorInformation::create($config->iban)
        );

        $qrBill->setUltimateDebtor(
            QrBillLib\DataGroup\Element\StructuredAddress::createWithStreet(
                $invoice->client->name,
                $invoice->client->street,
                $invoice->client->houseNumber,
                $invoice->client->postalCode,
                $invoice->client->city,
                $invoice->client->country,
            )
        );

        $qrBill->setPaymentAmountInformation(
            QrBillLib\DataGroup\Element\PaymentAmountInformation::create(
                $invoice->currency,
                $invoice->totalAmount(),
            )
        );

        $qrBill->setPaymentReference(
            QrBillLib\DataGroup\Element\PaymentReference::create(
                QrBillLib\DataGroup\Element\PaymentReference::TYPE_NON
            )
        );

        $qrBill->setAdditionalInformation(
            QrBillLib\DataGroup\Element\AdditionalInformation::create(
                \sprintf('%s %s', Translations::get('invoice', $invoice->language), $invoice->number)
            )
        );

        return $qrBill;
    }
}
