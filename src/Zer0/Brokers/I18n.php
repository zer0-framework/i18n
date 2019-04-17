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
     * @return Translator
     */
    public function instantiate(?ConfigInterface $config): Translator
    {
        $config = $this->getConfig();
        $t = new Translator;
        $path = ZERO_ROOT . '/' . ($this->i18nConfig->compiled_dir ?? 'compiled/locales') . '/' . $this->lastName . '.php';
        $t->loadTranslations(include $path);
        $t->register();
        return $t;
    }

    /**
     * @param string $name
     * @param bool $caching
     * @return Translator
     */
    public function get(string $name = '', bool $caching = true): Translator
    {
        return parent::get($name, $caching);
    }
}
