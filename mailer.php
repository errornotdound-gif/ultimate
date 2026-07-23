<?php

declare(strict_types=1);

// ============================================
// RAILWAY MAILER ULTIMATE - INTERFACE PURE
// ============================================
// Envoi d'emails directement depuis l'interface
// Pas de fichier targets.txt requis
// ============================================

error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', -1);
ini_set('smtp_port', 25);
date_default_timezone_set('UTC');

class RailwayMailerUltimate
{
    private $smtpHost = 'localhost';
    private $smtpPort = 25;
    private $fromEmail = '';
    private $fromName = '';
    private $replyTo = '';
    private $availableSenders = [];
    private $selectedSender = '';
    private $subject = '';
    private $body = '';
    private $bodyType = 'html';
    private $stats = [
        'total' => 0,
        'sent' => 0,
        'failed' => 0,
        'startTime' => null,
        'endTime' => null
    ];
    private $logFile = 'logs/mailer.log';
    private $logEntries = [];
    private $config = [];

    public function __construct()
    {
        $this->stats['startTime'] = microtime(true);
        $this->loadConfig();
        $this->loadSenders();
        $this->initLog();
        $this->log("🚀 RAILWAY MAILER ULTIMATE INITIALIZED");
        $this->log("📊 SMTP: {$this->smtpHost}:{$this->smtpPort}");
        $this->log("📊 Senders: " . count($this->availableSenders));
    }

    private function loadConfig()
    {
        $this->config = [
            'smtp_host' => 'localhost',
            'smtp_port' => 25,
            'from_email' => 'system@railway.local',
            'from_name' => 'Railway System',
            'reply_to' => 'noreply@railway.local',
            'delay_between' => 0.3,
            'max_retries' => 3,
            'spoof_headers' => true,
            'rotate_ip' => true
        ];

        if (file_exists('.env')) {
            $env = parse_ini_file('.env');
            if ($env) {
                $this->config = array_merge($this->config, $env);
            }
        }

        $this->smtpHost = $this->config['smtp_host'];
        $this->smtpPort = (int)$this->config['smtp_port'];
        $this->fromEmail = $this->config['from_email'];
        $this->fromName = $this->config['from_name'];
        $this->replyTo = $this->config['reply_to'];
    }

    private function loadSenders()
    {
        $this->availableSenders = [];
        
        // Senders depuis le serveur Railway
        $serverSenders = $this->grabServerSenders();
        $this->availableSenders = array_merge($this->availableSenders, $serverSenders);

        // Senders depuis senders.txt
        if (file_exists('senders.txt')) {
            $lines = file('senders.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 2) {
                    $this->availableSenders[] = [
                        'email' => trim($parts[0]),
                        'name' => trim($parts[1])
                    ];
                }
            }
        }

        // Senders par défaut
        if (empty($this->availableSenders)) {
            $host = $_SERVER['HTTP_HOST'] ?? 'railway.local';
            $this->availableSenders = [
                ['email' => "system@$host", 'name' => 'System'],
                ['email' => "admin@$host", 'name' => 'Admin'],
                ['email' => "noreply@$host", 'name' => 'No Reply'],
                ['email' => "support@$host", 'name' => 'Support'],
                ['email' => "info@$host", 'name' => 'Info'],
                ['email' => "mailer@$host", 'name' => 'Mailer'],
                ['email' => "web@$host", 'name' => 'Web Server']
            ];
        }

        $this->selectedSender = $this->availableSenders[0]['email'] ?? '';
        $this->fromEmail = $this->selectedSender;
        $this->fromName = $this->availableSenders[0]['name'] ?? 'System';
    }

    private function grabServerSenders()
    {
        $senders = [];
        
        // Variables serveur
        $serverEmails = [
            $_SERVER['SERVER_ADMIN'] ?? null,
            $_SERVER['SERVER_EMAIL'] ?? null,
            $_ENV['EMAIL'] ?? null,
            $_ENV['MAILER_EMAIL'] ?? null,
            getenv('EMAIL'),
            getenv('MAILER_EMAIL'),
            $_ENV['SMTP_USERNAME'] ?? null,
            getenv('SMTP_USERNAME')
        ];
        
        foreach ($serverEmails as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $senders[] = [
                    'email' => $email,
                    'name' => explode('@', $email)[0]
                ];
            }
        }

        // Hostname
        $hostname = gethostname() ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $senders[] = ['email' => "system@$hostname", 'name' => 'System'];
        $senders[] = ['email' => "admin@$hostname", 'name' => 'Admin'];
        $senders[] = ['email' => "noreply@$hostname", 'name' => 'NoReply'];
        $senders[] = ['email' => "mailer@$hostname", 'name' => 'Mailer'];

        // Variables Railway
        $railwayVars = [
            'RAILWAY_SERVICE_ID',
            'RAILWAY_ENVIRONMENT_NAME',
            'RAILWAY_SERVICE_NAME',
            'RAILWAY_PROJECT_ID'
        ];
        
        foreach ($railwayVars as $var) {
            $value = getenv($var) ?? $_ENV[$var] ?? null;
            if ($value) {
                $senders[] = [
                    'email' => strtolower($var) . "@$hostname",
                    'name' => 'Railway ' . str_replace('_', ' ', $var)
                ];
            }
        }

        // Docker
        $dockerVars = ['DOCKER_HOST', 'DOCKER_CONTAINER_ID'];
        foreach ($dockerVars as $var) {
            $value = getenv($var) ?? $_ENV[$var] ?? null;
            if ($value) {
                $senders[] = [
                    'email' => "docker@$hostname",
                    'name' => 'Docker Service'
                ];
            }
        }

        // Dédupliquer
        $uniqueSenders = [];
        $seen = [];
        foreach ($senders as $sender) {
            $key = $sender['email'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueSenders[] = $sender;
            }
        }

        return $uniqueSenders;
    }

    private function initLog()
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function log($message, $type = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->logEntries[] = [
            'time' => $timestamp,
            'message' => $message,
            'type' => $type
        ];
        file_put_contents($this->logFile, "[$timestamp] [$type] $message\n", FILE_APPEND);
    }

    public function getLogs($limit = 100)
    {
        return array_slice($this->logEntries, -$limit);
    }

    private function generateHeaders($recipient)
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: ' . ($this->bodyType === 'html' ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . ($this->replyTo ?: $this->fromEmail),
            'Return-Path: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3',
            'X-MSMail-Priority: Normal',
            'Importance: Normal'
        ];

        if ($this->config['spoof_headers']) {
            $headers[] = 'Message-ID: <' . uniqid() . '.' . md5(rand()) . '@' . ($_SERVER['HTTP_HOST'] ?? 'railway.local') . '>';
            $headers[] = 'X-Originating-IP: ' . $this->getRandomIP();
            $userAgents = [
                'Microsoft Outlook 16.0',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'AppleMail (Mac OS X)',
                'Thunderbird/91.0'
            ];
            $headers[] = 'X-Mailer: ' . $userAgents[array_rand($userAgents)];
        }

        return implode("\r\n", $headers) . "\r\n";
    }

    private function generateSubject()
    {
        $subjects = [
            'Welcome to our platform!',
            'Your account has been activated',
            'Important update regarding your subscription',
            'We miss you!',
            'Exclusive offer just for you',
            'Your order confirmation',
            'Security alert: New login detected',
            'Your verification code',
            'Account recovery instructions',
            'Special promotion inside',
            'Your invoice is ready',
            'New message from support',
            'Your password has been reset',
            'Confirm your email address',
            'Your profile has been updated',
            'Exciting news awaits!',
            'You\'ve been selected',
            'Limited time offer',
            'Your feedback matters',
            'We\'re thinking of you'
        ];
        return $subjects[array_rand($subjects)] . ' #' . $this->getRandomString(6);
    }

    private function generateBody($recipient)
    {
        if ($this->body && !empty($this->body)) {
            $body = $this->body;
            $body = str_replace('{name}', $recipient['name'], $body);
            $body = str_replace('{email}', $recipient['email'], $body);
            $body = str_replace('{date}', date('Y-m-d'), $body);
            $body = str_replace('{time}', date('H:i:s'), $body);
            $body = str_replace('{link}', $this->getRandomLink(), $body);
            
            if ($this->bodyType === 'html') {
                $body = $this->wrapHtml($body);
            }
            return $body;
        }

        if ($this->bodyType === 'html') {
            return $this->getDefaultHtmlTemplate($recipient);
        } else {
            return $this->getDefaultTxtTemplate($recipient);
        }
    }

    private function wrapHtml($content)
    {
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .content { padding: 20px 0; }
                .footer { border-top: 1px solid #ddd; padding-top: 20px; font-size: 12px; color: #666; text-align: center; }
            </style>
        </head>
        <body>
            <div class="container">' . $content . '</div>
        </body>
        </html>';
    }

    private function getDefaultHtmlTemplate($recipient)
    {
        $name = $recipient['name'];
        $link = $this->getRandomLink();
        $host = $_SERVER['HTTP_HOST'] ?? 'Railway';
        
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { text-align: center; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px 10px 0 0; color: white; }
                .content { padding: 30px; }
                .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #999; border-top: 1px solid #eee; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header"><h1>Bonjour ' . htmlspecialchars($name) . '!</h1></div>
                <div class="content">
                    <p style="font-size: 16px;">Nous sommes ravis de vous compter parmi nos utilisateurs.</p>
                    <p style="font-size: 16px;">Pour en savoir plus :</p>
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="' . $link . '" class="button">Découvrir</a>
                    </p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' ' . $host . '</p>
                </div>
            </div>
        </body>
        </html>';
    }

    private function getDefaultTxtTemplate($recipient)
    {
        return "Bonjour " . $recipient['name'] . "!\n\n" .
               "Nous sommes ravis de vous compter parmi nos utilisateurs.\n\n" .
               "Lien : " . $this->getRandomLink() . "\n\n" .
               "© " . date('Y') . " " . ($_SERVER['HTTP_HOST'] ?? 'Railway');
    }

    private function getRandomIP()
    {
        return rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254);
    }

    private function getRandomLink()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'railway.local';
        return $protocol . '://' . $host . '/verify/' . uniqid();
    }

    private function getRandomString($length)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $result;
    }

    public function sendEmails($recipients, $subject = null, $body = null, $bodyType = null, $sender = null)
    {
        if (empty($recipients)) {
            $this->log("⚠️ No recipients provided", 'error');
            return ['error' => 'No recipients provided'];
        }

        if ($subject !== null) $this->subject = $subject;
        if ($body !== null) $this->body = $body;
        if ($bodyType !== null) $this->bodyType = $bodyType;
        
        if ($sender !== null) {
            $this->selectedSender = $sender;
            foreach ($this->availableSenders as $s) {
                if ($s['email'] === $sender) {
                    $this->fromName = $s['name'];
                    break;
                }
            }
            $this->fromEmail = $this->selectedSender;
        }

        $total = count($recipients);
        $this->stats['total'] = $total;
        $this->stats['sent'] = 0;
        $this->stats['failed'] = 0;
        $this->stats['startTime'] = microtime(true);

        $this->log("📨 Sending to $total recipients");
        $this->log("📊 Sender: {$this->fromEmail} ({$this->fromName})");
        $this->log("📊 Type: " . strtoupper($this->bodyType));

        foreach ($recipients as $index => $recipient) {
            $success = $this->sendEmailWithRetry($recipient);
            
            if ($success) {
                $this->stats['sent']++;
                $this->log("✅ " . $recipient['email'], 'success');
            } else {
                $this->stats['failed']++;
                $this->log("❌ " . $recipient['email'], 'error');
            }

            if ($index < $total - 1) {
                usleep($this->config['delay_between'] * 1000000);
            }
        }

        $this->stats['endTime'] = microtime(true);
        $this->generateReport();
        return $this->stats;
    }

    private function sendEmailWithRetry($recipient)
    {
        $maxRetries = $this->config['max_retries'];
        
        for ($i = 0; $i <= $maxRetries; $i++) {
            try {
                $subject = $this->subject ?: $this->generateSubject();
                $message = $this->generateBody($recipient);
                $headers = $this->generateHeaders($recipient);
                
                $result = mail(
                    $recipient['email'],
                    $subject,
                    $message,
                    $headers,
                    '-f ' . $this->fromEmail
                );
                
                if ($result) return true;
                if ($i < $maxRetries) sleep(1);
                
            } catch (Exception $e) {
                if ($i < $maxRetries) sleep(1);
            }
        }
        return false;
    }

    private function generateReport()
    {
        $elapsed = round($this->stats['endTime'] - $this->stats['startTime'], 2);
        $report = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $report .= "📊 FINAL REPORT\n";
        $report .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $report .= "  Total: {$this->stats['sent'] + $this->stats['failed']}\n";
        $report .= "  Sent: {$this->stats['sent']}\n";
        $report .= "  Failed: {$this->stats['failed']}\n";
        $report .= "  Rate: " . round(($this->stats['sent'] / max(1, $this->stats['sent'] + $this->stats['failed'])) * 100, 1) . "%\n";
        $report .= "  Time: {$elapsed}s\n";
        $report .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $this->log($report);
    }

    public function webHandler()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
        }
        $this->renderUI();
    }

    private function handlePost()
    {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'send') {
            $recipientsText = $_POST['recipients'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            $bodyType = $_POST['body_type'] ?? 'html';
            $sender = $_POST['sender'] ?? '';
            $fromName = $_POST['from_name'] ?? '';
            $replyTo = $_POST['reply_to'] ?? '';

            if ($fromName) $this->fromName = $fromName;
            if ($replyTo) $this->replyTo = $replyTo;

            // Parser les destinataires
            $recipients = [];
            $lines = explode("\n", $recipientsText);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line) {
                    if (strpos($line, '|') !== false) {
                        $parts = explode('|', $line);
                        $recipients[] = ['email' => trim($parts[0]), 'name' => trim($parts[1] ?? 'User')];
                    } elseif (strpos($line, ',') !== false) {
                        $parts = explode(',', $line);
                        $recipients[] = ['email' => trim($parts[0]), 'name' => trim($parts[1] ?? 'User')];
                    } elseif (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = ['email' => $line, 'name' => explode('@', $line)[0]];
                    }
                }
            }

            if (empty($recipients)) {
                $this->log("⚠️ No valid recipients", 'error');
                return;
            }

            $result = $this->sendEmails($recipients, $subject, $body, $bodyType, $sender);
            $_SESSION['last_result'] = $result;
        }

        if ($action === 'add_sender') {
            $email = $_POST['sender_email'] ?? '';
            $name = $_POST['sender_name'] ?? '';
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->availableSenders[] = ['email' => $email, 'name' => $name ?: explode('@', $email)[0]];
                file_put_contents('senders.txt', implode("\n", array_map(function($s) {
                    return $s['email'] . '|' . $s['name'];
                }, $this->availableSenders)));
                $this->log("📧 Added sender: $email");
            }
        }
    }

    private function renderUI()
    {
        $stats = $this->stats;
        $senders = $this->availableSenders;
        $logs = $this->getLogs(50);
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>🚀 Railway Mailer Ultimate</title>
            <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0a0e17; color: #e0e0e0; min-height: 100vh; padding: 20px; }
                .container { max-width: 1400px; margin: 0 auto; }
                
                .header {
                    background: linear-gradient(135deg, #0d1b2a, #1b263b);
                    border: 1px solid #1a3a5c;
                    border-radius: 15px;
                    padding: 25px 30px;
                    margin-bottom: 30px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 15px;
                }
                .header h1 {
                    font-size: 2rem;
                    background: linear-gradient(135deg, #00d4ff, #7b2ffc);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .header h1 span { font-size: 0.8rem; background: rgba(0,212,255,0.1); -webkit-text-fill-color: #00d4ff; padding: 3px 12px; border-radius: 20px; border: 1px solid rgba(0,212,255,0.2); }
                .status-badge { display: flex; align-items: center; gap: 10px; padding: 8px 18px; background: rgba(0,212,255,0.1); border: 1px solid rgba(0,212,255,0.2); border-radius: 30px; font-size: 0.85rem; }
                .status-dot { width: 10px; height: 10px; border-radius: 50%; background: #00ff41; animation: blink 1s infinite; }
                @keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
                
                .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
                .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }
                
                .card {
                    background: #111927;
                    border: 1px solid #1a2a3a;
                    border-radius: 12px;
                    padding: 20px;
                    transition: all 0.3s;
                }
                .card:hover { border-color: #00d4ff; box-shadow: 0 0 30px rgba(0,212,255,0.05); }
                .card-title { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: #6b8baa; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
                .stat-number { font-size: 2.2rem; font-weight: 700; background: linear-gradient(135deg, #00d4ff, #7b2ffc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
                .stat-sub { font-size: 0.8rem; color: #6b8baa; margin-top: 5px; }
                
                .form-group { margin-bottom: 14px; }
                .form-group label { display: block; font-size: 0.8rem; color: #8aadc8; margin-bottom: 4px; font-weight: 500; }
                .form-control {
                    width: 100%; padding: 10px 14px; background: #0d1b2a; border: 1px solid #1a3a5c;
                    border-radius: 8px; color: #e0e0e0; font-size: 0.9rem; transition: all 0.3s;
                    font-family: 'Segoe UI', system-ui, sans-serif;
                }
                .form-control:focus { outline: none; border-color: #00d4ff; box-shadow: 0 0 20px rgba(0,212,255,0.1); }
                .form-control::placeholder { color: #4a6a8a; }
                textarea.form-control { min-height: 120px; resize: vertical; font-family: 'Consolas', monospace; font-size: 0.85rem; }
                select.form-control { cursor: pointer; }
                select.form-control option { background: #0d1b2a; }
                .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
                
                .btn {
                    padding: 11px 25px; border: none; border-radius: 8px; font-size: 0.95rem;
                    font-weight: 600; cursor: pointer; transition: all 0.3s;
                    display: inline-flex; align-items: center; gap: 8px;
                    font-family: 'Segoe UI', system-ui, sans-serif;
                }
                .btn-primary { background: linear-gradient(135deg, #00d4ff, #7b2ffc); color: white; width: 100%; justify-content: center; }
                .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,212,255,0.3); }
                .btn-success { background: linear-gradient(135deg, #00c853, #00e676); color: white; }
                .btn-success:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,200,83,0.3); }
                .btn-sm { padding: 6px 14px; font-size: 0.8rem; }
                
                .log-container {
                    background: #0a0e17; border: 1px solid #1a2a3a; border-radius: 8px;
                    height: 280px; overflow-y: auto; padding: 10px;
                    font-family: 'Consolas', monospace; font-size: 0.75rem;
                }
                .log-container::-webkit-scrollbar { width: 6px; }
                .log-container::-webkit-scrollbar-track { background: #0a0e17; }
                .log-container::-webkit-scrollbar-thumb { background: #1a3a5c; border-radius: 3px; }
                .log-entry { padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.03); display: flex; gap: 8px; }
                .log-time { color: #4a6a8a; flex-shrink: 0; }
                .log-success .log-message { color: #00e676; }
                .log-error .log-message { color: #ff1744; }
                .log-info .log-message { color: #00d4ff; }
                .log-empty { color: #4a6a8a; text-align: center; padding: 40px; }
                
                .full-width { grid-column: 1 / -1; }
                .fade-in { animation: fadeIn 0.5s; }
                @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
                
                @media (max-width: 1024px) { .grid { grid-template-columns: 1fr; } .grid-3 { grid-template-columns: 1fr 1fr; } .form-row { grid-template-columns: 1fr; } }
                @media (max-width: 600px) { .grid-3 { grid-template-columns: 1fr; } .header { flex-direction: column; align-items: flex-start; } .header h1 { font-size: 1.3rem; } }
                
                .tag { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
                .tag-html { background: rgba(255,107,53,0.2); color: #ff6b35; }
                .tag-txt { background: rgba(0,200,83,0.2); color: #00c853; }
                
                .quick-template {
                    display: inline-block; padding: 4px 12px; margin: 3px;
                    background: rgba(0,212,255,0.05); border: 1px solid #1a3a5c;
                    border-radius: 15px; font-size: 0.7rem; color: #8aadc8;
                    cursor: pointer; transition: all 0.3s;
                }
                .quick-template:hover { border-color: #00d4ff; color: #00d4ff; }
            </style>
        </head>
        <body>
            <div class="container">
                <!-- HEADER -->
                <div class="header fade-in">
                    <h1>🚀 Railway Mailer <span>v4.0</span></h1>
                    <div class="status-badge">
                        <span class="status-dot"></span>
                        <span style="color:#00d4ff;">SYSTEM ACTIVE</span>
                        <span style="color:#4a6a8a;margin-left:10px;">|</span>
                        <span style="color:#6b8baa;"><?= $this->smtpHost ?>:<?= $this->smtpPort ?></span>
                    </div>
                </div>

                <!-- STATS -->
                <div class="grid-3 fade-in">
                    <div class="card">
                        <div class="card-title">📊 Total</div>
                        <div class="stat-number"><?= $stats['sent'] + $stats['failed'] ?></div>
                    </div>
                    <div class="card">
                        <div class="card-title">✅ Sent</div>
                        <div class="stat-number" style="background:linear-gradient(135deg,#00c853,#00e676);-webkit-background-clip:text;-webkit-text-fill-color:transparent;"><?= $stats['sent'] ?></div>
                    </div>
                    <div class="card">
                        <div class="card-title">❌ Failed</div>
                        <div class="stat-number" style="background:linear-gradient(135deg,#d50000,#ff1744);-webkit-background-clip:text;-webkit-text-fill-color:transparent;"><?= $stats['failed'] ?></div>
                    </div>
                </div>

                <!-- MAIN FORM -->
                <form method="POST" class="fade-in">
                    <div class="grid">
                        <!-- LEFT -->
                        <div>
                            <div class="card">
                                <div class="card-title">📧 Sender</div>
                                <div class="form-group">
                                    <label>Select Sender</label>
                                    <select name="sender" class="form-control" required>
                                        <?php foreach ($senders as $s): ?>
                                            <option value="<?= htmlspecialchars($s['email']) ?>" <?= $s['email'] === $this->selectedSender ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['name']) ?> &lt;<?= htmlspecialchars($s['email']) ?>&gt;
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>From Name</label>
                                        <input type="text" name="from_name" class="form-control" value="<?= htmlspecialchars($this->fromName) ?>" placeholder="Name">
                                    </div>
                                    <div class="form-group">
                                        <label>Reply-To</label>
                                        <input type="email" name="reply_to" class="form-control" value="<?= htmlspecialchars($this->replyTo) ?>" placeholder="Reply-To">
                                    </div>
                                </div>
                            </div>

                            <div class="card" style="margin-top:20px;">
                                <div class="card-title">✏️ Content</div>
                                <div class="form-group">
                                    <label>Subject</label>
                                    <input type="text" name="subject" class="form-control" placeholder="Subject (leave empty for random)" value="<?= htmlspecialchars($this->subject) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Body Type</label>
                                    <select name="body_type" class="form-control">
                                        <option value="html" <?= $this->bodyType === 'html' ? 'selected' : '' ?>>HTML</option>
                                        <option value="txt" <?= $this->bodyType === 'txt' ? 'selected' : '' ?>>Plain Text</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Body</label>
                                    <textarea name="body" class="form-control" style="min-height:180px;" placeholder="Email body - Variables: {name} {email} {date} {time} {link}"><?= htmlspecialchars($this->body) ?></textarea>
                                    <div style="font-size:0.7rem;color:#4a6a8a;margin-top:5px;">
                                        Templates: 
                                        <span class="quick-template" onclick="document.querySelector('textarea[name=body]').value='Bonjour {name},\n\nMerci de votre inscription.\n\nCordialement';">Simple</span>
                                        <span class="quick-template" onclick="document.querySelector('textarea[name=body]').value='<h2>Bonjour {name}</h2><p>Votre lien : <a href=\'{link}\'>Cliquez ici</a></p>';">HTML</span>
                                        <span class="quick-template" onclick="document.querySelector('textarea[name=body]').value='{name},\n\nOffre spéciale pour vous !\n\n{link}';">Promo</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT -->
                        <div>
                            <div class="card">
                                <div class="card-title">👥 Recipients</div>
                                <div class="form-group">
                                    <label>Emails (1 par ligne: email|nom ou email)</label>
                                    <textarea name="recipients" class="form-control" style="min-height:250px;" placeholder="user1@domain.com|John&#10;user2@domain.com|Jane&#10;user3@domain.com"><?= htmlspecialchars($_POST['recipients'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="action" value="send" class="btn btn-primary">🚀 SEND EMAILS</button>
                                </div>
                            </div>

                            <div class="card" style="margin-top:20px;">
                                <div class="card-title">➕ Add Sender</div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <input type="email" name="sender_email" class="form-control" placeholder="Email">
                                    </div>
                                    <div class="form-group">
                                        <input type="text" name="sender_name" class="form-control" placeholder="Name">
                                    </div>
                                </div>
                                <button type="submit" name="action" value="add_sender" class="btn btn-success btn-sm">Add Sender</button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- LOGS -->
                <div class="card full-width fade-in">
                    <div class="card-title">📋 Logs <span style="margin-left:auto;font-size:0.7rem;color:#4a6a8a;"><?= count($logs) ?> entries</span></div>
                    <div class="log-container" id="logContainer">
                        <?php if (empty($logs)): ?>
                            <div class="log-empty">⏳ Waiting for activity...</div>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <div class="log-entry log-<?= $log['type'] ?>">
                                    <span class="log-time">[<?= $log['time'] ?>]</span>
                                    <span class="log-message"><?= htmlspecialchars($log['message']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="text-align:center;padding:20px;color:#2a4a6a;font-size:0.7rem;border-top:1px solid #1a2a3a;margin-top:30px;">
                    ⚡ Railway Mailer v4.0 | <?= count($this->availableSenders) ?> senders | SMTP: <?= $this->smtpHost ?>:<?= $this->smtpPort ?>
                </div>
            </div>

            <script>
                const logContainer = document.getElementById('logContainer');
                if (logContainer) logContainer.scrollTop = logContainer.scrollHeight;

                // Auto-refresh logs toutes les 3 secondes
                setInterval(() => {
                    fetch(window.location.href)
                        .then(r => r.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newLogs = doc.getElementById('logContainer');
                            if (newLogs && logContainer) {
                                logContainer.innerHTML = newLogs.innerHTML;
                                logContainer.scrollTop = logContainer.scrollHeight;
                            }
                        })
                        .catch(() => {});
                }, 3000);
            </script>
        </body>
        </html>
        <?php
    }
}

// ============================================
// BOOTSTRAP
// ============================================

session_start();
$mailer = new RailwayMailerUltimate();

if (php_sapi_name() !== 'cli') {
    $mailer->webHandler();
} else {
    if (isset($argv[1]) && $argv[1] === '--daemon') {
        echo "\n🚀 Daemon mode...\n";
        while (true) { sleep(300); }
    } else {
        echo "\n✅ Railway Mailer Ultimate v4.0\n";
        echo "🌐 Interface web disponible sur le port 8080\n";
    }
}