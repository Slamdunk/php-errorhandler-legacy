<?php

declare(strict_types=1);

namespace SlamTest\ErrorHandler;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Slam\ErrorHandler\ErrorHandler;
use Symfony\Component\Console\Terminal;

final class ErrorHandlerTest extends TestCase
{
    private $backupErrorLog;
    private $errorLog;
    private $exception;
    private $emailsSent;
    private $errorHandler;

    protected function setUp(): void
    {
        \ini_set('display_errors', (string) false);
        $this->backupErrorLog = \ini_get('error_log');
        $this->errorLog       = __DIR__ . \DIRECTORY_SEPARATOR . 'error_log_test';
        \touch($this->errorLog);
        \ini_set('error_log', $this->errorLog);

        $this->exception    = new ErrorException(\uniqid('normal_'), \E_USER_NOTICE);
        $this->emailsSent   = [];
        $this->errorHandler = new ErrorHandler(function (string $subject, string $body): void {
            $this->emailsSent[] = [
                'subject' => $subject,
                'body'    => $body,
            ];
        });

        $this->errorHandler->setAutoExit(false);
        $this->errorHandler->setTerminalWidth(50);
    }

    protected function tearDown(): void
    {
        \putenv('COLUMNS');
        \ini_set('error_log', $this->backupErrorLog);
        @\unlink($this->errorLog);
    }

    public function testDefaultConfiguration(): void
    {
        $errorHandler = new ErrorHandler(function (): void {
        });

        static::assertTrue($errorHandler->isCli());
        static::assertTrue($errorHandler->autoExit());
        static::assertNotNull($errorHandler->getTerminalWidth());
        static::assertSame(\STDERR, $errorHandler->getErrorOutputStream());
        static::assertFalse($errorHandler->logErrors());

        $errorHandler->setCli(false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setTerminalWidth($width = \mt_rand(1, 999));
        $errorHandler->setErrorOutputStream($memoryStream = \fopen('php://memory', 'r+'));
        $errorHandler->setLogErrors(true);

        static::assertFalse($errorHandler->isCli());
        static::assertFalse($errorHandler->autoExit());
        static::assertSame($width, $errorHandler->getTerminalWidth());
        static::assertSame($memoryStream, $errorHandler->getErrorOutputStream());
        static::assertTrue($errorHandler->logErrors());

        $errorHandler->setErrorOutputStream(\uniqid('not_a_stream_'));
        static::assertSame($memoryStream, $errorHandler->getErrorOutputStream());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegisterBuiltinHandlers(): void
    {
        $this->errorHandler->register();
        $arrayPerVerificaErrori = [];

        @ $arrayPerVerificaErrori['no_exception_thrown_on_undefined_index_now'];

        $this->expectException(ErrorException::class);
        $arrayPerVerificaErrori['undefined_index'];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testScream(): void
    {
        $scream = [
            \E_USER_WARNING => true,
        ];

        static::assertEmpty($this->errorHandler->getScreamSilencedErrors());
        $this->errorHandler->setScreamSilencedErrors($scream);
        static::assertSame($scream, $this->errorHandler->getScreamSilencedErrors());

        $this->errorHandler->register();

        @ \trigger_error(\uniqid('deprecated_'), \E_USER_DEPRECATED);

        $warningMessage = \uniqid('warning_');
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageRegExp(\sprintf('/%s/', \preg_quote($warningMessage)));

        @ \trigger_error($warningMessage, \E_USER_WARNING);
    }

    public function testHandleCliException(): void
    {
        $memoryStream = \fopen('php://memory', 'r+');
        static::assertIsResource($memoryStream);
        $this->errorHandler->setErrorOutputStream($memoryStream);

        $this->errorHandler->exceptionHandler($this->exception);

        \fseek($memoryStream, 0);
        $output = \stream_get_contents($memoryStream);
        static::assertStringContainsString($this->exception->getMessage(), $output);
    }

    public function testHandleWebExceptionWithDisplay(): void
    {
        \ini_set('display_errors', (string) true);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = \ob_get_clean();

        static::assertStringContainsString($this->exception->getMessage(), $output);

        $errorLogContent = \file_get_contents($this->errorLog);
        static::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testHandleWebExceptionWithoutDisplay(): void
    {
        \ini_set('display_errors', (string) false);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = \ob_get_clean();

        static::assertStringNotContainsString($this->exception->getMessage(), $output);

        $errorLogContent = \file_get_contents($this->errorLog);
        static::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testLogErrorAndException(): void
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->logException($this->exception);

        static::assertSame(0, \filesize($this->errorLog));

        $this->errorHandler->setLogErrors(true);

        $exception = new ErrorException(\uniqid(), \E_USER_ERROR, \E_ERROR, __FILE__, 1, $this->exception);

        $this->errorHandler->logException($exception);

        $errorLogContent = \file_get_contents($this->errorLog);

        static::assertStringContainsString($exception->getMessage(), $errorLogContent);
        static::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testEmailException(): void
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->emailException($this->exception);

        static::assertEmpty($this->emailsSent);

        $this->errorHandler->setLogErrors(true);

        $key      = \uniqid(__FUNCTION__);
        $_SESSION = [$key => \uniqid()];
        $_POST    = [$key => \uniqid()];

        $this->errorHandler->emailException($this->exception);

        static::assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        static::assertNotEmpty($message);

        $messageText = $message['body'];
        static::assertStringContainsString($this->exception->getMessage(), $messageText);
        static::assertStringContainsString($_SESSION[$key], $messageText);
        static::assertStringContainsString($_POST[$key], $messageText);
    }

    public function testCanHideVariablesFromEmail(): void
    {
        static::assertTrue($this->errorHandler->logVariables());
        $this->errorHandler->setLogVariables(false);
        static::assertFalse($this->errorHandler->logVariables());

        $this->errorHandler->setLogErrors(true);

        $key      = \uniqid(__FUNCTION__);
        $_SESSION = [$key => \uniqid()];
        $_POST    = [$key => \uniqid()];

        $this->errorHandler->emailException($this->exception);

        static::assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        static::assertNotEmpty($message);

        $messageText = $message['body'];
        static::assertStringContainsString($this->exception->getMessage(), $messageText);
        static::assertStringNotContainsString($_SESSION[$key], $messageText);
        static::assertStringNotContainsString($_POST[$key], $messageText);
    }

    public function testErroriNellInvioDellaMailVengonoComunqueLoggati(): void
    {
        $mailError    = \uniqid('mail_not_sent_');
        $mailCallback = static function () use ($mailError): void {
            throw new ErrorException($mailError, \E_USER_ERROR);
        };
        $errorHandler = new ErrorHandler($mailCallback);
        $errorHandler->setLogErrors(true);

        $errorHandler->emailException($this->exception);

        $errorLogContent = \file_get_contents($this->errorLog);
        static::assertStringNotContainsString($this->exception->getMessage(), $errorLogContent);
        static::assertStringContainsString($mailError, $errorLogContent);
    }

    public function testUsernameInEmailSubject(): void
    {
        $username = \uniqid('bob_');
        $_SESSION = ['custom_username_key' => $username];

        $this->errorHandler->setLogErrors(true);
        $this->errorHandler->emailException($this->exception);

        $message = \current($this->emailsSent);

        static::assertStringContainsString($username, $message['subject']);
    }

    public function testTerminalWidthByEnv(): void
    {
        $width = \mt_rand(1000, 9000);
        \putenv(\sprintf('COLUMNS=%s', $width));

        $errorHandler = new ErrorHandler(function (): void {
        });

        static::assertSame($width, $errorHandler->getTerminalWidth());

        \putenv('COLUMNS');

        $errorHandler = new ErrorHandler(function (): void {
        });

        $terminal = new Terminal();
        static::assertSame($terminal->getWidth(), $errorHandler->getTerminalWidth());
    }
}
