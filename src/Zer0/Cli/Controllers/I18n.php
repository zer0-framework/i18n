<?php

namespace Zer0\Cli\Controllers;

use Gettext\Merge;
use Gettext\Translation;
use Gettext\Translations;
use Hoa\Console\Cursor;
use Hoa\Console\Readline\Readline;
use Zer0\Cli\AbstractController;
use Zer0\Config\Interfaces\ConfigInterface;
use Zer0\Exceptions\InterruptedException;

/**
 * Class I18n
 * @package Zer0\Cli\Controllers
 */
final class I18n extends AbstractController
{
    /**
     * @var ConfigInterface
     */
    protected $i18nConfig;

    public function before(): void
    {
        $this->i18nConfig = $this->app->broker('I18n')->getConfig();
    }

    /**
     *
     */
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

    /**
     *
     */
    public function extractAction(): void
    {
        $poFile = ZERO_ROOT . '/' . ($this->i18nConfig->directory ?? 'locales') . '/' . $this->i18nConfig->source_language . '.po';

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
            /**
             * @var Translation $tr
             */
            foreach ($extracted as $tr) {
                if (!$translations->find($tr->getContext(), $tr->getOriginal())) {
                    if ($count === 0) {
                        $this->cli->successLine('Extracted from ' . $file);
                    }
                    ++$count;
                    $this->cli->writeln("\t" . $tr->getOriginal());
                }
            }
            $translations->mergeWith($extracted, Merge::ADD);
        }

        $translations->toPoFile($poFile);
    }

    public function forceAction(): void
    {
        $skippedFile = $_SERVER['HOME'] . '/.skipped-i18n';

        if (is_file($skippedFile)) {
            $skipped = array_map(function ($line) {
                return json_decode($line, true);
            }, explode("\n", file_get_contents($skippedFile)));
        } else {
            $skipped = [];
        }

        foreach (explode("\n", shell_exec('find src -name \'*.php\'; find src -name \'*.tpl\'')) as $file) {
            if ($file === '') {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'php') {

            } elseif ($extension === 'tpl') {
                $source = file_get_contents($file);
                //$source = html_entity_decode($source);у
                $rl = new Readline();
                find:
                $found = false;
                try_block:
                try {
                    $cleanSource = $source;
                    $cleanSource = preg_replace('~\{[^}]+\}~', "\x00", $cleanSource);

                    $replaced = [];
                    preg_replace_callback('~<script[^>]*>.*?</script>|<[^>]+>|\{[^}]+\}|(\s*)([A-ZА-Яа-я&][^<"{}\x00]+)(\s*)~siu',
                        function ($match) use ($rl, &$found, &$source, $file, $skippedFile, &$skipped, &$replaced) {
                            if (($match[2] ?? '') === '') {
                                return;
                            }
                            $orig = trim($match[2]);
                            if (in_array($orig, $replaced)) {
                                return;
                            }
                            foreach ($skipped as $item) {
                                if ($item['file'] === $file && $item['match'] === $orig) {
                                    return;
                                }
                            }

                            if (!$found) {
                                $this->cli->writeln($file);
                            }
                            $found = true;
                            $replacement = '{_ ' . $orig . '}';
                            $newlines = 0;
                            message:
                            for (; $newlines > 0; --$newlines) {
                                Cursor::move('up');
                                Cursor::clear('line');
                            }
                            $message = "Do you want to replace: " . $orig . "\n with " . $replacement . ' ?';
                            $newlines = substr_count($message, "\n") + 1;
                            readline:
                            $this->cli->writeln($message);
                            $line = strtolower($rl->readLine('(y)es/(n)o/(m)ore: '));
                            if ($line === 'y' || $line === 'yes') {
                                Cursor::move('up');
                                Cursor::clear('line');
                                $source = str_replace($orig, $replacement, $source);
                                file_put_contents($file, $source);
                                $this->cli->successLine('REPLACED');
                                $this->cli->writeln(str_repeat('-', 100));
                                //$replaced[] = $source;
                                throw new InterruptedException;
                                return;
                            } elseif ($line === 'n' || $line === 'no') {
                                Cursor::move('up');
                                Cursor::clear('line');
                                $this->cli->errorLine('SKIPPED');
                                file_put_contents($skippedFile, json_encode($item = [
                                        'file' => $file,
                                        'match' => $orig,
                                    ]) . "\n", FILE_APPEND);
                                $skipped[] = $item;
                                $this->cli->writeln(str_repeat('-', 100));
                                return;
                            } elseif ($line === 'm' || $line === 'more') {
                                Cursor::move('up');
                                Cursor::clear('line');
                                try {
                                    preg_replace_callback('~<script[^>]*>.*?</script>|<[^>]+>|\{.*?\}|'
                                        . '(.{0,200})(' . preg_quote($orig,
                                            '~') . ')(.{0,200})~siu', function ($match) {
                                        if (($match[2] ?? '') === '') {
                                            return;
                                        }
                                        $this->cli->write($match[1]);
                                        $this->cli->write($match[2], 'i');
                                        $this->cli->writeln($match[3]);
                                        $this->cli->writeln('');
                                        throw new InterruptedException;
                                    }, $source);
                                } catch (InterruptedException $e) {
                                    goto message;
                                }

                                goto readline;
                            }
                            Cursor::move('up');
                            Cursor::clear('line');
                            goto readline;
                        }, $cleanSource);
                } catch (InterruptedException $e) {
                    goto try_block;
                }
                if ($found) {
                    goto find;
                }
            }
        }
    }
}
