<?php

namespace Plugin\Elepay\Util;

/**
 * 決済モジュール用 汎用関数クラス
 */
class PluginUtil
{
    /**
     * コンストラクタ
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
     * 現在日時までの経過日数を取得.
     *
     * @param  string   $from_date
     *
     * @return integer  現在日時までの経過日数
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
     * 郵便番号文字列の間にハイフンを挿入.
     *
     * @param  string  整形前の郵便番号(ddddddd)
     *
     * @return string  整形後の郵便番号(ddd-dddd)
     */
    public function convertPostalCode($postal_code)
    {
        return substr($postal_code, 0, 3).'-'.substr($postal_code, 3);
    }
}