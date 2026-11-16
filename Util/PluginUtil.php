<?php

namespace Plugin\elepay42\Util;

/**
 * General-purpose helpers for the payment module
 */
class PluginUtil
{
    /**
     * Constructor
     */
    public function __construct() {
    }

    /**
     * getElepayBulkPaymentStatus
     *
     * @return array
     */
    public function getElepayBulkPaymentStatus() {
        return array(
                'captures'     => '売上確定（キャプチャー）',
                'status_check' => '状態確認（ステータス）',
                'close'        => 'キャンセル',
                'refunds'      => '全額売上取消（リファンド）',
        );
    }

    /**
     * getElepayStatus
     *
     * @return array
     */
    public function getElepayStatus() {
        return array(
                'authorized' => 'AUTHORIZED',
                'captured'   => 'CAPTURED',
                'closed'     => 'CLOSED',
        );
    }

    /**
     * Gets the number of days elapsed until now.
     *
     * @param  string   $from_date
     *
     * @return integer  Number of days elapsed until now
     */
    public function getDayDiff($from_date)
    {
        if (!$from_date) {
            return 0;
        }

        $from_date_time = strtotime($from_date);
        $to_date_time = strtotime(date('Y-m-d'));

        $time_diff = $to_date_time - $from_date_time;

        if ($time_diff <= 0) {
            return 0;
        } else {
            return (int) ceil($time_diff / (60 * 60 * 24));
        }
    }

    /**
     * Inserts a hyphen into a postal code string.
     *
     * @param  string  Postal code before formatting (ddddddd)
     *
     * @return string  Postal code after formatting (ddd-dddd)
     */
    public function convertPostalCode($postal_code)
    {
        return substr($postal_code, 0, 3).'-'.substr($postal_code, 3);
    }
}