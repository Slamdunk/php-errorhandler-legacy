<?php

declare(strict_types=1);

namespace SlamTest\ErrorHandler;

use ErrorException;
use Slam\ErrorHandler\ErrorHandler;

final class ErrorHandlerTest extends \PHPUnit_Framework_TestCase
{
    private $backupErrorLog;

    private $errorLog;

    protected function setUp()
    {
        $this->backupErrorLog = ini_get('error_log');
        $this->errorLog = __DIR__ . DIRECTORY_SEPARATOR . 'error_log_test';
        touch($this->errorLog);
        ini_set('error_log', $this->errorLog);

        $this->exception = new ErrorException(uniqid('normal_'), E_USER_NOTICE);
        $this->mailError = uniqid('mail_not_sent_');
        $this->mailCallback = function ($body, $text) {
            throw new ErrorException($this->mailError, E_USER_ERROR);
        };
    }

    protected function tearDown()
    {
        ini_set('error_log', $this->backupErrorLog);
        @unlink($this->errorLog);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegistraConfigurazioniBase()
    {
        $this->assertSame('E_USER_NOTICE', ErrorHandler::getExceptionCode($this->exception));
        $errorHandler = new ErrorHandler($this->mailCallback);
        $errorHandler->register();
        $arrayPerVerificaErrori = array();

        @ $arrayPerVerificaErrori['nessuna_eccezione_lanciata'];

        $this->setExpectedException(ErrorException::class);
        $arrayPerVerificaErrori['undefined_ndex'];
    }

    public function testHandleCliException()
    {
        $errorHandler = new ErrorHandler($this->mailCallback, false, true, false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setCli(true);
        $errorHandler->setTerminalWidth(50);

        ob_start();
        $errorHandler->exceptionHandler($this->exception);
        $output = ob_get_clean();

        $this->assertContains($this->exception->getMessage(), $output);
    }

    public function testHandleWebExceptionInSviluppo()
    {
        $errorHandler = new ErrorHandler($this->mailCallback, true, true, false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setCli(false);

        ob_start();
        $errorHandler->exceptionHandler($this->exception);
        $output = ob_get_clean();

        $this->assertContains($this->exception->getMessage(), $output);

        $errorLogContent = file_get_contents($this->errorLog);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testHandleWebExceptionInSviluppoConErrore()
    {
        $templateInesistente = uniqid('template_inesistente_');
        $errorHandler = new ErrorHandler($this->mailCallback, true, true, false, $templateInesistente);
        $errorHandler->setAutoExit(false);
        $errorHandler->setCli(false);

        ob_start();
        $errorHandler->exceptionHandler($this->exception);
        $output = ob_get_clean();

        $this->assertNotContains($this->exception->getMessage(), $output);

        $errorLogContent = file_get_contents($this->errorLog);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
        $this->assertContains($templateInesistente, $errorLogContent);
    }

    public function testHandleWebExceptionInProduzione()
    {
        $errorHandler = new ErrorHandler($this->mailCallback, false, true, false);
        $errorHandler->setAutoExit(false);
        $errorHandler->setCli(false);

        ob_start();
        $errorHandler->exceptionHandler($this->exception);
        $output = ob_get_clean();

        $this->assertNotContains($this->exception->getMessage(), $output);

        $errorLogContent = file_get_contents($this->errorLog);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testLogErrorAndException()
    {
        $errorHandler = new ErrorHandler($this->mailCallback, false, false, false);

        $errorHandler->logError(uniqid());
        $errorHandler->logException($this->exception);

        $this->assertSame(0, filesize($this->errorLog));

        $errorHandler = new ErrorHandler($this->mailCallback, false, true, false);

        $error = uniqid();
        $exception = new ErrorException(uniqid(), E_USER_ERROR, E_ERROR, __FILE__, 1, $this->exception);

        $errorHandler->logError($error);
        $errorHandler->logException($exception);

        $errorLogContent = file_get_contents($this->errorLog);

        $this->assertContains($error, $errorLogContent);
        $this->assertContains($exception->getMessage(), $errorLogContent);
        $this->assertContains($this->exception->getMessage(), $errorLogContent);
    }

    public function testEmailErrorAndException()
    {
        $emails = array();
        $callback = function ($subject, $body) use (& $emails) {
            $emails[] = array(
                'subject' => $subject,
                'body' => $body,
            );
        };

        $errorHandler = new ErrorHandler($callback, false, false, false);

        $errorHandler->emailError(uniqid(), uniqid());
        $errorHandler->emailException($this->exception);

        $this->assertEmpty($emails);

        $errorHandler = new ErrorHandler($callback, false, false, true);

        $key = uniqid(__FUNCTION__);
        $_SESSION = array($key => uniqid());
        $_POST = array($key => uniqid());

        $errorHandler->emailException($this->exception);

        $message = current($emails);
        $this->assertNotEmpty($message);

        $messageText = $message['body'];
        $this->assertContains($this->exception->getMessage(), $messageText);
        $this->assertContains($_SESSION[$key], $messageText);
        $this->assertContains($_POST[$key], $messageText);
    }

    public function testErroriNellInvioDellaMailVengonoComunqueLoggati()
    {
        $errorHandler = new ErrorHandler($this->mailCallback, false, true, true);

        $subject = uniqid();
        $bodyText = uniqid();
        $errorHandler->emailError($subject, $bodyText);

        $errorLogContent = file_get_contents($this->errorLog);
        $this->assertContains($subject, $errorLogContent);
        $this->assertContains($bodyText, $errorLogContent);
        $this->assertContains($this->mailError, $errorLogContent);
    }
}
