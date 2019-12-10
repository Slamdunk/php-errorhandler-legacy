<?php

declare(strict_types=1);

namespace SlamTest\ErrorHandler;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Slam\ErrorHandler\ErrorHandler;
use Symfony\Component\Console\Terminal;

final class ErrorHandlerTest extends TestCase
{
    /**
     * @var string
     */
    private $backupErrorLog;

    /**
     * @var string
     */
    private $errorLog;

    /**
     * @var ErrorException
     */
    private $exception;

    /**
     * @var array<int, array<string, string>>
     */
    private $emailsSent = [];

    /**
     * @var ErrorHandler
     */
    private $errorHandler;

    protected function setUp(): void
    {
        \ini_set('display_errors', (string) false);
        $this->backupErrorLog = (string) \ini_get('error_log');
        $this->errorLog       = __DIR__ . \DIRECTORY_SEPARATOR . 'error_log_test';
        \touch($this->errorLog);
        \ini_set('error_log', $this->errorLog);

        $this->exception    = new ErrorException(\uniqid('normal_'), \E_USER_NOTICE);
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

        self::assertTrue($errorHandler->isCli());
        self::assertTrue($errorHandler->autoExit());
        self::assertNotNull($errorHandler->getTerminalWidth());
        self::assertSame(\STDERR, $errorHandler->getErrorOutputStream());
        self::assertFalse($errorHandler->logErrors());

        $errorHandler->setCli(false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setTerminalWidth($width = \mt_rand(1, 999));
        $errorHandler->setErrorOutputStream($memoryStream = \fopen('php://memory', 'r+'));
        $errorHandler->setLogErrors(true);

        self::assertFalse($errorHandler->isCli());
        self::assertFalse($errorHandler->autoExit());
        self::assertSame($width, $errorHandler->getTerminalWidth());
        self::assertSame($memoryStream, $errorHandler->getErrorOutputStream());
        self::assertTrue($errorHandler->logErrors());

        $errorHandler->setErrorOutputStream(\uniqid('not_a_stream_'));
        self::assertSame($memoryStream, $errorHandler->getErrorOutputStream());
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

        self::assertEmpty($this->errorHandler->getScreamSilencedErrors());
        $this->errorHandler->setScreamSilencedErrors($scream);
        self::assertSame($scream, $this->errorHandler->getScreamSilencedErrors());

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
        self::assertIsResource($memoryStream);
        $this->errorHandler->setErrorOutputStream($memoryStream);

        $this->errorHandler->exceptionHandler($this->exception);

        \fseek($memoryStream, 0);
        $output = (string) \stream_get_contents($memoryStream);
        self::assertStringContainsString($this->exception->getMessage(), $output);
    }

    public function testHandleWebExceptionWithDisplay(): void
    {
        \ini_set('display_errors', (string) true);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = (string) \ob_get_clean();

        self::assertStringContainsString($this->exception->getMessage(), $output);

        $errorLogContent = (string) \file_get_contents($this->errorLog);
        self::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testHandleWebExceptionWithoutDisplay(): void
    {
        \ini_set('display_errors', (string) false);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = (string) \ob_get_clean();

        self::assertStringNotContainsString($this->exception->getMessage(), $output);

        $errorLogContent = (string) \file_get_contents($this->errorLog);
        self::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testLogErrorAndException(): void
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->logException($this->exception);

        self::assertSame(0, \filesize($this->errorLog));

        $this->errorHandler->setLogErrors(true);

        $exception = new ErrorException(\uniqid(), \E_USER_ERROR, \E_ERROR, __FILE__, 1, $this->exception);

        $this->errorHandler->logException($exception);

        $errorLogContent = (string) \file_get_contents($this->errorLog);

        self::assertStringContainsString($exception->getMessage(), $errorLogContent);
        self::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testEmailException(): void
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->emailException($this->exception);

        self::assertEmpty($this->emailsSent);

        $this->errorHandler->setLogErrors(true);

        $key      = \uniqid(__FUNCTION__);
        $_SESSION = [$key => \uniqid()];
        $_POST    = [$key => \uniqid()];

        $this->errorHandler->emailException($this->exception);

        self::assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        self::assertNotEmpty($message);

        $messageText = $message['body'];
        self::assertStringContainsString($this->exception->getMessage(), $messageText);
        self::assertStringContainsString($_SESSION[$key], $messageText);
        self::assertStringContainsString($_POST[$key], $messageText);
    }

    public function testCanHideVariablesFromEmail(): void
    {
        self::assertTrue($this->errorHandler->logVariables());
        $this->errorHandler->setLogVariables(false);
        self::assertFalse($this->errorHandler->logVariables());

        $this->errorHandler->setLogErrors(true);

        $key      = \uniqid(__FUNCTION__);
        $_SESSION = [$key => \uniqid()];
        $_POST    = [$key => \uniqid()];

        $this->errorHandler->emailException($this->exception);

        self::assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        self::assertNotEmpty($message);

        $messageText = $message['body'];
        self::assertStringContainsString($this->exception->getMessage(), $messageText);
        self::assertStringNotContainsString($_SESSION[$key], $messageText);
        self::assertStringNotContainsString($_POST[$key], $messageText);
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

        $errorLogContent = (string) \file_get_contents($this->errorLog);
        self::assertStringNotContainsString($this->exception->getMessage(), $errorLogContent);
        self::assertStringContainsString($mailError, $errorLogContent);
    }

    public function testUsernameInEmailSubject(): void
    {
        $username = \uniqid('bob_');
        $_SESSION = ['custom_username_key' => $username];

        $this->errorHandler->setLogErrors(true);
        $this->errorHandler->emailException($this->exception);

        $message = \current($this->emailsSent);

        self::assertStringContainsString($username, $message['subject']);
    }

    public function testTerminalWidthByEnv(): void
    {
        $width = \mt_rand(1000, 9000);
        \putenv(\sprintf('COLUMNS=%s', $width));

        $errorHandler = new ErrorHandler(function (): void {
        });

        self::assertSame($width, $errorHandler->getTerminalWidth());

        \putenv('COLUMNS');

        $errorHandler = new ErrorHandler(function (): void {
        });

        $terminal = new Terminal();
        self::assertSame($terminal->getWidth(), $errorHandler->getTerminalWidth());
    }
}
