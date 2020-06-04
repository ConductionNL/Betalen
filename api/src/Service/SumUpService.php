<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use SumUp\Exceptions\SumUpAuthenticationException;
use SumUp\Exceptions\SumUpResponseException;
use SumUp\Exceptions\SumUpSDKException;
use SumUp\SumUp;
use Symfony\Component\HttpFoundation\Request;

class SumUpService
{
    private $sumup;
    private $checkoutService;
    private $customerService;
    private $customService;
    private $client;

    public function __construct(Service $service)
    {
        $this->client = new Client();

        try {
            $this->sumup = new SumUp([
                'app_id'        => $service->getConfiguration()['app_id'],
                'app_secret'    => $service->getConfiguration()['app_secret'],
                'code'          => $service->getAuthorization(),
            ]);
            $this->checkoutService = $this->sumup->getCheckoutService();
            $this->customerService = $this->sumup->getCustomerService();
            $this->customService = $this->sumup->getCustomService();
        } catch (SumUpSDKException $e) {
            echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';
        }
    }

    public function createPayment(Invoice $invoice): string
    {
        $currency = $invoice->getPriceCurrency();
        $amount = $invoice->getPrice();
        $description = $invoice->getDescription();
        $payToEmail = json_decode($this->client->get($invoice->getCustomer()), true)['emails'][0]['email'];
        //@TODO: make return url configurable
        $redirectUrl = $invoice->getRedirectUrl();

        try {
            $sumUpPayment = $this->checkoutService->create(
                $amount,
                $currency,
                $invoice->getReference(),
                $payToEmail,
                $description
            );
        } catch (SumUpAuthenticationException $e) {
            echo '<section><h2>Could not authenticate with payment provider</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpResponseException $e) {
            echo '<section><h2>Could not create payment at payment provier</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpSDKException $e) {
            echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        }

        return 'https://api.sumup.com/v0.1/checkouts/'.$sumUpPayment->getBody()->id;
    }

    public function updatePayment(Request $request, EntityManagerInterface $manager): Payment
    {
        $payment = $manager->getRepository('App:Payment')->findOneBy(['id'=> $request['id']]);
        if ($payment instanceof Payment) {
            try {
                $result = $this->checkoutService->findById($payment->getPaymentÏd());
            } catch (SumUpAuthenticationException $e) {
                echo '<section><h2>Could not authenticate with payment provider</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            } catch (SumUpResponseException $e) {
                echo '<section><h2>Could not create payment at payment provier</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            } catch (SumUpSDKException $e) {
                echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            }

            $payment->setStatus(strtolower($result->getBody()->status));
        }

        return $payment;
    }

    public function payPayment(Request $request, EntityManagerInterface $manager): Payment
    {
        $payment = $manager->getRepository('App:Payment')->findOneBy(['id'=> $request['id']]);
        if ($payment instanceof Payment) {
            try {
                $result = $this->checkoutService->pay(
                    $payment->getPaymentId(),
                    $request['customerId'],
                    $request['cardToken']
                );
            } catch (SumUpAuthenticationException $e) {
                echo '<section><h2>Could not authenticate with payment provider</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            } catch (SumUpResponseException $e) {
                echo '<section><h2>Could not create payment at payment provier</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            } catch (SumUpSDKException $e) {
                echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';

                return null;
            }

            $payment->setStatus(strtolower($result->getBody()->status));
        }

        return $payment;
    }

    public function createCustomer(Request $request)
    {
        $customer = json_decode($this->client->get($request['invoice']['customer']));
        $customerId = $customer['id'];
        $firstName = $customer['givenName'];
        $lastName = $customer['familyName'];
        $email = $customer['emails'][0]['email'];
        $customerDetails = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
        ];

        try {
            $response = $this->customerService->create(
                $customerId,
                $customerDetails
            );
        } catch (SumUpAuthenticationException $e) {
            echo '<section><h2>Could not authenticate with payment provider</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpResponseException $e) {
            echo '<section><h2>Could not create payment at payment provier</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpSDKException $e) {
            echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        }

        return $response->getBody()->id;
    }

    public function addCard(Request $request)
    {
        try {
            $customer = json_decode($this->client->get($request['invoice']['customer']));
            $customerId = $customer['id'];
            $this->customService->request('post', "/customers/$customerId/payment-instruments", [
                'name'         => $request['cardholder'],
                'number'       => $request['cardnumber'],
                'expiry_year'  => $request['cardExpiryYear'],
                'expiry_month' => $request['cardExpiryMonth'],
                'cvv'          => $request['cvv'],
                'zip_code'     => $request['cardZipCode'],
            ]);
            $response = $this->customerService->getPaymentInstruments($customerId);
        } catch (SumUpAuthenticationException $e) {
            echo '<section><h2>Could not authenticate with payment provider</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpResponseException $e) {
            echo '<section><h2>Could not create payment at payment provier</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        } catch (SumUpSDKException $e) {
            echo '<section><h2>SumUp SDK Error</h2><pre>'.$e->getMessage().'</pre></section>';

            return null;
        }

        return $response->getBody()->token;
    }
}
