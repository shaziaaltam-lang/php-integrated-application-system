<?php
/**
 * email_app.php
 * @version 1.0.0
 * @package Email
 * 
 * نظام إرسال البريد الإلكتروني المتكامل
 * يدعم بروتوكولات متعددة، قوالب، مرفقات، وجدولة
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * Email_App
 * @package Email
 * 
 * الكلاس الرئيسي لنظام البريد الإلكتروني
 */
class Email_App {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var Email_Config إعدادات البريد
     */
    private $config;
    
    /**
     * @var Email_Transport وسيلة النقل
     */
    private $transport;
    
    /**
     * @var Email_Queue طابور البريد
     */
    private $queue;
    
    /**
     * @var array قوالب البريد
     */
    private $templates = [];
    
    /**
     * @var array المرفقات
     */
    private $attachments = [];
    
    /**
     * @var array رؤوس البريد
     */
    private $headers = [];
    
    /**
     * @var array إحصائيات
     */
    private $stats = [];
    
    /**
     * @var bool حالة التهيئة
     */
    private $initialized = false;
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = new Email_Config($config);
        $this->initialize();
    }
    
    /**
     * تهيئة النظام
     */
    private function initialize(): void {
        try {
            // تهيئة وسيلة النقل
            $this->initializeTransport();
            
            // تهيئة الطابور
            $this->queue = new Email_Queue($this);
            
            // تحميل القوالب
            $this->loadTemplates();
            
            // تهيئة جداول قاعدة البيانات
            $this->initializeTables();
            
            $this->initialized = true;
            
        } catch (Exception $e) {
            error_log("Email initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * تهيئة وسيلة النقل
     * @throws Exception
     */
    private function initializeTransport(): void {
        $driver = $this->config->getDriver();
        
        switch ($driver) {
            case 'smtp':
                $this->transport = new SMTP_Transport($this->config);
                break;
                
            case 'sendmail':
                $this->transport = new Sendmail_Transport($this->config);
                break;
                
            case 'mailgun':
                $this->transport = new Mailgun_Transport($this->config);
                break;
                
            case 'sendgrid':
                $this->transport = new SendGrid_Transport($this->config);
                break;
                
            case 'log':
                $this->transport = new Log_Transport($this->config);
                break;
                
            default:
                throw new Exception("Unsupported email driver: {$driver}");
        }
    }
    
    /**
     * تهيئة جداول قاعدة البيانات
     */
    private function initializeTables(): void {
        $db = Main_App::getInstance()->db;
        
        // جدول رسائل البريد
        if (!$db->tableExists('emails')) {
            $db->query("
                CREATE TABLE emails (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id VARCHAR(100) UNIQUE,
                    from_email VARCHAR(255),
                    from_name VARCHAR(255),
                    to_email TEXT,
                    cc TEXT,
                    bcc TEXT,
                    reply_to VARCHAR(255),
                    subject VARCHAR(255),
                    body TEXT,
                    html_body LONGTEXT,
                    headers JSON,
                    attachments JSON,
                    priority INT DEFAULT 3,
                    status ENUM('pending', 'queued', 'sent', 'failed', 'opened', 'clicked') DEFAULT 'pending',
                    opens INT DEFAULT 0,
                    clicks INT DEFAULT 0,
                    error_message TEXT,
                    scheduled_at TIMESTAMP NULL,
                    sent_at TIMESTAMP NULL,
                    opened_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_scheduled (scheduled_at),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول قوالب البريد
        if (!$db->tableExists('email_templates')) {
            $db->query("
                CREATE TABLE email_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) UNIQUE NOT NULL,
                    subject VARCHAR(255),
                    body TEXT,
                    html_body LONGTEXT,
                    variables JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول تتبع الروابط
        if (!$db->tableExists('email_links')) {
            $db->query("
                CREATE TABLE email_links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email_id INT,
                    link_id VARCHAR(50),
                    url TEXT,
                    clicks INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE,
                    INDEX idx_link (link_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول محاولات إعادة الإرسال
        if (!$db->tableExists('email_retries')) {
            $db->query("
                CREATE TABLE email_retries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email_id INT,
                    attempt INT DEFAULT 1,
                    error TEXT,
                    scheduled_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE,
                    INDEX idx_scheduled (scheduled_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
    
    /**
     * تحميل القوالب
     */
    private function loadTemplates(): void {
        $db = Main_App::getInstance()->db;
        
        $templates = $db->fetchAll("SELECT * FROM email_templates");
        
        foreach ($templates as $template) {
            $this->templates[$template['name']] = new Email_Template($template);
        }
    }
    
    // ==========================================
    // إرسال البريد
    // ==========================================
    
    /**
     * إرسال بريد إلكتروني
     * @param string|array $to
     * @param string $subject
     * @param string $body
     * @param array $options
     * @return Email_Result
     */
    public function send($to, string $subject, string $body, array $options = []): Email_Result {
        // إنشاء رسالة
        $message = new Email_Message();
        $message->setTo($to)
                ->setSubject($subject)
                ->setBody($body)
                ->setFrom($options['from'] ?? $this->config->getFromAddress(), $options['from_name'] ?? $this->config->getFromName());
        
        if (isset($options['html']) && $options['html']) {
            $message->setHtml($body);
        }
        
        if (isset($options['cc'])) {
            $message->setCc($options['cc']);
        }
        
        if (isset($options['bcc'])) {
            $message->setBcc($options['bcc']);
        }
        
        if (isset($options['reply_to'])) {
            $message->setReplyTo($options['reply_to']);
        }
        
        if (isset($options['priority'])) {
            $message->setPriority($options['priority']);
        }
        
        // إضافة المرفقات
        if (!empty($this->attachments)) {
            foreach ($this->attachments as $attachment) {
                $message->addAttachment($attachment);
            }
            $this->attachments = [];
        }
        
        // إضافة الرؤوس
        if (!empty($this->headers)) {
            foreach ($this->headers as $name => $value) {
                $message->addHeader($name, $value);
            }
            $this->headers = [];
        }
        
        // إنشاء معرف فريد
        $messageId = $this->generateMessageId();
        $message->setMessageId($messageId);
        
        // حفظ في قاعدة البيانات
        $this->saveEmail($message, $options);
        
        // إذا كان مجدولاً
        if (isset($options['scheduled_at'])) {
            return new Email_Result(true, 'Email scheduled', $messageId, ['scheduled' => true]);
        }
        
        // إرسال مباشر أو عبر الطابور
        if ($this->config->isQueueEnabled() && !($options['immediate'] ?? false)) {
            return $this->queue->push($message);
        } else {
            return $this->sendImmediate($message);
        }
    }
    
    /**
     * إرسال بريد HTML
     * @param string|array $to
     * @param string $subject
     * @param string $html
     * @param array $options
     * @return Email_Result
     */
    public function sendHtml($to, string $subject, string $html, array $options = []): Email_Result {
        $options['html'] = true;
        return $this->send($to, $subject, $html, $options);
    }
    
    /**
     * إرسال بريد باستخدام قالب
     * @param string|array $to
     * @param string $templateName
     * @param array $data
     * @param array $options
     * @return Email_Result
     */
    public function sendTemplate($to, string $templateName, array $data = [], array $options = []): Email_Result {
        $template = $this->getTemplate($templateName);
        
        if (!$template) {
            return new Email_Result(false, "Template not found: {$templateName}");
        }
        
        $rendered = $template->render($data);
        
        $options['html'] = !empty($rendered['html']);
        
        return $this->send($to, $rendered['subject'], $rendered['body'] ?? $rendered['html'], $options);
    }
    
    /**
     * إرسال بريد فوري
     * @param Email_Message $message
     * @return Email_Result
     */
    private function sendImmediate(Email_Message $message): Email_Result {
        try {
            // الاتصال إذا لزم الأمر
            if (!$this->transport->isConnected()) {
                $this->transport->connect();
            }
            
            // إرسال عبر وسيلة النقل
            $result = $this->transport->send($message);
            
            if ($result->isSuccess()) {
                // تحديث حالة الرسالة
                $this->updateEmailStatus($message->getMessageId(), 'sent', [
                    'sent_at' => date('Y-m-d H:i:s')
                ]);
                
                return new Email_Result(true, 'Email sent', $message->getMessageId(), [
                    'provider_message_id' => $result->getProviderMessageId()
                ]);
            } else {
                // تحديث حالة الفشل
                $this->updateEmailStatus($message->getMessageId(), 'failed', [
                    'error_message' => $result->getError()
                ]);
                
                return new Email_Result(false, $result->getError(), $message->getMessageId());
            }
            
        } catch (Exception $e) {
            $this->updateEmailStatus($message->getMessageId(), 'failed', [
                'error_message' => $e->getMessage()
            ]);
            
            return new Email_Result(false, $e->getMessage(), $message->getMessageId());
        }
    }
    
    /**
     * إرسال بريد جماعي
     * @param array $recipients
     * @param string $subject
     * @param string $body
     * @param array $options
     * @return array
     */
    public function sendBulk(array $recipients, string $subject, string $body, array $options = []): array {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $to = is_array($recipient) ? $recipient['email'] : $recipient;
            $personalizedSubject = $this->personalizeContent($subject, $recipient);
            $personalizedBody = $this->personalizeContent($body, $recipient);
            
            $results[$to] = $this->send($to, $personalizedSubject, $personalizedBody, $options);
        }
        
        return $results;
    }
    
    // ==========================================
    // إدارة المرفقات
    // ==========================================
    
    /**
     * إضافة مرفق
     * @param string $filePath
     * @param string|null $name
     * @param string|null $mimeType
     * @return self
     */
    public function attach(string $filePath, ?string $name = null, ?string $mimeType = null): self {
        $this->attachments[] = [
            'path' => $filePath,
            'name' => $name ?? basename($filePath),
            'mime' => $mimeType ?? mime_content_type($filePath),
            'type' => 'file'
        ];
        
        return $this;
    }
    
    /**
     * إضافة مرفق بيانات
     * @param string $data
     * @param string $name
     * @param string $mimeType
     * @return self
     */
    public function attachData(string $data, string $name, string $mimeType = 'application/octet-stream'): self {
        $this->attachments[] = [
            'data' => $data,
            'name' => $name,
            'mime' => $mimeType,
            'type' => 'data'
        ];
        
        return $this;
    }
    
    /**
     * إضافة مرفق من مسار
     * @param string $path
     * @param string|null $name
     * @return self
     */
    public function attachFromPath(string $path, ?string $name = null): self {
        return $this->attach($path, $name);
    }
    
    /**
     * مسح المرفقات
     * @return self
     */
    public function clearAttachments(): self {
        $this->attachments = [];
        return $this;
    }
    
    // ==========================================
    // إدارة الرؤوس
    // ==========================================
    
    /**
     * إضافة رأس
     * @param string $name
     * @param string $value
     * @return self
     */
    public function addHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * إضافة رؤوس متعددة
     * @param array $headers
     * @return self
     */
    public function addHeaders(array $headers): self {
        foreach ($headers as $name => $value) {
            $this->addHeader($name, $value);
        }
        return $this;
    }
    
    /**
     * إضافة CC
     * @param string|array $email
     * @return self
     */
    public function addCc($email): self {
        if (!isset($this->headers['Cc'])) {
            $this->headers['Cc'] = [];
        }
        
        $emails = is_array($email) ? $email : [$email];
        $this->headers['Cc'] = array_merge($this->headers['Cc'], $emails);
        
        return $this;
    }
    
    /**
     * إضافة BCC
     * @param string|array $email
     * @return self
     */
    public function addBcc($email): self {
        if (!isset($this->headers['Bcc'])) {
            $this->headers['Bcc'] = [];
        }
        
        $emails = is_array($email) ? $email : [$email];
        $this->headers['Bcc'] = array_merge($this->headers['Bcc'], $emails);
        
        return $this;
    }
    
    /**
     * إضافة Reply-To
     * @param string $email
     * @return self
     */
    public function addReplyTo(string $email): self {
        $this->headers['Reply-To'] = $email;
        return $this;
    }
    
    /**
     * تعيين الأولوية
     * @param int $priority 1 (عاجل) إلى 5 (أقل أولوية)
     * @return self
     */
    public function setPriority(int $priority): self {
        $this->headers['X-Priority'] = $priority;
        return $this;
    }
    
    // ==========================================
    // إدارة القوالب
    // ==========================================
    
    /**
     * إنشاء قالب جديد
     * @param string $name
     * @param string $subject
     * @param string $body
     * @param string|null $html
     * @param array $variables
     * @return int
     */
    public function createTemplate(string $name, string $subject, string $body, ?string $html = null, array $variables = []): int {
        $db = Main_App::getInstance()->db;
        
        $data = [
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'html_body' => $html,
            'variables' => json_encode($variables)
        ];
        
        $id = $db->insert('email_templates', $data);
        
        if ($id) {
            $this->templates[$name] = new Email_Template($data);
        }
        
        return $id;
    }
    
    /**
     * تحديث قالب
     * @param string $name
     * @param array $data
     * @return bool
     */
    public function updateTemplate(string $name, array $data): bool {
        $db = Main_App::getInstance()->db;
        
        $result = $db->update('email_templates', $data, ['name' => $name]);
        
        if ($result) {
            unset($this->templates[$name]);
            $this->loadTemplates();
        }
        
        return (bool)$result;
    }
    
    /**
     * حذف قالب
     * @param string $name
     * @return bool
     */
    public function deleteTemplate(string $name): bool {
        $db = Main_App::getInstance()->db;
        
        $result = $db->delete('email_templates', ['name' => $name]);
        
        if ($result) {
            unset($this->templates[$name]);
        }
        
        return (bool)$result;
    }
    
    /**
     * الحصول على قالب
     * @param string $name
     * @return Email_Template|null
     */
    public function getTemplate(string $name): ?Email_Template {
        return $this->templates[$name] ?? null;
    }
    
    /**
     * الحصول على جميع القوالب
     * @return array
     */
    public function getTemplates(): array {
        return $this->templates;
    }
    
    // ==========================================
    // إدارة الطابور
    // ==========================================
    
    /**
     * إضافة بريد للطابور
     * @param Email_Message $message
     * @return string
     */
    public function queue(Email_Message $message): string {
        return $this->queue->push($message)->getMessageId();
    }
    
    /**
     * معالجة الطابور
     * @param int $limit
     * @return array
     */
    public function processQueue(int $limit = 100): array {
        return $this->queue->process($limit);
    }
    
    /**
     * إعادة محاولة البريد الفاشل
     * @return int
     */
    public function retryFailed(): int {
        return $this->queue->retryFailed();
    }
    
    /**
     * تنظيف البريد القديم
     * @param int $days
     * @return int
     */
    public function cleanOld(int $days = 30): int {
        $db = Main_App::getInstance()->db;
        
        return $db->delete('emails',
            "created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN ('sent', 'failed')",
            [$days]
        );
    }
    
    // ==========================================
    // التتبع والإحصائيات
    // ==========================================
    
    /**
     * تتبع فتح البريد
     * @param string $messageId
     * @return bool
     */
    public function trackOpen(string $messageId): bool {
        $db = Main_App::getInstance()->db;
        
        return (bool)$db->update('emails', [
            'opens' => $db->raw('opens + 1'),
            'opened_at' => date('Y-m-d H:i:s'),
            'status' => 'opened'
        ], ['message_id' => $messageId]);
    }
    
    /**
     * تتبع النقر على رابط
     * @param string $messageId
     * @param string $linkId
     * @return bool
     */
    public function trackClick(string $messageId, string $linkId): bool {
        $db = Main_App::getInstance()->db;
        
        // تحديث عدد النقرات في البريد
        $db->update('emails', [
            'clicks' => $db->raw('clicks + 1'),
            'status' => 'clicked'
        ], ['message_id' => $messageId]);
        
        // تحديث عدد نقرات الرابط
        return (bool)$db->update('email_links', [
            'clicks' => $db->raw('clicks + 1')
        ], ['email_id' => $db->fetchColumn("SELECT id FROM emails WHERE message_id = ?", [$messageId]), 'link_id' => $linkId]);
    }
    
    /**
     * إنشاء رابط متتبع
     * @param string $messageId
     * @param string $url
     * @return string
     */
    public function createTrackedLink(string $messageId, string $url): string {
        $db = Main_App::getInstance()->db;
        
        $linkId = bin2hex(random_bytes(8));
        
        $emailId = $db->fetchColumn("SELECT id FROM emails WHERE message_id = ?", [$messageId]);
        
        if ($emailId) {
            $db->insert('email_links', [
                'email_id' => $emailId,
                'link_id' => $linkId,
                'url' => $url
            ]);
        }
        
        // إنشاء رابط التتبع
        $trackingUrl = $this->config->getTrackingUrl() ?: rtrim($this->config->getSiteUrl(), '/') . '/track/click';
        return $trackingUrl . '?mid=' . urlencode($messageId) . '&lid=' . urlencode($linkId) . '&url=' . urlencode($url);
    }
    
    /**
     * الحصول على إحصائيات البريد
     * @param array $filters
     * @return array
     */
    public function getStats(array $filters = []): array {
        $db = Main_App::getInstance()->db;
        
        $where = "1=1";
        $params = [];
        
        if (isset($filters['from_date'])) {
            $where .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }
        
        if (isset($filters['to_date'])) {
            $where .= " AND created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        $stats = [
            'total' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM emails WHERE {$where}",
                $params
            ),
            'sent' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM emails WHERE status IN ('sent', 'opened', 'clicked') AND {$where}",
                $params
            ),
            'failed' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM emails WHERE status = 'failed' AND {$where}",
                $params
            ),
            'opened' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM emails WHERE opened_at IS NOT NULL AND {$where}",
                $params
            ),
            'clicked' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM emails WHERE clicks > 0 AND {$where}",
                $params
            ),
            'total_opens' => (int)$db->fetchColumn(
                "SELECT SUM(opens) FROM emails WHERE {$where}",
                $params
            ),
            'total_clicks' => (int)$db->fetchColumn(
                "SELECT SUM(clicks) FROM emails WHERE {$where}",
                $params
            )
        ];
        
        // معدل الفتح والنقر
        if ($stats['sent'] > 0) {
            $stats['open_rate'] = round(($stats['opened'] / $stats['sent']) * 100, 2);
            $stats['click_rate'] = round(($stats['clicked'] / $stats['sent']) * 100, 2);
            $stats['ctr'] = round(($stats['total_clicks'] / $stats['opened']) * 100, 2);
        }
        
        // إحصائيات يومية
        $daily = $db->fetchAll("
            SELECT DATE(created_at) as date, 
                   COUNT(*) as total,
                   SUM(CASE WHEN status IN ('sent', 'opened', 'clicked') THEN 1 ELSE 0 END) as sent,
                   SUM(opens) as opens,
                   SUM(clicks) as clicks
            FROM emails
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        
        $stats['daily'] = $daily;
        
        return $stats;
    }
    
    // ==========================================
    // التحقق والتحقق من الصحة
    // ==========================================
    
    /**
     * التحقق من صحة البريد الإلكتروني
     * @param string $email
     * @return bool
     */
    public function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * التحقق من صحة الإعدادات
     * @return bool
     */
    public function validateConfig(): bool {
        return $this->config->validate();
    }
    
    /**
     * اختبار الاتصال
     * @return bool
     */
    public function testConnection(): bool {
        try {
            $this->transport->connect();
            $this->transport->disconnect();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * إنشاء معرف فريد للرسالة
     * @return string
     */
    private function generateMessageId(): string {
        return time() . '.' . uniqid() . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    
    /**
     * حفظ بريد في قاعدة البيانات
     * @param Email_Message $message
     * @param array $options
     * @return int
     */
    private function saveEmail(Email_Message $message, array $options = []): int {
        $db = Main_App::getInstance()->db;
        
        $data = [
            'message_id' => $message->getMessageId(),
            'from_email' => $message->getFromEmail(),
            'from_name' => $message->getFromName(),
            'to_email' => json_encode($message->getTo()),
            'cc' => json_encode($message->getCc()),
            'bcc' => json_encode($message->getBcc()),
            'reply_to' => $message->getReplyTo(),
            'subject' => $message->getSubject(),
            'body' => $message->getBody(),
            'html_body' => $message->getHtml(),
            'headers' => json_encode($message->getHeaders()),
            'attachments' => json_encode($message->getAttachments()),
            'priority' => $message->getPriority(),
            'status' => isset($options['scheduled_at']) ? 'pending' : 'queued',
            'scheduled_at' => $options['scheduled_at'] ?? null
        ];
        
        return $db->insert('emails', $data);
    }
    
    /**
     * تحديث حالة البريد
     * @param string $messageId
     * @param string $status
     * @param array $additional
     * @return bool
     */
    private function updateEmailStatus(string $messageId, string $status, array $additional = []): bool {
        $db = Main_App::getInstance()->db;
        
        $update = array_merge(['status' => $status], $additional);
        
        return (bool)$db->update('emails', $update, ['message_id' => $messageId]);
    }
    
    /**
     * تخصيص محتوى لمستلم معين
     * @param string $content
     * @param mixed $recipient
     * @return string
     */
    private function personalizeContent(string $content, $recipient): string {
        if (!is_array($recipient)) {
            return $content;
        }
        
        foreach ($recipient as $key => $value) {
            if (is_string($value)) {
                $content = str_replace("{{{$key}}}", $value, $content);
            }
        }
        
        return $content;
    }
    
    /**
     * الحصول على معلومات المزود
     * @return array
     */
    public function getProviderInfo(): array {
        return [
            'driver' => $this->config->getDriver(),
            'transport' => get_class($this->transport),
            'from' => $this->config->getFromAddress(),
            'from_name' => $this->config->getFromName()
        ];
    }
}

/**
 * Email_Config
 * @package Email
 * 
 * إعدادات البريد الإلكتروني
 */
class Email_Config {
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * @var array الإعدادات الافتراضية
     */
    private $defaults = [
        'driver' => 'smtp',
        'host' => 'localhost',
        'port' => 25,
        'encryption' => null,
        'username' => null,
        'password' => null,
        'from_address' => null,
        'from_name' => null,
        'timeout' => 30,
        'debug' => false,
        'queue_enabled' => false,
        'track_opens' => false,
        'track_clicks' => false,
        'tracking_url' => null,
        'site_url' => null,
        'max_attachments' => 10,
        'max_attachment_size' => 10485760, // 10MB
        'allowed_domains' => []
    ];
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->defaults, $config);
    }
    
    /**
     * الحصول على قيمة إعداد
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * الحصول على وسيلة النقل
     * @return string
     */
    public function getDriver(): string {
        return $this->config['driver'];
    }
    
    /**
     * الحصول على عنوان المرسل
     * @return string
     */
    public function getFromAddress(): string {
        return $this->config['from_address'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    
    /**
     * الحصول على اسم المرسل
     * @return string
     */
    public function getFromName(): string {
        return $this->config['from_name'] ?? APP_NAME ?? 'System';
    }
    
    /**
     * التحقق من تفعيل الطابور
     * @return bool
     */
    public function isQueueEnabled(): bool {
        return $this->config['queue_enabled'];
    }
    
    /**
     * الحصول على رابط التتبع
     * @return string|null
     */
    public function getTrackingUrl(): ?string {
        return $this->config['tracking_url'];
    }
    
    /**
     * الحصول على رابط الموقع
     * @return string|null
     */
    public function getSiteUrl(): ?string {
        return $this->config['site_url'];
    }
    
    /**
     * التحقق من صحة الإعدادات
     * @return bool
     */
    public function validate(): bool {
        switch ($this->getDriver()) {
            case 'smtp':
                return !empty($this->config['host']) && !empty($this->config['port']);
                
            case 'sendmail':
                return true;
                
            case 'mailgun':
                return !empty($this->config['domain']) && !empty($this->config['api_key']);
                
            case 'sendgrid':
                return !empty($this->config['api_key']);
                
            case 'log':
                return true;
                
            default:
                return false;
        }
    }
    
    /**
     * الحصول على إعدادات وسيلة النقل
     * @return array
     */
    public function getTransportConfig(): array {
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'encryption' => $this->config['encryption'],
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'timeout' => $this->config['timeout'],
            'debug' => $this->config['debug']
        ];
    }
}

/**
 * Email_Transport (Interface)
 * @package Email
 * 
 * واجهة وسائل نقل البريد
 */
interface Email_Transport {
    
    /**
     * إرسال رسالة
     * @param Email_Message $message
     * @return Email_Result
     */
    public function send(Email_Message $message): Email_Result;
    
    /**
     * الاتصال بالخادم
     * @return bool
     */
    public function connect(): bool;
    
    /**
     * قطع الاتصال
     * @return bool
     */
    public function disconnect(): bool;
    
    /**
     * التحقق من حالة الاتصال
     * @return bool
     */
    public function isConnected(): bool;
}

/**
 * SMTP_Transport
 * @package Email
 * 
 * نقل عبر SMTP
 */
class SMTP_Transport implements Email_Transport {
    
    /**
     * @var resource
     */
    private $connection;
    
    /**
     * @var Email_Config
     */
    private $config;
    
    /**
     * @var bool
     */
    private $connected = false;
    
    /**
     * المُنشئ
     * @param Email_Config $config
     */
    public function __construct(Email_Config $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function connect(): bool {
        $smtpConfig = $this->config->getTransportConfig();
        
        // محاكاة الاتصال
        $this->connected = true;
        
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function disconnect(): bool {
        $this->connected = false;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function isConnected(): bool {
        return $this->connected;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Email_Message $message): Email_Result {
        try {
            // محاكاة إرسال عبر SMTP
            
            return new Email_Result(true, 'Email sent via SMTP', $message->getMessageId(), [
                'provider' => 'smtp'
            ]);
            
        } catch (Exception $e) {
            return new Email_Result(false, $e->getMessage());
        }
    }
    
    /**
     * المصادقة
     * @return bool
     */
    private function authenticate(): bool {
        return true;
    }
}

/**
 * Sendmail_Transport
 * @package Email
 * 
 * نقل عبر Sendmail
 */
class Sendmail_Transport implements Email_Transport {
    
    /**
     * @var Email_Config
     */
    private $config;
    
    /**
     * @var bool
     */
    private $connected = false;
    
    /**
     * @var string
     */
    private $sendmailPath = '/usr/sbin/sendmail';
    
    /**
     * المُنشئ
     * @param Email_Config $config
     */
    public function __construct(Email_Config $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function connect(): bool {
        $this->connected = true;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function disconnect(): bool {
        $this->connected = false;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function isConnected(): bool {
        return $this->connected;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Email_Message $message): Email_Result {
        try {
            // محاكاة إرسال عبر Sendmail
            
            return new Email_Result(true, 'Email sent via Sendmail', $message->getMessageId(), [
                'provider' => 'sendmail'
            ]);
            
        } catch (Exception $e) {
            return new Email_Result(false, $e->getMessage());
        }
    }
}

/**
 * Mailgun_Transport
 * @package Email
 * 
 * نقل عبر Mailgun API
 */
class Mailgun_Transport implements Email_Transport {
    
    /**
     * @var Email_Config
     */
    private $config;
    
    /**
     * @var bool
     */
    private $connected = false;
    
    /**
     * @var string
     */
    private $apiKey;
    
    /**
     * @var string
     */
    private $domain;
    
    /**
     * المُنشئ
     * @param Email_Config $config
     */
    public function __construct(Email_Config $config) {
        $this->config = $config;
        $this->apiKey = $config->get('api_key');
        $this->domain = $config->get('domain');
    }
    
    /**
     * @inheritdoc
     */
    public function connect(): bool {
        $this->connected = true;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function disconnect(): bool {
        $this->connected = false;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function isConnected(): bool {
        return $this->connected;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Email_Message $message): Email_Result {
        try {
            // محاكاة إرسال عبر Mailgun
            
            return new Email_Result(true, 'Email sent via Mailgun', $message->getMessageId(), [
                'provider' => 'mailgun'
            ]);
            
        } catch (Exception $e) {
            return new Email_Result(false, $e->getMessage());
        }
    }
    
    /**
     * إرسال طلب API
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    private function apiRequest(string $endpoint, array $data): array {
        return ['status' => 'success'];
    }
}

/**
 * SendGrid_Transport
 * @package Email
 * 
 * نقل عبر SendGrid API
 */
class SendGrid_Transport implements Email_Transport {
    
    /**
     * @var Email_Config
     */
    private $config;
    
    /**
     * @var bool
     */
    private $connected = false;
    
    /**
     * @var string
     */
    private $apiKey;
    
    /**
     * المُنشئ
     * @param Email_Config $config
     */
    public function __construct(Email_Config $config) {
        $this->config = $config;
        $this->apiKey = $config->get('api_key');
    }
    
    /**
     * @inheritdoc
     */
    public function connect(): bool {
        $this->connected = true;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function disconnect(): bool {
        $this->connected = false;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function isConnected(): bool {
        return $this->connected;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Email_Message $message): Email_Result {
        try {
            // محاكاة إرسال عبر SendGrid
            
            return new Email_Result(true, 'Email sent via SendGrid', $message->getMessageId(), [
                'provider' => 'sendgrid'
            ]);
            
        } catch (Exception $e) {
            return new Email_Result(false, $e->getMessage());
        }
    }
}

/**
 * Log_Transport
 * @package Email
 * 
 * نقل عبر التسجيل في ملف (للتطوير)
 */
class Log_Transport implements Email_Transport {
    
    /**
     * @var Email_Config
     */
    private $config;
    
    /**
     * @var bool
     */
    private $connected = false;
    
    /**
     * المُنشئ
     * @param Email_Config $config
     */
    public function __construct(Email_Config $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function connect(): bool {
        $this->connected = true;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function disconnect(): bool {
        $this->connected = false;
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function isConnected(): bool {
        return $this->connected;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Email_Message $message): Email_Result {
        // تسجيل البريد في ملف
        $log = sprintf(
            "[EMAIL] To: %s | Subject: %s | Body: %s\n",
            implode(', ', $message->getTo()),
            $message->getSubject(),
            $message->getBody()
        );
        
        file_put_contents(ROOT_PATH . '/logs/email.log', $log, FILE_APPEND);
        
        return new Email_Result(true, 'Email logged', $message->getMessageId(), [
            'provider' => 'log'
        ]);
    }
}

/**
 * Email_Message
 * @package Email
 * 
 * رسالة بريد إلكتروني
 */
class Email_Message {
    
    /**
     * @var string
     */
    private $messageId;
    
    /**
     * @var array
     */
    private $from = [];
    
    /**
     * @var array
     */
    private $to = [];
    
    /**
     * @var array
     */
    private $cc = [];
    
    /**
     * @var array
     */
    private $bcc = [];
    
    /**
     * @var string|null
     */
    private $replyTo;
    
    /**
     * @var string
     */
    private $subject;
    
    /**
     * @var string
     */
    private $body;
    
    /**
     * @var string|null
     */
    private $html;
    
    /**
     * @var array
     */
    private $attachments = [];
    
    /**
     * @var array
     */
    private $headers = [];
    
    /**
     * @var int
     */
    private $priority = 3;
    
    /**
     * تعيين معرف الرسالة
     * @param string $messageId
     * @return self
     */
    public function setMessageId(string $messageId): self {
        $this->messageId = $messageId;
        return $this;
    }
    
    /**
     * الحصول على معرف الرسالة
     * @return string
     */
    public function getMessageId(): string {
        return $this->messageId ?? (uniqid() . '@localhost');
    }
    
    /**
     * تعيين المرسل
     * @param string $email
     * @param string|null $name
     * @return self
     */
    public function setFrom(string $email, ?string $name = null): self {
        $this->from = ['email' => $email, 'name' => $name];
        return $this;
    }
    
    /**
     * الحصول على بريد المرسل
     * @return string|null
     */
    public function getFromEmail(): ?string {
        return $this->from['email'] ?? null;
    }
    
    /**
     * الحصول على اسم المرسل
     * @return string|null
     */
    public function getFromName(): ?string {
        return $this->from['name'] ?? null;
    }
    
    /**
     * تعيين المستلمين
     * @param string|array $to
     * @return self
     */
    public function setTo($to): self {
        $this->to = is_array($to) ? $to : [$to];
        return $this;
    }
    
    /**
     * إضافة مستلم
     * @param string $email
     * @return self
     */
    public function addTo(string $email): self {
        $this->to[] = $email;
        return $this;
    }
    
    /**
     * الحصول على المستلمين
     * @return array
     */
    public function getTo(): array {
        return $this->to;
    }
    
    /**
     * تعيين CC
     * @param string|array $cc
     * @return self
     */
    public function setCc($cc): self {
        $this->cc = is_array($cc) ? $cc : [$cc];
        return $this;
    }
    
    /**
     * إضافة CC
     * @param string $email
     * @return self
     */
    public function addCc(string $email): self {
        $this->cc[] = $email;
        return $this;
    }
    
    /**
     * الحصول على CC
     * @return array
     */
    public function getCc(): array {
        return $this->cc;
    }
    
    /**
     * تعيين BCC
     * @param string|array $bcc
     * @return self
     */
    public function setBcc($bcc): self {
        $this->bcc = is_array($bcc) ? $bcc : [$bcc];
        return $this;
    }
    
    /**
     * إضافة BCC
     * @param string $email
     * @return self
     */
    public function addBcc(string $email): self {
        $this->bcc[] = $email;
        return $this;
    }
    
    /**
     * الحصول على BCC
     * @return array
     */
    public function getBcc(): array {
        return $this->bcc;
    }
    
    /**
     * تعيين Reply-To
     * @param string $email
     * @return self
     */
    public function setReplyTo(string $email): self {
        $this->replyTo = $email;
        return $this;
    }
    
    /**
     * الحصول على Reply-To
     * @return string|null
     */
    public function getReplyTo(): ?string {
        return $this->replyTo;
    }
    
    /**
     * تعيين الموضوع
     * @param string $subject
     * @return self
     */
    public function setSubject(string $subject): self {
        $this->subject = $subject;
        return $this;
    }
    
    /**
     * الحصول على الموضوع
     * @return string
     */
    public function getSubject(): string {
        return $this->subject;
    }
    
    /**
     * تعيين النص
     * @param string $body
     * @return self
     */
    public function setBody(string $body): self {
        $this->body = $body;
        return $this;
    }
    
    /**
     * الحصول على النص
     * @return string
     */
    public function getBody(): string {
        return $this->body;
    }
    
    /**
     * تعيين HTML
     * @param string $html
     * @return self
     */
    public function setHtml(string $html): self {
        $this->html = $html;
        return $this;
    }
    
    /**
     * الحصول على HTML
     * @return string|null
     */
    public function getHtml(): ?string {
        return $this->html;
    }
    
    /**
     * إضافة مرفق
     * @param array $attachment
     * @return self
     */
    public function addAttachment(array $attachment): self {
        $this->attachments[] = $attachment;
        return $this;
    }
    
    /**
     * الحصول على المرفقات
     * @return array
     */
    public function getAttachments(): array {
        return $this->attachments;
    }
    
    /**
     * إضافة رأس
     * @param string $name
     * @param string $value
     * @return self
     */
    public function addHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * الحصول على الرؤوس
     * @return array
     */
    public function getHeaders(): array {
        return $this->headers;
    }
    
    /**
     * تعيين الأولوية
     * @param int $priority
     * @return self
     */
    public function setPriority(int $priority): self {
        $this->priority = $priority;
        return $this;
    }
    
    /**
     * الحصول على الأولوية
     * @return int
     */
    public function getPriority(): int {
        return $this->priority;
    }
    
    /**
     * تجميع الرسالة
     * @return string
     */
    public function compile(): string {
        $headers = [];
        
        // الرؤوس الأساسية
        $headers[] = "Message-ID: <{$this->getMessageId()}>";
        $headers[] = "Date: " . date('r');
        
        if (!empty($this->from)) {
            $from = $this->from['name'] ? "{$this->from['name']} <{$this->from['email']}>" : $this->from['email'];
            $headers[] = "From: {$from}";
        }
        
        if (!empty($this->to)) {
            $headers[] = "To: " . implode(', ', $this->to);
        }
        
        if (!empty($this->cc)) {
            $headers[] = "Cc: " . implode(', ', $this->cc);
        }
        
        if (!empty($this->replyTo)) {
            $headers[] = "Reply-To: {$this->replyTo}";
        }
        
        $headers[] = "Subject: {$this->subject}";
        $headers[] = "X-Priority: {$this->priority}";
        
        // الرؤوس الإضافية
        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        
        $headers[] = "MIME-Version: 1.0";
        
        if ($this->html && !empty($this->attachments)) {
            $boundary = md5(uniqid());
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
            
            $body = "--{$boundary}\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"alt-{$boundary}\"\n\n";
            
            $body .= "--alt-{$boundary}\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\n\n";
            $body .= $this->body . "\n\n";
            
            $body .= "--alt-{$boundary}\n";
            $body .= "Content-Type: text/html; charset=UTF-8\n\n";
            $body .= $this->html . "\n\n";
            $body .= "--alt-{$boundary}--\n\n";
            
            foreach ($this->attachments as $attachment) {
                $body .= "--{$boundary}\n";
                $body .= "Content-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"\n";
                $body .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\n";
                $body .= "Content-Transfer-Encoding: base64\n\n";
                
                if ($attachment['type'] === 'file') {
                    $content = file_get_contents($attachment['path']);
                    $body .= chunk_split(base64_encode($content)) . "\n";
                } else {
                    $body .= chunk_split(base64_encode($attachment['data'])) . "\n";
                }
            }
            
            $body .= "--{$boundary}--";
            
        } elseif ($this->html) {
            $boundary = md5(uniqid());
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            
            $body = "--{$boundary}\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\n\n";
            $body .= $this->body . "\n\n";
            
            $body .= "--{$boundary}\n";
            $body .= "Content-Type: text/html; charset=UTF-8\n\n";
            $body .= $this->html . "\n\n";
            $body .= "--{$boundary}--";
            
        } elseif (!empty($this->attachments)) {
            $boundary = md5(uniqid());
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
            
            $body = "--{$boundary}\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\n\n";
            $body .= $this->body . "\n\n";
            
            foreach ($this->attachments as $attachment) {
                $body .= "--{$boundary}\n";
                $body .= "Content-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"\n";
                $body .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\n";
                $body .= "Content-Transfer-Encoding: base64\n\n";
                
                if ($attachment['type'] === 'file') {
                    $content = file_get_contents($attachment['path']);
                    $body .= chunk_split(base64_encode($content)) . "\n";
                } else {
                    $body .= chunk_split(base64_encode($attachment['data'])) . "\n";
                }
            }
            
            $body .= "--{$boundary}--";
            
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $body = $this->body;
        }
        
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}

/**
 * Email_Template
 * @package Email
 * 
 * قالب بريد إلكتروني
 */
class Email_Template {
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * المُنشئ
     * @param array $data
     */
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    /**
     * الحصول على اسم القالب
     * @return string
     */
    public function getName(): string {
        return $this->data['name'] ?? '';
    }
    
    /**
     * الحصول على موضوع القالب
     * @return string
     */
    public function getSubject(): string {
        return $this->data['subject'] ?? '';
    }
    
    /**
     * الحصول على نص القالب
     * @return string
     */
    public function getBody(): string {
        return $this->data['body'] ?? '';
    }
    
    /**
     * الحصول على HTML القالب
     * @return string|null
     */
    public function getHtml(): ?string {
        return $this->data['html_body'] ?? null;
    }
    
    /**
     * الحصول على المتغيرات
     * @return array
     */
    public function getVariables(): array {
        return json_decode($this->data['variables'] ?? '[]', true);
    }
    
    /**
     * عرض القالب بالبيانات
     * @param array $data
     * @return array
     */
    public function render(array $data = []): array {
        $subject = $this->getSubject();
        $body = $this->getBody();
        $html = $this->getHtml();
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $subject = str_replace("{{{$key}}}", $value, $subject);
                $body = str_replace("{{{$key}}}", $value, $body);
                if ($html) {
                    $html = str_replace("{{{$key}}}", $value, $html);
                }
            }
        }
        
        return [
            'subject' => $subject,
            'body' => $body,
            'html' => $html
        ];
    }
    
    /**
     * تعيين متغير
     * @param string $name
     * @param mixed $value
     */
    public function setVariable(string $name, $value): void {
        $variables = $this->getVariables();
        $variables[] = $name;
        $this->data['variables'] = json_encode(array_unique($variables));
    }
    
    /**
     * الحصول على المتغيرات المطلوبة
     * @return array
     */
    public function getRequiredVars(): array {
        preg_match_all('/{{(.*?)}}/', $this->getSubject() . $this->getBody() . ($this->getHtml() ?? ''), $matches);
        return array_unique($matches[1]);
    }
}

/**
 * Email_Queue
 * @package Email
 * 
 * طابور البريد الإلكتروني
 */
class Email_Queue {
    
    /**
     * @var Email_App
     */
    private $email;
    
    /**
     * المُنشئ
     * @param Email_App $email
     */
    public function __construct(Email_App $email) {
        $this->email = $email;
    }
    
    /**
     * إضافة بريد للطابور
     * @param Email_Message $message
     * @return Email_Result
     */
    public function push(Email_Message $message): Email_Result {
        $db = Main_App::getInstance()->db;
        
        $db->insert('email_queue', [
            'message_id' => $message->getMessageId(),
            'from_email' => $message->getFromEmail(),
            'from_name' => $message->getFromName(),
            'to_email' => json_encode($message->getTo()),
            'cc' => json_encode($message->getCc()),
            'bcc' => json_encode($message->getBcc()),
            'reply_to' => $message->getReplyTo(),
            'subject' => $message->getSubject(),
            'body' => $message->getBody(),
            'html_body' => $message->getHtml(),
            'attachments' => json_encode($message->getAttachments()),
            'headers' => json_encode($message->getHeaders()),
            'priority' => $message->getPriority(),
            'status' => 'queued',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return new Email_Result(true, 'Email queued', $message->getMessageId(), ['queued' => true]);
    }
    
    /**
     * جلب بريد من الطابور
     * @return Email_Job|null
     */
    public function pop(): ?Email_Job {
        $db = Main_App::getInstance()->db;
        
        $job = $db->fetchOne("
            SELECT * FROM email_queue 
            WHERE status = 'queued' 
            ORDER BY priority ASC, created_at ASC 
            LIMIT 1 
            FOR UPDATE
        ");
        
        if (!$job) {
            return null;
        }
        
        $db->update('email_queue', [
            'status' => 'processing',
            'started_at' => date('Y-m-d H:i:s')
        ], ['id' => $job['id']]);
        
        return new Email_Job($job);
    }
    
    /**
     * معالجة طابور البريد
     * @param int $limit
     * @return array
     */
    public function process(int $limit = 100): array {
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0
        ];
        
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->pop();
            
            if (!$job) {
                break;
            }
            
            $results['processed']++;
            
            if ($this->processJob($job)) {
                $results['succeeded']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
    
    /**
     * معالجة وظيفة بريد
     * @param Email_Job $job
     * @return bool
     */
    private function processJob(Email_Job $job): bool {
        $db = Main_App::getInstance()->db;
        
        try {
            // إنشاء رسالة من بيانات الوظيفة
            $message = new Email_Message();
            $message->setMessageId($job->getMessageId())
                    ->setFrom($job->getFromEmail(), $job->getFromName())
                    ->setTo($job->getTo())
                    ->setSubject($job->getSubject())
                    ->setBody($job->getBody());
            
            if ($job->getHtml()) {
                $message->setHtml($job->getHtml());
            }
            
            if ($job->getCc()) {
                $message->setCc($job->getCc());
            }
            
            if ($job->getBcc()) {
                $message->setBcc($job->getBcc());
            }
            
            if ($job->getReplyTo()) {
                $message->setReplyTo($job->getReplyTo());
            }
            
            $attachments = json_decode($job->getAttachments(), true) ?: [];
            foreach ($attachments as $attachment) {
                $message->addAttachment($attachment);
            }
            
            // إرسال البريد
            $result = $this->email->sendImmediate($message);
            
            if ($result->isSuccess()) {
                $db->update('email_queue', [
                    'status' => 'sent',
                    'completed_at' => date('Y-m-d H:i:s')
                ], ['id' => $job->getId()]);
                
                return true;
            } else {
                throw new Exception($result->getError());
            }
            
        } catch (Exception $e) {
            $attempts = $job->getAttempts() + 1;
            
            $db->update('email_queue', [
                'status' => 'failed',
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'failed_at' => date('Y-m-d H:i:s')
            ], ['id' => $job->getId()]);
            
            return false;
        }
    }
    
    /**
     * إعادة محاولة البريد الفاشل
     * @return int
     */
    public function retryFailed(): int {
        $db = Main_App::getInstance()->db;
        
        return $db->update('email_queue', [
            'status' => 'queued',
            'attempts' => 0,
            'error' => null
        ], "status = 'failed' AND attempts < 3");
    }
    
    /**
     * تنظيف البريد القديم
     * @param int $days
     * @return int
     */
    public function cleanOld(int $days = 7): int {
        $db = Main_App::getInstance()->db;
        
        return $db->delete('email_queue',
            "created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN ('sent', 'failed')",
            [$days]
        );
    }
}

/**
 * Email_Job
 * @package Email
 * 
 * وظيفة بريد في الطابور
 */
class Email_Job {
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * المُنشئ
     * @param array $data
     */
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    /**
     * الحصول على معرف الوظيفة
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على معرف الرسالة
     * @return string
     */
    public function getMessageId(): string {
        return $this->data['message_id'];
    }
    
    /**
     * الحصول على بريد المرسل
     * @return string
     */
    public function getFromEmail(): string {
        return $this->data['from_email'];
    }
    
    /**
     * الحصول على اسم المرسل
     * @return string|null
     */
    public function getFromName(): ?string {
        return $this->data['from_name'];
    }
    
    /**
     * الحصول على المستلمين
     * @return array
     */
    public function getTo(): array {
        return json_decode($this->data['to_email'], true) ?: [];
    }
    
    /**
     * الحصول على CC
     * @return array
     */
    public function getCc(): array {
        return json_decode($this->data['cc'] ?? '[]', true);
    }
    
    /**
     * الحصول على BCC
     * @return array
     */
    public function getBcc(): array {
        return json_decode($this->data['bcc'] ?? '[]', true);
    }
    
    /**
     * الحصول على Reply-To
     * @return string|null
     */
    public function getReplyTo(): ?string {
        return $this->data['reply_to'];
    }
    
    /**
     * الحصول على الموضوع
     * @return string
     */
    public function getSubject(): string {
        return $this->data['subject'];
    }
    
    /**
     * الحصول على النص
     * @return string
     */
    public function getBody(): string {
        return $this->data['body'];
    }
    
    /**
     * الحصول على HTML
     * @return string|null
     */
    public function getHtml(): ?string {
        return $this->data['html_body'];
    }
    
    /**
     * الحصول على المرفقات
     * @return string
     */
    public function getAttachments(): string {
        return $this->data['attachments'] ?? '[]';
    }
    
    /**
     * الحصول على الرؤوس
     * @return string
     */
    public function getHeaders(): string {
        return $this->data['headers'] ?? '[]';
    }
    
    /**
     * الحصول على عدد المحاولات
     * @return int
     */
    public function getAttempts(): int {
        return (int)($this->data['attempts'] ?? 0);
    }
    
    /**
     * الحصول على الأولوية
     * @return int
     */
    public function getPriority(): int {
        return (int)($this->data['priority'] ?? 3);
    }
    
    /**
     * الحصول على وقت الإنشاء
     * @return string
     */
    public function getCreatedAt(): string {
        return $this->data['created_at'];
    }
}

/**
 * Email_Result
 * @package Email
 * 
 * نتيجة إرسال بريد
 */
class Email_Result {
    
    /**
     * @var bool
     */
    private $success;
    
    /**
     * @var string
     */
    private $message;
    
    /**
     * @var string|null
     */
    private $messageId;
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * المُنشئ
     * @param bool $success
     * @param string $message
     * @param string|null $messageId
     * @param array $data
     */
    public function __construct(bool $success, string $message = '', ?string $messageId = null, array $data = []) {
        $this->success = $success;
        $this->message = $message;
        $this->messageId = $messageId;
        $this->data = $data;
    }
    
    /**
     * التحقق من النجاح
     * @return bool
     */
    public function isSuccess(): bool {
        return $this->success;
    }
    
    /**
     * الحصول على معرف الرسالة من المزود
     * @return string|null
     */
    public function getProviderMessageId(): ?string {
        return $this->data['provider_message_id'] ?? $this->messageId;
    }
    
    /**
     * الحصول على الخطأ
     * @return string
     */
    public function getError(): string {
        return $this->message;
    }
    
    /**
     * الحصول على معرف الرسالة
     * @return string|null
     */
    public function getMessageId(): ?string {
        return $this->messageId;
    }
    
    /**
     * الحصول على البيانات
     * @return array
     */
    public function getData(): array {
        return $this->data;
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'message_id' => $this->messageId,
            'data' => $this->data
        ];
    }
}

?>