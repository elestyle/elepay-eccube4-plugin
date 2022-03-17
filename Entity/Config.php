<?php

namespace Plugin\Elepay\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;
use stdClass;

/**
 * Config
 * 插件安装时，会根据此文件自动创建数据库表
 *
 * @ORM\Table(name="plg_elepay_config")
 * @ORM\Entity(repositoryClass="Plugin\Elepay\Repository\ConfigRepository")
 */
class Config extends AbstractEntity
{

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="public_key", type="string", length=255, nullable=true)
     */
    private $public_key;

    /**
     * @var string
     *
     * @ORM\Column(name="secret_key", type="string", length=255, nullable=true)
     */
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
