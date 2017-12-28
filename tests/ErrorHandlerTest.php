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

    protected function setUp()
    {
        \ini_set('display_errors', (string) false);
        $this->backupErrorLog = \ini_get('error_log');
        $this->errorLog = __DIR__ . \DIRECTORY_SEPARATOR . 'error_log_test';
        \touch($this->errorLog);
        \ini_set('error_log', $this->errorLog);

        $this->exception = new ErrorException(\uniqid('normal_'), \E_USER_NOTICE);
        $this->emailsSent = array();
        $this->errorHandler = new ErrorHandler(function ($subject, $body) {
            $this->emailsSent[] = array(
                'subject' => $subject,
                'body' => $body,
            );
        });

        $this->errorHandler->setAutoExit(false);
        $this->errorHandler->setTerminalWidth(50);
    }

    protected function tearDown()
    {
        \putenv('COLUMNS');
        \ini_set('error_log', $this->backupErrorLog);
        @\unlink($this->errorLog);
    }

    public function testDefaultConfiguration()
    {
        $errorHandler = new ErrorHandler(function () {
        });

        $this->assertTrue($errorHandler->isCli());
        $this->assertTrue($errorHandler->autoExit());
        $this->assertNotNull($errorHandler->getTerminalWidth());
        $this->assertSame(\STDERR, $errorHandler->getErrorOutputStream());
        $this->assertFalse($errorHandler->logErrors());

        $errorHandler->setCli(false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setTerminalWidth($width = \mt_rand(1, 999));
        $errorHandler->setErrorOutputStream($memoryStream = \fopen('php://memory', 'r+'));
        $errorHandler->setLogErrors(true);

        $this->assertFalse($errorHandler->isCli());
        $this->assertFalse($errorHandler->autoExit());
        $this->assertSame($width, $errorHandler->getTerminalWidth());
        $this->assertSame($memoryStream, $errorHandler->getErrorOutputStream());
        $this->assertTrue($errorHandler->logErrors());

        $errorHandler->setErrorOutputStream(\uniqid('not_a_stream_'));
        $this->assertSame($memoryStream, $errorHandler->getErrorOutputStream());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegisterBuiltinHandlers()
    {
        $this->errorHandler->register();
        $arrayPerVerificaErrori = array();

        @ $arrayPerVerificaErrori['no_exception_thrown_on_undefined_index_now'];

        $this->expectException(ErrorException::class);
        $arrayPerVerificaErrori['undefined_index'];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testScream()
    {
        $scream = array(
            \E_USER_WARNING => true,
        );

        $this->assertEmpty($this->errorHandler->getScreamSilencedErrors());
        $this->errorHandler->setScreamSilencedErrors($scream);
        $this->assertSame($scream, $this->errorHandler->getScreamSilencedErrors());

        $this->errorHandler->register();

        @ \trigger_error(\uniqid('deprecated_'), \E_USER_DEPRECATED);

        $warningMessage = \uniqid('warning_');
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageRegexp(\sprintf('/%s/', \preg_quote($warningMessage)));

        @ \trigger_error($warningMessage, \E_USER_WARNING);
    }

    public function testHandleCliException()
    {
        $memoryStream = \fopen('php://memory', 'r+');
        $this->errorHandler->setErrorOutputStream($memoryStream);

        $this->errorHandler->exceptionHandler($this->exception);

        \fseek($memoryStream, 0);
        $output = \stream_get_contents($memoryStream);
        $this->assertContains($this->exception->getMessage(), $output);
    }

    public function testHandleWebExceptionWithDisplay()
    {
        \ini_set('display_errors', (string) true);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = \ob_get_clean();

        $this->assertContains($this->exception->getMessage(), $output);

        $errorLogContent = \file_get_contents($this->errorLog);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testHandleWebExceptionWithoutDisplay()
    {
        \ini_set('display_errors', (string) false);
        $this->errorHandler->setCli(false);
        $this->errorHandler->setLogErrors(true);

        \ob_start();
        $this->errorHandler->exceptionHandler($this->exception);
        $output = \ob_get_clean();

        $this->assertNotContains($this->exception->getMessage(), $output);

        $errorLogContent = \file_get_contents($this->errorLog);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testLogErrorAndException()
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->logException($this->exception);

        $this->assertSame(0, \filesize($this->errorLog));

        $this->errorHandler->setLogErrors(true);

        $exception = new ErrorException(\uniqid(), \E_USER_ERROR, \E_ERROR, __FILE__, 1, $this->exception);

        $this->errorHandler->logException($exception);

        $errorLogContent = \file_get_contents($this->errorLog);

        $this->assertContains($exception->getMessage(), $errorLogContent);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testEmailException()
    {
        $this->errorHandler->setLogErrors(false);

        $this->errorHandler->emailException($this->exception);

        $this->assertEmpty($this->emailsSent);

        $this->errorHandler->setLogErrors(true);

        $key = \uniqid(__FUNCTION__);
        $_SESSION = array($key => \uniqid());
        $_POST = array($key => \uniqid());

        $this->errorHandler->emailException($this->exception);

        $this->assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        $this->assertNotEmpty($message);

        $messageText = $message['body'];
        $this->assertContains($this->exception->getMessage(), $messageText);
        $this->assertContains($_SESSION[$key], $messageText);
        $this->assertContains($_POST[$key], $messageText);
    }

    public function testCanHideVariablesFromEmail()
    {
        $this->assertTrue($this->errorHandler->logVariables());
        $this->errorHandler->setLogVariables(false);
        $this->assertFalse($this->errorHandler->logVariables());

        $this->errorHandler->setLogErrors(true);

        $key = \uniqid(__FUNCTION__);
        $_SESSION = array($key => \uniqid());
        $_POST = array($key => \uniqid());

        $this->errorHandler->emailException($this->exception);

        $this->assertNotEmpty($this->emailsSent);
        $message = \current($this->emailsSent);
        $this->assertNotEmpty($message);

        $messageText = $message['body'];
        $this->assertContains($this->exception->getMessage(), $messageText);
        $this->assertNotContains($_SESSION[$key], $messageText);
        $this->assertNotContains($_POST[$key], $messageText);
    }

    public function testErroriNellInvioDellaMailVengonoComunqueLoggati()
    {
        $mailError = \uniqid('mail_not_sent_');
        $mailCallback = function ($body, $text) use ($mailError) {
            throw new ErrorException($mailError, \E_USER_ERROR);
        };
        $errorHandler = new ErrorHandler($mailCallback);
        $errorHandler->setLogErrors(true);

        $errorHandler->emailException($this->exception);

        $errorLogContent = \file_get_contents($this->errorLog);
        $this->assertNotContains($this->exception->getMessage(), $errorLogContent);
        $this->assertContains($mailError, $errorLogContent);
    }

    public function testUsernameInEmailSubject()
    {
        $username = \uniqid('bob_');
        $_SESSION = array('custom_username_key' => $username);

        $this->errorHandler->setLogErrors(true);
        $this->errorHandler->emailException($this->exception);

        $message = \current($this->emailsSent);

        $this->assertContains($username, $message['subject']);
    }

    public function testTerminalWidthByEnv()
    {
        $width = \mt_rand(1000, 9000);
        \putenv(\sprintf('COLUMNS=%s', $width));

        $errorHandler = new ErrorHandler(function () {
        });

        $this->assertSame($width, $errorHandler->getTerminalWidth());

        \putenv('COLUMNS');

        $errorHandler = new ErrorHandler(function () {
        });

        $terminal = new Terminal();
        $this->assertSame($terminal->getWidth(), $errorHandler->getTerminalWidth());
    }
}
