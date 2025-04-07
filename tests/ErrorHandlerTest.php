<?php

declare(strict_types=1);

namespace SlamTest\ErrorHandler;

use ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slam\ErrorHandler\ErrorHandler;
use Symfony\Component\Console\Terminal;

final class ErrorHandlerTest extends TestCase
{
    private ErrorException $exception;

    /** @var list<array{subject: string, body: string}> */
    private array $emailsSent = [];
    private ErrorHandler $errorHandler;
    private bool $unregister = false;

    protected function setUp(): void
    {
        $this->exception        = new ErrorException(\uniqid('normal_'), \E_USER_NOTICE);
        $this->errorHandler     = new ErrorHandler(function (string $subject, string $body): void {
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
        if ($this->unregister) {
            \restore_exception_handler();
            \restore_error_handler();
        }
    }

    public function testDefaultConfiguration(): void
    {
        $errorHandler = new ErrorHandler(function (): void {});

        self::assertTrue($errorHandler->isCli());
        self::assertTrue($errorHandler->autoExit());
        self::assertGreaterThan(0, $errorHandler->getTerminalWidth());
        self::assertSame(\STDERR, $errorHandler->getErrorOutputStream());
        self::assertFalse($errorHandler->logErrors());

        $errorHandler->setCli(false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setTerminalWidth($width            = \mt_rand(1, 999));
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

    public function testRegisterBuiltinHandlers(): void
    {
        \error_reporting(\E_ALL);
        $this->errorHandler->register();
        $this->unregister       = true;
        $arrayPerVerificaErrori = [];

        @ $arrayPerVerificaErrori['no_exception_thrown_on_undefined_index_now'];

        $this->expectException(ErrorException::class);
        $arrayPerVerificaErrori['undefined_index'];
    }

    public function testScream(): void
    {
        $scream = [
            \E_USER_WARNING => true,
        ];

        self::assertEmpty($this->errorHandler->getScreamSilencedErrors());
        $this->errorHandler->setScreamSilencedErrors($scream);
        self::assertSame($scream, $this->errorHandler->getScreamSilencedErrors());

        \error_reporting(\E_ALL);
        $this->errorHandler->register();
        $this->unregister = true;

        @ \trigger_error(\uniqid('deprecated_'), \E_USER_DEPRECATED);

        $warningMessage = \uniqid('warning_');
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageMatches(\sprintf('/%s/', \preg_quote($warningMessage)));

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
        $this->errorHandler->setDisplayErrors(true);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = (string) \ob_get_clean();

        self::assertStringContainsString($this->exception->getMessage(), $output);

        self::expectErrorLog();
        $errorLogContent = (string) \file_get_contents(\ini_get('error_log'));
        self::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testHandleWebExceptionWithoutDisplay(): void
    {
        $this->errorHandler->setDisplayErrors(false);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = (string) \ob_get_clean();

        self::assertStringNotContainsString($this->exception->getMessage(), $output);

        self::expectErrorLog();
        $errorLogContent = (string) \file_get_contents(\ini_get('error_log'));
        self::assertStringContainsString($this->exception->getMessage(), $errorLogContent);
    }

    public function testLogErrorAndException(): void
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->logException($this->exception);

        self::assertSame(0, \filesize(\ini_get('error_log')));

        $this->errorHandler->setLogErrors(true);

        $exception = new ErrorException(\uniqid(), \E_USER_ERROR, \E_ERROR, __FILE__, 1, $this->exception);

        $this->errorHandler->logException($exception);

        self::expectErrorLog();
        $errorLogContent = (string) \file_get_contents(\ini_get('error_log'));

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
        $message     = \current($this->emailsSent);
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

        self::expectErrorLog();
        $errorLogContent = (string) \file_get_contents(\ini_get('error_log'));
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
        self::assertNotFalse($message);

        self::assertStringContainsString($username, $message['subject']);
    }

    public function testTerminalWidthByEnv(): void
    {
        $width = \mt_rand(1000, 9000);
        \putenv(\sprintf('COLUMNS=%s', $width));

        $errorHandler = new ErrorHandler(function (): void {});

        self::assertSame($width, $errorHandler->getTerminalWidth());

        \putenv('COLUMNS');

        $errorHandler = new ErrorHandler(function (): void {});

        $terminal = new Terminal();
        self::assertSame($terminal->getWidth(), $errorHandler->getTerminalWidth());
    }

    public function test404SpecificExceptionForHeaders(): void
    {
        self::assertEmpty($this->errorHandler->get404ExceptionTypes());

        self::assertStringNotContainsString('404: Not Found', $this->errorHandler->renderHtmlException(new RuntimeException()));

        $exceptionTypes = [RuntimeException::class];
        $this->errorHandler->set404ExceptionTypes($exceptionTypes);

        self::assertSame($exceptionTypes, $this->errorHandler->get404ExceptionTypes());

        self::assertStringContainsString('404: Not Found', $this->errorHandler->renderHtmlException(new RuntimeException()));
    }

    public function test404ExceptionCanBeDisabledToSendEmail(): void
    {
        $this->errorHandler->setLogErrors(true);
        $exceptionTypes = [RuntimeException::class];
        $this->errorHandler->set404ExceptionTypes($exceptionTypes);

        self::assertTrue($this->errorHandler->shouldEmail404Exceptions());

        $this->errorHandler->emailException(new RuntimeException());

        self::assertNotEmpty($this->emailsSent);
        $this->emailsSent = [];

        $this->errorHandler->setShouldEmail404Exceptions(false);
        self::assertFalse($this->errorHandler->shouldEmail404Exceptions());

        $this->errorHandler->emailException(new RuntimeException());

        self::assertEmpty($this->emailsSent);
    }

    public function testCanSetCustomErrorLogCallback(): void
    {
        $this->errorHandler->setLogErrors(true);
        self::assertSame('\error_log', $this->errorHandler->getErrorLogCallback());

        $data           = [];
        $customCallback = static function (string $text) use (& $data): void {
            $data[]     = $text;
        };

        $this->errorHandler->setErrorLogCallback($customCallback);

        self::assertSame($customCallback, $this->errorHandler->getErrorLogCallback());

        $this->errorHandler->logException($this->exception);

        self::assertSame(0, \filesize(\ini_get('error_log')));
        self::assertStringContainsString($this->exception->getMessage(), \var_export($data, true));
    }
}
