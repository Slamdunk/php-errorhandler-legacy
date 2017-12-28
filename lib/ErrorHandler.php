<?php

declare(strict_types=1);

namespace Slam\ErrorHandler;

use Doctrine\Common\Util\Debug as DoctrineDebug;

final class ErrorHandler
{
    private $autoExit = true;
    private $cli;
    private $terminalWidth;
    private $errorOutputStream;
    private $hasColorSupport = false;
    private $logErrors;
    private $logVariables = true;
    private $emailCallback;

    private static $colors = array(
        '<error>'   => "\033[37;41m",
        '</error>'  => "\033[0m",
    );

    private static $errors = array(
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
    );

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

            // @codeCoverageIgnoreStart
            if (\defined('PHP_WINDOWS_VERSION_BUILD') and $ansicon = \getenv('ANSICON')) {
                $width = \preg_replace('{^(\d+)x.*$}', '$1', $ansicon);
            }
            // @codeCoverageIgnoreEnd

            if (\preg_match('{rows.(\d+);.columns.(\d+);}i', \exec('stty -a 2> /dev/null | grep columns'), $match)) {
                $width = $match[2];
            }

            $this->setTerminalWidth((int) $width ?: 80);
        }

        return $this->terminalWidth;
    }

    public function setErrorOutputStream($errorOutputStream): void
    {
        if (! \is_resource($errorOutputStream)) {
            return;
        }

        $this->errorOutputStream = $errorOutputStream;
        $this->hasColorSupport = (\function_exists('posix_isatty') and @\posix_isatty($errorOutputStream));
    }

    public function getErrorOutputStream()
    {
        if (null === $this->errorOutputStream) {
            $this->setErrorOutputStream(\STDERR);
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

    public function register(): void
    {
        \set_error_handler(array($this, 'errorHandler'), \error_reporting());
        \set_exception_handler(array($this, 'exceptionHandler'));
    }

    public function errorHandler($errno, $errstr = '', $errfile = '', $errline = 0): void
    {
        // Mandatory check for @ operator
        if (0 === \error_reporting()) {
            return;
        }

        throw new \ErrorException($errstr, $errno, $errno, $errfile, $errline);
    }

    public function exceptionHandler(\Throwable $exception): void
    {
        $this->logException($exception);
        $this->emailException($exception);

        if ($this->isCli()) {
            $currentEx = $exception;
            do {
                $width = $this->getTerminalWidth() ? $this->getTerminalWidth() - 3 : 120;
                $lines = array(
                    'Message: ' . $currentEx->getMessage(),
                    '',
                    'Class: ' . \get_class($currentEx),
                    'Code: ' . $this->getExceptionCode($currentEx),
                    'File: ' . $currentEx->getFile() . ':' . $currentEx->getLine(),
                );
                $lines = \array_merge($lines, \explode(\PHP_EOL, $this->purgeTrace($currentEx->getTraceAsString())));

                $i = 0;
                while (isset($lines[$i])) {
                    $line = $lines[$i];

                    if (isset($line[$width])) {
                        $lines[$i] = \mb_substr($line, 0, $width);
                        if (isset($line[0]) and '#' !== $line[0]) {
                            \array_splice($lines, $i + 1, 0, '   ' . \mb_substr($line, $width));
                        }
                    }

                    $i += 1;
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

        $ajax = (isset($_SERVER) and isset($_SERVER['X_REQUESTED_WITH']) and 'XMLHttpRequest' === $_SERVER['X_REQUESTED_WITH']);
        $output = '';
        if (! $ajax) {
            $output .= '<!DOCTYPE html><html><head><title>500: Errore interno</title></head><body>';
        }
        $output .= '<h1>500: Errore interno</h1>';
        $output .= \PHP_EOL;
        if (true === (bool) \ini_get('display_errors')) {
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

        echo $output;
    }

    private function outputError(string $text): void
    {
        \fwrite($this->getErrorOutputStream(), \str_replace(\array_keys(self::$colors), $this->hasColorSupport ? \array_values(self::$colors) : '', $text) . \PHP_EOL);
    }

    public function logException(\Throwable $exception): void
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

    public function emailException(\Throwable $exception): void
    {
        if (! $this->logErrors()) {
            return;
        }

        $bodyArray = array(
            'Data'          => \date(\DATE_RFC850),
            'REQUEST_URI'   => $_SERVER['REQUEST_URI'] ?? '',
            'HTTP_REFERER'  => $_SERVER['HTTP_REFERER'] ?? '',
            'USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'REMOTE_ADDR'   => $_SERVER['REMOTE_ADDR'] ?? '',
        );
        if ($this->isCli()) {
            $bodyArray = array(
                'Data'      => \date(\DATE_RFC850),
                'Comando'   => \sprintf('$ php %s', \implode(' ', $_SERVER['argv'])),
            );
        }

        $bodyText = '';
        foreach ($bodyArray as $key => $val) {
            $bodyText .= \sprintf('%-15s%s%s', $key, $val, \PHP_EOL);
        }

        $currentEx = $exception;
        do {
            $bodyArray = array(
                'Class'     => \get_class($currentEx),
                'Code'      => $this->getExceptionCode($currentEx),
                'Message'   => $currentEx->getMessage(),
                'File'      => $currentEx->getFile() . ':' . $currentEx->getLine(),
            );

            foreach ($bodyArray as $key => $val) {
                $bodyText .= \sprintf('%-15s%s%s', $key, $val, \PHP_EOL);
            }

            $bodyText .= 'Stack trace:' . "\n\n" . $this->purgeTrace($currentEx->getTraceAsString()) . "\n\n";
        } while ($currentEx = $currentEx->getPrevious());

        $username = null;

        if ($this->logVariables()) {
            if (isset($_POST) and ! empty($_POST)) {
                $bodyText .= '$_POST = ' . \print_r($_POST, true) . \PHP_EOL;
            }
            if (isset($_SESSION) and ! empty($_SESSION)) {
                $sessionText = \print_r(\class_exists(DoctrineDebug::class) ? DoctrineDebug::export($_SESSION, 4) : $_SESSION, true);
                $bodyText .= '$_SESSION = ' . $sessionText . \PHP_EOL;

                $count = 0;
                $username = \preg_replace('/.+\[([^\]]+)?username([^\]]+)?\] => ([\w\-\.]+).+/s', '\3', $sessionText, -1, $count);
                if (! isset($username[0]) or isset($username[255]) or 1 !== $count) {
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
        } catch (\Throwable $e) {
            $this->logException($e);
        }
    }

    private function getExceptionCode(\Throwable $exception): string
    {
        $code = $exception->getCode();
        if ($exception instanceof \ErrorException and isset(static::$errors[$code])) {
            $code = static::$errors[$code];
        }

        return (string) $code;
    }

    private function purgeTrace(string $trace): string
    {
        return \defined('ROOT_PATH') ? \str_replace(ROOT_PATH, '.', $trace) : $trace;
    }
}
