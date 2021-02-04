<?php
namespace Madit\Atos\Controller\Payment;

use Madit\Atos\Controller\Index\Index;
use Madit\Atos\Model\Api\Request;
use Madit\Atos\Model\Api\Response;
use Madit\EdiSync\Helper\Data;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;

class NormalCallback extends Index
{
    /**
     * Dispatch request
     * When customer returns from Atos/Sips payment platform
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        if (!array_key_exists('DATA', $_REQUEST)) {

            // Set redirect message
            $this->getAtosSession()->setRedirectMessage(('An error occured: no data received.'));
            // Log error
            $errorMessage = __('Customer #%1 returned successfully from Atos/Sips payment platform but no data received for order #%2.', $this->getCustomerSession()->getCustomerId(), $this->getCheckoutSession()->getLastRealOrder()->getId());



            //echo "<pre> request: print_r($_REQUEST, 1)</pre>";
            $this->atosHelper->logError(get_class($this), __FUNCTION__, $errorMessage);

            // Redirect
            $this->_redirect('*/*/failure');
            return;
        }

        // Get Sips Server Response
        $response = $this->_getAtosResponse($_REQUEST['DATA']);

        //echo var_dump($_REQUEST['DATA']);
        //$this->logger->debug(var_dump($_REQUEST['DATA']));

        //$this->atosHelper->logError(get_class($this), __FUNCTION__, $response['hash']);
        // Debug
        $this->getMethodInstance()->debugResponse($response['hash'], 'Normal');

        // Check if merchant ID matches
        if ($response['hash']['merchant_id'] != $this->getconfig()->getMerchantId()) {
            // Set redirect message
            $this->getAtosSession()->setRedirectMessage(('An error occured: merchant ID mismatch.'));
            // Log error
            $errorMessage = __('Response Merchant ID (%1) is mismatch with configuration value (%2)', $response['hash']['merchant_id'], $this->getConfig()->getMerchantId());
            $this->atosHelper->logError(get_class($this), __FUNCTION__, $errorMessage);
            // Redirect
            $this->_redirect('*/*/failure');
            return;
        }

        // Treat response
        $order = $this->orderInterface;
        if ($response['hash']['order_id']) {
            $order->loadByIncrementId($response['hash']['order_id']);
        }

        switch ($response['hash']['response_code']) {
            case '00':
                if ($order->getId()) {
                    /*
                    $this->checkoutSession->setLastOrderId($order->getId());
                    $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                    $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                    */
                    $order->addCommentToStatusHistory(_('Customer returned successfully from Atos/Sips payment platform.'))->save();
                    //addStatusHistoryComment(('Customer returned successfully from Atos/Sips payment platform.'))
                     //   ->save();
                }
                $curQuote = $this->getCheckoutSession()->getQuote();
                $curQuote->setIsActive(false);
                $this->quoteRepository->save($curQuote);

                // Set redirect URL
                $response['redirect_url'] = 'checkout/onepage/success';
                break;
            default:
                // Log error
                $errorMessage = __('Error: code %1.<br /> %1', $response['hash']['response_code'], $response['hash']['error']);
                $this->atosHelper->logError(get_class($this), __FUNCTION__, $errorMessage);
                // Add error on order message, cancel order and reorder
                if ($order->getId()) {
                    if ($order->canCancel()) {
                        try {
                            $order->registerCancellation($errorMessage)->save();
                        } catch (LocalizedException $e) {
                            $this->logger->critical($e);
                        } catch (\Exception $e) {
                            $this->logger->critical($e);
                            $errorMessage .= '<br/><br/>';
                            $errorMessage .= __('The order has not been cancelled.') . ' : ' . $e->getMessage();
                            $order->addStatusHistoryComment($errorMessage)->save();
                        }
                    } else {
                        $errorMessage .= '<br/><br/>';
                        $errorMessage .= __('The order was already cancelled.');
                        $order->addStatusHistoryComment($errorMessage)->save();
                    }

                    // Refill cart
                    $this->atosHelper->reorder($response['hash']['order_id']);
                }
                // Set redirect message
                $this->getAtosSession()->setRedirectTitle(('Your payment has been rejected'));
                $describedResponse = $this->getApiResponse()->describeResponse($response['hash'], 'array');
                $this->getAtosSession()->setRedirectMessage(__('The payment platform has rejected your transaction with the message: <strong>%1</strong>, because the bank send the error: <strong>%2</strong>.', $describedResponse['response_code'], $describedResponse['bank_response_code']));
                // Set redirect URL
                $response['redirect_url'] = '*/*/failure';
                break;
        }

        // Save Atos/Sips response in session
        $this->getAtosSession()->setResponse($response);

        $this->_redirect($response['redirect_url'], ['_secure' => true]);
    }


}
