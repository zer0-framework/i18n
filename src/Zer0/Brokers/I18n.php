<?php

namespace Zer0\Brokers;

use PHPDaemon\Core\ClassFinder;
use Zer0\Config\Interfaces\ConfigInterface;

/**
 * Class I18n
 * @package Zer0\Brokers
 */
class I18n extends Base
{

    /**
     * @param ConfigInterface $config
     * @return \Zer0\Mailer\Base
     */
    public function instantiate(ConfigInterface $config):  \Zer0\I18n\I18n
    {
        return new \Zer0\I18n\I18n($config);
    }
}
