<?php

use WHMCS\Database\Capsule;

if (isset($_REQUEST['invoiceId']) && is_numeric($_REQUEST['invoiceId'])) {
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../includes/invoicefunctions.php';
    $gatewayParams = getGatewayVariables('aqayepardakht');
    if (isset($_POST['status'])) {
        $invoice = Capsule::table('tblinvoices')->where('id', $_GET['invoiceId'])->where('status', 'Unpaid')->first();
        if (!$invoice) {
            die("Invoice not found");
        }
        if ($_POST['status'] == 1) {
            $amount = ceil($invoice->total / ($gatewayParams['currencyType'] == 'IRT' ? 1 : 10));

            if ($gatewayParams['feeFromClient'] == 'on') {
                $amount = ceil(1.01 * $amount);
            }

            $trans_id = $_POST[ 'transid' ];
            $trackingnumber = $_POST[ 'tracking_number' ];
            $card_number = $_POST[ 'cardnumber' ];

            if ( $gatewayParams[ 'testMode' ] == 'on' ) {
                $pin_pay = 'sandbox';
              } else {
                $pin_pay = $gatewayParams[ 'PIN' ];
              }

              $data = [
                'pin' => $pin_pay,
                'amount' => $amount,
                'transid' => $trans_id
              ];
          
              $data = json_encode($data);
              $ch = curl_init('https://panel.aqayepardakht.ir/api/v2/verify');
              curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
              curl_setopt($ch, CURLINFO_HEADER_OUT, true);
              curl_setopt($ch, CURLOPT_POST, true);
              curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
              
              curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
              'Content-Length: ' . strlen($data))
              );
              $result = curl_exec($ch);
              curl_close($ch);
              $result = json_decode($result);

              if ( $result->code == "1" ) {
                checkCbTransID($trans_id);
                logTransaction($gatewayParams['name'], $_REQUEST, 'Success');
                addInvoicePayment(
                    $invoice->id,
                    $trans_id,
                    $invoice->total,
                    0,
                    'aqayepardakht'
                );
            } else {
                logTransaction($gatewayParams['name'], array(
                    'Code'           => 'AqayePardakht Result Code',
                    'Message'        => $result,
                    'Transaction ID' => $trans_id,
                    'Tracking Number'=> $trackingnumber,
                    'Card Number'    => $card_number,
                    'Invoice'        => $invoice->id,
                    'Amount'         => $invoice->total,
                ),  'Failure');
            }
        }
        header('Location: ' . $gatewayParams['systemurl'] . '/viewinvoice.php?id=' . $invoice->id);
    } else if (isset($_SESSION['uid'])) {
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->where('userid', $_SESSION['uid'])->first();
        if (!$invoice) {
            die("Invoice not found");
        }
        $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
        $amount = ceil($invoice->total / ($gatewayParams['currencyType'] == 'IRT' ? 1 : 10));

        if ($gatewayParams['feeFromClient'] == 'on') {
            $amount = ceil(1.01 * $amount);
        }

        if ( $gatewayParams[ 'testMode' ] == 'on' ) {
            $send = 'https://panel.aqayepardakht.ir/startpay/sandbox/';
            $pin_pay = 'sandbox';
          } else {
            $send = 'https://panel.aqayepardakht.ir/startpay/';
            $pin_pay = $gatewayParams[ 'PIN' ];
          }

          $data = [
            'pin'    => $pin_pay,
            'amount'    => $amount,
            'callback' => $gatewayParams['systemurl'] . '/modules/gateways/aqayepardakht.php?invoiceId=' . $invoice->id . '&callback=1',
            'mobile' => str_replace( ' ', '', str_replace( '+98.', '', $client->phonenumber )),
            'email' => $client->email,
            'invoice_id' => $invoice->id,
            'description' => 'خریدار : ' . $client->firstname . ' ' . $client->lastname
            ];
            

            $data = json_encode($data);
            $ch = curl_init('https://panel.aqayepardakht.ir/api/v2/create');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data))
            );
            $result = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($result);

            if ($result->status == "success") {
            header('Location: '. $send . $result->transid);
        } else {
            echo 'خطا در اتصال به درگاه پرداخت - کدخطا : ', $result->code;
        }
    }
    return;
}

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function aqayepardakht_MetaData()
{
    return array(
        'DisplayName' => 'ماژول پرداخت آقای پرداخت',
        'APIVersion' => '1.3',
    );
}

function aqayepardakht_config()
{
    return array(
        'FriendlyName' => array(
          'Type' => 'System',
          'Value' => 'آقای پرداخت',
        ),
        'currencyType' => array(
            'FriendlyName' => 'واحد مالی',
            'Type' => 'dropdown',
            'Options' => array(
              'IRR' => 'ریال',
              'IRT' => 'تومان',
            ),
          ),
        'PIN' => array(
            'FriendlyName' => 'پین درگاه پرداخت',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'پین دریافتی از درگاه پرداخت <a href="https://aqayepardakht.ir/" target="_blank">آقای پرداخت</a>',
          ),
          'feeFromClient' => array(
            'FriendlyName' => 'دریافت مالیات از کاربر',
            'Type' => 'yesno',
            'Description' => 'برای دریافت مالیات از کاربر تیک بزنید',
          ),
          'testMode' => array(
            'FriendlyName' => 'حالت سندباکس (تست)',
            'Type' => 'yesno',
            'Description' => 'برای فعال کردن حالت سندباکس (تست) تیک بزنید',
          ),
    );
}

function aqayepardakht_link( $params ) {
    $htmlOutput = '<form method="GET" action="modules/gateways/aqayepardakht.php">';
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . $params[ 'invoiceid' ] . '">';
    $htmlOutput .= '<input type="submit" value="' . $params[ 'langpaynow' ] . '" />';
    $htmlOutput .= '</form>';
    return $htmlOutput;
}
