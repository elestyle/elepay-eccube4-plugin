<?php

namespace Plugin\elepay42\Entity;

use Eccube\Annotation\EntityExtension;
use Doctrine\ORM\Mapping as ORM;

/**
 * プラグインのインストール時に、dtb_order テーブルへ elepay_charge_id カラムを追加する
 *
 * マッピングは docblock アノテーション（EC-CUBE 4.2/4.3 が読む）と PHP Attribute（4.4 が読む）の二重定義。
 * PHP 7.4 で Attribute を行コメントとして読み飛ばさせるため、Attribute は必ず 1 行で書くこと。
 *
 * @EntityExtension("Eccube\Entity\Order")
 */
#[\Eccube\Attribute\EntityExtension(\Eccube\Entity\Order::class)]
trait OrderTrait
{
    /**
     * @var string|null
     *
     * @ORM\Column(name="elepay_charge_id", type="string", length=255, nullable=true)
     */
    #[ORM\Column(name: 'elepay_charge_id', type: 'string', length: 255, nullable: true)]
    private $elepay_charge_id;

    /**
     * Set elepayChargeId.
     *
     * @param string|null $elepayChargeId
     *
     * @return $this
     */
    public function setElepayChargeId($elepayChargeId = null)
    {
        $this->elepay_charge_id = $elepayChargeId;

        return $this;
    }

    /**
     * Get elepayChargeId.
     *
     * @return string|null
     */
    public function getElepayChargeId()
    {
        return $this->elepay_charge_id;
    }
}
