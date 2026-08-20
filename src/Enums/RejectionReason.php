<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Why a buyer refuses an invoice.
 *
 * A normative list, not a vendor one: the French reform fixes these so that a
 * refusal can be acted on rather than merely read. `Other` carries a free-text
 * message and should stay the last resort — "OTHER" tells a supplier nothing.
 */
enum RejectionReason: string
{
    case LegalInformationMissing = 'LEGAL_INFORMATION_MISSING';
    case IncorrectRecipient = 'INCORRECT_RECIPIENT';
    case EinvoicingAddressIncorrect = 'EINVOICING_ADDRESS_INCORRECT';
    case UnknownTransaction = 'UNKNOWN_TRANSACTION';
    case PoReferenceIncorrectOrMissing = 'PO_REFERENCE_INCORRECT_OR_MISSING';
    case ContractReferenceMissing = 'CONTRACT_REFERENCE_MISSING';
    case VatRateIncorrect = 'VAT_RATE_INCORRECT';
    case TotalAmountIncorrect = 'TOTAL_AMOUNT_INCORRECT';
    case CalculationError = 'CALCULATION_ERROR';
    case DuplicateInvoice = 'DUPLICATE_INVOICE';
    case DoubleInvoicing = 'DOUBLE_INVOICING';
    case ContractTerminated = 'CONTRACT_TERMINATED';
    case UnknownIssuer = 'UNKNOWN_ISSUER';
    case FinanceTermsIncorrect = 'FINANCE_TERMS_INCORRECT';
    case PricesIncorrect = 'PRICES_INCORRECT';
    case SiretIncorrectOrMissing = 'SIRET_INCORRECT_OR_MISSING';
    case RoutingCodeIncorrect = 'ROUTING_CODE_INCORRECT';
    case ReferenceIncorrect = 'REFERENCE_INCORRECT';
    case QuantityIncorrect = 'QUANTITY_INCORRECT';
    case ItemIncorrect = 'ITEM_INCORRECT';
    case ItemQualityInsufficient = 'ITEM_QUALITY_INSUFFICIENT';
    case DeliveryIssue = 'DELIVERY_ISSUE';
    case PaymentTermsIncorrect = 'PAYMENT_TERMS_INCORRECT';
    case UnitPriceIncorrect = 'UNIT_PRICE_INCORRECT';
    case DiscountIncorrect = 'DISCOUNT_INCORRECT';
    case BankDetailsIncorrect = 'BANK_DETAILS_INCORRECT';
    case MissingDocuments = 'MISSING_DOCUMENTS';

    /** Last resort: say what is wrong in the accompanying message. */
    case Other = 'OTHER';
}
