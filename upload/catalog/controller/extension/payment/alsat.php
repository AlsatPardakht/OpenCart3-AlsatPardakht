<?php

/**
 * Alsat Pardakht payment gateway
 *
 * @publisher Alsat Pardakht
 * @copyright (C) 2018 Alsat Pardakht
 *
 * http://alsatpardakht.com
 */
class ControllerExtensionPaymentAlsat extends Controller
{

    /**
     * @param $id
     * @return string
     */
    public function generateString($id)
    {
        return 'Alsat Transaction ID: ' . $id;
    }

    /**
     * @return mixed
     */
    public function index()
    {
        $this->load->language('extension/payment/alsat');

        $data['text_connect'] = $this->language->get('text_connect');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['text_wait'] = $this->language->get('text_wait');
        $data['button_confirm'] = $this->language->get('button_confirm');

        return $this->load->view('extension/payment/alsat', $data);
    }

    /**
     *
     */
    public function confirm()
    {
        $this->load->language('extension/payment/alsat');

        $this->load->model('checkout/order');
        /** @var \ModelCheckoutOrder $model */
        $model = $this->model_checkout_order;
        $order_id = $this->session->data['order_id'];
        $order_info = $model->getOrder($order_id);

        $data['return'] = $this->url->link('checkout/success', '', true);
        $data['cancel_return'] = $this->url->link('checkout/payment', '', true);
        $data['back'] = $this->url->link('checkout/payment', '', true);
        $data['order_id'] = $this->session->data['order_id'];

        $api_key = $this->config->get('payment_alsat_api_key');
        $sandbox = $this->config->get('payment_alsat_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $this->correctAmount($order_info);

        $desc = $this->language->get('text_order_no') . $order_info['order_id'];
        $callback = $this->url->link('extension/payment/alsat/callback', '', true);

        if (empty($amount)) {
            $json['error'] = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }
        // Customer information
        $name = $order_info['firstname'] . ' ' . $order_info['lastname'];
        $mail = $order_info['email'];
        $phone = $order_info['telephone'];

        $Tashim = [];
        $Tashim = json_encode($Tashim,JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://www.alsatpardakht.com/IPGAPI/Api22/send.php');
        curl_setopt($ch, CURLOPT_POSTFIELDS, "Amount=$amount&ApiKey=$api_key&&Tashim=$Tashim&&RedirectAddressPage=$callback&&PayId=$order_id");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $result = curl_exec($ch);
        $result = json_decode($result);
        $Token = $result->Token ?? '';
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $pre = 'https://www.alsatpardakht.com/IPGAPI/Api2/Go.php?Token=';

        if ($http_status !== 200  || empty($result) || empty($result->Token)) {
            // Set Order status id to 10 (Failed) and add a history.
            $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, 'خطا', 'از وارد کردن درست api-key اطمینان حاصل فرمائید و مبلغ می بایست بالاتر از ۲ هزار تومان باشد');
            $model->addOrderHistory($order_id, 10, $msg, true);
            $json['error'] = $msg;
        } else {
            // Add a specific history to the order with order status 1 (Pending);
            $model->addOrderHistory($order_id, 1, $this->generateString($result->TimeStamp), false);
            $model->addOrderHistory($order_id, 1, 'در حال هدایت به درگاه پرداخت آلسات پرداخت', false);
            $data['action'] = $result->Token;
            $json['success'] = $pre.$data['action'];
        }


        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * http request callback
     */
    public function callback()
    {
        if ($this->session->data['payment_method']['code'] == 'alsat') {

            // Check method http request
            $method = !empty($this->request->server['REQUEST_METHOD']) ? strtolower($this->request->server['REQUEST_METHOD']) : null;
            if (empty($method)) {
                die;
            }

            $status = empty($this->request->{$method}['status']) ? NULL : $this->request->{$method}['status'];
            $track_id = empty($this->request->{$method}['track_id']) ? NULL : $this->request->{$method}['track_id'];
            $id = empty($this->request->{$method}['id']) ? NULL : $this->request->{$method}['id'];
            $order_id = empty($this->request->{$method}['PayId']) ? NULL : $this->request->{$method}['PayId'];

            $this->load->language('extension/payment/alsat');

            $this->document->setTitle($this->config->get('payment_alsat_title'));

            $data['heading_title'] = $this->config->get('payment_alsat_title');
            $data['peyment_result'] = "";

            $data['breadcrumbs'] = array();
            $data['breadcrumbs'][] = array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/home', '', true)
            );
            $data['breadcrumbs'][] = array(
                'text' => $this->config->get('payment_alsat_title'),
                'href' => $this->url->link('extension/payment/alsat/callback', '', true)
            );


            if ($this->session->data['order_id'] != $order_id) {
                $comment = 'شماره سفارش اشتباه است.';
                $data['peyment_result'] = $comment;
                $data['button_continue'] = $this->language->get('button_view_cart');
                $data['continue'] = $this->url->link('checkout/cart');
            } else {
                $this->load->model('checkout/order');

                /** @var  \ModelCheckoutOrder $model */
                $model = $this->model_checkout_order;
                $order_info = $model->getOrder($order_id);


                if (!$order_info) {
                    $comment = $this->alsat_get_failed_message($track_id, $order_id);
                    // Set Order status id to 10 (Failed) and add a history.
                    $model->addOrderHistory($order_id, 10, $comment, true);
                    $data['peyment_result'] = $comment;
                    $data['button_continue'] = $this->language->get('button_view_cart');
                    $data['continue'] = $this->url->link('checkout/cart');
                } else {
//                    if ($status != 10) {
//                        $comment = $this->alsat_get_failed_message($track_id, $order_id, $status);
//                        // Set Order status id to 10 (Failed) and add a history.
//                        $model->addOrderHistory($order_id, 10, $this->otherStatusMessages($status), true);
//                        $data['peyment_result'] = $comment;
//                        $data['button_continue'] = $this->language->get('button_view_cart');
//                        $data['continue'] = $this->url->link('checkout/cart');
//
//                    } else {
                    $amount = $this->correctAmount($order_info);
                    $api_key = $this->config->get('payment_alsat_api_key');
                    $sandbox = $this->config->get('payment_alsat_sandbox') == 'yes' ? 'true' : 'false';

                    $alsat_data = array(
                        'Api' => $api_key,
                        'tref' => $_GET['tref'],
                        'iN' => $_GET['iN'],
                        'iD' => $_GET['iD'],
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://www.alsatpardakht.com/IPGAPI/Api22/VerifyTransaction.php');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $alsat_data);
                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);


                    if ($result == null) {
                        $comment = sprintf('خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, 'پرداخت انجام نشد');
                        // Set Order status id to 10 (Failed) and add a history.
                        $model->addOrderHistory($order_id, 10, $comment, true);
                        $data['peyment_result'] = $comment;
                        $data['button_continue'] = $this->language->get('button_view_cart');
                        $data['continue'] = $this->url->link('checkout/cart');
                    } else {
                        $verify_status = empty($result->PSP->IsSuccess) ? NULL : $result->PSP->IsSuccess;
                        $verify_track_id = empty($result->PSP->TraceNumber) ? NULL : $result->PSP->TraceNumber;
                        $verify_order_id = empty($result->PSP->InvoiceNumber) ? NULL : $result->PSP->InvoiceNumber;
                        $verify_amount = empty($result->PSP->Amount) ? NULL : $result->PSP->Amount;


                        //get result id from database
                        $sql = $this->db->query('SELECT `comment`  FROM ' . DB_PREFIX . 'order_history WHERE order_id = ' . $order_id . '');

                        if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_amount != $amount) {
                            $comment = $this->alsat_get_failed_message($verify_track_id, $verify_order_id);
                            // Set Order status id to 10 (Failed) and add a history.
                            $model->addOrderHistory($order_id, 10, $comment, true);
                            $data['peyment_result'] = $comment;
                            $data['button_continue'] = $this->language->get('button_view_cart');
                            $data['continue'] = $this->url->link('checkout/cart');

                        } elseif (count($sql->row) == 0) {
                            //check double spending
                            $comment = $this->alsat_get_failed_message($track_id, $order_id, 0);
                            $model->addOrderHistory($order_id, 10, $this->otherStatusMessages($status), true);
                            $data['peyment_result'] = $comment;
                            $data['button_continue'] = $this->language->get('button_view_cart');
                            $data['continue'] = $this->url->link('checkout/cart');


                        } else { // Transaction is successful.
                            $comment = $this->alsat_get_success_message($verify_track_id, $verify_order_id);

                            $config_successful_payment_status = $this->config->get('payment_alsat_order_status_id');
                            // Set Order status id to the configured status id and add a history.
                            $model->addOrderHistory($verify_order_id, $config_successful_payment_status, $comment, true);
                            // Add another history.
                            $comment2 = 'status: ' . $result->PSP->IsSuccess . ' - track id: ' . $result->PSP->TraceNumber . ' - card no: ' . $result->PSP->TrxMaskedCardNumber . ' - hashed card no: ' . $result->PSP->TrxHashedCardNumber;

                            $model->addOrderHistory($order_id, $config_successful_payment_status, $comment2, true);
                            $data['peyment_result'] = $comment;
                            $data['button_continue'] = $this->language->get('button_complete');
                            $data['continue'] = $this->url->link('checkout/success');
                        }
                    }
                }
            }
//            }


            $data['column_left'] = $this->load->controller('common/column_left');
            $data['column_right'] = $this->load->controller('common/column_right');
            $data['content_top'] = $this->load->controller('common/content_top');
            $data['content_bottom'] = $this->load->controller('common/content_bottom');
            $data['footer'] = $this->load->controller('common/footer');
            $data['header'] = $this->load->controller('common/header');
            $this->response->setOutput($this->load->view('extension/payment/alsat_confirm', $data));

        }
    }

    /**
     * @param $order_info
     * @return int
     */
    private function correctAmount($order_info)
    {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $amount = round($amount);
        $amount = $this->currency->convert($amount, $order_info['currency_code'], "RLS");
        return (int)$amount;
    }


    /**
     * @param $track_id
     * @param $order_id
     * @return mixed
     */
    public function alsat_get_success_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->config->get('payment_alsat_success_massage'));
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    public function alsat_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->config->get('payment_alsat_failed_massage')) . "<br>" . "$msg";
    }

    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "4":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";
    }

}

?>
