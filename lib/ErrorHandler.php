<?php

declare(strict_types=1);

namespace Slam\ErrorHandler;

use ErrorException;

final class ErrorHandler
{
    private $autoExit = true;
    private $cli;
    private $terminalWidth;
    private $hasColorSupport;

    private $emailCallback;
    private $exceptionTemplate;
    private $logErrors;
    private $displayExceptions;
    private $emailErrors;

    private static $colors = array(
        '<error>'   => "\033[37;41m",
        '</error>'  => "\033[0m",
    );

    private static $errors = array(
        E_ERROR                 => 'E_ERROR',
        E_WARNING               => 'E_WARNING',
        E_PARSE                 => 'E_PARSE',
        E_NOTICE                => 'E_NOTICE',
        E_CORE_ERROR            => 'E_CORE_ERROR',
        E_CORE_WARNING          => 'E_CORE_WARNING',
        E_COMPILE_ERROR         => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING       => 'E_COMPILE_WARNING',
        E_USER_ERROR            => 'E_USER_ERROR',
        E_USER_WARNING          => 'E_USER_WARNING',
        E_USER_NOTICE           => 'E_USER_NOTICE',
        E_STRICT                => 'E_STRICT',
        E_RECOVERABLE_ERROR     => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED            => 'E_DEPRECATED',
        E_USER_DEPRECATED       => 'E_USER_DEPRECATED',
    );

    public function __construct(callable $emailCallback, bool $displayExceptions = false, bool $logErrors = true, bool $emailErrors = true, string $exceptionTemplate = null)
    {
        $this->emailCallback = $emailCallback;
        $this->logErrors = $logErrors;
        $this->displayExceptions = $displayExceptions;
        $this->emailErrors = $emailErrors;
        $this->exceptionTemplate = $exceptionTemplate ?: dirname(__DIR__) . '/templates/exception.html';
    }

    public function setAutoExit(bool $autoExit)
    {
        $this->autoExit = $autoExit;
    }

    public function setCli(bool $cli)
    {
        $this->cli = $cli;
    }

    public function isCli()
    {
        if ($this->cli === null) {
            $this->setCli(PHP_SAPI === 'cli');
        }

        return $this->cli;
    }

    public function setTerminalWidth(int $terminalWidth)
    {
        $this->terminalWidth = $terminalWidth;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTerminalWidth()
    {
        if ($this->terminalWidth !== null) {
            return $this->terminalWidth;
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD') && $ansicon = getenv('ANSICON')) {
            return preg_replace('{^(\d+)x.*$}', '$1', $ansicon);
        }

        if (preg_match("{rows.(\d+);.columns.(\d+);}i", exec('stty -a | grep columns'), $match)) {
            return $match[2];
        }
    }

    public function register()
    {
        set_error_handler(array($this, 'errorHandler'), error_reporting());
        set_exception_handler(array($this, 'exceptionHandler'));
    }

    public function errorHandler($errno, $errstr = '', $errfile = '', $errline = 0)
    {
        // Controllo necessario per l'operatore @ di soppressione
        if (error_reporting() === 0) {
            return;
        }

        throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
    }

    public function exceptionHandler($exception)
    {
        $this->logException($exception);

        if ($this->isCli()) {
            try {
                $currentEx = $exception;
                do {
                    $width = $this->getTerminalWidth() ? $this->getTerminalWidth() - 3 : 120;
                    $lines = array(
                        'Message: ' . $currentEx->getMessage(),
                        '',
                        'Class: ' . get_class($currentEx),
                        'Code: ' . $this->getExceptionCode($currentEx),
                        'File: ' . $currentEx->getFile() . ':' . $currentEx->getLine(),
                    );
                    $lines = array_merge($lines, explode(PHP_EOL, self::purgeTrace($currentEx->getTraceAsString())));

                    $i = 0;
                    while (isset($lines[$i])) {
                        $line = $lines[$i];

                        if (isset($line[$width])) {
                            $lines[$i] = substr($line, 0, $width);
                            if (isset($line[0]) and $line[0] !== '#') {
                                array_splice($lines, $i + 1, 0, '   ' . substr($line, $width));
                            }
                        }

                        $i += 1;
                    }

                    $this->outputError(PHP_EOL);
                    $this->outputError(sprintf('<error> %s </error>', str_repeat(' ', $width)));
                    foreach ($lines as $line) {
                        $this->outputError(sprintf('<error> %s%s </error>', $line, str_repeat(' ', max(0, $width - strlen($line)))));
                    }
                    $this->outputError(sprintf('<error> %s </error>', str_repeat(' ', $width)));
                    $this->outputError(PHP_EOL);
                } while ($currentEx = $currentEx->getPrevious());
            } catch (\Throwable $e) {
                $this->logException($e);
            }

            $exitStatus = 255;
        } else {
            // @codeCoverageIgnoreStart
            if (! headers_sent()) {
                header('HTTP/1.1 500 Internal Server Error');
            }
            // @codeCoverageIgnoreEnd

            try {
                $template = $this->exceptionTemplate;
                $renderException = function ($exception, bool $ajax, bool $displayExceptions) use ($template) {
                    include $template;
                };

                $renderException(
                    $exception,
                    (isset($_SERVER) and isset($_SERVER['X_REQUESTED_WITH']) and $_SERVER['X_REQUESTED_WITH'] === 'XMLHttpRequest'),
                    $this->displayExceptions
                );
            } catch (\Throwable $e) {
                $this->logException($e);
            }
        }

        $this->emailException($exception);

        // @codeCoverageIgnoreStart
        if ($this->autoExit and isset($exitStatus)) {
            exit($exitStatus);
        }
        // @codeCoverageIgnoreEnd
    }

    private function outputError($text)
    {
        echo str_replace(array_keys(self::$colors), $this->hasColorSupport() ? array_values(self::$colors) : '', $text) . PHP_EOL;
    }

    private function hasColorSupport()
    {
        if ($this->hasColorSupport === null) {
            $this->hasColorSupport = function_exists('posix_isatty') && @posix_isatty($this->stream);
        }

        return $this->hasColorSupport;
    }

    public static function getExceptionCode($exception)
    {
        $code = $exception->getCode();
        if ($exception instanceof ErrorException and isset(static::$errors[$code])) {
            $code = static::$errors[$code];
        }

        return $code;
    }

    public function logError($msg)
    {
        if (! $this->logErrors) {
            return;
        }

        file_put_contents(ini_get('error_log'), sprintf('[%s] %s%s', date('d-M-Y H:i:s e'), $msg, PHP_EOL), FILE_APPEND);
    }

    public function logException($exception)
    {
        if (! $this->logErrors) {
            return;
        }

        $i = 0;
        do {
            $output = sprintf('%s%s: %s in %s:%s%s%s',
                ($i > 0 ? '{PR ' . $i . '} ' : ''),
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                PHP_EOL,
                self::purgeTrace($exception->getTraceAsString())
            );

            $this->logError($output);

            ++$i;
        } while ($exception = $exception->getPrevious());
    }

    public function emailError($subject, $bodyText)
    {
        if (! $this->emailErrors) {
            return;
        }

        $callback = $this->emailCallback;
        try {
            $callback($subject, $bodyText);
        } catch (\Throwable $e) {
            $this->logException($e);
        }
    }

    public function emailException($exception)
    {
        if (! $this->emailErrors) {
            return;
        }

        $bodyArray = array(
            'Data'          => date(DATE_RFC850),
            'REQUEST_URI'   => $_SERVER['REQUEST_URI'] ?? '',
            'HTTP_REFERER'  => $_SERVER['HTTP_REFERER'] ?? '',
            'USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'REMOTE_ADDR'   => $_SERVER['REMOTE_ADDR'] ?? '',
        );
        if ($this->isCli()) {
            $bodyArray = array(
                'Data'      => date(DATE_RFC850),
                'Comando'   => sprintf('$ php %s', implode(' ', $_SERVER['argv'])),
            );
        }

        $bodyText = '';
        foreach ($bodyArray as $key => $val) {
            $bodyText .= sprintf('%-15s%s%s', $key, $val, PHP_EOL);
        }

        $currentEx = $exception;
        do {
            $bodyArray = array(
                'Class'     => get_class($currentEx),
                'Code'      => $this->getExceptionCode($currentEx),
                'Message'   => $currentEx->getMessage(),
                'File'      => $currentEx->getFile() . ':' . $currentEx->getLine(),
            );

            foreach ($bodyArray as $key => $val) {
                $bodyText .= sprintf('%-15s%s%s', $key, $val, PHP_EOL);
            }

            $bodyText .= 'Stack trace:' . "\n\n" . self::purgeTrace($currentEx->getTraceAsString()) . "\n\n";
        } while ($currentEx = $currentEx->getPrevious());

        if (isset($_SESSION) and ! empty($_SESSION)) {
            $bodyText .= '$_SESSION = ' . print_r($_SESSION, true) . PHP_EOL;
        }
        if (isset($_POST) and ! empty($_POST)) {
            $bodyText .= '$_POST = ' . print_r($_POST, true) . PHP_EOL;
        }

        $subject = sprintf('Error: %s', $exception->getMessage());

        $this->emailError($subject, $bodyText);
    }

    public static function purgeTrace($trace)
    {
        return defined('ROOT_PATH') ? str_replace(ROOT_PATH, '.', $trace) : $trace;
    }
}
