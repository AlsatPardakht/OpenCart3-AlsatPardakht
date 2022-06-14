<?php
/**
 * Alsat Pardakht payment gateway
 *
 * @publisher Alsat Pardakht
 * @copyright (C) 2018 Alsat Pardakht
 *
 * http://alsatpardakht.com
 */
class ModelExtensionPaymentAlsat extends Model
{
    public function getMethod($address)
    {
        if ($this->config->get('payment_alsat_status')) {
            $status = true;
        } else {
            $status = false;
        }
        $method_data = array();
        if ($status) {
            $method_data = array(
                'code' => 'alsat',
                'title' => $this->config->get('payment_alsat_title'),
                'terms' => '',
                'sort_order' => $this->config->get('payment_alsat_sort_order')
            );
        }
        return $method_data;
    }
}

?>
