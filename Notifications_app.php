<?php
/**
 * الإشعارات_App.php
 * @version 1.0.0
 * @package Notifications
 * 
 * نظام الإشعارات المتكامل
 * يدعم قنوات متعددة (بريد، رسائل نصية، دفع، قاعدة بيانات، WebSocket)
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * الإشعارات_App
 * @package Notifications
 * 
 * الكلاس الرئيسي لنظام الإشعارات
 */
class Notifications_app {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var App_DB|null قاعدة البيانات
     */
    private $db = null;
    
    /**
     * @var Email_App|null البريد الإلكتروني
     */
    private $email = null;
    
    /**
     * @var SMS_App|null الرسائل النصية
     */
    private $sms = null;
    
    /**
     * @var array قنوات الإشعارات المسجلة
     */
    private $channels = [];
    
    /**
     * @var array قوالب الإشعارات
     */
    private $templates = [];
    
    /**
     * @var Notification_Queue طابور الإشعارات
     */
    private $queue = null;
    
    /**
     * @var array الأحداث المسجلة
     */
    private $events = [];
    
    /**
     * @var array إعدادات الإشعارات
     */
    private $config = [
        'queue_enabled' => true,
        'default_channels' => ['database'],
        'retry_failed' => 3,
        'retry_delay' => 60, // ثواني
        'clean_old_days' => 30,
        'batch_size' => 100
    ];
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param Email_App|null $email
     * @param SMS_App|null $sms
     */
    public function __construct(App_DB $db, ?Email_App $email = null, ?SMS_App $sms = null) {
        $this->db = $db;
        $this->email = $email;
        $this->sms = $sms;
        
        $this->queue = new Notification_Queue($db);
        
        $this->initializeTables();
        $this->registerDefaultChannels();
        $this->loadConfig();
        $this->loadTemplates();
    }
    
    /**
     * تهيئة جداول الإشعارات
     */
    private function initializeTables(): void {
        // جدول أنواع الإشعارات
        if (!$this->db->tableExists('notification_types')) {
            $this->db->query("
                CREATE TABLE notification_types (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    code VARCHAR(50) UNIQUE NOT NULL,
                    description TEXT,
                    channels JSON,
                    priority INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_code (code),
                    INDEX idx_priority (priority)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول الإشعارات
        if (!$this->db->tableExists('notifications')) {
            $this->db->query("
                CREATE TABLE notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type_id INT,
                    type_code VARCHAR(50),
                    title VARCHAR(255),
                    body TEXT,
                    data JSON,
                    channel VARCHAR(50),
                    priority INT DEFAULT 0,
                    status ENUM('pending', 'sent', 'failed', 'read', 'deleted') DEFAULT 'pending',
                    scheduled_at TIMESTAMP NULL,
                    sent_at TIMESTAMP NULL,
                    read_at TIMESTAMP NULL,
                    failed_at TIMESTAMP NULL,
                    error_message TEXT,
                    retry_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (type_id) REFERENCES notification_types(id) ON DELETE SET NULL,
                    INDEX idx_user_status (user_id, status),
                    INDEX idx_scheduled (scheduled_at),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول قوالب الإشعارات
        if (!$this->db->tableExists('notification_templates')) {
            $this->db->query("
                CREATE TABLE notification_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type_id INT,
                    channel VARCHAR(50),
                    subject VARCHAR(255),
                    template TEXT NOT NULL,
                    variables JSON,
                    language VARCHAR(10) DEFAULT 'ar',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (type_id) REFERENCES notification_types(id) ON DELETE CASCADE,
                    INDEX idx_type_channel (type_id, channel),
                    INDEX idx_language (language)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول إعدادات المستخدمين للإشعارات
        if (!$this->db->tableExists('user_notification_settings')) {
            $this->db->query("
                CREATE TABLE user_notification_settings (
                    user_id INT PRIMARY KEY,
                    email_notifications BOOLEAN DEFAULT TRUE,
                    sms_notifications BOOLEAN DEFAULT FALSE,
                    push_notifications BOOLEAN DEFAULT TRUE,
                    database_notifications BOOLEAN DEFAULT TRUE,
                    notification_types JSON,
                    quiet_hours JSON,
                    digest_frequency ENUM('instant', 'hourly', 'daily', 'weekly') DEFAULT 'instant',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول إشعارات المستخدمين المقروءة
        if (!$this->db->tableExists('notification_reads')) {
            $this->db->query("
                CREATE TABLE notification_reads (
                    notification_id INT,
                    user_id INT,
                    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (notification_id, user_id),
                    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
    
    /**
     * تسجيل القنوات الافتراضية
     */
    private function registerDefaultChannels(): void {
        $this->registerChannel('database', new Database_Channel($this->db));
        
        if ($this->email) {
            $this->registerChannel('email', new Email_Channel($this->email));
        }
        
        if ($this->sms) {
            $this->registerChannel('sms', new SMS_Channel($this->sms));
        }
        
        $this->registerChannel('push', new Push_Channel());
    }
    
    /**
     * تحميل الإعدادات
     */
    private function loadConfig(): void {
        try {
            $settings = $this->db->fetchOne("SELECT * FROM system_settings WHERE key = 'notifications_config'");
            if ($settings && !empty($settings['value'])) {
                $this->config = array_merge($this->config, json_decode($settings['value'], true));
            }
        } catch (Exception $e) {
            // استخدام الإعدادات الافتراضية
        }
    }
    
    /**
     * تحميل القوالب
     */
    private function loadTemplates(): void {
        $templates = $this->db->fetchAll("
            SELECT nt.*, ntc.code as type_code 
            FROM notification_templates nt
            JOIN notification_types ntc ON nt.type_id = ntc.id
        ");
        
        foreach ($templates as $template) {
            $key = $template['type_code'] . '_' . $template['channel'] . '_' . $template['language'];
            $this->templates[$key] = $template;
        }
    }
    
    // ==========================================
    // إدارة القنوات
    // ==========================================
    
    /**
     * تسجيل قناة جديدة
     * @param string $name
     * @param Notification_Channel $channel
     */
    public function registerChannel(string $name, Notification_Channel $channel): void {
        $this->channels[$name] = $channel;
    }
    
    /**
     * الحصول على قناة
     * @param string $name
     * @return Notification_Channel|null
     */
    public function getChannel(string $name): ?Notification_Channel {
        return $this->channels[$name] ?? null;
    }
    
    /**
     * الحصول على جميع القنوات
     * @return array
     */
    public function getChannels(): array {
        return $this->channels;
    }
    
    /**
     * تفعيل/تعطيل قناة
     * @param string $name
     * @param bool $enabled
     * @return bool
     */
    public function setChannelEnabled(string $name, bool $enabled): bool {
        if (!isset($this->channels[$name])) {
            return false;
        }
        
        if ($enabled) {
            $this->channels[$name]->enable();
        } else {
            $this->channels[$name]->disable();
        }
        
        return true;
    }
    
    // ==========================================
    // إدارة أنواع الإشعارات
    // ==========================================
    
    /**
     * إنشاء نوع إشعار جديد
     * @param string $code
     * @param string $name
     * @param string $description
     * @param array $channels
     * @param int $priority
     * @return int
     */
    public function createNotificationType(string $code, string $name, string $description = '', array $channels = [], int $priority = 0): int {
        $data = [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'channels' => json_encode($channels ?: $this->config['default_channels']),
            'priority' => $priority
        ];
        
        return $this->db->insert('notification_types', $data);
    }
    
    /**
     * تحديث نوع إشعار
     * @param int $type_id
     * @param array $data
     * @return bool
     */
    public function updateNotificationType(int $type_id, array $data): bool {
        $allowed = ['name', 'description', 'channels', 'priority'];
        $update_data = [];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if ($field === 'channels' && is_array($data[$field])) {
                    $update_data[$field] = json_encode($data[$field]);
                } else {
                    $update_data[$field] = $data[$field];
                }
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return (bool)$this->db->update('notification_types', $update_data, ['id' => $type_id]);
    }
    
    /**
     * حذف نوع إشعار
     * @param int $type_id
     * @return bool
     */
    public function deleteNotificationType(int $type_id): bool {
        return (bool)$this->db->delete('notification_types', ['id' => $type_id]);
    }
    
    /**
     * الحصول على نوع إشعار
     * @param string $code
     * @return array|null
     */
    public function getNotificationType(string $code): ?array {
        return $this->db->fetchOne("SELECT * FROM notification_types WHERE code = ?", [$code]);
    }
    
    // ==========================================
    // إدارة القوالب
    // ==========================================
    
    /**
     * تعيين قالب لنوع إشعار
     * @param string $type_code
     * @param string $channel
     * @param string $subject
     * @param string $template
     * @param array $variables
     * @param string $language
     * @return int
     */
    public function setTemplate(string $type_code, string $channel, string $subject, string $template, array $variables = [], string $language = 'ar'): int {
        $type = $this->getNotificationType($type_code);
        
        if (!$type) {
            return 0;
        }
        
        // حذف القالب القديم إذا موجود
        $this->db->delete('notification_templates', [
            'type_id' => $type['id'],
            'channel' => $channel,
            'language' => $language
        ]);
        
        $data = [
            'type_id' => $type['id'],
            'channel' => $channel,
            'subject' => $subject,
            'template' => $template,
            'variables' => json_encode($variables),
            'language' => $language
        ];
        
        $id = $this->db->insert('notification_templates', $data);
        
        // تحديث الذاكرة المؤقتة
        $key = $type_code . '_' . $channel . '_' . $language;
        $this->templates[$key] = array_merge($data, ['type_code' => $type_code]);
        
        return $id;
    }
    
    /**
     * الحصول على قالب
     * @param string $type_code
     * @param string $channel
     * @param string $language
     * @return array|null
     */
    public function getTemplate(string $type_code, string $channel, string $language = 'ar'): ?array {
        $key = $type_code . '_' . $channel . '_' . $language;
        
        if (isset($this->templates[$key])) {
            return $this->templates[$key];
        }
        
        $template = $this->db->fetchOne("
            SELECT nt.*, ntc.code as type_code 
            FROM notification_templates nt
            JOIN notification_types ntc ON nt.type_id = ntc.id
            WHERE ntc.code = ? AND nt.channel = ? AND nt.language = ?
        ", [$type_code, $channel, $language]);
        
        if ($template) {
            $this->templates[$key] = $template;
        }
        
        return $template;
    }
    
    /**
     * عرض قالب بالبيانات
     * @param string $type_code
     * @param string $channel
     * @param array $data
     * @param string $language
     * @return array
     */
    public function renderTemplate(string $type_code, string $channel, array $data = [], string $language = 'ar'): array {
        $template = $this->getTemplate($type_code, $channel, $language);
        
        if (!$template) {
            return [
                'subject' => $type_code,
                'body' => json_encode($data)
            ];
        }
        
        $variables = json_decode($template['variables'] ?? '[]', true) ?: [];
        $render_data = [];
        
        // تجهيز المتغيرات
        foreach ($variables as $var) {
            $render_data['{{' . $var . '}}'] = $data[$var] ?? '';
        }
        
        // تطبيق القالب
        $subject = str_replace(array_keys($render_data), array_values($render_data), $template['subject']);
        $body = str_replace(array_keys($render_data), array_values($render_data), $template['template']);
        
        return [
            'subject' => $subject,
            'body' => $body,
            'template_id' => $template['id']
        ];
    }
    
    // ==========================================
    // إرسال الإشعارات
    // ==========================================
    
    /**
     * إرسال إشعار
     * @param int $user_id
     * @param string $type
     * @param array $data
     * @param array|null $channels
     * @return string|array
     */
    public function send(int $user_id, string $type, array $data = [], ?array $channels = null) {
        // الحصول على نوع الإشعار
        $type_data = $this->getNotificationType($type);
        
        if (!$type_data) {
            return ['error' => 'Notification type not found'];
        }
        
        // تحديد القنوات
        $channels = $channels ?: json_decode($type_data['channels'] ?? '[]', true);
        
        // الحصول على إعدادات المستخدم
        $settings = $this->getUserSettings($user_id);
        
        // تصفية القنوات حسب إعدادات المستخدم
        $channels = $this->filterChannelsByUserSettings($channels, $settings, $type);
        
        if (empty($channels)) {
            return ['error' => 'No channels available'];
        }
        
        // إنشاء الإشعار في قاعدة البيانات
        $notification_id = $this->createNotification($user_id, $type_data, $data, $channels);
        
        if (!$notification_id) {
            return ['error' => 'Failed to create notification'];
        }
        
        $results = [];
        
        // إرسال عبر كل قناة
        foreach ($channels as $channel_name) {
            $channel = $this->getChannel($channel_name);
            
            if (!$channel || !$channel->isEnabled()) {
                continue;
            }
            
            // التحقق من القناة في إعدادات المستخدم
            if (!$this->isChannelEnabledForUser($settings, $channel_name, $type)) {
                continue;
            }
            
            // التحقق من ساعات الهدوء
            if ($this->isQuietHours($settings)) {
                // تأجيل الإشعار
                $scheduled = date('Y-m-d H:i:s', strtotime('tomorrow 08:00:00'));
                $this->schedule($user_id, $type, $data, $scheduled, [$channel_name]);
                continue;
            }
            
            // تجهيز بيانات الإشهار
            $notification = $this->prepareNotification($notification_id, $user_id, $type_data, $data, $channel_name);
            
            // إرسال عبر القناة
            if ($this->config['queue_enabled']) {
                // إضافة للطابور
                $job_id = $this->queue->push($notification);
                $results[$channel_name] = ['queued' => true, 'job_id' => $job_id];
            } else {
                // إرسال مباشر
                $result = $channel->send($notification);
                $this->updateNotificationStatus($notification_id, $channel_name, $result);
                $results[$channel_name] = $result->toArray();
            }
        }
        
        return $results;
    }
    
    /**
     * إرسال إشعار لمجموعة مستخدمين
     * @param array $user_ids
     * @param string $type
     * @param array $data
     * @param array|null $channels
     * @return array
     */
    public function sendBulk(array $user_ids, string $type, array $data = [], ?array $channels = null): array {
        $results = [];
        
        foreach (array_chunk($user_ids, $this->config['batch_size']) as $batch) {
            foreach ($batch as $user_id) {
                $results[$user_id] = $this->send($user_id, $type, $data, $channels);
            }
        }
        
        return $results;
    }
    
    /**
     * إرسال إشعار لدور معين
     * @param string $role
     * @param string $type
     * @param array $data
     * @param array|null $channels
     * @return int
     */
    public function sendToRole(string $role, string $type, array $data = [], ?array $channels = null): int {
        // الحصول على مستخدمين الدور
        $users = $this->db->fetchAll("
            SELECT DISTINCT ur.user_id 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE r.name = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
        ", [$role]);
        
        $user_ids = array_column($users, 'user_id');
        
        if (empty($user_ids)) {
            return 0;
        }
        
        $this->sendBulk($user_ids, $type, $data, $channels);
        
        return count($user_ids);
    }
    
    /**
     * جدولة إشعار
     * @param int $user_id
     * @param string $type
     * @param array $data
     * @param string $datetime
     * @param array|null $channels
     * @return int|false
     */
    public function schedule(int $user_id, string $type, array $data, string $datetime, ?array $channels = null) {
        $type_data = $this->getNotificationType($type);
        
        if (!$type_data) {
            return false;
        }
        
        $channels = $channels ?: json_decode($type_data['channels'] ?? '[]', true);
        
        $notification_data = [
            'user_id' => $user_id,
            'type_id' => $type_data['id'],
            'type_code' => $type,
            'title' => $type_data['name'],
            'body' => json_encode($data),
            'data' => json_encode($data),
            'channel' => json_encode($channels),
            'status' => 'pending',
            'scheduled_at' => $datetime,
            'priority' => $type_data['priority'] ?? 0
        ];
        
        return $this->db->insert('notifications', $notification_data);
    }
    
    /**
     * إلغاء إشعار مجدول
     * @param int $notification_id
     * @return bool
     */
    public function cancel(int $notification_id): bool {
        return (bool)$this->db->update('notifications', [
            'status' => 'cancelled'
        ], ['id' => $notification_id, 'status' => 'pending', 'scheduled_at IS NOT NULL']);
    }
    
    /**
     * إنشاء إشعار في قاعدة البيانات
     * @param int $user_id
     * @param array $type_data
     * @param array $data
     * @param array $channels
     * @return int
     */
    private function createNotification(int $user_id, array $type_data, array $data, array $channels): int {
        $notification_data = [
            'user_id' => $user_id,
            'type_id' => $type_data['id'],
            'type_code' => $type_data['code'],
            'title' => $this->extractTitle($data),
            'body' => $this->extractBody($data),
            'data' => json_encode($data),
            'channel' => json_encode($channels),
            'status' => 'pending',
            'priority' => $type_data['priority'] ?? 0
        ];
        
        return $this->db->insert('notifications', $notification_data);
    }
    
    /**
     * تجهيز إشعار للإرسال
     * @param int $notification_id
     * @param int $user_id
     * @param array $type_data
     * @param array $data
     * @param string $channel
     * @return Notification
     */
    private function prepareNotification(int $notification_id, int $user_id, array $type_data, array $data, string $channel): Notification {
        // محاولة استخدام قالب
        $rendered = $this->renderTemplate($type_data['code'], $channel, $data);
        
        return new Notification([
            'id' => $notification_id,
            'user_id' => $user_id,
            'type' => $type_data['code'],
            'title' => $rendered['subject'] ?: $this->extractTitle($data),
            'body' => $rendered['body'] ?: $this->extractBody($data),
            'data' => $data,
            'channel' => $channel
        ]);
    }
    
    /**
     * تحديث حالة الإشعار
     * @param int $notification_id
     * @param string $channel
     * @param Channel_Result $result
     */
    private function updateNotificationStatus(int $notification_id, string $channel, Channel_Result $result): void {
        $status = $result->isSuccess() ? 'sent' : 'failed';
        
        $update = [
            'status' => $status,
            $status . '_at' => date('Y-m-d H:i:s')
        ];
        
        if (!$result->isSuccess()) {
            $update['error_message'] = $result->getError();
            $update['retry_count'] = $this->db->raw('retry_count + 1');
        }
        
        $this->db->update('notifications', $update, ['id' => $notification_id]);
    }
    
    /**
     * استخراج العنوان من البيانات
     * @param array $data
     * @return string
     */
    private function extractTitle(array $data): string {
        return $data['title'] ?? $data['subject'] ?? 'إشعار جديد';
    }
    
    /**
     * استخراج المحتوى من البيانات
     * @param array $data
     * @return string
     */
    private function extractBody(array $data): string {
        return $data['body'] ?? $data['message'] ?? json_encode($data);
    }
    
    // ==========================================
    // إدارة طابور الإشعارات
    // ==========================================
    
    /**
     * معالجة طابور الإشعارات
     * @param int $limit
     * @return array
     */
    public function processQueue(int $limit = 100): array {
        return $this->queue->process($limit);
    }
    
    /**
     * إعادة محاولة الإشعارات الفاشلة
     * @return int
     */
    public function retryFailed(): int {
        return $this->queue->retryFailed();
    }
    
    /**
     * تنظيف الإشعارات القديمة
     * @param int $days
     * @return int
     */
    public function cleanOldNotifications(int $days = null): int {
        $days = $days ?? $this->config['clean_old_days'];
        
        return $this->db->delete('notifications', 
            "created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN ('sent', 'read', 'failed')",
            [$days]
        );
    }
    
    // ==========================================
    // إدارة إعدادات المستخدم
    // ==========================================
    
    /**
     * الحصول على إعدادات مستخدم
     * @param int $user_id
     * @return array
     */
    public function getUserSettings(int $user_id): array {
        $settings = $this->db->fetchOne(
            "SELECT * FROM user_notification_settings WHERE user_id = ?",
            [$user_id]
        );
        
        if (!$settings) {
            // إنشاء إعدادات افتراضية
            $default = [
                'user_id' => $user_id,
                'email_notifications' => true,
                'sms_notifications' => false,
                'push_notifications' => true,
                'database_notifications' => true,
                'notification_types' => json_encode([]),
                'quiet_hours' => json_encode([]),
                'digest_frequency' => 'instant'
            ];
            
            $this->db->insert('user_notification_settings', $default);
            
            return $default;
        }
        
        return $settings;
    }
    
    /**
     * تحديث إعدادات مستخدم
     * @param int $user_id
     * @param array $settings
     * @return bool
     */
    public function updateUserSettings(int $user_id, array $settings): bool {
        $allowed = [
            'email_notifications', 'sms_notifications', 'push_notifications',
            'database_notifications', 'notification_types', 'quiet_hours', 'digest_frequency'
        ];
        
        $update_data = [];
        
        foreach ($allowed as $field) {
            if (isset($settings[$field])) {
                if (in_array($field, ['notification_types', 'quiet_hours']) && is_array($settings[$field])) {
                    $update_data[$field] = json_encode($settings[$field]);
                } else {
                    $update_data[$field] = $settings[$field];
                }
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $update_data['updated_at'] = date('Y-m-d H:i:s');
        
        // التحقق من وجود السجل
        $exists = $this->db->fetchOne("SELECT user_id FROM user_notification_settings WHERE user_id = ?", [$user_id]);
        
        if ($exists) {
            return (bool)$this->db->update('user_notification_settings', $update_data, ['user_id' => $user_id]);
        } else {
            $update_data['user_id'] = $user_id;
            return (bool)$this->db->insert('user_notification_settings', $update_data);
        }
    }
    
    /**
     * تصفية القنوات حسب إعدادات المستخدم
     * @param array $channels
     * @param array $settings
     * @param string $type
     * @return array
     */
    private function filterChannelsByUserSettings(array $channels, array $settings, string $type): array {
        $filtered = [];
        
        // أنواع الإشعارات المسموحة للمستخدم
        $allowed_types = json_decode($settings['notification_types'] ?? '[]', true);
        
        if (!empty($allowed_types) && !in_array($type, $allowed_types)) {
            return [];
        }
        
        foreach ($channels as $channel) {
            $setting_key = $channel . '_notifications';
            
            if (isset($settings[$setting_key]) && $settings[$setting_key]) {
                $filtered[] = $channel;
            }
        }
        
        return $filtered;
    }
    
    /**
     * التحقق من تفعيل قناة لمستخدم
     * @param array $settings
     * @param string $channel
     * @param string $type
     * @return bool
     */
    private function isChannelEnabledForUser(array $settings, string $channel, string $type): bool {
        $setting_key = $channel . '_notifications';
        
        if (!isset($settings[$setting_key]) || !$settings[$setting_key]) {
            return false;
        }
        
        // التحقق من أنواع الإشعارات المسموحة
        $allowed_types = json_decode($settings['notification_types'] ?? '[]', true);
        
        if (!empty($allowed_types) && !in_array($type, $allowed_types)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * التحقق من ساعات الهدوء
     * @param array $settings
     * @return bool
     */
    private function isQuietHours(array $settings): bool {
        $quiet_hours = json_decode($settings['quiet_hours'] ?? '[]', true);
        
        if (empty($quiet_hours)) {
            return false;
        }
        
        $current_time = date('H:i');
        
        foreach ($quiet_hours as $range) {
            if (isset($range['start']) && isset($range['end'])) {
                if ($current_time >= $range['start'] && $current_time <= $range['end']) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    // ==========================================
    // استعلامات الإشعارات
    // ==========================================
    
    /**
     * الحصول على إشعارات المستخدم
     * @param int $user_id
     * @param array $filters
     * @return array
     */
    public function getUserNotifications(int $user_id, array $filters = []): array {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$user_id];
        
        // تطبيق الفلاتر
        if (isset($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['type'])) {
            $sql .= " AND type_code = ?";
            $params[] = $filters['type'];
        }
        
        if (isset($filters['from_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }
        
        if (isset($filters['to_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        // ترتيب
        $sql .= " ORDER BY " . ($filters['order_by'] ?? 'created_at DESC');
        
        // حد
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }
        
        $notifications = $this->db->fetchAll($sql, $params);
        
        // تحويل JSON
        foreach ($notifications as &$notif) {
            $notif['data'] = json_decode($notif['data'], true);
        }
        
        return $notifications;
    }
    
    /**
     * الحصول على عدد الإشعارات غير المقروءة
     * @param int $user_id
     * @return int
     */
    public function getUnreadCount(int $user_id): int {
        return (int)$this->db->fetchColumn("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND status = 'sent' AND read_at IS NULL
        ", [$user_id]);
    }
    
    /**
     * تعيين إشعار كمقروء
     * @param int $notification_id
     * @return bool
     */
    public function markAsRead(int $notification_id): bool {
        return (bool)$this->db->update('notifications', [
            'status' => 'read',
            'read_at' => date('Y-m-d H:i:s')
        ], ['id' => $notification_id]);
    }
    
    /**
     * تعيين جميع إشعارات المستخدم كمقروءة
     * @param int $user_id
     * @return int
     */
    public function markAllAsRead(int $user_id): int {
        return $this->db->update('notifications', [
            'status' => 'read',
            'read_at' => date('Y-m-d H:i:s')
        ], ['user_id' => $user_id, 'status' => 'sent']);
    }
    
    /**
     * حذف إشعار
     * @param int $notification_id
     * @return bool
     */
    public function deleteNotification(int $notification_id): bool {
        return (bool)$this->db->update('notifications', [
            'status' => 'deleted'
        ], ['id' => $notification_id]);
    }
    
    /**
     * حذف جميع إشعارات المستخدم
     * @param int $user_id
     * @return int
     */
    public function deleteUserNotifications(int $user_id): int {
        return $this->db->update('notifications', [
            'status' => 'deleted'
        ], ['user_id' => $user_id]);
    }
    
    // ==========================================
    // نظام الأحداث
    // ==========================================
    
    /**
     * الاستماع لحدث
     * @param string $event
     * @param callable $callback
     */
    public function listenToEvent(string $event, callable $callback): void {
        if (!isset($this->events[$event])) {
            $this->events[$event] = [];
        }
        
        $this->events[$event][] = $callback;
    }
    
    /**
     * إطلاق حدث
     * @param string $event
     * @param mixed $data
     */
    public function triggerEvent(string $event, $data = null): void {
        if (!isset($this->events[$event])) {
            return;
        }
        
        foreach ($this->events[$event] as $callback) {
            call_user_func($callback, $data);
        }
    }
    
    // ==========================================
    // إحصاءات
    // ==========================================
    
    /**
     * الحصول على إحصاءات الإشعارات
     * @param array $filters
     * @return array
     */
    public function getStatistics(array $filters = []): array {
        $stats = [
            'total' => 0,
            'by_status' => [],
            'by_type' => [],
            'by_channel' => [],
            'daily' => []
        ];
        
        // إجمالي الإشعارات
        $sql = "SELECT COUNT(*) as total FROM notifications WHERE 1=1";
        $params = [];
        
        if (isset($filters['from_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }
        
        if (isset($filters['to_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        $stats['total'] = (int)$this->db->fetchColumn($sql, $params);
        
        // حسب الحالة
        $by_status = $this->db->fetchAll("
            SELECT status, COUNT(*) as count 
            FROM notifications 
            GROUP BY status
        ");
        
        foreach ($by_status as $row) {
            $stats['by_status'][$row['status']] = (int)$row['count'];
        }
        
        // حسب النوع
        $by_type = $this->db->fetchAll("
            SELECT type_code, COUNT(*) as count 
            FROM notifications 
            GROUP BY type_code 
            ORDER BY count DESC 
            LIMIT 10
        ");
        
        foreach ($by_type as $row) {
            $stats['by_type'][$row['type_code']] = (int)$row['count'];
        }
        
        // حسب اليوم
        $daily = $this->db->fetchAll("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM notifications 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        
        foreach ($daily as $row) {
            $stats['daily'][$row['date']] = (int)$row['count'];
        }
        
        return $stats;
    }
}

/**
 * Notification_Channel (Interface)
 * @package Notifications
 * 
 * واجهة قنوات الإشعارات
 */
interface Notification_Channel {
    
    /**
     * إرسال إشعار
     * @param Notification $notification
     * @return Channel_Result
     */
    public function send(Notification $notification): Channel_Result;
    
    /**
     * التحقق من صحة الإعدادات
     * @return bool
     */
    public function validateConfig(): bool;
    
    /**
     * الحصول على اسم القناة
     * @return string
     */
    public function getName(): string;
    
    /**
     * التحقق من تفعيل القناة
     * @return bool
     */
    public function isEnabled(): bool;
    
    /**
     * تفعيل القناة
     */
    public function enable(): void;
    
    /**
     * تعطيل القناة
     */
    public function disable(): void;
    
    /**
     * الحصول على الإعدادات
     * @return array
     */
    public function getConfig(): array;
    
    /**
     * تحديث الإعدادات
     * @param array $config
     */
    public function updateConfig(array $config): void;
}

/**
 * Database_Channel
 * @package Notifications
 * 
 * قناة قاعدة البيانات
 */
class Database_Channel implements Notification_Channel {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var string
     */
    private $name = 'database';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'table' => 'notifications',
        'store_data' => true
    ];
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            // قاعدة البيانات بالفعل خزنت الإشعار عند الإنشاء
            // هنا فقط نحدث الحالة إذا لزم الأمر
            
            return new Channel_Result(true, 'Notification stored in database');
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return isset($this->config['table']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * تخزين إشعار
     * @param Notification $notification
     * @return int
     */
    public function store(Notification $notification): int {
        $data = [
            'user_id' => $notification->getUserId(),
            'type_code' => $notification->getType(),
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'data' => json_encode($notification->getData()),
            'channel' => 'database',
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->db->insert('notifications', $data);
    }
    
    /**
     * الحصول على إشعارات مستخدم
     * @param int $user_id
     * @return array
     */
    public function getUserNotifications(int $user_id): array {
        return $this->db->fetchAll(
            "SELECT * FROM notifications WHERE user_id = ? AND channel = 'database' ORDER BY created_at DESC",
            [$user_id]
        );
    }
    
    /**
     * تعيين إشعار كمقروء
     * @param int $notification_id
     * @return bool
     */
    public function markRead(int $notification_id): bool {
        return (bool)$this->db->update('notifications', [
            'read_at' => date('Y-m-d H:i:s')
        ], ['id' => $notification_id]);
    }
}

/**
 * Email_Channel
 * @package Notifications
 * 
 * قناة البريد الإلكتروني
 */
class Email_Channel implements Notification_Channel {
    
    /**
     * @var Email_App
     */
    private $email;
    
    /**
     * @var string
     */
    private $name = 'email';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'from_address' => '',
        'from_name' => '',
        'template_prefix' => 'emails/'
    ];
    
    /**
     * المُنشئ
     * @param Email_App $email
     */
    public function __construct(Email_App $email) {
        $this->email = $email;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            // الحصول على بريد المستخدم
            $user = $this->getUserEmail($notification->getUserId());
            
            if (!$user) {
                return new Channel_Result(false, 'User email not found');
            }
            
            // إرسال البريد
            $result = $this->email->send(
                $user,
                $notification->getTitle(),
                $notification->getBody(),
                ['html' => true]
            );
            
            if ($result) {
                return new Channel_Result(true, 'Email sent successfully');
            } else {
                return new Channel_Result(false, 'Failed to send email');
            }
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return isset($this->config['from_address']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * الحصول على بريد المستخدم
     * @param int $user_id
     * @return string|null
     */
    private function getUserEmail(int $user_id): ?string {
        $db = Main_App::getInstance()->db;
        return $db->fetchColumn("SELECT email FROM users WHERE id = ?", [$user_id]);
    }
    
    /**
     * تنسيق الإشعار كبريد
     * @param Notification $notification
     * @return array
     */
    private function formatEmail(Notification $notification): array {
        return [
            'subject' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'html' => true
        ];
    }
}

/**
 * SMS_Channel
 * @package Notifications
 * 
 * قناة الرسائل النصية
 */
class SMS_Channel implements Notification_Channel {
    
    /**
     * @var SMS_App
     */
    private $sms;
    
    /**
     * @var string
     */
    private $name = 'sms';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'from_number' => '',
        'max_length' => 160,
        'unicode' => false
    ];
    
    /**
     * المُنشئ
     * @param SMS_App $sms
     */
    public function __construct(SMS_App $sms) {
        $this->sms = $sms;
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            // الحصول على رقم المستخدم
            $phone = $this->getUserPhone($notification->getUserId());
            
            if (!$phone) {
                return new Channel_Result(false, 'User phone not found');
            }
            
            // تنسيق الرسالة
            $message = $this->formatSMS($notification);
            
            // تقسيم الرسالة الطويلة
            $messages = $this->splitLongMessage($message);
            
            // إرسال الرسائل
            foreach ($messages as $msg) {
                $result = $this->sms->send($phone, $msg);
                
                if (!$result) {
                    return new Channel_Result(false, 'Failed to send SMS');
                }
            }
            
            return new Channel_Result(true, 'SMS sent successfully');
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return isset($this->config['from_number']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * الحصول على رقم المستخدم
     * @param int $user_id
     * @return string|null
     */
    private function getUserPhone(int $user_id): ?string {
        $db = Main_App::getInstance()->db;
        return $db->fetchColumn("SELECT phone FROM users WHERE id = ?", [$user_id]);
    }
    
    /**
     * تنسيق الرسالة
     * @param Notification $notification
     * @return string
     */
    private function formatSMS(Notification $notification): string {
        $message = $notification->getTitle() . "\n" . $notification->getBody();
        
        // قص الطول إذا لزم الأمر
        if (strlen($message) > $this->config['max_length']) {
            $message = substr($message, 0, $this->config['max_length'] - 3) . '...';
        }
        
        return $message;
    }
    
    /**
     * تقسيم الرسالة الطويلة
     * @param string $message
     * @return array
     */
    private function splitLongMessage(string $message): array {
        if (strlen($message) <= $this->config['max_length']) {
            return [$message];
        }
        
        $parts = [];
        $length = $this->config['max_length'];
        $total = ceil(strlen($message) / $length);
        
        for ($i = 0; $i < $total; $i++) {
            $start = $i * $length;
            $part = substr($message, $start, $length);
            
            if ($total > 1) {
                $part = "($i/" . ($total-1) . ") " . $part;
            }
            
            $parts[] = $part;
        }
        
        return $parts;
    }
}

/**
 * Push_Channel
 * @package Notifications
 * 
 * قناة الإشعارات الفورية (Push)
 */
class Push_Channel implements Notification_Channel {
    
    /**
     * @var string
     */
    private $name = 'push';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'fcm_key' => '',
        'apns_cert' => '',
        'android_config' => [],
        'ios_config' => []
    ];
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            // الحصول على أجهزة المستخدم
            $devices = $this->getUserDevices($notification->getUserId());
            
            if (empty($devices)) {
                return new Channel_Result(false, 'No devices found');
            }
            
            // إعداد بيانات الإشعار
            $push_data = [
                'title' => $notification->getTitle(),
                'body' => $notification->getBody(),
                'data' => $notification->getData(),
                'badge' => 1,
                'sound' => 'default'
            ];
            
            // إرسال لكل جهاز
            $success_count = 0;
            
            foreach ($devices as $device) {
                if ($device['platform'] === 'android') {
                    $result = $this->sendToAndroid($device['token'], $push_data);
                } elseif ($device['platform'] === 'ios') {
                    $result = $this->sendToIOS($device['token'], $push_data);
                } else {
                    continue;
                }
                
                if ($result) {
                    $success_count++;
                }
            }
            
            if ($success_count > 0) {
                return new Channel_Result(true, "Push sent to $success_count devices");
            } else {
                return new Channel_Result(false, 'Failed to send push');
            }
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->config['fcm_key']) || !empty($this->config['apns_cert']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * الحصول على أجهزة المستخدم
     * @param int $user_id
     * @return array
     */
    private function getUserDevices(int $user_id): array {
        $db = Main_App::getInstance()->db;
        return $db->fetchAll(
            "SELECT * FROM user_devices WHERE user_id = ? AND push_enabled = 1",
            [$user_id]
        );
    }
    
    /**
     * إرسال إشعار لأندرويد
     * @param string $token
     * @param array $data
     * @return bool
     */
    private function sendToAndroid(string $token, array $data): bool {
        // تنفيذ FCM
        return true; // تبسيطاً
    }
    
    /**
     * إرسال إشعار لـ iOS
     * @param string $token
     * @param array $data
     * @return bool
     */
    private function sendToIOS(string $token, array $data): bool {
        // تنفيذ APNs
        return true; // تبسيطاً
    }
    
    /**
     * إرسال لموضوع معين
     * @param string $topic
     * @param array $data
     * @return bool
     */
    public function sendToTopic(string $topic, array $data): bool {
        // تنفيذ FCM topic
        return true;
    }
    
    /**
     * اشتراك جهاز في موضوع
     * @param string $token
     * @param string $topic
     * @return bool
     */
    public function subscribeToTopic(string $token, string $topic): bool {
        // تنفيذ اشتراك FCM
        return true;
    }
}

/**
 * WebSocket_Channel
 * @package Notifications
 * 
 * قناة WebSocket للإشعارات الفورية
 */
class WebSocket_Channel implements Notification_Channel {
    
    /**
     * @var string
     */
    private $name = 'websocket';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'server_url' => '',
        'app_id' => '',
        'app_key' => '',
        'app_secret' => ''
    ];
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            // إرسال عبر WebSocket
            $this->sendToUser($notification->getUserId(), [
                'type' => 'notification',
                'data' => [
                    'id' => $notification->getId(),
                    'title' => $notification->getTitle(),
                    'body' => $notification->getBody(),
                    'type' => $notification->getType(),
                    'timestamp' => time()
                ]
            ]);
            
            return new Channel_Result(true, 'WebSocket notification sent');
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->config['server_url']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * إرسال لمستخدم
     * @param int $user_id
     * @param array $data
     * @return bool
     */
    public function sendToUser(int $user_id, array $data): bool {
        // تنفيذ WebSocket
        return true;
    }
    
    /**
     * بث لغرفة
     * @param string $room
     * @param array $data
     * @return bool
     */
    public function broadcast(string $room, array $data): bool {
        // تنفيذ بث
        return true;
    }
}

/**
 * Slack_Channel
 * @package Notifications
 * 
 * قناة Slack
 */
class Slack_Channel implements Notification_Channel {
    
    /**
     * @var string
     */
    private $name = 'slack';
    
    /**
     * @var bool
     */
    private $enabled = true;
    
    /**
     * @var array
     */
    private $config = [
        'webhook_url' => '',
        'channel' => '#general',
        'username' => 'Notification Bot',
        'icon_emoji' => ':bell:'
    ];
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * @inheritdoc
     */
    public function send(Notification $notification): Channel_Result {
        try {
            $message = $this->formatSlackMessage($notification);
            
            // إرسال إلى Slack
            $result = $this->sendToSlack($message);
            
            if ($result) {
                return new Channel_Result(true, 'Slack message sent');
            } else {
                return new Channel_Result(false, 'Failed to send to Slack');
            }
            
        } catch (Exception $e) {
            return new Channel_Result(false, $e->getMessage());
        }
    }
    
    /**
     * @inheritdoc
     */
    public function validateConfig(): bool {
        return !empty($this->config['webhook_url']);
    }
    
    /**
     * @inheritdoc
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * @inheritdoc
     */
    public function enable(): void {
        $this->enabled = true;
    }
    
    /**
     * @inheritdoc
     */
    public function disable(): void {
        $this->enabled = false;
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
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * تنسيق رسالة Slack
     * @param Notification $notification
     * @return array
     */
    private function formatSlackMessage(Notification $notification): array {
        return [
            'channel' => $this->config['channel'],
            'username' => $this->config['username'],
            'icon_emoji' => $this->config['icon_emoji'],
            'attachments' => [
                [
                    'color' => '#36a64f',
                    'title' => $notification->getTitle(),
                    'text' => $notification->getBody(),
                    'fields' => $this->formatFields($notification->getData()),
                    'ts' => time()
                ]
            ]
        ];
    }
    
    /**
     * تنسيق الحقول
     * @param array $data
     * @return array
     */
    private function formatFields(array $data): array {
        $fields = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $fields[] = [
                    'title' => ucfirst($key),
                    'value' => $value,
                    'short' => true
                ];
            }
        }
        
        return $fields;
    }
    
    /**
     * إرسال إلى Slack
     * @param array $message
     * @return bool
     */
    private function sendToSlack(array $message): bool {
        $ch = curl_init($this->config['webhook_url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
}

/**
 * Notification
 * @package Notifications
 * 
 * تمثل إشعاراً
 */
class Notification {
    
    /**
     * @var array بيانات الإشعار
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
     * الحصول على معرف الإشعار
     * @return int|null
     */
    public function getId(): ?int {
        return $this->data['id'] ?? null;
    }
    
    /**
     * الحصول على معرف المستخدم
     * @return int
     */
    public function getUserId(): int {
        return $this->data['user_id'];
    }
    
    /**
     * الحصول على نوع الإشعار
     * @return string
     */
    public function getType(): string {
        return $this->data['type'];
    }
    
    /**
     * الحصول على العنوان
     * @return string
     */
    public function getTitle(): string {
        return $this->data['title'] ?? '';
    }
    
    /**
     * الحصول على المحتوى
     * @return string
     */
    public function getBody(): string {
        return $this->data['body'] ?? '';
    }
    
    /**
     * الحصول على البيانات
     * @return array
     */
    public function getData(): array {
        return $this->data['data'] ?? [];
    }
    
    /**
     * الحصول على القناة
     * @return string
     */
    public function getChannel(): string {
        return $this->data['channel'] ?? '';
    }
    
    /**
     * الحصول على الأولوية
     * @return int
     */
    public function getPriority(): int {
        return $this->data['priority'] ?? 0;
    }
    
    /**
     * الحصول على وقت الإنشاء
     * @return string|null
     */
    public function getCreatedAt(): ?string {
        return $this->data['created_at'] ?? null;
    }
    
    /**
     * الحصول على وقت الإرسال
     * @return string|null
     */
    public function getSentAt(): ?string {
        return $this->data['sent_at'] ?? null;
    }
    
    /**
     * الحصول على وقت القراءة
     * @return string|null
     */
    public function getReadAt(): ?string {
        return $this->data['read_at'] ?? null;
    }
    
    /**
     * التحقق من القراءة
     * @return bool
     */
    public function isRead(): bool {
        return !empty($this->data['read_at']);
    }
    
    /**
     * التحقق من الإرسال
     * @return bool
     */
    public function isSent(): bool {
        return !empty($this->data['sent_at']);
    }
    
    /**
     * التحقق من الجدولة
     * @return bool
     */
    public function isScheduled(): bool {
        return !empty($this->data['scheduled_at']);
    }
    
    /**
     * التحقق من الفشل
     * @return bool
     */
    public function hasFailed(): bool {
        return !empty($this->data['failed_at']);
    }
    
    /**
     * تعيين كمقروء
     */
    public function markAsRead(): void {
        $this->data['read_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }
    
    /**
     * تحويل إلى JSON
     * @return string
     */
    public function toJson(): string {
        return json_encode($this->data);
    }
}

/**
 * Notification_Template
 * @package Notifications
 * 
 * قالب إشعار
 */
class Notification_Template {
    
    /**
     * @var array بيانات القالب
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
     * الحصول على معرف القالب
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على اسم القالب
     * @return string
     */
    public function getName(): string {
        return $this->data['name'] ?? '';
    }
    
    /**
     * الحصول على نوع الإشعار
     * @return string
     */
    public function getType(): string {
        return $this->data['type'] ?? '';
    }
    
    /**
     * الحصول على قالب العنوان
     * @return string
     */
    public function getTitleTemplate(): string {
        return $this->data['title_template'] ?? '';
    }
    
    /**
     * الحصول على قالب المحتوى
     * @return string
     */
    public function getBodyTemplate(): string {
        return $this->data['body_template'] ?? '';
    }
    
    /**
     * الحصول على القنوات
     * @return array
     */
    public function getChannels(): array {
        return json_decode($this->data['channels'] ?? '[]', true);
    }
    
    /**
     * الحصول على المتغيرات
     * @return array
     */
    public function getVariables(): array {
        return json_decode($this->data['variables'] ?? '[]', true);
    }
    
    /**
     * الحصول على اللغة
     * @return string
     */
    public function getLanguage(): string {
        return $this->data['language'] ?? 'ar';
    }
    
    /**
     * عرض القالب بالبيانات
     * @param array $data
     * @return array
     */
    public function render(array $data): array {
        $variables = $this->getVariables();
        $render_data = [];
        
        foreach ($variables as $var) {
            $render_data['{{' . $var . '}}'] = $data[$var] ?? '';
        }
        
        $title = str_replace(array_keys($render_data), array_values($render_data), $this->getTitleTemplate());
        $body = str_replace(array_keys($render_data), array_values($render_data), $this->getBodyTemplate());
        
        return [
            'title' => $title,
            'body' => $body
        ];
    }
    
    /**
     * التحقق من صحة البيانات للقالب
     * @param array $data
     * @return bool
     */
    public function validateData(array $data): bool {
        $variables = $this->getVariables();
        
        foreach ($variables as $var) {
            if (!isset($data[$var])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * الحصول على بيانات تجريبية
     * @return array
     */
    public function getSampleData(): array {
        $sample = [];
        $variables = $this->getVariables();
        
        foreach ($variables as $var) {
            $sample[$var] = '{{' . $var . '}}';
        }
        
        return $sample;
    }
    
    /**
     * معاينة القالب
     * @return array
     */
    public function preview(): array {
        return $this->render($this->getSampleData());
    }
}

/**
 * Notification_Queue
 * @package Notifications
 * 
 * طابور الإشعارات
 */
class Notification_Queue {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var array
     */
    private $jobs = [];
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
    }
    
    /**
     * إضافة إشعار للطابور
     * @param Notification $notification
     * @return string
     */
    public function push(Notification $notification): string {
        $job_id = uniqid('job_', true);
        
        $this->db->insert('notification_queue', [
            'job_id' => $job_id,
            'notification_id' => $notification->getId(),
            'notification_data' => json_encode($notification->toArray()),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $job_id;
    }
    
    /**
     * إضافة دفعة إشعارات
     * @param array $notifications
     * @return array
     */
    public function pushBatch(array $notifications): array {
        $job_ids = [];
        
        foreach ($notifications as $notif) {
            $job_ids[] = $this->push($notif);
        }
        
        return $job_ids;
    }
    
    /**
     * جلب إشعار من الطابور
     * @return Notification_Job|null
     */
    public function pop(): ?Notification_Job {
        // قفل الصف للتحديث
        $job = $this->db->fetchOne("
            SELECT * FROM notification_queue 
            WHERE status = 'pending' AND attempts < 3
            ORDER BY created_at ASC 
            LIMIT 1 
            FOR UPDATE
        ");
        
        if (!$job) {
            return null;
        }
        
        // تحديث الحالة
        $this->db->update('notification_queue', [
            'status' => 'processing',
            'started_at' => date('Y-m-d H:i:s')
        ], ['id' => $job['id']]);
        
        return new Notification_Job($job);
    }
    
    /**
     * معالجة إشعار
     * @param Notification_Job $job
     * @return bool
     */
    public function process(Notification_Job $job): bool {
        $notifications_app = Main_App::getInstance()->notifications;
        $channel_name = $job->getChannel();
        
        $channel = $notifications_app->getChannel($channel_name);
        
        if (!$channel || !$channel->isEnabled()) {
            $job->markFailed('Channel not available');
            $this->updateJob($job);
            return false;
        }
        
        $notification = new Notification($job->getNotificationData());
        $result = $channel->send($notification);
        
        if ($result->isSuccess()) {
            $job->markCompleted();
            $this->updateJob($job);
            
            // تحديث حالة الإشعار الأصلي
            $notifications_app->updateNotificationStatus(
                $notification->getId(),
                $channel_name,
                $result
            );
            
            return true;
        } else {
            $job->markFailed($result->getError());
            $this->updateJob($job);
            return false;
        }
    }
    
    /**
     * تحديث job في قاعدة البيانات
     * @param Notification_Job $job
     */
    private function updateJob(Notification_Job $job): void {
        $this->db->update('notification_queue', [
            'status' => $job->getStatus(),
            'attempts' => $job->getAttempts(),
            'error' => $job->getError(),
            'completed_at' => $job->getCompletedAt(),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $job->getId()]);
    }
    
    /**
     * معالجة طابور الإشعارات
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
            
            if ($this->process($job)) {
                $results['succeeded']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
    
    /**
     * إعادة محاولة الإشعارات الفاشلة
     * @return int
     */
    public function retryFailed(): int {
        return $this->db->update('notification_queue', [
            'status' => 'pending',
            'attempts' => 0,
            'error' => null
        ], "status = 'failed' AND attempts < 3");
    }
    
    /**
     * تنظيف الإشعارات القديمة
     * @param int $days
     * @return int
     */
    public function cleanOld(int $days = 7): int {
        return $this->db->delete('notification_queue',
            "created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN ('completed', 'failed')",
            [$days]
        );
    }
    
    /**
     * الحصول على عدد الإشعارات المعلقة
     * @return int
     */
    public function getPendingCount(): int {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM notification_queue WHERE status = 'pending'"
        );
    }
    
    /**
     * الحصول على عدد الإشعارات الفاشلة
     * @return int
     */
    public function getFailedCount(): int {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM notification_queue WHERE status = 'failed'"
        );
    }
}

/**
 * Notification_Job
 * @package Notifications
 * 
 * مهمة إشعار في الطابور
 */
class Notification_Job {
    
    /**
     * @var array بيانات المهمة
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
     * الحصول على معرف المهمة
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على معرف الوظيفة
     * @return string
     */
    public function getJobId(): string {
        return $this->data['job_id'];
    }
    
    /**
     * الحصول على معرف الإشعار
     * @return int
     */
    public function getNotificationId(): int {
        return (int)$this->data['notification_id'];
    }
    
    /**
     * الحصول على بيانات الإشعار
     * @return array
     */
    public function getNotificationData(): array {
        return json_decode($this->data['notification_data'], true);
    }
    
    /**
     * الحصول على القناة
     * @return string
     */
    public function getChannel(): string {
        $data = $this->getNotificationData();
        return $data['channel'] ?? 'database';
    }
    
    /**
     * الحصول على الحالة
     * @return string
     */
    public function getStatus(): string {
        return $this->data['status'];
    }
    
    /**
     * الحصول على عدد المحاولات
     * @return int
     */
    public function getAttempts(): int {
        return (int)$this->data['attempts'];
    }
    
    /**
     * الحصول على الخطأ
     * @return string|null
     */
    public function getError(): ?string {
        return $this->data['error'] ?? null;
    }
    
    /**
     * الحصول على وقت الإنشاء
     * @return string
     */
    public function getCreatedAt(): string {
        return $this->data['created_at'];
    }
    
    /**
     * الحصول على وقت الإكمال
     * @return string|null
     */
    public function getCompletedAt(): ?string {
        return $this->data['completed_at'] ?? null;
    }
    
    /**
     * تعيين كمكتمل
     */
    public function markCompleted(): void {
        $this->data['status'] = 'completed';
        $this->data['completed_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * تعيين كفاشل
     * @param string $error
     */
    public function markFailed(string $error): void {
        $this->data['status'] = 'failed';
        $this->data['attempts'] = ($this->data['attempts'] ?? 0) + 1;
        $this->data['error'] = $error;
    }
}

/**
 * Channel_Result
 * @package Notifications
 * 
 * نتيجة إرسال عبر قناة
 */
class Channel_Result {
    
    /**
     * @var bool
     */
    private $success;
    
    /**
     * @var string
     */
    private $message;
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * المُنشئ
     * @param bool $success
     * @param string $message
     * @param array $data
     */
    public function __construct(bool $success, string $message = '', array $data = []) {
        $this->success = $success;
        $this->message = $message;
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
     * الحصول على الرسالة
     * @return string
     */
    public function getMessage(): string {
        return $this->message;
    }
    
    /**
     * الحصول على الخطأ
     * @return string
     */
    public function getError(): string {
        return $this->message;
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
            'data' => $this->data
        ];
    }
}

/**
 * User_Notification_Settings
 * @package Notifications
 * 
 * إعدادات المستخدم للإشعارات
 */
class User_Notification_Settings {
    
    /**
     * @var array بيانات الإعدادات
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
     * الحصول على معرف المستخدم
     * @return int
     */
    public function getUserId(): int {
        return (int)$this->data['user_id'];
    }
    
    /**
     * التحقق من تفعيل قناة
     * @param string $channel
     * @return bool
     */
    public function isChannelEnabled(string $channel): bool {
        $key = $channel . '_notifications';
        return isset($this->data[$key]) && $this->data[$key];
    }
    
    /**
     * التحقق من تفعيل نوع
     * @param string $type
     * @return bool
     */
    public function isTypeEnabled(string $type): bool {
        $types = json_decode($this->data['notification_types'] ?? '[]', true);
        
        if (empty($types)) {
            return true; // الكل مسموح
        }
        
        return in_array($type, $types);
    }
    
    /**
     * التحقق من ساعات الهدوء
     * @return bool
     */
    public function isWithinQuietHours(): bool {
        $quiet_hours = json_decode($this->data['quiet_hours'] ?? '[]', true);
        
        if (empty($quiet_hours)) {
            return false;
        }
        
        $current_time = date('H:i');
        
        foreach ($quiet_hours as $range) {
            if (isset($range['start']) && isset($range['end'])) {
                if ($current_time >= $range['start'] && $current_time <= $range['end']) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * الحصول على القنوات المفضلة لنوع
     * @param string $type
     * @return array
     */
    public function getPreferredChannels(string $type): array {
        $channels = [];
        
        if ($this->isTypeEnabled($type)) {
            if ($this->isChannelEnabled('email')) {
                $channels[] = 'email';
            }
            if ($this->isChannelEnabled('sms')) {
                $channels[] = 'sms';
            }
            if ($this->isChannelEnabled('push')) {
                $channels[] = 'push';
            }
            if ($this->isChannelEnabled('database')) {
                $channels[] = 'database';
            }
        }
        
        return $channels;
    }
    
    /**
     * تحديث الإعدادات
     * @param array $settings
     * @return bool
     */
    public function update(array $settings): bool {
        $notifications = Main_App::getInstance()->notifications;
        return $notifications->updateUserSettings($this->getUserId(), $settings);
    }
}

?>