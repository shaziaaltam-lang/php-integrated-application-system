<?php
/**
 * App_Session.php
 * @version 1.0.0
 * @package Session
 * 
 * نظام إدارة الجلسات المتقدم
 * يدير جلسات المستخدمين، الفلاش ميسيجز، والتخزين المؤقت للجلسة
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__FILE__));
}

/**
 * App_Session
 * @package Session
 * 
 * الكلاس الرئيسي لإدارة الجلسات
 * يستخدم نمط Singleton لضمان وجود جلسة واحدة فقط
 */
class App_Session {
    
    // ==========================================
    // خصائص Singleton
    // ==========================================
    
    /**
     * النسخة الوحيدة من الكلاس
     * @var App_Session|null
     */
    private static $instance = null;
    
    // ==========================================
    // ثوابت الجلسة
    // ==========================================
    
    /**
     * مفاتيح التخزين في الجلسة
     */
    const SESSION_KEY_USER = 'user_data';
    const SESSION_KEY_FLASH = 'flash_messages';
    const SESSION_KEY_ACTIVITY = 'last_activity';
    const SESSION_KEY_CSRF = 'csrf_tokens';
    
    // ==========================================
    // خصائص الجلسة
    // ==========================================
    
    /**
     * معرف الجلسة الحالي
     * @var string|null
     */
    private $session_id = null;
    
    /**
     * بيانات المستخدم المخزنة في الجلسة
     * @var array
     */
    private $user_data = [];
    
    /**
     * رسائل الفلاش (تظهر مرة واحدة)
     * @var array
     */
    private $flash_messages = [];
    
    /**
     * بادئة الجلسة للتطبيقات المتعددة
     * @var string
     */
    private $session_prefix = 'app_';
    
    /**
     * وقت انتهاء الجلسة بالثواني
     * @var int
     */
    private $session_timeout = 7200; // ساعتين
    
    /**
     * آخر نشاط على الجلسة
     * @var int
     */
    private $last_activity = 0;
    
    /**
     * عنوان IP للمستخدم
     * @var string
     */
    private $ip_address = '';
    
    /**
     * وكيل المستخدم (User Agent)
     * @var string
     */
    private $user_agent = '';
    
    /**
     * حالة بدء الجلسة
     * @var bool
     */
    private $started = false;
    
    /**
     * تكوين الجلسة
     * @var array
     */
    private $config = [
        'name' => 'APPSESSID',
        'lifetime' => 7200,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
        'use_cookies' => true,
        'use_only_cookies' => true,
        'cookie_secure_auto' => true
    ];
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ - خاص لمنع الإنشاء المباشر
     */
    private function __construct() {
        $this->ip_address = $this->getClientIP();
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    /**
     * منع الاستنساخ
     */
    private function __clone() {}
    
    /**
     * منع إعادة الإنشاء
     */
    private function __wakeup() {}
    
    /**
     * الحصول على نسخة من الكلاس
     * @return App_Session
     */
    public static function getInstance(): App_Session {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // ==========================================
    // إدارة الجلسة
    // ==========================================
    
    /**
     * بدء الجلسة
     * @return bool
     */
    public function start(): bool {
        if ($this->started) {
            return true;
        }
        
        // تعيين إعدادات الجلسة
        $this->configure();
        
        // بدء الجلسة
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->started = true;
        $this->session_id = session_id();
        
        // تحميل بيانات الجلسة إلى الخصائص
        $this->loadFromSession();
        
        // التحقق من انتهاء الجلسة
        if (!$this->checkTimeout()) {
            $this->destroy();
            return false;
        }
        
        // التحقق من صحة الجلسة (IP و User Agent)
        if (!$this->validateSession()) {
            $this->destroy();
            return false;
        }
        
        // تحديث آخر نشاط
        $this->updateActivity();
        
        // تنظيف رسائل الفلاش المنتهية
        $this->cleanExpiredFlash();
        
        return true;
    }
    
    /**
     * تكوين إعدادات الجلسة
     */
    private function configure(): void {
        // تعيين اسم الجلسة
        session_name($this->config['name']);
        
        // تعيين إعدادات الكوكي
        $secure = $this->config['secure'];
        if ($this->config['cookie_secure_auto'] && isset($_SERVER['HTTPS'])) {
            $secure = ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1');
        }
        
        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['path'],
            'domain' => $this->config['domain'],
            'secure' => $secure,
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ]);
    }
    
    /**
     * تحميل البيانات من الجلسة إلى الخصائص
     */
    private function loadFromSession(): void {
        $this->user_data = $_SESSION[$this->session_prefix . self::SESSION_KEY_USER] ?? [];
        $this->flash_messages = $_SESSION[$this->session_prefix . self::SESSION_KEY_FLASH] ?? [];
        $this->last_activity = $_SESSION[$this->session_prefix . self::SESSION_KEY_ACTIVITY] ?? time();
    }
    
    /**
     * حفظ البيانات في الجلسة
     */
    private function saveToSession(): void {
        $_SESSION[$this->session_prefix . self::SESSION_KEY_USER] = $this->user_data;
        $_SESSION[$this->session_prefix . self::SESSION_KEY_FLASH] = $this->flash_messages;
        $_SESSION[$this->session_prefix . self::SESSION_KEY_ACTIVITY] = $this->last_activity;
    }
    
    /**
     * التحقق من انتهاء الجلسة
     * @return bool
     */
    private function checkTimeout(): bool {
        if (time() - $this->last_activity > $this->session_timeout) {
            return false;
        }
        return true;
    }
    
    /**
     * تحديث آخر نشاط
     */
    public function updateActivity(): void {
        $this->last_activity = time();
        $_SESSION[$this->session_prefix . self::SESSION_KEY_ACTIVITY] = $this->last_activity;
    }
    
    /**
     * التحقق من صحة الجلسة
     * @return bool
     */
    private function validateSession(): bool {
        // التحقق من IP إذا كان التكوين يطلب ذلك
        if (isset($this->config['validate_ip']) && $this->config['validate_ip']) {
            if ($this->validateIP() === false) {
                return false;
            }
        }
        
        // التحقق من User Agent إذا كان التكوين يطلب ذلك
        if (isset($this->config['validate_user_agent']) && $this->config['validate_user_agent']) {
            if ($this->validateUserAgent() === false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * التحقق من عنوان IP
     * @return bool
     */
    private function validateIP(): bool {
        $storedIP = $_SESSION[$this->session_prefix . 'ip'] ?? null;
        
        if ($storedIP === null) {
            $_SESSION[$this->session_prefix . 'ip'] = $this->ip_address;
            return true;
        }
        
        return $storedIP === $this->ip_address;
    }
    
    /**
     * التحقق من وكيل المستخدم
     * @return bool
     */
    private function validateUserAgent(): bool {
        $storedUA = $_SESSION[$this->session_prefix . 'user_agent'] ?? null;
        
        if ($storedUA === null) {
            $_SESSION[$this->session_prefix . 'user_agent'] = $this->user_agent;
            return true;
        }
        
        return $storedUA === $this->user_agent;
    }
    
    /**
     * إعادة توليد معرف الجلسة
     * @return bool
     */
    public function regenerateId(): bool {
        if (!$this->started) {
            return false;
        }
        
        session_regenerate_id(true);
        $this->session_id = session_id();
        
        return true;
    }
    
    /**
     * إتلاف الجلسة
     * @return bool
     */
    public function destroy(): bool {
        if (!$this->started) {
            return false;
        }
        
        // مسح بيانات الجلسة
        $_SESSION = [];
        
        // حذف كوكي الجلسة
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        // إتلاف الجلسة
        session_destroy();
        
        $this->started = false;
        $this->user_data = [];
        $this->flash_messages = [];
        
        return true;
    }
    
    /**
     * إغلاق الجلسة (حفظ وفتح)
     */
    public function close(): void {
        session_write_close();
        $this->started = false;
    }
    
    // ==========================================
    // إدارة بيانات المستخدم
    // ==========================================
    
    /**
     * تعيين بيانات المستخدم
     * @param mixed $key مفتاح أو مصفوفة بيانات
     * @param mixed $value القيمة (إذا كان المفتاح نصاً)
     * @return self
     */
    public function setUser($key, $value = null): self {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->user_data[$k] = $v;
            }
        } else {
            $this->user_data[$key] = $value;
        }
        
        $this->saveToSession();
        return $this;
    }
    
    /**
     * الحصول على بيانات المستخدم
     * @param string|null $key المفتاح (إذا كان null يرجع كل البيانات)
     * @param mixed $default القيمة الافتراضية
     * @return mixed
     */
    public function getUser(?string $key = null, $default = null) {
        if ($key === null) {
            return $this->user_data;
        }
        
        return $this->user_data[$key] ?? $default;
    }
    
    /**
     * الحصول على جميع بيانات المستخدم
     * @return array
     */
    public function getAllUser(): array {
        return $this->user_data;
    }
    
    /**
     * إزالة بيانات المستخدم
     * @param string $key
     * @return self
     */
    public function removeUser(string $key): self {
        unset($this->user_data[$key]);
        $this->saveToSession();
        return $this;
    }
    
    /**
     * مسح جميع بيانات المستخدم
     * @return self
     */
    public function clearUser(): self {
        $this->user_data = [];
        $this->saveToSession();
        return $this;
    }
    
    /**
     * التحقق من وجود مفتاح في بيانات المستخدم
     * @param string $key
     * @return bool
     */
    public function hasUser(string $key): bool {
        return isset($this->user_data[$key]);
    }
    
    // ==========================================
    // إدارة رسائل الفلاش
    // ==========================================
    
    /**
     * تعيين رسالة فلاش
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setFlash(string $key, $value): self {
        $this->flash_messages[$key] = [
            'value' => $value,
            'expires' => time() + 3600 // ساعة واحدة افتراضياً
        ];
        
        $this->saveToSession();
        return $this;
    }
    
    /**
     * تعيين رسالة فلاش مع وقت انتهاء مخصص
     * @param string $key
     * @param mixed $value
     * @param int $ttl وقت الحياة بالثواني
     * @return self
     */
    public function setFlashWithTTL(string $key, $value, int $ttl): self {
        $this->flash_messages[$key] = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        $this->saveToSession();
        return $this;
    }
    
    /**
     * الحصول على رسالة فلاش
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getFlash(string $key, $default = null) {
        if (!isset($this->flash_messages[$key])) {
            return $default;
        }
        
        $flash = $this->flash_messages[$key];
        
        // التحقق من الصلاحية
        if ($flash['expires'] < time()) {
            unset($this->flash_messages[$key]);
            $this->saveToSession();
            return $default;
        }
        
        $value = $flash['value'];
        
        // حذف بعد القراءة
        unset($this->flash_messages[$key]);
        $this->saveToSession();
        
        return $value;
    }
    
    /**
     * الحصول على رسالة فلاش مع الاحتفاظ بها
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function peekFlash(string $key, $default = null) {
        if (!isset($this->flash_messages[$key])) {
            return $default;
        }
        
        $flash = $this->flash_messages[$key];
        
        if ($flash['expires'] < time()) {
            unset($this->flash_messages[$key]);
            $this->saveToSession();
            return $default;
        }
        
        return $flash['value'];
    }
    
    /**
     * التحقق من وجود رسالة فلاش
     * @param string $key
     * @return bool
     */
    public function hasFlash(string $key): bool {
        if (!isset($this->flash_messages[$key])) {
            return false;
        }
        
        $flash = $this->flash_messages[$key];
        
        if ($flash['expires'] < time()) {
            unset($this->flash_messages[$key]);
            $this->saveToSession();
            return false;
        }
        
        return true;
    }
    
    /**
     * مسح جميع رسائل الفلاش
     * @return self
     */
    public function clearFlash(): self {
        $this->flash_messages = [];
        $this->saveToSession();
        return $this;
    }
    
    /**
     * تنظيف رسائل الفلاش المنتهية
     */
    private function cleanExpiredFlash(): void {
        $now = time();
        $changed = false;
        
        foreach ($this->flash_messages as $key => $flash) {
            if ($flash['expires'] < $now) {
                unset($this->flash_messages[$key]);
                $changed = true;
            }
        }
        
        if ($changed) {
            $this->saveToSession();
        }
    }
    
    /**
     * الحصول على جميع رسائل الفلاش
     * @return array
     */
    public function getAllFlash(): array {
        $this->cleanExpiredFlash();
        return array_map(function($flash) {
            return $flash['value'];
        }, $this->flash_messages);
    }
    
    // ==========================================
    // إدارة CSRF Tokens
    // ==========================================
    
    /**
     * إنشاء توكن CSRF
     * @param string $action
     * @return string
     */
    public function generateCSRFToken(string $action = 'default'): string {
        $token = bin2hex(random_bytes(32));
        
        if (!isset($_SESSION[self::SESSION_KEY_CSRF])) {
            $_SESSION[self::SESSION_KEY_CSRF] = [];
        }
        
        $_SESSION[self::SESSION_KEY_CSRF][$action] = [
            'token' => $token,
            'expires' => time() + 3600
        ];
        
        return $token;
    }
    
    /**
     * التحقق من توكن CSRF
     * @param string $token
     * @param string $action
     * @param bool $removeAfter حذف التوكن بعد التحقق
     * @return bool
     */
    public function validateCSRFToken(string $token, string $action = 'default', bool $removeAfter = true): bool {
        if (!isset($_SESSION[self::SESSION_KEY_CSRF][$action])) {
            return false;
        }
        
        $stored = $_SESSION[self::SESSION_KEY_CSRF][$action];
        
        if ($stored['expires'] < time()) {
            unset($_SESSION[self::SESSION_KEY_CSRF][$action]);
            return false;
        }
        
        $valid = hash_equals($stored['token'], $token);
        
        if ($removeAfter) {
            unset($_SESSION[self::SESSION_KEY_CSRF][$action]);
        }
        
        return $valid;
    }
    
    // ==========================================
    // إعدادات الجلسة
    // ==========================================
    
    /**
     * تعيين وقت انتهاء الجلسة
     * @param int $seconds
     * @return self
     */
    public function setTimeout(int $seconds): self {
        $this->session_timeout = $seconds;
        return $this;
    }
    
    /**
     * تعيين بادئة الجلسة
     * @param string $prefix
     * @return self
     */
    public function setPrefix(string $prefix): self {
        $this->session_prefix = $prefix;
        return $this;
    }
    
    /**
     * تعيين إعدادات الكوكي
     * @param array $params
     * @return self
     */
    public function setCookieParams(array $params): self {
        $this->config = array_merge($this->config, $params);
        
        if ($this->started) {
            $this->configure();
        }
        
        return $this;
    }
    
    /**
     * تعيين اسم الجلسة
     * @param string $name
     * @return self
     */
    public function setName(string $name): self {
        $this->config['name'] = $name;
        
        if ($this->started) {
            session_name($name);
        }
        
        return $this;
    }
    
    /**
     * تعيين وقت حياة الجلسة
     * @param int $lifetime
     * @return self
     */
    public function setLifetime(int $lifetime): self {
        $this->config['lifetime'] = $lifetime;
        return $this;
    }
    
    // ==========================================
    // دوال التشفير
    // ==========================================
    
    /**
     * تشفير بيانات الجلسة
     * @param mixed $data
     * @return string
     */
    public function encryptData($data): string {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt(
            serialize($data),
            'AES-256-CBC',
            $key,
            0,
            $iv
        );
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * فك تشفير بيانات الجلسة
     * @param string $data
     * @return mixed
     */
    public function decryptData(string $data) {
        $key = $this->getEncryptionKey();
        $data = base64_decode($data);
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );
        
        return unserialize($decrypted);
    }
    
    /**
     * الحصول على مفتاح التشفير
     * @return string
     */
    private function getEncryptionKey(): string {
        // يمكن جلب المفتاح من ملف الإعدادات
        $key = defined('SESSION_ENCRYPTION_KEY') ? SESSION_ENCRYPTION_KEY : 'default-key-change-this';
        return hash('sha256', $key, true);
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * الحصول على معرف الجلسة الحالي
     * @return string|null
     */
    public function getId(): ?string {
        return $this->session_id;
    }
    
    /**
     * الحصول على عنوان IP للعميل
     * @return string
     */
    private function getClientIP(): string {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * الحصول على معلومات الجلسة
     * @return array
     */
    public function getInfo(): array {
        return [
            'id' => $this->session_id,
            'started' => $this->started,
            'last_activity' => $this->last_activity,
            'timeout' => $this->session_timeout,
            'ip' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'user_data_keys' => array_keys($this->user_data),
            'flash_count' => count($this->flash_messages)
        ];
    }
    
    /**
     * الحصول على قيمة من الجلسة مباشرة
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $_SESSION[$this->session_prefix . $key] ?? $default;
    }
    
    /**
     * تعيين قيمة في الجلسة مباشرة
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function set(string $key, $value): self {
        $_SESSION[$this->session_prefix . $key] = $value;
        return $this;
    }
    
    /**
     * إزالة قيمة من الجلسة
     * @param string $key
     * @return self
     */
    public function remove(string $key): self {
        unset($_SESSION[$this->session_prefix . $key]);
        return $this;
    }
    
    /**
     * التحقق من وجود قيمة في الجلسة
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($_SESSION[$this->session_prefix . $key]);
    }
    
    /**
     * مسح جميع بيانات الجلسة (مع الاحتفاظ بالجلسة)
     * @return self
     */
    public function clear(): self {
        $prefix = $this->session_prefix;
        
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                unset($_SESSION[$key]);
            }
        }
        
        $this->user_data = [];
        $this->flash_messages = [];
        
        return $this;
    }
}

/**
 * Session_Config
 * @package Session
 * 
 * إدارة تكوين الجلسة
 */
class Session_Config {
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * الحصول على الإعدادات الافتراضية
     * @return array
     */
    private function getDefaultConfig(): array {
        return [
            'name' => 'APPSESSID',
            'lifetime' => 7200,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
            'use_cookies' => true,
            'use_only_cookies' => true,
            'cookie_secure_auto' => true,
            'validate_ip' => false,
            'validate_user_agent' => false,
            'encrypt_data' => false,
            'gc_probability' => 1,
            'gc_divisor' => 100,
            'gc_maxlifetime' => 7200
        ];
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
     * تعيين قيمة إعداد
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function set(string $key, $value): self {
        $this->config[$key] = $value;
        return $this;
    }
    
    /**
     * الحصول على جميع الإعدادات
     * @return array
     */
    public function getAll(): array {
        return $this->config;
    }
    
    /**
     * التحقق من صحة الإعدادات
     * @return bool
     */
    public function validate(): bool {
        // التحقق من اسم الجلسة
        if (empty($this->config['name']) || strlen($this->config['name']) < 3) {
            return false;
        }
        
        // التحقق من وقت الحياة
        if (!is_numeric($this->config['lifetime']) || $this->config['lifetime'] < 0) {
            return false;
        }
        
        // التحقق من SameSite
        if (!in_array($this->config['samesite'], ['None', 'Lax', 'Strict'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * تطبيق الإعدادات على الجلسة الحالية
     * @return bool
     */
    public function apply(): bool {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return false;
        }
        
        session_name($this->config['name']);
        
        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['path'],
            'domain' => $this->config['domain'],
            'secure' => $this->config['secure'],
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ]);
        
        ini_set('session.use_cookies', $this->config['use_cookies'] ? '1' : '0');
        ini_set('session.use_only_cookies', $this->config['use_only_cookies'] ? '1' : '0');
        ini_set('session.gc_probability', (string)$this->config['gc_probability']);
        ini_set('session.gc_divisor', (string)$this->config['gc_divisor']);
        ini_set('session.gc_maxlifetime', (string)$this->config['gc_maxlifetime']);
        
        return true;
    }
}

/**
 * Session_Handler
 * @package Session
 * 
 * معالج مخصص للجلسات (يمكن توسيعه لاستخدام Redis أو قاعدة بيانات)
 */
class Session_Handler implements SessionHandlerInterface {
    
    /**
     * @var string
     */
    private $savePath;
    
    /**
     * @var int
     */
    private $gcProbability;
    
    /**
     * @var int
     */
    private $gcDivisor;
    
    /**
     * المُنشئ
     * @param string $savePath
     */
    public function __construct(string $savePath = '') {
        $this->savePath = $savePath ?: session_save_path();
        $this->gcProbability = (int)ini_get('session.gc_probability');
        $this->gcDivisor = (int)ini_get('session.gc_divisor');
    }
    
    /**
     * فتح الجلسة
     * @param string $savePath
     * @param string $sessionName
     * @return bool
     */
    public function open($savePath, $sessionName): bool {
        $this->savePath = $savePath ?: $this->savePath;
        
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0755, true);
        }
        
        return true;
    }
    
    /**
     * إغلاق الجلسة
     * @return bool
     */
    public function close(): bool {
        return true;
    }
    
    /**
     * قراءة بيانات الجلسة
     * @param string $id
     * @return string
     */
    public function read($id): string {
        $file = $this->getSessionFile($id);
        
        if (file_exists($file)) {
            return (string)file_get_contents($file);
        }
        
        return '';
    }
    
    /**
     * كتابة بيانات الجلسة
     * @param string $id
     * @param string $data
     * @return bool
     */
    public function write($id, $data): bool {
        $file = $this->getSessionFile($id);
        
        return file_put_contents($file, $data) !== false;
    }
    
    /**
     * إتلاف الجلسة
     * @param string $id
     * @return bool
     */
    public function destroy($id): bool {
        $file = $this->getSessionFile($id);
        
        if (file_exists($file)) {
            unlink($file);
        }
        
        return true;
    }
    
    /**
     * تنظيف الجلسات القديمة
     * @param int $maxlifetime
     * @return bool
     */
    public function gc($maxlifetime): bool {
        $files = glob($this->savePath . '/sess_*');
        $now = time();
        
        foreach ($files as $file) {
            if (filemtime($file) + $maxlifetime < $now) {
                unlink($file);
            }
        }
        
        return true;
    }
    
    /**
     * الحصول على مسار ملف الجلسة
     * @param string $id
     * @return string
     */
    private function getSessionFile(string $id): string {
        return $this->savePath . '/sess_' . $id;
    }
}

?>