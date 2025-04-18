<?php

declare(strict_types=1);

namespace Slam\ErrorHandler;

use Doctrine\Common\Util\Debug as DoctrineDebug;
use ErrorException;
use PHPUnit\Framework\Test;
use Throwable;

final class ErrorHandler
{
    private const COLORS = [
        '<error>'   => "\033[37;41m",
        '</error>'  => "\033[0m",
    ];

    private const ERRORS = [
        \E_COMPILE_ERROR        => 'E_COMPILE_ERROR',
        \E_COMPILE_WARNING      => 'E_COMPILE_WARNING',
        \E_CORE_ERROR           => 'E_CORE_ERROR',
        \E_CORE_WARNING         => 'E_CORE_WARNING',
        \E_DEPRECATED           => 'E_DEPRECATED',
        \E_ERROR                => 'E_ERROR',
        \E_NOTICE               => 'E_NOTICE',
        \E_PARSE                => 'E_PARSE',
        \E_RECOVERABLE_ERROR    => 'E_RECOVERABLE_ERROR',
        \E_USER_DEPRECATED      => 'E_USER_DEPRECATED',
        \E_USER_ERROR           => 'E_USER_ERROR',
        \E_USER_NOTICE          => 'E_USER_NOTICE',
        \E_USER_WARNING         => 'E_USER_WARNING',
        \E_WARNING              => 'E_WARNING',
    ];

    private bool $autoExit      = true;
    private ?bool $cli          = null;
    private ?int $terminalWidth = null;

    /** @var null|resource */
    private $errorOutputStream;
    private bool $hasColorSupport = false;
    private ?bool $logErrors      = null;
    private bool $logVariables    = true;
    private ?bool $displayErrors  = null;

    /** @var callable */
    private $emailCallback;

    /** @var callable */
    private $errorLogCallback = '\error_log';

    /** @var array<int, bool> */
    private array $scream = [];

    /** @var array<int, class-string<Throwable>> */
    private array $exceptionsTypesFor404 = [];

    private bool $shouldEmail404Exceptions = true;

    public function __construct(callable $emailCallback)
    {
        $this->emailCallback = $emailCallback;
    }

    public function setErrorLogCallback(callable $callback): void
    {
        $this->errorLogCallback = $callback;
    }

    public function getErrorLogCallback(): callable
    {
        return $this->errorLogCallback;
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
            $width = (int) \getenv('COLUMNS');
            if (0 === $width && 1 === \preg_match('{rows.(\d+);.columns.(\d+);}i', (string) \exec('stty -a 2> /dev/null | grep columns'), $match)) {
                $width = (int) $match[2]; // @codeCoverageIgnore
            }
            if (0 === $width) {
                $width = 80; // @codeCoverageIgnore
            }

            $this->setTerminalWidth($width);
            \assert(null !== $this->terminalWidth);
        }

        return $this->terminalWidth;
    }

    /** @param mixed $errorOutputStream */
    public function setErrorOutputStream($errorOutputStream): void
    {
        if (! \is_resource($errorOutputStream)) {
            return;
        }

        $this->errorOutputStream = $errorOutputStream;
        $this->hasColorSupport   = (\function_exists('posix_isatty') && @\posix_isatty($errorOutputStream));
    }

    /** @return resource */
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
            $this->setLogErrors(! \interface_exists(Test::class));
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

    /** @param array<int, bool> $scream */
    public function setScreamSilencedErrors(array $scream): void
    {
        $this->scream = $scream;
    }

    /** @return array<int, bool> */
    public function getScreamSilencedErrors(): array
    {
        return $this->scream;
    }

    public function register(): void
    {
        \set_error_handler([$this, 'errorHandler'], \error_reporting());
        \set_exception_handler([$this, 'exceptionHandler']);
    }

    public function errorHandler(int $errno, string $errstr = '', string $errfile = '', int $errline = 0): bool
    {
        // Mandatory check for @ operator
        if (0 === (\error_reporting() & $errno) && ! isset($this->scream[$errno])) {
            return true;
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
                $width = 0 !== $this->getTerminalWidth() ? $this->getTerminalWidth() - 3 : 120;
                $lines = [
                    'Message: ' . $currentEx->getMessage(),
                    '',
                    'Class: ' . $currentEx::class,
                    'Code: ' . $this->getExceptionCode($currentEx),
                    'File: ' . $currentEx->getFile() . ':' . $currentEx->getLine(),
                ];
                $lines = \array_merge($lines, \explode(\PHP_EOL, $this->purgeTrace($currentEx->getTraceAsString())));

                $i = 0;
                while (isset($lines[$i])) {
                    $line = $lines[$i];

                    if (isset($line[$width])) {
                        $lines[$i] = \substr($line, 0, $width);
                        if (isset($line[0]) && '#' !== $line[0]) {
                            \array_splice($lines, $i + 1, 0, '   ' . \substr($line, $width));
                        }
                    }

                    ++$i;
                }

                $this->outputError(\PHP_EOL);
                $this->outputError(\sprintf('<error> %s </error>', \str_repeat(' ', $width)));
                foreach ($lines as $line2) {
                    $this->outputError(\sprintf('<error> %s%s </error>', $line2, \str_repeat(' ', \max(0, $width - \strlen($line2)))));
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
            $header = 'HTTP/1.1 500 Internal Server Error';
            if (\in_array($exception::class, $this->exceptionsTypesFor404, true)) {
                $header = 'HTTP/1.1 404 Not Found';
            }
            \header($header);
        }
        // @codeCoverageIgnoreEnd

        echo $this->renderHtmlException($exception);
    }

    public function renderHtmlException(Throwable $exception): string
    {
        $ajax      = (isset($_SERVER['X_REQUESTED_WITH']) && 'XMLHttpRequest' === $_SERVER['X_REQUESTED_WITH']);
        $output    = '';
        $errorType = '500: Internal Server Error';
        if (\in_array($exception::class, $this->exceptionsTypesFor404, true)) {
            $errorType = '404: Not Found';
        }
        if (! $ajax) {
            $output .= \sprintf('<!DOCTYPE html><html><head><title>%s</title></head><body>', $errorType);
        }
        $output .= \sprintf('<h1>%s</h1>', $errorType);
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
                    $currentEx::class,
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
        \fwrite($this->getErrorOutputStream(), \str_replace(
            \array_keys(self::COLORS),
            $this->hasColorSupport ? \array_values(self::COLORS) : '',
            $text
        ) . \PHP_EOL);
    }

    public function logException(Throwable $exception): void
    {
        if (! $this->logErrors()) {
            return;
        }

        $errorLogCallback = $this->errorLogCallback;

        $i = 0;
        do {
            $output = \sprintf(
                '%s%s: %s in %s:%s%s%s',
                $i > 0 ? '{PR ' . $i . '} ' : '',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                \PHP_EOL,
                $this->purgeTrace($exception->getTraceAsString())
            );

            $errorLogCallback($output);

            ++$i;
        } while ($exception = $exception->getPrevious());
    }

    public function emailException(Throwable $exception): void
    {
        if (
            ! $this->logErrors()
            || (
                ! $this->shouldEmail404Exceptions
                && \in_array($exception::class, $this->exceptionsTypesFor404, true)
            )
        ) {
            return;
        }

        $bodyArray = [
            'Date'          => \date(\DATE_RFC850),
            'REQUEST_URI'   => $_SERVER['REQUEST_URI']     ?? '',
            'HTTP_REFERER'  => $_SERVER['HTTP_REFERER']    ?? '',
            'USER_AGENT'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'REMOTE_ADDR'   => $_SERVER['REMOTE_ADDR']     ?? '',
        ];
        if ($this->isCli()) {
            $bodyArray = [
                'Date'      => \date(\DATE_RFC850),
                'Command'   => \sprintf('$ %s %s', \PHP_BINARY, \implode(' ', $_SERVER['argv'])),
            ];
        }

        $bodyText = '';
        foreach ($bodyArray as $key => $val) {
            $bodyText .= \sprintf('%-15s%s%s', $key, $val, \PHP_EOL);
        }

        $currentEx = $exception;
        do {
            $bodyArray = [
                'Class'     => $currentEx::class,
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
            if ([] !== $_POST) {
                $bodyText .= '$_POST = ' . \print_r($_POST, true) . \PHP_EOL;
            }
            if (isset($_SESSION) && [] !== $_SESSION) {
                $sessionText = \print_r(\class_exists(DoctrineDebug::class) ? DoctrineDebug::export($_SESSION, 4) : $_SESSION, true);
                $bodyText .= '$_SESSION = ' . $sessionText . \PHP_EOL;

                $count    = 0;
                $username = \preg_replace('/.+\[([^\]]+)?username([^\]]+)?\] => ([\w\-\.]+).+/s', '\3', $sessionText, -1, $count);
                if (! isset($username[0]) || isset($username[255]) || 1 !== $count) {
                    $username = null;
                }
            }
        }

        $subject = \sprintf(
            'Error%s: %s',
            null !== $username ? \sprintf(' [%s]', $username) : '',
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
        if ($exception instanceof ErrorException && isset(self::ERRORS[$code])) {
            $code = self::ERRORS[$code];
        }

        return (string) $code;
    }

    private function purgeTrace(string $trace): string
    {
        return \defined('ROOT_PATH') ? \str_replace(ROOT_PATH, '.', $trace) : $trace;
    }

    /** @param array<int, class-string<Throwable>> $exceptionsTypesFor404 */
    public function set404ExceptionTypes(array $exceptionsTypesFor404): void
    {
        $this->exceptionsTypesFor404 = $exceptionsTypesFor404;
    }

    /** @return array<int, class-string<Throwable>> */
    public function get404ExceptionTypes(): array
    {
        return $this->exceptionsTypesFor404;
    }

    public function setShouldEmail404Exceptions(bool $shouldEmail404Exceptions): void
    {
        $this->shouldEmail404Exceptions = $shouldEmail404Exceptions;
    }

    public function shouldEmail404Exceptions(): bool
    {
        return $this->shouldEmail404Exceptions;
    }
}
