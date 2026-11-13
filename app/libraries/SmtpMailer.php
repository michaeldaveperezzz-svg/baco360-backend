<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmtpMailer
{
    public static function isConfigured()
    {
        $host = (string) config_item('mail_host');
        $port = (int) config_item('mail_port');
        $encryption = (string) config_item('mail_encryption');
        $username = (string) config_item('mail_username');
        $password = self::smtpPassword();

        return (string) config_item('mail_mailer') === 'smtp'
            && preg_match('/\A[A-Za-z0-9.-]+\z/', $host) === 1
            && $port > 0
            && $port <= 65535
            && in_array($encryption, ['tls', 'ssl', 'starttls'], true)
            && $username !== ''
            && $password !== ''
            && filter_var(config_item('mail_from_address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function sendAccountChangeCode($recipient, $name, $code, $contact)
    {
        $contactLabel = $contact === 'email' ? 'new email address' : 'account contact change';
        self::sendCodeEmail(
            $recipient,
            $name,
            $code,
            'Confirm your Baco360 account change',
            'Your verification code for changing your ' . $contactLabel . ' is:',
            'If you did not request this change, you can ignore this email.'
        );
    }

    public static function sendVerificationCode($recipient, $name, $code)
    {
        self::sendCodeEmail(
            $recipient,
            $name,
            $code,
            'Verify your Baco360 email',
            'Your Baco360 email verification code is:',
            'If you did not create an account, you can ignore this email.'
        );
    }

    public static function sendPasswordResetCode($recipient, $name, $code)
    {
        self::sendCodeEmail(
            $recipient,
            $name,
            $code,
            'Reset your Baco360 password',
            'Your Baco360 password reset code is:',
            'If you did not request a password reset, you can ignore this email.'
        );
    }

    private static function sendCodeEmail($recipient, $name, $code, $subject, $prompt, $safetyMessage)
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "Hello {$name},\n\n{$prompt}\n{$code}\n\nThis code expires in 15 minutes. {$safetyMessage}";
        $html = '<p>Hello ' . $safeName . ',</p>'
            . '<p>' . htmlspecialchars($prompt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . $code . '</p>'
            . '<p>This code expires in 15 minutes. '
            . htmlspecialchars($safetyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

        self::sendEmail($recipient, $subject, $text, $html);
    }

    public static function sendEmail($recipient, $subject, $text, $html)
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('SMTP email is not configured. Check the MAIL_* settings.');
        }
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The email recipient is invalid.');
        }
        if (!is_string($subject) || trim($subject) === '' || !is_string($text) || !is_string($html)) {
            throw new RuntimeException('The email subject and message bodies are required.');
        }

        $host = (string) config_item('mail_host');
        $port = (int) config_item('mail_port');
        $encryption = (string) config_item('mail_encryption');
        $password = self::smtpPassword();
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            20,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException('Could not connect to the SMTP server. Check MAIL_HOST, MAIL_PORT, and TLS settings.');
        }

        stream_set_timeout($socket, 20);
        try {
            self::expectReply($socket, [220]);
            self::command($socket, 'EHLO ' . self::clientName(), [250]);

            if (in_array($encryption, ['tls', 'starttls'], true)) {
                self::command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Could not establish SMTP TLS. Check the PHP OpenSSL configuration.');
                }
                self::command($socket, 'EHLO ' . self::clientName(), [250]);
            }

            $username = (string) config_item('mail_username');
            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode($password), [235]);
            }

            $from = (string) config_item('mail_from_address');
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            self::writeAll($socket, self::buildMessage($recipient, $subject, $text, $html) . ".\r\n");
            self::expectReply($socket, [250]);
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, $command, array $expectedCodes)
    {
        self::writeAll($socket, $command . "\r\n");
        return self::expectReply($socket, $expectedCodes);
    }

    private static function expectReply($socket, array $expectedCodes)
    {
        $replyCode = null;
        do {
            $line = fgets($socket, 2048);
            if ($line === false || !preg_match('/\A([0-9]{3})([ -])/', $line, $matches)) {
                throw new RuntimeException('The SMTP server returned an invalid response.');
            }
            $replyCode = (int) $matches[1];
        } while ($matches[2] === '-');

        if (!in_array($replyCode, $expectedCodes, true)) {
            if (in_array($replyCode, [530, 534, 535], true)) {
                throw new RuntimeException(
                    'SMTP authentication was rejected (response code ' . $replyCode
                    . '). Verify MAIL_USERNAME and MAIL_PASSWORD; Gmail requires a current App Password.'
                );
            }
            throw new RuntimeException('SMTP delivery failed with response code ' . $replyCode . '.');
        }

        return $replyCode;
    }

    private static function writeAll($socket, $data)
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Could not write to the SMTP server.');
            }
            $written += $bytes;
        }
    }

    private static function clientName()
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '-', (string) gethostname());
        return $name !== '' ? $name : 'localhost';
    }

    private static function smtpPassword()
    {
        $password = trim((string) config_item('mail_password'));
        if (strcasecmp((string) config_item('mail_host'), 'smtp.gmail.com') === 0) {
            $password = preg_replace('/\s+/', '', $password);
        }
        return $password;
    }

    private static function buildMessage($recipient, $subject, $text, $html)
    {
        $senderEmail = (string) config_item('mail_from_address');
        $senderName = str_replace(["\r", "\n"], '', (string) config_item('mail_from_name'));
        $safeSubject = str_replace(["\r", "\n"], '', trim($subject));
        $boundary = 'baco360_' . bin2hex(random_bytes(16));
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($senderName) . '?= <' . $senderEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: =?UTF-8?B?' . base64_encode($safeSubject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";

        return preg_replace('/^\./m', '..', $message);
    }
}
