<?php

declare(strict_types=1);

namespace Slam\ErrorHandler;

use Doctrine\Common\Util\Debug as DoctrineDebug;
use ErrorException;
use Throwable;

final class ErrorHandler
{
    /**
     * @var bool
     */
    private $autoExit = true;

    /**
     * @var null|bool
     */
    private $cli;

    /**
     * @var null|int
     */
    private $terminalWidth;

    /**
     * @var null|resource
     */
    private $errorOutputStream;

    /**
     * @var bool
     */
    private $hasColorSupport = false;

    /**
     * @var null|bool
     */
    private $logErrors;

    /**
     * @var bool
     */
    private $logVariables = true;

    /**
     * @var null|bool
     */
    private $displayErrors;

    /**
     * @var callable
     */
    private $emailCallback;

    /**
     * @var array<int, bool>
     */
    private $scream = [];

    /**
     * @var array<string, string>
     */
    private static $colors = [
        '<error>'   => "\033[37;41m",
        '</error>'  => "\033[0m",
    ];

    /**
     * @var array<int, string>
     */
    private static $errors = [
        \E_COMPILE_ERROR        => 'E_COMPILE_ERROR',
        \E_COMPILE_WARNING      => 'E_COMPILE_WARNING',
        \E_CORE_ERROR           => 'E_CORE_ERROR',
        \E_CORE_WARNING         => 'E_CORE_WARNING',
        \E_DEPRECATED           => 'E_DEPRECATED',
        \E_ERROR                => 'E_ERROR',
        \E_NOTICE               => 'E_NOTICE',
        \E_PARSE                => 'E_PARSE',
        \E_RECOVERABLE_ERROR    => 'E_RECOVERABLE_ERROR',
        \E_STRICT               => 'E_STRICT',
        \E_USER_DEPRECATED      => 'E_USER_DEPRECATED',
        \E_USER_ERROR           => 'E_USER_ERROR',
        \E_USER_NOTICE          => 'E_USER_NOTICE',
        \E_USER_WARNING         => 'E_USER_WARNING',
        \E_WARNING              => 'E_WARNING',
    ];

    public function __construct(callable $emailCallback)
    {
        $this->emailCallback = $emailCallback;
    }

    public function setAutoExit(bool $autoExit): void
    {
        $this->autoExit = $autoExit;
    }

    public function autoExit(): bool
    {
        return $this->autoExit;
    }

    public function setCli(bool $cli): void
    {
        $this->cli = $cli;
    }

    public function isCli(): bool
    {
        if (null === $this->cli) {
            $this->setCli(\PHP_SAPI === 'cli');
            \assert(null !== $this->cli);
        }

        return $this->cli;
    }

    public function setTerminalWidth(int $terminalWidth): void
    {
        $this->terminalWidth = $terminalWidth;
    }

    public function getTerminalWidth(): int
    {
        if (null === $this->terminalWidth) {
            $width = \getenv('COLUMNS');

            if (false === $width && 1 === \preg_match('{rows.(\d+);.columns.(\d+);}i', \exec('stty -a 2> /dev/null | grep columns'), $match)) {
                $width = $match[2];
            }

            $this->setTerminalWidth((int) $width ?: 80);
            \assert(null !== $this->terminalWidth);
        }

        return $this->terminalWidth;
    }

    /**
     * @param mixed $errorOutputStream
     */
    public function setErrorOutputStream($errorOutputStream): void
    {
        if (! \is_resource($errorOutputStream)) {
            return;
        }

        $this->errorOutputStream = $errorOutputStream;
        $this->hasColorSupport   = (\function_exists('posix_isatty') && @\posix_isatty($errorOutputStream));
    }

    /**
     * @return resource
     */
    public function getErrorOutputStream()
    {
        if (null === $this->errorOutputStream) {
            $this->setErrorOutputStream(\STDERR);
            \assert(null !== $this->errorOutputStream);
        }

        return $this->errorOutputStream;
    }

    public function setLogErrors(bool $logErrors): void
    {
        $this->logErrors = $logErrors;
    }

    public function logErrors(): bool
    {
        if (null === $this->logErrors) {
            $this->setLogErrors(! \interface_exists(\PHPUnit\Framework\Test::class));
            \assert(null !== $this->logErrors);
        }

        return $this->logErrors;
    }

    public function setLogVariables(bool $logVariables): void
    {
        $this->logVariables = $logVariables;
    }

    public function logVariables(): bool
    {
        return $this->logVariables;
    }

    public function setDisplayErrors(bool $displayErrors): void
    {
        $this->displayErrors = $displayErrors;
    }

    public function displayErrors(): bool
    {
        if (null === $this->displayErrors) {
            $this->setDisplayErrors((bool) \ini_get('display_errors'));
            \assert(null !== $this->displayErrors);
        }

        return $this->displayErrors;
    }

    /**
     * @param array<int, bool> $scream
     */
    public function setScreamSilencedErrors(array $scream): void
    {
        $this->scream = $scream;
    }

    /**
     * @return array<int, bool>
     */
    public function getScreamSilencedErrors(): array
    {
        return $this->scream;
    }

    public function register(): void
    {
        \set_error_handler([$this, 'errorHandler'], \error_reporting());
        \set_exception_handler([$this, 'exceptionHandler']);
    }

    /**
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     */
    public function errorHandler($errno, $errstr = '', $errfile = '', $errline = 0): void
    {
        // Mandatory check for @ operator
        if (0 === \error_reporting() && ! isset($this->scream[$errno])) {
            return;
        }

        throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
    }

    public function exceptionHandler(Throwable $exception): void
    {
        $this->logException($exception);
        $this->emailException($exception);

        if ($this->isCli()) {
            $currentEx = $exception;
            do {
                $width = $this->getTerminalWidth() ? $this->getTerminalWidth() - 3 : 120;
                $lines = [
                    'Message: ' . $currentEx->getMessage(),
                    '',
                    'Class: ' . \get_class($currentEx),
                    'Code: ' . $this->getExceptionCode($currentEx),
                    'File: ' . $currentEx->getFile() . ':' . $currentEx->getLine(),
                ];
                $lines = \array_merge($lines, \explode(\PHP_EOL, $this->purgeTrace($currentEx->getTraceAsString())));

                $i = 0;
                while (isset($lines[$i])) {
                    $line = $lines[$i];

                    if (isset($line[$width])) {
                        $lines[$i] = \mb_substr($line, 0, $width);
                        if (isset($line[0]) && '#' !== $line[0]) {
                            \array_splice($lines, $i + 1, 0, '   ' . \mb_substr($line, $width));
                        }
                    }

                    ++$i;
                }

                $this->outputError(\PHP_EOL);
                $this->outputError(\sprintf('<error> %s </error>', \str_repeat(' ', $width)));
                foreach ($lines as $line) {
                    $this->outputError(\sprintf('<error> %s%s </error>', $line, \str_repeat(' ', \max(0, $width - \mb_strlen($line)))));
                }
                $this->outputError(\sprintf('<error> %s </error>', \str_repeat(' ', $width)));
                $this->outputError(\PHP_EOL);
            } while ($currentEx = $currentEx->getPrevious());

            // @codeCoverageIgnoreStart
            if ($this->autoExit()) {
                exit(255);
            }
            // @codeCoverageIgnoreEnd

            return;
        }

        // @codeCoverageIgnoreStart
        if (! \headers_sent()) {
            \header('HTTP/1.1 500 Internal Server Error');
        }
        // @codeCoverageIgnoreEnd

        echo $this->renderHtmlException($exception);
    }

    public function renderHtmlException(Throwable $exception): string
    {
        $ajax   = (isset($_SERVER) && isset($_SERVER['X_REQUESTED_WITH']) && 'XMLHttpRequest' === $_SERVER['X_REQUESTED_WITH']);
        $output = '';
        if (! $ajax) {
            $output .= '<!DOCTYPE html><html><head><title>500: Internal Server Error</title></head><body>';
        }
        $output .= '<h1>500: Internal Server Error</h1>';
        $output .= \PHP_EOL;
        if ($this->displayErrors()) {
            $currentEx = $exception;
            do {
                $output .= \sprintf(
                    '<div style="background-color: #FCC; border: 1px solid #600; color: #600; margin: 1em 0; padding: .33em 6px 0">'
                        . '<b>Message:</b> %s<br />'
                        . '<br />'
                        . '<b>Class:</b> %s<br />'
                        . '<b>Code:</b> %s<br />'
                        . '<b>File:</b> %s:%s<br />'
                        . '<b>Stack trace:</b><pre>%s</pre>'
                    . '</div>%s',
                    \htmlspecialchars($currentEx->getMessage()),
                    \get_class($currentEx),
                    $this->getExceptionCode($currentEx),
                    $currentEx->getFile(),
                    $currentEx->getLine(),
                    \htmlspecialchars($this->purgeTrace($currentEx->getTraceAsString())),
                    \PHP_EOL
                );
            } while ($currentEx = $currentEx->getPrevious());
        }
        if (! $ajax) {
            $output .= '</body></html>';
        }

        return $output;
    }

    private function outputError(string $text): void
    {
        \fwrite($this->getErrorOutputStream(), \str_replace(\array_keys(self::$colors), $this->hasColorSupport ? \array_values(self::$colors) : '', $text) . \PHP_EOL);
    }

    public function logException(Throwable $exception): void
    {
        if (! $this->logErrors()) {
            return;
        }

        $i = 0;
        do {
            $output = \sprintf('%s%s: %s in %s:%s%s%s',
                ($i > 0 ? '{PR ' . $i . '} ' : ''),
                \get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                \PHP_EOL,
                $this->purgeTrace($exception->getTraceAsString())
            );

            \error_log($output);

            ++$i;
        } while ($exception = $exception->getPrevious());
    }

    public function emailException(Throwable $exception): void
    {
        if (! $this->logErrors()) {
            return;
        }

        $bodyArray = [
            'Data'          => \date(\DATE_RFC850),
            'REQUEST_URI'   => $_SERVER['REQUEST_URI'] ?? '',
            'HTTP_REFERER'  => $_SERVER['HTTP_REFERER'] ?? '',
            'USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'REMOTE_ADDR'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        if ($this->isCli()) {
            $bodyArray = [
                'Data'      => \date(\DATE_RFC850),
                'Comando'   => \sprintf('$ php %s', \implode(' ', $_SERVER['argv'])),
            ];
        }

        $bodyText = '';
        foreach ($bodyArray as $key => $val) {
            $bodyText .= \sprintf('%-15s%s%s', $key, $val, \PHP_EOL);
        }

        $currentEx = $exception;
        do {
            $bodyArray = [
                'Class'     => \get_class($currentEx),
                'Code'      => $this->getExceptionCode($currentEx),
                'Message'   => $currentEx->getMessage(),
                'File'      => $currentEx->getFile() . ':' . $currentEx->getLine(),
            ];

            foreach ($bodyArray as $key => $val) {
                $bodyText .= \sprintf('%-15s%s%s', $key, $val, \PHP_EOL);
            }

            $bodyText .= 'Stack trace:' . "\n\n" . $this->purgeTrace($currentEx->getTraceAsString()) . "\n\n";
        } while ($currentEx = $currentEx->getPrevious());

        $username = null;

        if ($this->logVariables()) {
            if (isset($_POST) && ! empty($_POST)) {
                $bodyText .= '$_POST = ' . \print_r($_POST, true) . \PHP_EOL;
            }
            if (isset($_SESSION) && ! empty($_SESSION)) {
                $sessionText = \print_r(\class_exists(DoctrineDebug::class) ? DoctrineDebug::export($_SESSION, 4) : $_SESSION, true);
                $bodyText .= '$_SESSION = ' . $sessionText . \PHP_EOL;

                $count    = 0;
                $username = \preg_replace('/.+\[([^\]]+)?username([^\]]+)?\] => ([\w\-\.]+).+/s', '\3', $sessionText, -1, $count);
                if (! isset($username[0]) || isset($username[255]) || 1 !== $count) {
                    $username = null;
                }
            }
        }

        $subject = \sprintf('Error%s: %s',
            $username ? \sprintf(' [%s]', $username) : '',
            $exception->getMessage()
        );

        $callback = $this->emailCallback;

        try {
            $callback($subject, $bodyText);
        } catch (Throwable $e) {
            $this->logException($e);
        }
    }

    private function getExceptionCode(Throwable $exception): string
    {
        $code = $exception->getCode();
        if ($exception instanceof ErrorException && isset(self::$errors[$code])) {
            $code = self::$errors[$code];
        }

        return (string) $code;
    }

    private function purgeTrace(string $trace): string
    {
        return \defined('ROOT_PATH') ? \str_replace(ROOT_PATH, '.', $trace) : $trace;
    }
}
