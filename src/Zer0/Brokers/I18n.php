<?php

namespace Zer0\Brokers;

use Gettext\Translator;
use Zer0\Config\Interfaces\ConfigInterface;

/**
 * Class I18n
 * @package Zer0\Brokers
 */
class I18n extends Base
{

    /**
     * @param ConfigInterface $config
     */
    public function instantiate(?ConfigInterface $config)
    {
        $config = $this->getConfig();
        $t = new Translator;
        $path = ZERO_ROOT . '/' . ($this->i18nConfig->compiled_dir ?? 'compiled/locales') . '/' . $this->lastName . '.php';
        $t->loadTranslations(include $path);
        $t->register();
        return $t;
    }
}
