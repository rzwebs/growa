<?php

header('Content-Type: application/json; charset=utf-8');

function json_error(int $status, string $error, array $details = []): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $error,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function smtp_read($socket, array &$log): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $log[] = trim($response);
    return $response;
}

function smtp_expect($socket, array &$log, array $codes): string
{
    $response = smtp_read($socket, $log);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($response));
    }

    return $response;
}

function smtp_write($socket, string $command, array &$log): void
{
    $log[] = '> ' . trim($command);
    fwrite($socket, $command);
}

function smtp_send_mail(array $config, string $subject, string $message): array
{
    $log = [];
    $host = $config['host'];
    $port = (int)$config['port'];
    $username = $config['username'];
    $password = $config['password'];
    $from = $config['from'];
    $fromName = $config['from_name'];
    $recipients = $config['to'];
    $encryption = strtolower(trim((string)$config['encryption']));
    $timeout = (int)$config['timeout'];

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $from === '' || count($recipients) === 0) {
        throw new RuntimeException('SMTP config is incomplete');
    }

    $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client(
        $transport . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect($socket, $log, [220]);

        $ehloHost = gethostname() ?: 'localhost';
        smtp_write($socket, "EHLO {$ehloHost}\r\n", $log);
        smtp_expect($socket, $log, [250]);

        if ($encryption === 'tls' || $encryption === 'starttls') {
            smtp_write($socket, "STARTTLS\r\n", $log);
            smtp_expect($socket, $log, [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable TLS encryption');
            }

            smtp_write($socket, "EHLO {$ehloHost}\r\n", $log);
            smtp_expect($socket, $log, [250]);
        }

        smtp_write($socket, "AUTH LOGIN\r\n", $log);
        smtp_expect($socket, $log, [334]);

        smtp_write($socket, base64_encode($username) . "\r\n", $log);
        smtp_expect($socket, $log, [334]);

        smtp_write($socket, base64_encode($password) . "\r\n", $log);
        smtp_expect($socket, $log, [235]);

        smtp_write($socket, "MAIL FROM:<{$from}>\r\n", $log);
        smtp_expect($socket, $log, [250]);

        foreach ($recipients as $recipient) {
            smtp_write($socket, "RCPT TO:<{$recipient}>\r\n", $log);
            smtp_expect($socket, $log, [250, 251]);
        }

        smtp_write($socket, "DATA\r\n", $log);
        smtp_expect($socket, $log, [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $from),
            'To: ' . implode(', ', $recipients),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $body = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $message) . "\r\n.\r\n";
        smtp_write($socket, $body, $log);
        smtp_expect($socket, $log, [250]);

        smtp_write($socket, "QUIT\r\n", $log);
        smtp_expect($socket, $log, [221]);

        fclose($socket);

        return [
            'sent' => true,
            'error' => null,
            'log_tail' => array_slice($log, -8),
        ];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }

        return [
            'sent' => false,
            'error' => $e->getMessage(),
            'log_tail' => array_slice($log, -8),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    json_error(400, 'Invalid payload');
}

$topic = trim((string)($data['topic'] ?? ''));
$volume = trim((string)($data['volume'] ?? ''));
$contactType = trim((string)($data['contact_type'] ?? ''));
$contact = trim((string)($data['contact'] ?? ''));
$source = trim((string)($data['source'] ?? 'site'));
$page = trim((string)($data['page'] ?? ''));
$createdAt = trim((string)($data['created_at'] ?? ''));

if ($contact === '') {
    json_error(422, 'Contact is required');
}

$smtpHost = trim((string)(getenv('SMTP_HOST') ?: ''));
$smtpPort = (int)(getenv('SMTP_PORT') ?: 465);
$smtpUsername = trim((string)(getenv('SMTP_USERNAME') ?: ''));
$smtpPassword = trim((string)(getenv('SMTP_PASSWORD') ?: ''));
$smtpEncryption = trim((string)(getenv('SMTP_ENCRYPTION') ?: 'ssl'));
$smtpTimeout = (int)(getenv('SMTP_TIMEOUT') ?: 15);
$emailFrom = trim((string)(getenv('LEAD_EMAIL_FROM') ?: $smtpUsername ?: 'hello@growa.ru'));
$emailToRaw = trim((string)(getenv('LEAD_EMAIL_TO') ?: 'rzweb@growa.ru,ramazanov.web@growa.ru'));
$emailRecipients = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $emailToRaw))));

$telegramBotToken = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
$telegramChatId = trim((string)(getenv('TELEGRAM_CHAT_ID') ?: ''));
$telegramTimeout = (int)(getenv('TELEGRAM_TIMEOUT') ?: 3);

$message = implode("\n", [
    'Новая заявка с сайта Growa',
    '',
    'Источник: ' . ($source ?: 'site'),
    'Тип сайта: ' . ($topic ?: 'не указано'),
    'Ниша: ' . ($volume ?: 'не указано'),
    'Тип контакта: ' . ($contactType ?: 'не указано'),
    'Контакт: ' . $contact,
    'Страница: ' . ($page ?: 'не указано'),
    'Время: ' . ($createdAt ?: date('c')),
]);

$emailAttempted = false;
$telegramAttempted = false;
$emailSent = false;
$telegramSent = false;
$emailError = '';
$telegramError = '';
$emailLogTail = [];
$telegramMeta = [];

if ($smtpHost !== '' && $smtpUsername !== '' && $smtpPassword !== '' && count($emailRecipients) > 0) {
    $emailAttempted = true;
    $smtpResult = smtp_send_mail([
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUsername,
        'password' => $smtpPassword,
        'encryption' => $smtpEncryption,
        'timeout' => $smtpTimeout,
        'from' => $emailFrom,
        'from_name' => 'Growa',
        'to' => $emailRecipients,
    ], 'Новая заявка с сайта Growa', $message);

    $emailSent = $smtpResult['sent'];
    $emailError = $smtpResult['error'] ?? '';
    $emailLogTail = $smtpResult['log_tail'] ?? [];
} else {
    $missing = [];
    if ($smtpHost === '') {
        $missing[] = 'SMTP_HOST';
    }
    if ($smtpUsername === '') {
        $missing[] = 'SMTP_USERNAME';
    }
    if ($smtpPassword === '') {
        $missing[] = 'SMTP_PASSWORD';
    }
    if (count($emailRecipients) === 0) {
        $missing[] = 'LEAD_EMAIL_TO';
    }
    $emailError = 'Missing SMTP config: ' . implode(', ', $missing);
}

if ($telegramBotToken !== '' && $telegramChatId !== '') {
    $telegramAttempted = true;
    $telegramUrl = 'https://api.telegram.org/bot' . $telegramBotToken . '/sendMessage';
    $telegramPayload = http_build_query([
        'chat_id' => $telegramChatId,
        'text' => $message,
    ]);

    $telegramResponse = false;
    $telegramStatusCode = null;
    $telegramDecoded = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($telegramUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $telegramPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $telegramTimeout,
            CURLOPT_CONNECTTIMEOUT => $telegramTimeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $telegramResponse = curl_exec($ch);
        $telegramStatusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($telegramResponse !== false) {
            $telegramDecoded = json_decode($telegramResponse, true);
        } elseif ($curlError !== '') {
            $telegramError = 'Telegram cURL error: ' . $curlError;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $telegramPayload,
                'timeout' => $telegramTimeout,
                'ignore_errors' => true,
            ],
        ]);

        $telegramResponse = @file_get_contents($telegramUrl, false, $context);
        $telegramHeaders = $http_response_header ?? [];
        $telegramStatusLine = $telegramHeaders[0] ?? '';
        $telegramStatusCode = preg_match('/\s(\d{3})\s/', $telegramStatusLine, $matches) ? (int)$matches[1] : null;
        $telegramDecoded = is_string($telegramResponse) ? json_decode($telegramResponse, true) : null;
    }

    $telegramMeta = [
        'timeout' => $telegramTimeout,
        'status_code' => $telegramStatusCode,
        'response' => is_array($telegramDecoded) ? $telegramDecoded : $telegramResponse,
        'transport' => function_exists('curl_init') ? 'curl' : 'stream',
    ];

    $telegramSent = is_array($telegramDecoded)
        ? (bool)($telegramDecoded['ok'] ?? false)
        : ($telegramResponse !== false && $telegramStatusCode !== null && $telegramStatusCode >= 200 && $telegramStatusCode < 300);

    if (!$telegramSent) {
        if ($telegramError !== '') {
            // keep transport-level error
        } elseif (is_array($telegramDecoded) && isset($telegramDecoded['description'])) {
            $telegramError = 'Telegram API error: ' . $telegramDecoded['description'];
        } elseif ($telegramStatusCode !== null) {
            $telegramError = 'Telegram request failed with HTTP ' . $telegramStatusCode;
        } else {
            $telegramError = 'Telegram request failed or timed out';
        }
    }
} else {
    if ($telegramBotToken === '' && $telegramChatId === '') {
        $telegramError = 'TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID are missing';
    } elseif ($telegramBotToken === '') {
        $telegramError = 'TELEGRAM_BOT_TOKEN is missing';
    } else {
        $telegramError = 'TELEGRAM_CHAT_ID is missing';
    }
}

$details = [
    'email' => [
        'attempted' => $emailAttempted,
        'sent' => $emailSent,
        'to' => $emailRecipients,
        'from' => $emailFrom,
        'smtp_host' => $smtpHost ?: null,
        'smtp_port' => $smtpPort,
        'smtp_encryption' => $smtpEncryption ?: null,
        'error' => $emailError ?: null,
        'log_tail' => $emailLogTail,
    ],
    'telegram' => [
        'attempted' => $telegramAttempted,
        'sent' => $telegramSent,
        'chat_id' => $telegramChatId ?: null,
        'error' => $telegramError ?: null,
        'meta' => $telegramMeta,
    ],
];

if (!$emailSent && !$telegramSent) {
    json_error(500, 'Lead delivery failed', $details);
}

echo json_encode([
    'ok' => true,
    'email_sent' => $emailSent,
    'telegram_sent' => $telegramSent,
    'details' => $details,
], JSON_UNESCAPED_UNICODE);
