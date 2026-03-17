<?php
/**
 * SMS_app.php
 * @version 1.0.0
 * @package SMS
 * 
 * نظام إرسال الرسائل النصية المتكامل
 * يدعم مقدمي خدمات متعددين، جدولة، قوالب، وتتبع حالة الرسائل
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * SMS_App
 * @package SMS
 * 
 * الكلاس الرئيسي لنظام الرسائل النصية
 */
class SMS_App {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var SMS_Provider مزود الخدمة
     */
    private $provider;
    
    /**
     * @var array إعدادات النظام
     */
    private $config;
    
    /**
     * @var SMS_Queue طابور الرسائل
     */
    private $queue;
    
    /**
     * @var float الرصيد الحالي
     */
    private $balance = 0;
    
    /**
     * @var SMS_Webhook معالج webhook
     */
    private $webhook;
    
    /**
     * @var array قوالب الرسائل
     */
    private $templates = [];
    
    /**
     * @var array محادثات SMS
     */
    private $conversations = [];
    
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
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initialize();
    }
    
    /**
     * الحصول على الإعدادات الافتراضية
     * @return array
     */
    private function getDefaultConfig(): array {
        return [
            'provider' => 'twilio',
            'api_key' => '',
            'api_secret' => '',
            'from' => '',
            'country_code' => '966', // السعودية
            'unicode' => true,
            'queue_enabled' => true,
            'max_length' => 160,
            'webhook_url' => '',
            'webhook_secret' => '',
            'retry_attempts' => 3,
            'retry_delay' => 60,
            'track_delivery' => true,
            'store_conversations' => true
        ];
    }
    
    /**
     * تهيئة النظام
     */
    private function initialize(): void {
        try {
            // تهيئة مزود الخدمة
            $this->initializeProvider();
            
            // تهيئة الطابور
            $this->queue = new SMS_Queue($this);
            
            // تهيئة webhook
            if (!empty($this->config['webhook_url'])) {
                $this->webhook = new SMS_Webhook($this->config['webhook_url'], $this->config['webhook_secret']);
            }
            
            // تحميل القوالب
            $this->loadTemplates();
            
            // الحصول على الرصيد
            $this->refreshBalance();
            
            // تهيئة جداول قاعدة البيانات
            $this->initializeTables();
            
            $this->initialized = true;
            
        } catch (Exception $e) {
            error_log("SMS initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * تهيئة مزود الخدمة
     * @throws Exception
     */
    private function initializeProvider(): void {
        $provider = $this->config['provider'];
        
        switch ($provider) {
            case 'twilio':
                $this->provider = new Twilio_Provider($this->config);
                break;
                
            case 'nexmo':
            case 'vonage':
                $this->provider = new Nexmo_Provider($this->config);
                break;
                
            case 'aws':
            case 'sns':
                $this->provider = new AWS_SNS_Provider($this->config);
                break;
                
            case 'msg91':
                $this->provider = new Msg91_Provider($this->config);
                break;
                
            case 'local':
                $this->provider = new Local_SMS_Provider($this->config);
                break;
                
            default:
                throw new Exception("Unsupported SMS provider: {$provider}");
        }
    }
    
    /**
     * تهيئة جداول قاعدة البيانات
     */
    private function initializeTables(): void {
        $db = Main_App::getInstance()->db;
        
        // جدول الرسائل النصية
        if (!$db->tableExists('sms_messages')) {
            $db->query("
                CREATE TABLE sms_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id VARCHAR(100) UNIQUE,
                    to_number VARCHAR(20) NOT NULL,
                    from_number VARCHAR(20),
                    body TEXT,
                    status ENUM('pending', 'sent', 'delivered', 'failed', 'cancelled') DEFAULT 'pending',
                    segments INT DEFAULT 1,
                    cost DECIMAL(10,4) DEFAULT 0,
                    currency VARCHAR(3) DEFAULT 'USD',
                    provider VARCHAR(50),
                    error_message TEXT,
                    scheduled_at TIMESTAMP NULL,
                    sent_at TIMESTAMP NULL,
                    delivered_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_to (to_number),
                    INDEX idx_status (status),
                    INDEX idx_scheduled (scheduled_at),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول قوالب الرسائل
        if (!$db->tableExists('sms_templates')) {
            $db->query("
                CREATE TABLE sms_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) UNIQUE NOT NULL,
                    body TEXT NOT NULL,
                    variables JSON,
                    max_length INT DEFAULT 160,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول المحادثات
        if (!$db->tableExists('sms_conversations')) {
            $db->query("
                CREATE TABLE sms_conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    phone_number VARCHAR(20) NOT NULL,
                    last_message TEXT,
                    last_message_at TIMESTAMP,
                    unread_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_phone (phone_number),
                    INDEX idx_last (last_message_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول webhooks
        if (!$db->tableExists('sms_webhooks')) {
            $db->query("
                CREATE TABLE sms_webhooks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event VARCHAR(50),
                    payload JSON,
                    processed BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    processed_at TIMESTAMP NULL,
                    INDEX idx_event (event),
                    INDEX idx_processed (processed)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول محاولات إعادة الإرسال
        if (!$db->tableExists('sms_retries')) {
            $db->query("
                CREATE TABLE sms_retries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id VARCHAR(100),
                    attempt INT DEFAULT 1,
                    error TEXT,
                    scheduled_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (message_id) REFERENCES sms_messages(message_id) ON DELETE CASCADE,
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
        
        $templates = $db->fetchAll("SELECT * FROM sms_templates");
        
        foreach ($templates as $template) {
            $this->templates[$template['name']] = new SMS_Template($template);
        }
    }
    
    // ==========================================
    // إرسال الرسائل
    // ==========================================
    
    /**
     * إرسال رسالة نصية
     * @param string $to
     * @param string $message
     * @param array $options
     * @return SMS_Result
     */
    public function send(string $to, string $message, array $options = []): SMS_Result {
        // تنسيق الرقم
        $to = $this->formatNumber($to, $options['country_code'] ?? $this->config['country_code']);
        
        // التحقق من صحة الرقم
        if (!$this->validateNumber($to)) {
            return new SMS_Result(false, 'Invalid phone number', null, ['number' => $to]);
        }
        
        // تجهيز الرسالة
        $from = $options['from'] ?? $this->config['from'];
        $segments = $this->countSegments($message);
        
        // إنشاء معرف فريد
        $messageId = $this->generateMessageId();
        
        // حفظ في قاعدة البيانات
        $this->saveMessage([
            'message_id' => $messageId,
            'to_number' => $to,
            'from_number' => $from,
            'body' => $message,
            'status' => 'pending',
            'segments' => $segments,
            'provider' => $this->config['provider'],
            'scheduled_at' => $options['scheduled_at'] ?? null
        ]);
        
        // إذا كان مجدولاً
        if (isset($options['scheduled_at'])) {
            return new SMS_Result(true, 'Message scheduled', $messageId, ['scheduled' => true]);
        }
        
        // إرسال مباشر أو عبر الطابور
        if ($this->config['queue_enabled'] && !($options['immediate'] ?? false)) {
            return $this->queue->push($messageId, $to, $message, $from);
        } else {
            return $this->sendImmediate($messageId, $to, $message, $from);
        }
    }
    
    /**
     * إرسال رسالة فورية
     * @param string $messageId
     * @param string $to
     * @param string $message
     * @param string $from
     * @return SMS_Result
     */
    private function sendImmediate(string $messageId, string $to, string $message, string $from): SMS_Result {
        try {
            // إرسال عبر المزود
            $result = $this->provider->send($to, $message, $from);
            
            if ($result->isSuccess()) {
                // تحديث حالة الرسالة
                $this->updateMessageStatus($messageId, 'sent', [
                    'provider_message_id' => $result->getProviderMessageId(),
                    'cost' => $result->getCost(),
                    'sent_at' => date('Y-m-d H:i:s')
                ]);
                
                // حفظ المحادثة
                if ($this->config['store_conversations']) {
                    $this->saveConversation($to, $message, 'outgoing');
                }
                
                // تتبع التسليم إذا كان مطلوباً
                if ($this->config['track_delivery']) {
                    $this->trackDelivery($result->getProviderMessageId());
                }
                
                return new SMS_Result(true, 'Message sent', $messageId, [
                    'provider_message_id' => $result->getProviderMessageId(),
                    'cost' => $result->getCost(),
                    'segments' => $result->getSegments()
                ]);
            } else {
                // تحديث حالة الفشل
                $this->updateMessageStatus($messageId, 'failed', [
                    'error_message' => $result->getError()
                ]);
                
                // جدولة إعادة المحاولة
                if ($this->config['retry_attempts'] > 0) {
                    $this->scheduleRetry($messageId);
                }
                
                return new SMS_Result(false, $result->getError(), $messageId);
            }
            
        } catch (Exception $e) {
            $this->updateMessageStatus($messageId, 'failed', [
                'error_message' => $e->getMessage()
            ]);
            
            return new SMS_Result(false, $e->getMessage(), $messageId);
        }
    }
    
    /**
     * إرسال رسائل متعددة
     * @param array $recipients
     * @param string $message
     * @param array $options
     * @return array
     */
    public function sendBulk(array $recipients, string $message, array $options = []): array {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $to = is_array($recipient) ? $recipient['number'] : $recipient;
            $personalizedMessage = $this->personalizeMessage($message, $recipient);
            
            $results[$to] = $this->send($to, $personalizedMessage, $options);
        }
        
        return $results;
    }
    
    /**
     * جدولة رسالة
     * @param string $to
     * @param string $message
     * @param string $datetime
     * @param array $options
     * @return SMS_Result
     */
    public function schedule(string $to, string $message, string $datetime, array $options = []): SMS_Result {
        $options['scheduled_at'] = $datetime;
        return $this->send($to, $message, $options);
    }
    
    /**
     * إرسال رسالة باستخدام قالب
     * @param string $to
     * @param string $templateName
     * @param array $data
     * @param array $options
     * @return SMS_Result
     */
    public function sendTemplate(string $to, string $templateName, array $data = [], array $options = []): SMS_Result {
        $template = $this->getTemplate($templateName);
        
        if (!$template) {
            return new SMS_Result(false, "Template not found: {$templateName}");
        }
        
        $message = $template->render($data);
        
        return $this->send($to, $message, $options);
    }
    
    // ==========================================
    // إدارة القوالب
    // ==========================================
    
    /**
     * إنشاء قالب جديد
     * @param string $name
     * @param string $body
     * @param array $variables
     * @return int
     */
    public function createTemplate(string $name, string $body, array $variables = []): int {
        $db = Main_App::getInstance()->db;
        
        $data = [
            'name' => $name,
            'body' => $body,
            'variables' => json_encode($variables),
            'max_length' => $this->countSegments($body) * 160
        ];
        
        $id = $db->insert('sms_templates', $data);
        
        if ($id) {
            $this->templates[$name] = new SMS_Template($data);
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
        
        $result = $db->update('sms_templates', $data, ['name' => $name]);
        
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
        
        $result = $db->delete('sms_templates', ['name' => $name]);
        
        if ($result) {
            unset($this->templates[$name]);
        }
        
        return (bool)$result;
    }
    
    /**
     * الحصول على قالب
     * @param string $name
     * @return SMS_Template|null
     */
    public function getTemplate(string $name): ?SMS_Template {
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
    // إدارة المحادثات
    // ==========================================
    
    /**
     * الحصول على محادثة
     * @param string $phone
     * @return SMS_Conversation|null
     */
    public function getConversation(string $phone): ?SMS_Conversation {
        $db = Main_App::getInstance()->db;
        
        $conversation = $db->fetchOne(
            "SELECT * FROM sms_conversations WHERE phone_number = ?",
            [$phone]
        );
        
        if (!$conversation) {
            return null;
        }
        
        // جلب الرسائل
        $messages = $db->fetchAll(
            "SELECT * FROM sms_messages WHERE to_number = ? OR from_number = ? ORDER BY created_at DESC LIMIT 50",
            [$phone, $phone]
        );
        
        return new SMS_Conversation($conversation, $messages);
    }
    
    /**
     * حفظ محادثة
     * @param string $phone
     * @param string $message
     * @param string $direction
     */
    private function saveConversation(string $phone, string $message, string $direction): void {
        $db = Main_App::getInstance()->db;
        
        $exists = $db->fetchOne(
            "SELECT id FROM sms_conversations WHERE phone_number = ?",
            [$phone]
        );
        
        if ($exists) {
            $db->update('sms_conversations', [
                'last_message' => $message,
                'last_message_at' => date('Y-m-d H:i:s'),
                'unread_count' => $direction === 'incoming' ? $db->raw('unread_count + 1') : 0
            ], ['phone_number' => $phone]);
        } else {
            $db->insert('sms_conversations', [
                'phone_number' => $phone,
                'last_message' => $message,
                'last_message_at' => date('Y-m-d H:i:s'),
                'unread_count' => $direction === 'incoming' ? 1 : 0
            ]);
        }
    }
    
    /**
     * تعيين المحادثة كمقروءة
     * @param string $phone
     * @return bool
     */
    public function markConversationRead(string $phone): bool {
        $db = Main_App::getInstance()->db;
        
        return (bool)$db->update('sms_conversations', [
            'unread_count' => 0
        ], ['phone_number' => $phone]);
    }
    
    /**
     * الحصول على جميع المحادثات
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getConversations(int $limit = 50, int $offset = 0): array {
        $db = Main_App::getInstance()->db;
        
        return $db->fetchAll(
            "SELECT * FROM sms_conversations ORDER BY last_message_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }
    
    // ==========================================
    // التحقق والتحقق من الصحة
    // ==========================================
    
    /**
     * التحقق من صحة رقم الهاتف
     * @param string $number
     * @return bool
     */
    public function validateNumber(string $number): bool {
        // إزالة أي أحرف غير رقمية
        $number = preg_replace('/[^0-9+]/', '', $number);
        
        // التحقق من الطول (بعد إزالة +)
        $length = strlen(str_replace('+', '', $number));
        
        return $length >= 10 && $length <= 15;
    }
    
    /**
     * تنسيق رقم الهاتف
     * @param string $number
     * @param string $countryCode
     * @return string
     */
    public function formatNumber(string $number, string $countryCode = '966'): string {
        // إزالة المسافات والشرطات
        $number = preg_replace('/[\s\-\(\)]/', '', $number);
        
        // إذا كان الرقم يبدأ بـ 00، استبدله بـ +
        if (strpos($number, '00') === 0) {
            $number = '+' . substr($number, 2);
        }
        
        // إذا كان الرقم لا يبدأ بـ +، أضف رمز الدولة
        if (strpos($number, '+') !== 0) {
            // إذا كان الرقم يبدأ بصفر، احذفه
            if (strpos($number, '0') === 0) {
                $number = substr($number, 1);
            }
            $number = '+' . $countryCode . $number;
        }
        
        return $number;
    }
    
    /**
     * حساب عدد أجزاء الرسالة
     * @param string $message
     * @return int
     */
    public function countSegments(string $message): int {
        $length = $this->config['unicode'] ? mb_strlen($message) : strlen($message);
        
        if ($length <= 160) {
            return 1;
        }
        
        // للرسائل الطويلة، كل جزء 153 حرف
        return (int)ceil($length / 153);
    }
    
    // ==========================================
    // إدارة الحالة والتتبع
    // ==========================================
    
    /**
     * الحصول على حالة الرسالة
     * @param string $messageId
     * @return string|null
     */
    public function getDeliveryStatus(string $messageId): ?string {
        $db = Main_App::getInstance()->db;
        
        return $db->fetchColumn(
            "SELECT status FROM sms_messages WHERE message_id = ?",
            [$messageId]
        );
    }
    
    /**
     * تتبع حالة التسليم
     * @param string $providerMessageId
     */
    private function trackDelivery(string $providerMessageId): void {
        // يمكن تنفيذها لاحقاً
    }
    
    /**
     * معالجة تحديث الحالة من webhook
     * @param array $data
     * @return bool
     */
    public function handleStatusCallback(array $data): bool {
        $db = Main_App::getInstance()->db;
        
        $providerMessageId = $data['MessageSid'] ?? $data['message_id'] ?? null;
        $status = $data['MessageStatus'] ?? $data['status'] ?? null;
        
        if (!$providerMessageId || !$status) {
            return false;
        }
        
        // تحديث الحالة
        $update = ['status' => strtolower($status)];
        
        if ($status === 'delivered') {
            $update['delivered_at'] = date('Y-m-d H:i:s');
        }
        
        return (bool)$db->update('sms_messages', $update, [
            'message_id' => $providerMessageId
        ]);
    }
    
    /**
     * إعادة محاولة الرسائل الفاشلة
     * @param int $limit
     * @return array
     */
    public function retryFailed(int $limit = 50): array {
        $db = Main_App::getInstance()->db;
        
        $messages = $db->fetchAll(
            "SELECT * FROM sms_messages 
             WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT ?",
            [$limit]
        );
        
        $results = [];
        
        foreach ($messages as $message) {
            $result = $this->sendImmediate(
                $message['message_id'],
                $message['to_number'],
                $message['body'],
                $message['from_number']
            );
            
            $results[$message['message_id']] = $result;
        }
        
        return $results;
    }
    
    // ==========================================
    // إدارة الرصيد
    // ==========================================
    
    /**
     * الحصول على الرصيد الحالي
     * @return float
     */
    public function getBalance(): float {
        $this->refreshBalance();
        return $this->balance;
    }
    
    /**
     * تحديث الرصيد
     */
    private function refreshBalance(): void {
        try {
            $this->balance = $this->provider->getBalance();
        } catch (Exception $e) {
            error_log("Failed to get SMS balance: " . $e->getMessage());
        }
    }
    
    /**
     * الحصول على معلومات المزود
     * @return array
     */
    public function getProviderInfo(): array {
        return [
            'name' => $this->config['provider'],
            'balance' => $this->balance,
            'config' => $this->provider->getConfig()
        ];
    }
    
    // ==========================================
    // معالجة webhook
    // ==========================================
    
    /**
     * تعيين webhook
     * @param string $url
     * @param string $events
     * @return bool
     */
    public function setWebhook(string $url, string $events = 'all'): bool {
        $this->config['webhook_url'] = $url;
        $this->webhook = new SMS_Webhook($url, $this->config['webhook_secret']);
        
        return true;
    }
    
    /**
     * معالجة طلب webhook
     * @param array $request
     * @return array
     */
    public function handleWebhook(array $request): array {
        if (!$this->webhook) {
            return ['error' => 'Webhook not configured'];
        }
        
        // التحقق من التوقيع
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if (!$this->webhook->verifySignature($signature, json_encode($request))) {
            return ['error' => 'Invalid signature'];
        }
        
        // حفظ webhook
        $this->saveWebhook($request);
        
        // معالجة حسب النوع
        $event = $request['event'] ?? 'unknown';
        
        switch ($event) {
            case 'delivery':
                return $this->handleStatusCallback($request);
                
            case 'incoming':
                return $this->handleIncomingMessage($request);
                
            default:
                return ['status' => 'received'];
        }
    }
    
    /**
     * حفظ webhook في قاعدة البيانات
     * @param array $payload
     */
    private function saveWebhook(array $payload): void {
        $db = Main_App::getInstance()->db;
        
        $db->insert('sms_webhooks', [
            'event' => $payload['event'] ?? 'unknown',
            'payload' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * معالجة رسالة واردة
     * @param array $data
     * @return array
     */
    private function handleIncomingMessage(array $data): array {
        $from = $data['from'] ?? $data['From'] ?? null;
        $body = $data['body'] ?? $data['Body'] ?? null;
        
        if (!$from || !$body) {
            return ['error' => 'Missing data'];
        }
        
        // حفظ الرسالة
        $messageId = $this->generateMessageId();
        
        $this->saveMessage([
            'message_id' => $messageId,
            'to_number' => $data['to'] ?? $this->config['from'],
            'from_number' => $from,
            'body' => $body,
            'status' => 'received',
            'provider' => $this->config['provider'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // حفظ المحادثة
        if ($this->config['store_conversations']) {
            $this->saveConversation($from, $body, 'incoming');
        }
        
        return [
            'status' => 'processed',
            'message_id' => $messageId
        ];
    }
    
    // ==========================================
    // إدارة قاعدة البيانات
    // ==========================================
    
    /**
     * حفظ رسالة في قاعدة البيانات
     * @param array $data
     * @return int
     */
    private function saveMessage(array $data): int {
        $db = Main_App::getInstance()->db;
        
        return $db->insert('sms_messages', $data);
    }
    
    /**
     * تحديث حالة الرسالة
     * @param string $messageId
     * @param string $status
     * @param array $additional
     * @return bool
     */
    private function updateMessageStatus(string $messageId, string $status, array $additional = []): bool {
        $db = Main_App::getInstance()->db;
        
        $update = array_merge(['status' => $status], $additional);
        
        return (bool)$db->update('sms_messages', $update, ['message_id' => $messageId]);
    }
    
    /**
     * جدولة إعادة محاولة
     * @param string $messageId
     */
    private function scheduleRetry(string $messageId): void {
        $db = Main_App::getInstance()->db;
        
        // الحصول على عدد المحاولات السابقة
        $attempts = $db->fetchColumn(
            "SELECT COUNT(*) FROM sms_retries WHERE message_id = ?",
            [$messageId]
        );
        
        if ($attempts >= $this->config['retry_attempts']) {
            return;
        }
        
        // جدولة المحاولة بعد delay
        $scheduledAt = date('Y-m-d H:i:s', time() + ($this->config['retry_delay'] * ($attempts + 1)));
        
        $db->insert('sms_retries', [
            'message_id' => $messageId,
            'attempt' => $attempts + 1,
            'scheduled_at' => $scheduledAt,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * معالجة طابور إعادة المحاولات
     * @param int $limit
     * @return array
     */
    public function processRetryQueue(int $limit = 50): array {
        $db = Main_App::getInstance()->db;
        
        $retries = $db->fetchAll(
            "SELECT r.*, m.* FROM sms_retries r
             JOIN sms_messages m ON r.message_id = m.message_id
             WHERE r.scheduled_at <= NOW()
             LIMIT ?",
            [$limit]
        );
        
        $results = [];
        
        foreach ($retries as $retry) {
            $result = $this->sendImmediate(
                $retry['message_id'],
                $retry['to_number'],
                $retry['body'],
                $retry['from_number']
            );
            
            if ($result->isSuccess()) {
                // حذف من جدول المحاولات
                $db->delete('sms_retries', ['id' => $retry['id']]);
            }
            
            $results[$retry['message_id']] = $result;
        }
        
        return $results;
    }
    
    // ==========================================
    // نظام التحقق (Verification)
    // ==========================================
    
    /**
     * إرسال رمز تحقق
     * @param string $phone
     * @param int $length
     * @param int $expiry
     * @return string|null
     */
    public function sendVerificationCode(string $phone, int $length = 6, int $expiry = 300): ?string {
        $code = $this->generateVerificationCode($length);
        
        $message = "رمز التحقق الخاص بك هو: {$code}\nصالح لمدة " . ($expiry / 60) . " دقائق";
        
        $result = $this->send($phone, $message);
        
        if ($result->isSuccess()) {
            // تخزين الرمز في قاعدة البيانات أو cache
            $this->storeVerificationCode($phone, $code, $expiry);
            return $code;
        }
        
        return null;
    }
    
    /**
     * التحقق من الرمز
     * @param string $phone
     * @param string $code
     * @return bool
     */
    public function verifyCode(string $phone, string $code): bool {
        // التحقق من الرمز المخزن
        $stored = $this->getStoredVerificationCode($phone);
        
        if (!$stored) {
            return false;
        }
        
        // التحقق من الصلاحية
        if (time() > $stored['expires_at']) {
            return false;
        }
        
        // التحقق من الرمز
        return hash_equals($stored['code'], $code);
    }
    
    /**
     * إنشاء رمز تحقق
     * @param int $length
     * @return string
     */
    private function generateVerificationCode(int $length = 6): string {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
    
    /**
     * تخزين رمز التحقق
     * @param string $phone
     * @param string $code
     * @param int $expiry
     */
    private function storeVerificationCode(string $phone, string $code, int $expiry): void {
        $cache = Main_App::getInstance()->cache;
        $cache->set("sms_verify_{$phone}", [
            'code' => $code,
            'expires_at' => time() + $expiry
        ], $expiry);
    }
    
    /**
     * الحصول على رمز التحقق المخزن
     * @param string $phone
     * @return array|null
     */
    private function getStoredVerificationCode(string $phone): ?array {
        $cache = Main_App::getInstance()->cache;
        return $cache->get("sms_verify_{$phone}");
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * إنشاء معرف فريد للرسالة
     * @return string
     */
    private function generateMessageId(): string {
        return uniqid('sms_', true) . '_' . bin2hex(random_bytes(8));
    }
    
    /**
     * تخصيص رسالة لمستلم معين
     * @param string $message
     * @param mixed $recipient
     * @return string
     */
    private function personalizeMessage(string $message, $recipient): string {
        if (!is_array($recipient)) {
            return $message;
        }
        
        foreach ($recipient as $key => $value) {
            if (is_string($value)) {
                $message = str_replace("{{$key}}", $value, $message);
            }
        }
        
        return $message;
    }
    
    /**
     * الحصول على إحصائيات
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
                "SELECT COUNT(*) FROM sms_messages WHERE {$where}",
                $params
            ),
            'sent' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM sms_messages WHERE status = 'sent' AND {$where}",
                $params
            ),
            'delivered' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM sms_messages WHERE status = 'delivered' AND {$where}",
                $params
            ),
            'failed' => (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM sms_messages WHERE status = 'failed' AND {$where}",
                $params
            ),
            'total_cost' => (float)$db->fetchColumn(
                "SELECT SUM(cost) FROM sms_messages WHERE status IN ('sent', 'delivered') AND {$where}",
                $params
            ),
            'total_segments' => (int)$db->fetchColumn(
                "SELECT SUM(segments) FROM sms_messages WHERE {$where}",
                $params
            )
        ];
        
        // إحصائيات يومية
        $daily = $db->fetchAll("
            SELECT DATE(created_at) as date, 
                   COUNT(*) as count,
                   SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                   SUM(cost) as cost
            FROM sms_messages
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        
        $stats['daily'] = $daily;
        
        return $stats;
    }
}

/**
 * SMS_Provider (Interface)
 * @package SMS
 * 
 * واجهة مزودي خدمة الرسائل النصية
 */
interface SMS_Provider {
    
    /**
     * إرسال رسالة
     * @param string $to
     * @param string $message
     * @param string $from
     * @return SMS_Result
     */
    public function send(string $to, string $message, string $from): SMS_Result;
    
    /**
     * الحصول على الرصيد
     * @return float
     */
    public function getBalance(): float;
    
    /**
     * التحقق من حالة الرسالة
     * @param string $messageId
     * @return string
     */
    public function checkStatus(string $messageId): string;
    
    /**
     * الحصول على اسم المزود
     * @return string
     */
    public function getName(): string;
    
    /**
     * الحصول على الإعدادات
     * @return array
     */
    public function getConfig(): array;
    
    /**
     * التحقق من صحة الإعدادات
     * @return bool
     */
    public function validateConfig(): bool;
}

/**
 * Twilio_Provider
 * @package SMS
 * 
 * مزود Twilio
 */
class Twilio_Provider implements SMS_Provider {
    
    /**
     * @var string
     */
    private $accountSid;
    
    /**
     * @var string
     */
    private $authToken;
    
    /**
     * @var string
     */
    private $fromNumber;
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config;
        $this->accountSid = $config['api_key'] ?? '';
        $this->authToken = $config['api_secret'] ?? '';
        $this->fromNumber = $config['from'] ?? '';
    }
    
    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $from): SMS_Result {
        try {
            // محاكاة إرسال عبر Twilio
            // في الواقع سيتم استخدام مكتبة Twilio
            
            return new SMS_Result(true, 'Message sent via Twilio', 'tw_' . uniqid(), [
                'provider' => 'twilio',
                'segments' => 1,
                'cost' => 0.0075
            ]);
            
        } catch (Exception $e) {
            return new SMS_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function getBalance(): float {
        // محاكاة الحصول على الرصيد
        return 50.00;
    }
    
    /**
     * @inheritdoc
     */
    public function checkStatus(string $messageId): string {
        return 'delivered';
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return 'twilio';
    }
    
    /**
     * @inheritdoc
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->accountSid) && !empty($this->authToken) && !empty($this->fromNumber);
    }
}

/**
 * Nexmo_Provider (Vonage)
 * @package SMS
 * 
 * مزود Nexmo/Vonage
 */
class Nexmo_Provider implements SMS_Provider {
    
    /**
     * @var string
     */
    private $apiKey;
    
    /**
     * @var string
     */
    private $apiSecret;
    
    /**
     * @var string
     */
    private $from;
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config;
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
        $this->from = $config['from'] ?? '';
    }
    
    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $from): SMS_Result {
        try {
            // محاكاة إرسال عبر Nexmo
            
            return new SMS_Result(true, 'Message sent via Nexmo', 'nex_' . uniqid(), [
                'provider' => 'nexmo',
                'segments' => 1,
                'cost' => 0.0058
            ]);
            
        } catch (Exception $e) {
            return new SMS_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function getBalance(): float {
        return 25.50;
    }
    
    /**
     * @inheritdoc
     */
    public function checkStatus(string $messageId): string {
        return 'delivered';
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return 'nexmo';
    }
    
    /**
     * @inheritdoc
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->apiKey) && !empty($this->apiSecret) && !empty($this->from);
    }
}

/**
 * AWS_SNS_Provider
 * @package SMS
 * 
 * مزود AWS SNS
 */
class AWS_SNS_Provider implements SMS_Provider {
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $from): SMS_Result {
        try {
            // محاكاة إرسال عبر AWS SNS
            
            return new SMS_Result(true, 'Message sent via AWS SNS', 'aws_' . uniqid(), [
                'provider' => 'aws',
                'segments' => 1,
                'cost' => 0.00645
            ]);
            
        } catch (Exception $e) {
            return new SMS_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function getBalance(): float {
        return 100.00;
    }
    
    /**
     * @inheritdoc
     */
    public function checkStatus(string $messageId): string {
        return 'delivered';
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return 'aws';
    }
    
    /**
     * @inheritdoc
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->config['aws_key']) && !empty($this->config['aws_secret']);
    }
}

/**
 * Msg91_Provider
 * @package SMS
 * 
 * مزود Msg91
 */
class Msg91_Provider implements SMS_Provider {
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $from): SMS_Result {
        try {
            // محاكاة إرسال عبر Msg91
            
            return new SMS_Result(true, 'Message sent via Msg91', 'msg_' . uniqid(), [
                'provider' => 'msg91',
                'segments' => 1,
                'cost' => 0.0045
            ]);
            
        } catch (Exception $e) {
            return new SMS_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function getBalance(): float {
        return 1000; // رصيد بالرسائل
    }
    
    /**
     * @inheritdoc
     */
    public function checkStatus(string $messageId): string {
        return 'delivered';
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return 'msg91';
    }
    
    /**
     * @inheritdoc
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->config['auth_key']);
    }
}

/**
 * Local_SMS_Provider
 * @package SMS
 * 
 * مزود محلي للتطوير والاختبار
 */
class Local_SMS_Provider implements SMS_Provider {
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * @var array
     */
    private $messages = [];
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $from): SMS_Result {
        // تخزين الرسالة محلياً
        $id = 'local_' . uniqid();
        
        $this->messages[] = [
            'id' => $id,
            'to' => $to,
            'from' => $from,
            'message' => $message,
            'time' => date('Y-m-d H:i:s')
        ];
        
        // كتابة في ملف log للتطوير
        $log = "[SMS] To: {$to} | From: {$from} | Message: {$message}\n";
        file_put_contents(ROOT_PATH . '/logs/sms.log', $log, FILE_APPEND);
        
        return new SMS_Result(true, 'Message logged locally', $id, [
            'provider' => 'local',
            'segments' => 1,
            'cost' => 0
        ]);
    }
    
    /**
     * @inheritdoc
     */
    public function getBalance(): float {
        return 999999.99;
    }
    
    /**
     * @inheritdoc
     */
    public function checkStatus(string $messageId): string {
        return 'delivered';
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return 'local';
    }
    
    /**
     * @inheritdoc
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return true;
    }
    
    /**
     * الحصول على جميع الرسائل المرسلة
     * @return array
     */
    public function getMessages(): array {
        return $this->messages;
    }
}

/**
 * SMS_Result
 * @package SMS
 * 
 * نتيجة إرسال رسالة
 */
class SMS_Result {
    
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
     * الحصول على التكلفة
     * @return float
     */
    public function getCost(): float {
        return $this->data['cost'] ?? 0;
    }
    
    /**
     * الحصول على عدد الأجزاء
     * @return int
     */
    public function getSegments(): int {
        return $this->data['segments'] ?? 1;
    }
    
    /**
     * الحصول على الخطأ
     * @return string
     */
    public function getError(): string {
        return $this->message;
    }
    
    /**
     * الحصول على جميع البيانات
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

/**
 * SMS_Message
 * @package SMS
 * 
 * تمثل رسالة نصية
 */
class SMS_Message {
    
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
     * الحصول على معرف الرسالة
     * @return string
     */
    public function getId(): string {
        return $this->data['message_id'] ?? '';
    }
    
    /**
     * الحصول على رقم المستلم
     * @return string
     */
    public function getTo(): string {
        return $this->data['to_number'] ?? '';
    }
    
    /**
     * الحصول على رقم المرسل
     * @return string
     */
    public function getFrom(): string {
        return $this->data['from_number'] ?? '';
    }
    
    /**
     * الحصول على نص الرسالة
     * @return string
     */
    public function getBody(): string {
        return $this->data['body'] ?? '';
    }
    
    /**
     * الحصول على الحالة
     * @return string
     */
    public function getStatus(): string {
        return $this->data['status'] ?? 'pending';
    }
    
    /**
     * الحصول على عدد الأجزاء
     * @return int
     */
    public function getSegments(): int {
        return (int)($this->data['segments'] ?? 1);
    }
    
    /**
     * الحصول على التكلفة
     * @return float
     */
    public function getCost(): float {
        return (float)($this->data['cost'] ?? 0);
    }
    
    /**
     * الحصول على وقت الإرسال
     * @return string|null
     */
    public function getSentAt(): ?string {
        return $this->data['sent_at'] ?? null;
    }
    
    /**
     * الحصول على وقت التسليم
     * @return string|null
     */
    public function getDeliveredAt(): ?string {
        return $this->data['delivered_at'] ?? null;
    }
    
    /**
     * التحقق من التسليم
     * @return bool
     */
    public function isDelivered(): bool {
        return $this->getStatus() === 'delivered';
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }
}

/**
 * SMS_Template
 * @package SMS
 * 
 * قالب رسالة نصية
 */
class SMS_Template {
    
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
     * الحصول على نص القالب
     * @return string
     */
    public function getBody(): string {
        return $this->data['body'] ?? '';
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
     * @return string
     */
    public function render(array $data = []): string {
        $body = $this->getBody();
        $variables = $this->getVariables();
        
        foreach ($variables as $var) {
            $value = $data[$var] ?? '';
            $body = str_replace("{{{$var}}}", $value, $body);
        }
        
        return $body;
    }
    
    /**
     * التحقق من الطول
     * @param int $maxLength
     * @return bool
     */
    public function validateLength(int $maxLength = 160): bool {
        return strlen($this->getBody()) <= $maxLength;
    }
    
    /**
     * الحصول على معاينة
     * @return string
     */
    public function getPreview(): string {
        return $this->render();
    }
}

/**
 * SMS_Webhook
 * @package SMS
 * 
 * معالج Webhook للرسائل النصية
 */
class SMS_Webhook {
    
    /**
     * @var string
     */
    private $url;
    
    /**
     * @var string
     */
    private $secret;
    
    /**
     * المُنشئ
     * @param string $url
     * @param string $secret
     */
    public function __construct(string $url, string $secret) {
        $this->url = $url;
        $this->secret = $secret;
    }
    
    /**
     * معالجة طلب webhook
     * @param array $request
     * @return array
     */
    public function handle(array $request): array {
        // التحقق من التوقيع
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        
        if (!$this->verifySignature($signature, json_encode($request))) {
            return ['error' => 'Invalid signature'];
        }
        
        // معالجة حسب الحدث
        $event = $request['event'] ?? 'unknown';
        
        switch ($event) {
            case 'delivery':
                return $this->processDelivery($request);
                
            case 'incoming':
                return $this->processIncoming($request);
                
            default:
                return ['status' => 'received'];
        }
    }
    
    /**
     * التحقق من التوقيع
     * @param string $signature
     * @param string $payload
     * @return bool
     */
    public function verifySignature(string $signature, string $payload): bool {
        $expected = hash_hmac('sha256', $payload, $this->secret);
        return hash_equals($expected, $signature);
    }
    
    /**
     * معالجة تحديث التسليم
     * @param array $data
     * @return array
     */
    private function processDelivery(array $data): array {
        return ['status' => 'delivery_processed'];
    }
    
    /**
     * معالجة رسالة واردة
     * @param array $data
     * @return array
     */
    private function processIncoming(array $data): array {
        return ['status' => 'incoming_processed'];
    }
}

/**
 * SMS_Conversation
 * @package SMS
 * 
 * محادثة SMS
 */
class SMS_Conversation {
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * @var array
     */
    private $messages;
    
    /**
     * المُنشئ
     * @param array $data
     * @param array $messages
     */
    public function __construct(array $data, array $messages = []) {
        $this->data = $data;
        $this->messages = $messages;
    }
    
    /**
     * الحصول على رقم الهاتف
     * @return string
     */
    public function getPhone(): string {
        return $this->data['phone_number'] ?? '';
    }
    
    /**
     * الحصول على آخر رسالة
     * @return string
     */
    public function getLastMessage(): string {
        return $this->data['last_message'] ?? '';
    }
    
    /**
     * الحصول على وقت آخر رسالة
     * @return string
     */
    public function getLastMessageAt(): string {
        return $this->data['last_message_at'] ?? '';
    }
    
    /**
     * الحصول على عدد الرسائل غير المقروءة
     * @return int
     */
    public function getUnreadCount(): int {
        return (int)($this->data['unread_count'] ?? 0);
    }
    
    /**
     * الحصول على جميع الرسائل
     * @param int $limit
     * @return array
     */
    public function getMessages(int $limit = 50): array {
        return array_slice($this->messages, 0, $limit);
    }
    
    /**
     * إضافة رسالة
     * @param SMS_Message $message
     */
    public function addMessage(SMS_Message $message): void {
        array_unshift($this->messages, $message->toArray());
        $this->data['last_message'] = $message->getBody();
        $this->data['last_message_at'] = date('Y-m-d H:i:s');
        
        if ($message->getTo() === $this->getPhone()) {
            $this->data['unread_count'] = ($this->data['unread_count'] ?? 0) + 1;
        }
    }
    
    /**
     * تعيين كمقروءة
     */
    public function markAsRead(): void {
        $this->data['unread_count'] = 0;
    }
}

/**
 * SMS_Queue
 * @package SMS
 * 
 * طابور الرسائل النصية
 */
class SMS_Queue {
    
    /**
     * @var SMS_App
     */
    private $sms;
    
    /**
     * @var array
     */
    private $queue = [];
    
    /**
     * المُنشئ
     * @param SMS_App $sms
     */
    public function __construct(SMS_App $sms) {
        $this->sms = $sms;
    }
    
    /**
     * إضافة رسالة للطابور
     * @param string $messageId
     * @param string $to
     * @param string $message
     * @param string $from
     * @return SMS_Result
     */
    public function push(string $messageId, string $to, string $message, string $from): SMS_Result {
        $db = Main_App::getInstance()->db;
        
        $db->insert('sms_queue', [
            'message_id' => $messageId,
            'to_number' => $to,
            'message' => $message,
            'from_number' => $from,
            'status' => 'queued',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return new SMS_Result(true, 'Message queued', $messageId, ['queued' => true]);
    }
    
    /**
     * معالجة الطابور
     * @param int $limit
     * @return array
     */
    public function process(int $limit = 100): array {
        $db = Main_App::getInstance()->db;
        
        $messages = $db->fetchAll(
            "SELECT * FROM sms_queue WHERE status = 'queued' LIMIT ?",
            [$limit]
        );
        
        $results = [];
        
        foreach ($messages as $msg) {
            $result = $this->sms->sendImmediate(
                $msg['message_id'],
                $msg['to_number'],
                $msg['message'],
                $msg['from_number']
            );
            
            if ($result->isSuccess()) {
                $db->update('sms_queue', ['status' => 'sent'], ['id' => $msg['id']]);
            } else {
                $db->update('sms_queue', [
                    'status' => 'failed',
                    'error' => $result->getError()
                ], ['id' => $msg['id']]);
            }
            
            $results[$msg['message_id']] = $result;
        }
        
        return $results;
    }
    
    /**
     * الحصول على عدد الرسائل في الطابور
     * @return int
     */
    public function count(): int {
        $db = Main_App::getInstance()->db;
        
        return (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM sms_queue WHERE status = 'queued'"
        );
    }
}

?>