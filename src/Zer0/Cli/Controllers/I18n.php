<?php

namespace Zer0\Cli\Controllers;

use Gettext\Merge;
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
            $phpFile = ZERO_ROOT . '/' . ($this->i18nConfig->compiled_dir ?? 'compiled/locales') . '/' . pathinfo($poFile,
                    PATHINFO_FILENAME) . '.php';
            $translations->toPhpArrayFile($phpFile);
            $this->cli->successLine('Written ' . $phpFile);
        }
    }

    public function extractAction(): void
    {
        $poFile = ZERO_ROOT . '/' . ($this->i18nConfig->directory ?? 'locales') . '/ru.po';

        $translations = Translations::fromPoFile($poFile);

        foreach (explode("\n", shell_exec('find src -name \'*.php\'; find src -name \'*.tpl\'')) as $file) {
            if ($file === '') {
                continue;
            }
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'php') {
                $extracted = Translations::fromPhpCodeFile($file);
            } elseif ($extension === 'tpl') {
                $extracted = Translations::fromQuickyFile($file);
            }
            $count = 0;
            foreach ($extracted as $tr) {
                $original = $tr->getOriginal();
                if (!$translations->find(null, $original)) {
                    if ($count === 0) {
                        $this->cli->successLine('Extracted from ' . $file);
                    }
                    ++$count;
                    $this->cli->writeln("\t" . $original);
                }
            }
            $translations->mergeWith($extracted, Merge::ADD);
        }

        $translations->toPoFile($poFile);
    }
}
