<?php

namespace Zer0\Cli\Controllers;

use Gettext\Merge;
use Gettext\Translation;
use Gettext\Translations;
use Hoa\Console\Cursor;
use Hoa\Console\Readline\Readline;
use Peast\Formatter\Compact;
use Peast\Traverser;
use PhpParser\BuilderFactory;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Zer0\Cli\AbstractController;
use Zer0\Cli\Cli;
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

    /**
     * @var string
     */
    protected $command = 'i18n';

    /**
     * @var string
     */
    protected $skippedFile;

    /**
     * @var array
     */
    protected $skipped = [];

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

    /**
     * @param string $file
     * @param string $orig
     */
    public function addSkipped(string $file, string $orig)
    {
        $item = [
            'file' => $file,
            'match' => $orig,
        ];
        file_put_contents($this->skippedFile, json_encode($item) . "\n", FILE_APPEND);
        $this->skipped[] = $item;
    }

    /**
     *
     */
    public function forceAction(): void
    {
        $this->skippedFile = $_SERVER['HOME'] . '/.skipped-i18n';

        if (is_file($this->skippedFile)) {
            $this->skipped = array_map(function ($line) {
                return json_decode($line, true);
            }, explode("\n", file_get_contents($this->skippedFile)));
        } else {
            $this->skipped = [];
        }

        $rl = new Readline;
        foreach (explode("\n", shell_exec(
            'find src -name \'*.php\';'
            //. ' find src -name \'*.tpl\';'
            . ' find public/js -name \'*.js\''
        )) as $file) {
            if ($file === '') {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $source = file_get_contents($file);
            if ($extension === 'js') {
                if (preg_match('~\.min\b~i', $file)) {
                    continue;
                }
                start:
                $ast = \Peast\Peast::latest($source, [])->parse();
                $traverser = new \Peast\Traverser;
                $changed = false;
                $traverser->addFunction(function (\Peast\Syntax\Node\Node $node) use (&$source, $rl, $file, &$changed) {
                    $type = $node->getType();
                    if ($type === 'CallExpression') {
                        $callee = $node->getCallee();
                        if ($callee->getType() === 'Identifier') {
                            if ($callee->getName() === '_') {
                                return Traverser::DONT_TRAVERSE_CHILD_NODES;
                            }
                        }
                    } elseif ($type === 'Literal') {
                        $orig = $node->getValue();
                        $isUtf = mb_strlen($orig, 'utf-8') != strlen($orig);
                        if (!$isUtf) { //&& !preg_match('~\s~', $orig)
                            return;
                        }

                        $newlines = 0;
                        message:
                        for (; $newlines > 0; --$newlines) {
                            Cursor::move('up');
                            Cursor::clear('line');
                        }
                        $message = "Do you want to replace '$orig' in file $file?";
                        $newlines = substr_count($message, "\n") + 1;

                        $this->cli->writeln($message);
                        readline:

                        $line = strtolower($rl->readLine('(y)es/(n)o/(m)ore: '));
                        if ($line === 'y' || $line === 'yes') {
                            Cursor::move('up');
                            Cursor::clear('line');

                            $location = $node->getLocation();
                            $source = mb_substr($source, 0, $location->getStart()->getIndex())
                                . '_(' . json_encode($orig, JSON_UNESCAPED_UNICODE) . ')'
                                . mb_substr($source, $location->getEnd()->getIndex());
                            file_put_contents($file, $source);
                            $this->cli->successLine('REPLACED');
                            $this->cli->writeln(str_repeat('-', 100));
                            $changed = true;
                            return Traverser::STOP_TRAVERSING;
                        } elseif ($line === 'n' || $line === 'no') {
                            Cursor::move('up');
                            Cursor::clear('line');
                            $this->cli->errorLine('SKIPPED');
                            $this->addSkipped($file, $orig);
                            $this->cli->writeln(str_repeat('-', 100));
                        } elseif ($line === 'm' || $line === 'more') {
                            goto readline;
                        } elseif ($line === 'q') {
                            exit;
                        }
                    }
                });
                $traverser->traverse($ast);
                if ($changed) {
                    goto start;
                }
                //$renderer = new \Peast\Renderer;
                //$renderer->setFormatter(new \Peast\Formatter\PrettyPrint);
            } elseif ($extension === 'php') {
                $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
                try {
                    $ast = $parser->parse($source);
                } catch (Error $error) {
                    echo "Parse error: {$error->getMessage()}\n";
                    return;
                }

                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor = new class(new BuilderFactory, $this->cli, $this) extends NodeVisitorAbstract
                {

                    /**
                     * @var bool
                     */
                    public $changed = false;

                    /**
                     * @var BuilderFactory
                     */
                    protected $factory;

                    /**
                     * @var Readline
                     */
                    protected $rl;

                    /**
                     * @var Cli
                     */
                    protected $cli;

                    /**
                     * @var I18n
                     */
                    protected $controller;

                    /**
                     *  constructor.
                     * @param BuilderFactory $factory
                     */
                    public function __construct(BuilderFactory $factory, Cli $cli, I18n $controller)
                    {
                        $this->factory = $factory;
                        $this->rl = new Readline;
                        $this->cli = $cli;
                        $this->controller = $controller;
                    }

                    /**
                     * @param Node $node
                     * @return int|null|Node|Node[]|Node\Expr\FuncCall
                     */
                    public function leaveNode(Node $node)
                    {
                        if ($node instanceof Node\Stmt\Expression) {
                            // Clean out the function body
                        }
                        if ($node instanceof Node\Stmt\Expression
                            && $node->expr instanceof Node\Expr\FuncCall
                            && $node->expr->name instanceof Node\Name
                            && $node->expr->name->toString() === '__'
                        ) {
                            return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                        }
                        if ($node instanceof Node\Scalar\String_) {
                            $orig = $node->value;
                            $isUtf = mb_strlen($orig, 'utf-8') != strlen($orig);
                            if (!$isUtf) { //&& !preg_match('~\s~', $orig)
                                return;
                            }

                            $newlines = 0;
                            message:
                            for (; $newlines > 0; --$newlines) {
                                Cursor::move('up');
                                Cursor::clear('line');
                            }
                            $message = "Do you want to replace " . $orig . " in file " . $file;
                            $newlines = substr_count($message, "\n") + 1;

                            $this->cli->writeln($message);
                            readline:

                            $line = strtolower($this->rl->readLine('(y)es/(n)o/(m)ore: '));
                            if ($line === 'y' || $line === 'yes') {
                                Cursor::move('up');
                                Cursor::clear('line');
                                $this->changed = true;
                                return $this->factory->funcCall('__', [$node->value]);
                            } elseif ($line === 'n' || $line === 'no') {
                                Cursor::move('up');
                                Cursor::clear('line');
                                $this->cli->errorLine('SKIPPED');
                                $this->controller->addSkipped($file, $orig);
                                $this->cli->writeln(str_repeat('-', 100));
                                return;
                            } elseif ($line === 'm' || $line === 'more') {
                                goto readline;
                            } elseif ($line === 'q') {
                                exit;
                            }
                        }
                    }
                });
                if ($visitor->changed) {
                    $ast = $traverser->traverse($ast);
                    $prettyPrinter = new PrettyPrinter\Standard;
                    file_put_contents($file, $prettyPrinter->prettyPrintFile($ast));
                    $this->cli->successLine('REPLACED');
                }

            } elseif ($extension === 'tpl') {
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
                        function ($match) use ($rl, &$found, &$source, $file, &$replaced) {
                            if (($match[2] ?? '') === '') {
                                return;
                            }
                            $orig = trim($match[2]);
                            if (in_array($orig, $replaced)) {
                                return;
                            }
                            foreach ($this->skipped as $item) {
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
                                $this->addSkipped($file, $orig);
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
                            } elseif ($line === 'q') {
                                exit;
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
