<?php

namespace Plugin\elepay42\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;
use stdClass;

/**
 * Config
 * The plg_elepay_config table is created from this definition when the plugin is installed
 *
 * Mappings are declared twice: as docblock annotations (read by EC-CUBE 4.2/4.3) and as PHP attributes (read by 4.4).
 * Always write each attribute on a single line, so that PHP 7.4 skips it as a line comment.
 *
 * @ORM\Table(name="plg_elepay_config")
 * @ORM\Entity(repositoryClass="Plugin\elepay42\Repository\ConfigRepository")
 */
#[ORM\Table(name: 'plg_elepay_config')]
#[ORM\Entity(repositoryClass: \Plugin\elepay42\Repository\ConfigRepository::class)]
class Config extends AbstractEntity
{

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    #[ORM\Column(name: 'id', type: 'integer', options: ['unsigned' => true])]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="public_key", type="string", length=255, nullable=true)
     */
    #[ORM\Column(name: 'public_key', type: 'string', length: 255, nullable: true)]
    private $public_key;

    /**
     * @var string
     *
     * @ORM\Column(name="secret_key", type="string", length=255, nullable=true)
     */
    #[ORM\Column(name: 'secret_key', type: 'string', length: 255, nullable: true)]
    private $secret_key;


    /**
     * Constructor
     * @param stdClass $params
     */
    public function __construct(stdClass $params)
    {
        $this->public_key = $params->public_key;
        $this->secret_key = $params->secret_key;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getPublicKey()
    {
        return $this->public_key;
    }

    /**
     * {@inheritdoc}
     */
    public function setPublicKey($public_key)
    {
        $this->public_key = $public_key;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecretKey()
    {
        return $this->secret_key;
    }

    /**
     * {@inheritdoc}
     */
    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;

        return $this;
    }

    /**
     * @return Config
     */
    public static function createInitialConfig(): Config
    {
        /** @var stdClass $params */
        $params = new stdClass();
        $params->public_key = '';
        $params->secret_key = '';
        return new static($params);
    }
}
