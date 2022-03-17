<?php

namespace Plugin\Elepay\Entity;

use Eccube\Annotation\EntityExtension;
use Doctrine\ORM\Mapping as ORM;

/**
 * 安装插件时，会对数据库 dtb_order 表进行扩展
 *
 * @EntityExtension("Eccube\Entity\Order")
 */
trait OrderTrait
{
    /**
     * @var string|null
     *
     * @ORM\Column(name="elepay_charge_id", type="string", length=255, nullable=true)
     */
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
