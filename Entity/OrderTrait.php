<?php

namespace Plugin\elepay42\Entity;

use Eccube\Annotation\EntityExtension;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adds the elepay_charge_id column to the dtb_order table when the plugin is installed
 *
 * Mappings are declared twice: as docblock annotations (read by EC-CUBE 4.2/4.3) and as PHP attributes (read by 4.4).
 * Always write each attribute on a single line, so that PHP 7.4 skips it as a line comment.
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
