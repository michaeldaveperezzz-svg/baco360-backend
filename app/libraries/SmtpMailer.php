<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmtpMailer
{
    public static function isConfigured()
    {
        $host = (string) config_item('smtp_host');
        $port = (int) config_item('smtp_port');
        $encryption = (string) config_item('smtp_encryption');

        return $host !== ''
            && preg_match('/\A[A-Za-z0-9.-]+\z/', $host) === 1
            && $port > 0
            && $port <= 65535
            && in_array($encryption, ['starttls', 'ssl'], true)
            && (string) config_item('smtp_username') !== ''
            && (string) config_item('smtp_password') !== ''
            && filter_var(config_item('email_from'), FILTER_VALIDATE_EMAIL) !== false;
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

    private static function sendCodeEmail($recipient, $name, $code, $subject, $codePrompt, $safetyMessage)
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "Hello {$name},\n\n{$codePrompt}\n{$code}\n\nThis code expires in 15 minutes. {$safetyMessage}";
        $html = '<p>Hello ' . $safeName . ',</p>'
            . '<p>' . htmlspecialchars($codePrompt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . $code . '</p>'
            . '<p>This code expires in 15 minutes. ' . htmlspecialchars($safetyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

        self::sendEmail($recipient, $subject, $text, $html);
    }

    public static function sendEmail($recipient, $subject, $text, $html)
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('SMTP email is not configured.');
        }
        if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The email recipient is invalid.');
        }
        if (!is_string($subject) || trim($subject) === '' || !is_string($text) || !is_string($html)) {
            throw new RuntimeException('The email subject and message bodies are required.');
        }
        if (!function_exists('stream_socket_client') || !function_exists('stream_socket_enable_crypto')) {
            throw new RuntimeException('PHP stream sockets and OpenSSL are required to send email.');
        }

        $host = (string) config_item('smtp_host');
        $port = (int) config_item('smtp_port');
        $encryption = (string) config_item('smtp_encryption');
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
            ],
        ]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException('Could not connect to the SMTP server.');
        }

        stream_set_timeout($socket, 15);
        try {
            self::expectResponse($socket, [220], 'server greeting');
            $clientName = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
            self::sendCommand($socket, 'EHLO ' . $clientName, [250], 'greeting');

            if ($encryption === 'starttls') {
                self::sendCommand($socket, 'STARTTLS', [220], 'TLS negotiation');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not establish a secure SMTP connection.');
                }
                self::sendCommand($socket, 'EHLO ' . $clientName, [250], 'secure greeting');
            }

            self::sendCommand($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN initialization');
            self::sendCommand($socket, base64_encode((string) config_item('smtp_username')), [334], 'SMTP username authentication');
            self::sendCommand($socket, base64_encode((string) config_item('smtp_password')), [235], 'SMTP password authentication');
            self::sendCommand($socket, 'MAIL FROM:<' . config_item('email_from') . '>', [250], 'sender validation');
            self::sendCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], 'recipient validation');
            self::sendCommand($socket, 'DATA', [354], 'message preparation');

            $boundary = 'baco360_' . bin2hex(random_bytes(16));
            $senderName = (string) config_item('email_from_name');
            $subjectHeader = '=?UTF-8?B?' . base64_encode(trim($subject)) . '?=';
            $headers = [
                'From: =?UTF-8?B?' . base64_encode($senderName) . '?= <' . config_item('email_from') . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $subjectHeader,
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
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
                . '--' . $boundary . "--\r\n.";
            self::writeAll($socket, $message . "\r\n");
            self::expectResponse($socket, [250], 'message delivery');
            self::writeAll($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private static function sendCommand($socket, $command, array $expectedCodes, $operation)
    {
        self::writeAll($socket, $command . "\r\n");
        self::expectResponse($socket, $expectedCodes, $operation);
    }

    private static function expectResponse($socket, array $expectedCodes, $operation)
    {
        $code = null;
        $responseText = '';
        do {
            $line = fgets($socket, 515);
            $metadata = stream_get_meta_data($socket);
            if ($line === false || !empty($metadata['timed_out']) || strlen($line) < 4 || !ctype_digit(substr($line, 0, 3))) {
                throw new RuntimeException('The SMTP server returned an invalid response during ' . $operation . '.');
            }
            $code = (int) substr($line, 0, 3);
            $responseText .= substr($line, 4);
        } while ($line[3] === '-');

        if (!in_array($code, $expectedCodes, true)) {
            $responseText = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $responseText);
            $responseText = substr(trim($responseText), 0, 160);
            throw new RuntimeException(
                'The SMTP server returned status ' . $code . ' during ' . $operation
                . ($responseText !== '' ? ': ' . $responseText : '.')
            );
        }
    }

    private static function writeAll($socket, $data)
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Could not write to the SMTP server.');
            }
            $written += $result;
        }
    }
}
