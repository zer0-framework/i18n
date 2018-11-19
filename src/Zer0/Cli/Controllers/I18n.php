<?php

namespace Zer0\Cli\Controllers;

use Gettext\Translations;
use Zer0\Cli\AbstractController;

/**
 * Class I18n
 * @package Zer0\Cli\Controllers
 */
final class I18n extends AbstractController
{
    protected $i18nConfig;

    public function before(): void
    {
        $this->i18n = $this->app->broker('I18n')->getConfig();
    }

    public function buildAction(): void
    {
        foreach (glob(ZERO_ROOT . '/' . ($this->i18nConfig->directory ?? 'locales') . '/*.po') as $poFile) {
            $translations = Translations::fromPoFile($poFile);
            $phpFile = ZERO_ROOT . '/' . ($this->i18nConfig->compiled_dir ?? 'compiled/locales') . '/' . pathinfo($poFile, PATHINFO_FILENAME) . '.php';
            $translations->toPhpArrayFile($phpFile);
            $this->cli->successLine('Written ' . $phpFile);
        }
    }
}
