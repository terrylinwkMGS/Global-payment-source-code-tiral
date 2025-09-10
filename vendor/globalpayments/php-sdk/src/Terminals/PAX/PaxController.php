<?php

namespace GlobalPayments\Api\Terminals\PAX;

use GlobalPayments\Api\Terminals\Abstractions\IDeviceCommInterface;
use GlobalPayments\Api\Terminals\Abstractions\ITerminalReport;
use GlobalPayments\Api\Terminals\Builders\TerminalAuthBuilder;
use GlobalPayments\Api\Terminals\Builders\TerminalManageBuilder;
use GlobalPayments\Api\Terminals\Builders\TerminalReportBuilder;
use GlobalPayments\Api\Terminals\DeviceController;
use GlobalPayments\Api\Terminals\ConnectionConfig;
use GlobalPayments\Api\Terminals\Enums\ConnectionModes;
use GlobalPayments\Api\Terminals\Abstractions\IDeviceInterface;
use GlobalPayments\Api\Terminals\PAX\Interfaces\PaxTcpInterface;
use GlobalPayments\Api\Terminals\PAX\SubGroups\AmountRequest;
use GlobalPayments\Api\Terminals\PAX\SubGroups\AccountRequest;
use GlobalPayments\Api\Terminals\PAX\SubGroups\TraceRequest;
use GlobalPayments\Api\Terminals\PAX\SubGroups\AvsRequest;
use GlobalPayments\Api\Terminals\TerminalResponse;
use GlobalPayments\Api\Terminals\TerminalUtils;
use GlobalPayments\Api\PaymentMethods\CreditCardData;
use GlobalPayments\Api\PaymentMethods\TransactionReference;
use GlobalPayments\Api\Entities\Enums\TransactionType;
use GlobalPayments\Api\Terminals\PAX\SubGroups\ExtDataSubGroup;
use GlobalPayments\Api\Terminals\PAX\Entities\Enums\PaxExtData;
use GlobalPayments\Api\Terminals\PAX\SubGroups\CommercialRequest;
use GlobalPayments\Api\Terminals\PAX\Entities\Enums\PaxTxnType;
use GlobalPayments\Api\Entities\Exceptions\UnsupportedTransactionException;
use GlobalPayments\Api\Entities\Enums\PaymentMethodType;
use GlobalPayments\Api\Terminals\PAX\Entities\Enums\PaxMessageId;
use GlobalPayments\Api\Terminals\PAX\Responses\CreditResponse;
use GlobalPayments\Api\Terminals\PAX\Responses\DebitResponse;
use GlobalPayments\Api\Terminals\PAX\SubGroups\EcomSubGroup;
use GlobalPayments\Api\Terminals\Enums\ControlCodes;
use GlobalPayments\Api\Terminals\PAX\SubGroups\CashierSubGroup;
use GlobalPayments\Api\Terminals\Enums\CurrencyType;
use GlobalPayments\Api\PaymentMethods\GiftCard;
use GlobalPayments\Api\Terminals\PAX\Responses\GiftResponse;
use GlobalPayments\Api\Terminals\PAX\Interfaces\PaxHttpInterface;
use GlobalPayments\Api\Terminals\Enums\TerminalReportType;
use GlobalPayments\Api\Terminals\PAX\Responses\PaxLocalReportResponse;
use GlobalPayments\Api\Terminals\PAX\Responses\EBTResponse;

/*
 * Main controller class for Heartland payment application
 *
 */

class PaxController extends DeviceController
{
    public $device;
    public $deviceConfig;

    /*
     * Create interface based on connection mode TCP / HTTP
     */

    public function __construct(ConnectionConfig $config)
    {
        parent::__construct($config);
        $this->requestIdProvider = $config->requestIdProvider;
    }

    public function configureInterface() : IDeviceInterface
    {
        if (empty($this->device)) {
            $this->device = new PaxInterface($this);
        }

        return $this->device;
    }

    /*
     * Send control message to device
     *
     * @param string $message control message to device
     *
     * @return DeviceResponse parsed device response
     */

    public function send($message, $requestType = null)
    {
        //send message to gateway
        return $this->connector->send(trim($message), $requestType);
    }

    public function manageTransaction(TerminalManageBuilder $builder) : TerminalResponse
    {
        $requestId = (!empty($builder->requestId)) ?
                        $builder->requestId :
                        $this->requestIdProvider->getRequestId();

        $amount = new AmountRequest();
        $account = new AccountRequest();
        $extData = new ExtDataSubGroup();
        $trace = new TraceRequest();
        $trace->referenceNumber = $requestId;
        
        //Tip Adjust
        if($builder->transactionType === TransactionType::EDIT && !empty($builder->gratuity)){
            /*
             * Transaction Type 06 : ADJUST: Used for additional charges or gratuity. 
             * Typically used for tip adjustment.
             * Set the amount to Transaction Amount, not the Tip Amount
             */
            $amount->transactionAmount = TerminalUtils::formatAmount($builder->gratuity);
            $extData->details[PaxExtData::TIP_REQUEST] = 1;
        } else {
            $amount->transactionAmount = TerminalUtils::formatAmount($builder->amount);
        }

        if ($builder->paymentMethod != null) {
            if ($builder->paymentMethod instanceof TransactionReference) {
                $transactionReference = $builder->paymentMethod;
                if (!empty($transactionReference->transactionId)) {
                    $extData->details[PaxExtData::HOST_REFERENCE_NUMBER] =
                            $transactionReference->transactionId;
                }
            } elseif ($builder->paymentMethod instanceof GiftCard) {
                $card = $builder->paymentMethod;
                $account->accountNumber = $card->number;
            }
        }        
        
        $transactionType = $this->mapTransactionType($builder->transactionType);
        switch ($builder->paymentMethodType) {
            case PaymentMethodType::CREDIT:
                return $this->doCredit(
                    $transactionType,
                    $amount,
                    $account,
                    $trace,
                    new AvsRequest(),
                    new CashierSubGroup(),
                    new CommercialRequest(),
                    new EcomSubGroup(),
                    $extData
                );
            case PaymentMethodType::GIFT:
                $messageId = ($builder->currency == CurrencyType::CURRENCY) ?
                                PaxMessageId::T06_DO_GIFT : PaxMessageId::T08_DO_LOYALTY;
                return $this->doGift(
                    $messageId,
                    $transactionType,
                    $amount,
                    $account,
                    $trace,
                    new CashierSubGroup(),
                    $extData
                );
        }
    }

    public function processTransaction(TerminalAuthBuilder $builder) : TerminalResponse
    {
        $requestId = (!empty($builder->requestId)) ?
                        $builder->requestId :
                        $this->requestIdProvider->getRequestId();

        $amount = new AmountRequest();
        $account = new AccountRequest();
        $extData = new ExtDataSubGroup();
        $trace = new TraceRequest();
        $commercial = new CommercialRequest();
        $ecom = new EcomSubGroup();
        $cashier = new CashierSubGroup();
        $avs = new AvsRequest();
        
        $amount->transactionAmount = TerminalUtils::formatAmount($builder->amount);
        $amount->tipAmount = TerminalUtils::formatAmount($builder->gratuity);
        $amount->cashBackAmount = TerminalUtils::formatAmount($builder->cashBackAmount);
        $amount->taxAmount = TerminalUtils::formatAmount($builder->taxAmount);
        
        $trace->referenceNumber = $requestId;
        $trace->invoiceNumber = $builder->invoiceNumber;

        if (!empty($builder->clientTransactionId)) {
            $trace->clientTransactionId = $builder->clientTransactionId;
        }
        if (!empty($builder->cardBrandTransId))
            $trace->cardBrandTransactionId = $builder->cardBrandTransId;
        
        if ($builder->paymentMethod != null) {
            if ($builder->paymentMethod instanceof CreditCardData) {
                $card = $builder->paymentMethod;
                if (empty($card->token)) {
                    $account->accountNumber = $card->number;
                    $account->expd = $card->getShortExpiry();
                    if ($builder->transactionType != TransactionType::VERIFY &&
                            $builder->transactionType != TransactionType::REFUND) {
                        $account->cvvCode = $card->cvn;
                    }
                } else {
                    $extData->details[PaxExtData::TOKEN] = $card->token;
                }
            } elseif ($builder->paymentMethod instanceof TransactionReference) {
                $reference = $builder->paymentMethod;
                if (!empty($reference->authCode)) {
                    $trace->authCode = $reference->authCode;
                }
                if (!empty($reference->transactionId)) {
                    $extData->details[PaxExtData::HOST_REFERENCE_NUMBER] = $reference->transactionId;
                }
            } elseif ($builder->paymentMethod instanceof GiftCard) {
                $card = $builder->paymentMethod;
                $account->accountNumber = $card->number;
            }
        }
        
        if ($builder->allowDuplicates !== null) {
            $account->dupOverrideFlag = 1;
        }
        
        if ($builder->address !== null) {
            $avs->address = $builder->address->streetAddress1;
            $avs->zipCode = $builder->address->postalCode;
        }

        $commercial->customerCode = $builder->customerCode;
        $commercial->poNumber = $builder->poNumber;
        $commercial->taxExempt = $builder->taxExempt;
        $commercial->taxExemptId = $builder->taxExemptId;
        
        if ($builder->requestMultiUseToken !== null) {
            $extData->details[PaxExtData::TOKEN_REQUEST] = $builder->requestMultiUseToken;
        }
        
        if ($builder->signatureCapture !== null) {
            $extData->details[PaxExtData::SIGNATURE_CAPTURE] = $builder->signatureCapture;
        }

        if (empty($builder->gratuity)) {
            $extData->details[PaxExtData::TIP_REQUEST] = 1;
        }

        if (!empty($builder->autoSubstantiation)) 
            $extData->details[PaxExtData::PASS_THROUGH_DATA] = $builder->autoSubstantiation;
        
        $transactionType = $this->mapTransactionType($builder->transactionType, $builder->requestMultiUseToken);
        switch ($builder->paymentMethodType) {
            case PaymentMethodType::CREDIT:
                return $this->doCredit(
                    $transactionType,
                    $amount,
                    $account,
                    $trace,
                    $avs,
                    $cashier,
                    $commercial,
                    $ecom,
                    $extData
                );
            case PaymentMethodType::DEBIT:
                return $this->doDebit(
                    $transactionType,
                    $amount,
                    $account,
                    $trace,
                    $cashier,
                    $extData
                );
            case PaymentMethodType::GIFT:
                $messageId = ($builder->currency == CurrencyType::CURRENCY) ?
                                PaxMessageId::T06_DO_GIFT : PaxMessageId::T08_DO_LOYALTY;
                return $this->doGift($messageId, $transactionType, $amount, $account, $trace, $cashier, $extData);
                
            case PaymentMethodType::EBT:
                if (!empty($builder->currency)) {
                    $account->ebtType = substr($builder->currency, 0, 1);
                }
                return $this->doEBT($transactionType, $amount, $account, $trace, $cashier, $extData);
        }
    }

    private function mapTransactionType($type, $requestToken = null)
    {
        switch ($type) {
            case TransactionType::ADD_VALUE:
                return PaxTxnType::ADD;
            case TransactionType::AUTH:
                return PaxTxnType::AUTH;
            case TransactionType::BALANCE:
                return PaxTxnType::BALANCE;
            case TransactionType::CAPTURE:
                return PaxTxnType::POSTAUTH;
            case TransactionType::REFUND:
                return PaxTxnType::RETURN_REQUEST;
            case TransactionType::SALE:
                return PaxTxnType::SALE_REDEEM;
            case TransactionType::VERIFY:
                return $requestToken ? PaxTxnType::TOKENIZE : PaxTxnType::VERIFY;
            case TransactionType::VOID:
                return PaxTxnType::VOID;
            case TransactionType::BENEFIT_WITHDRAWAL:
                return PaxTxnType::WITHDRAWAL;
            case TransactionType::REVERSAL:
                return PaxTxnType::REVERSAL;
            case TransactionType::EDIT:
                return PaxTxnType::ADJUST;
            default:
                throw new UnsupportedTransactionException(
                    'The selected gateway does not support this transaction type.'
                );
        }
    }
    
    private function doCredit(
        $transactionType,
        $amounts,
        $accounts,
        $trace,
        $avs,
        $cashier,
        $commercial,
        $ecom,
        $extData
    ) : CreditResponse {
    
        $commands = [
            PaxMessageId::T00_DO_CREDIT,
            '1.35',
            $transactionType,
            $amounts->getElementString(),
            $accounts->getElementString(),
            $trace->getElementString(),
            $avs->getElementString(),
            $cashier->getElementString(),
            $commercial->getElementString(),
            $ecom->getElementString(),
            $extData->getElementString(),
        ];
        $response = $this->doTransaction($commands, PaxMessageId::T00_DO_CREDIT);
        return new CreditResponse($response);
    }
    
    private function doTransaction($commands, $requestType = null)
    {
        $message = implode(chr(ControlCodes::FS), $commands);
        $finalMessage = TerminalUtils::buildMessage($message);
        return $this->send($finalMessage, $requestType);
    }
    
    private function doDebit($transactionType, $amounts, $accounts, $trace, $cashier, $extData) : DebitResponse
    {
        $commands = [
            PaxMessageId::T02_DO_DEBIT,
            '1.35',
            $transactionType,
            $amounts->getElementString(),
            $accounts->getElementString(),
            $trace->getElementString(),
            $cashier->getElementString(),
            $extData->getElementString(),
        ];
        $response = $this->doTransaction($commands, PaxMessageId::T02_DO_DEBIT);
        return new DebitResponse($response);
    }
    
    private function doGift($messageId, $transactionType, $amounts, $accounts, $trace, $cashier, $extData)
    {
        $commands = [
            $messageId,
            '1.35',
            $transactionType,
            $amounts->getElementString(),
            $accounts->getElementString(),
            $trace->getElementString(),
            $cashier->getElementString(),
            $extData->getElementString(),
        ];
        $response = $this->doTransaction($commands, $messageId);
        return new GiftResponse($response);
    }
    
    public function processReport(TerminalReportBuilder $builder) : ITerminalReport
    {
        $response = $this->buildReportTransaction($builder);
        return new PaxLocalReportResponse($response);
    }
    
    public function buildReportTransaction($builder)
    {
        $messageId = $this->mapReportType($builder->reportType);
        
        switch ($builder->reportType) {
            case TerminalReportType::LOCAL_DETAIL_REPORT:
                $criteria = $builder->searchBuilder;
                $extData = new ExtDataSubGroup();
                if (!empty($criteria->MerchantId)) {
                    $extData->details[PaxExtData::MERCHANT_ID] = $criteria->MerchantId;
                }
                
                if (!empty($criteria->MerchantName)) {
                    $extData->details[PaxExtData::MERCHANT_NAME] = $criteria->MerchantName;
                }
                
                $commands = [
                    $messageId,
                    '1.35',
                    '00',
                    (isset($criteria->TransactionType)) ? $criteria->TransactionType : '',
                    (isset($criteria->CardType)) ? $criteria->CardType : '',
                    (isset($criteria->RecordNumber)) ? $criteria->RecordNumber : '',
                    (isset($criteria->TerminalReferenceNumber)) ? $criteria->TerminalReferenceNumber : '',
                    (isset($criteria->AuthCode)) ? $criteria->AuthCode : '',
                    (isset($criteria->ReferenceNumber)) ? $criteria->ReferenceNumber : '',
                    $extData->getElementString(),
                ];
                return $this->doTransaction($commands, $messageId);
            default:
                throw new UnsupportedTransactionException(
                    'The selected gateway does not support this transaction type.'
                );
        }
    }
    
    private function mapReportType($type)
    {
        switch ($type) {
            case TerminalReportType::LOCAL_DETAIL_REPORT:
                return PaxMessageId::R02_LOCAL_DETAIL_REPORT;
            default:
                throw new UnsupportedTransactionException(
                    'The selected gateway does not support this transaction type.'
                );
        }
    }
    private function doEBT($transactionType, $amounts, $accounts, $trace, $cashier, $extData)
    {
        $commands = [
            PaxMessageId::T04_DO_EBT,
            '1.35',
            $transactionType,
            $amounts->getElementString(),
            $accounts->getElementString(),
            $trace->getElementString(),
            $cashier->getElementString(),
            $extData->getElementString(),
        ];
        $response = $this->doTransaction($commands);
        return new EBTResponse($response);
    }

    public function configureConnector(): IDeviceCommInterface
    {
        switch ($this->settings->getConnectionMode()) {
            case ConnectionModes::TCP_IP:
            case ConnectionModes::SSL_TCP:
                return new PaxTcpInterface($this->settings);
            case ConnectionModes::HTTP:
            case ConnectionModes::HTTPS:
                return new PaxHttpInterface($this->settings);
        }
    }
}
