<?php
/**
 * main_app.php
 * @version 1.0.0
 * @package Core
 * 
 * الملف الرئيسي للتطبيق - القلب النابض
 * ملاحظة: هذا الملف لا يمكن الوصول إليه مباشرة، فقط عبر api_action.php
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * Main_App
 * @package Core
 * 
 * الكلاس الرئيسي الذي يحتوي على جميع خدمات التطبيق
 * يستخدم نمط Singleton لضمان وجود نسخة واحدة فقط
 */
class Main_App {
    
    // ==========================================
    // خصائص Singleton
    // ==========================================
    
    /**
     * النسخة الوحيدة من الكلاس
     * @var Main_App|null
     */
    private static $instance = null;
    
    /**
     * حالة التهيئة
     * @var bool
     */
    private $initialized = false;
    
    /**
     * وقت بدء التطبيق
     * @var float
     */
    private $start_time = 0;
    
    // ==========================================
    // خصائص الخدمات (Composition)
    // ==========================================
    
    /**
     * @var App_Session|null إدارة الجلسات
     */
    private $session = null;
    
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
     * @var صلاحيات_App|null نظام الصلاحيات
     */
    private $permissions = null;
    
    /**
     * @var Auth_App|null نظام المصادقة
     */
    private $auth = null;
    
    /**
     * @var CRUD_App|null نظام CRUD العام
     */
    private $crud = null;
    
    /**
     * @var الإشعارات_App|null نظام الإشعارات
     */
    private $notifications = null;
    
    /**
     * @var لوحة_تحكم_المستخدم_App|null لوحة تحكم المستخدم
     */
    private $dashboard = null;
    
    /**
     * @var Router|null نظام التوجيه الداخلي
     */
    private $router = null;
    
    /**
     * @var Config_Manager|null إدارة الإعدادات
     */
    private $config = null;
    
    /**
     * @var Cache_Manager|null نظام التخزين المؤقت
     */
    private $cache = null;
    
    /**
     * @var Logger|null نظام التسجيل
     */
    private $logger = null;
    
    /**
     * @var Event_Manager|null نظام الأحداث
     */
    private $events = null;
    
    /**
     * @var array قائمة الخدمات المسجلة
     */
    private $services = [];
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ - خاص لمنع الإنشاء المباشر
     */
    private function __construct() {
        $this->start_time = microtime(true);
        $this->loadConstants();
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
     * الحصول على النسخة الوحيدة
     * @return Main_App
     */
    public static function getInstance(): Main_App {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * تهيئة التطبيق وجميع خدماته
     * @throws Exception
     * @return bool
     */
    public function initialize(): bool {
        if ($this->initialized) {
            return true;
        }
        
        try {
            $this->log('Starting application initialization', 'info');
            
            // ترتيب التهيئة مهم جداً
            $this->initializeConfig();
            $this->initializeLogger();
            $this->initializeDatabase();
            $this->initializeCache();
            $this->initializeSession();
            $this->initializeEvents();
            $this->initializePermissions();
            $this->initializeAuth();
            $this->initializeEmail();
            $this->initializeSMS();
            $this->initializeNotifications();
            $this->initializeCRUD();
            $this->initializeDashboard();
            $this->initializeRouter();
            
            $this->initialized = true;
            
            // إطلاق حدث التهيئة
            $this->dispatchEvent('app.initialized', [
                'time' => microtime(true) - $this->start_time
            ]);
            
            $this->log('Application initialized successfully', 'info');
            
            return true;
            
        } catch (Exception $e) {
            $this->log('Initialization failed: ' . $e->getMessage(), 'error');
            throw new Exception('Failed to initialize application: ' . $e->getMessage());
        }
    }
    
    /**
     * تحميل الثوابت العامة
     */
    private function loadConstants(): void {
        if (!defined('APP_VERSION')) {
            define('APP_VERSION', '1.0.0');
        }
        
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'My Application');
        }
        
        if (!defined('TIMEZONE')) {
            define('TIMEZONE', 'UTC');
        }
        
        if (!defined('SESSION_LIFETIME')) {
            define('SESSION_LIFETIME', 7200); // ساعتين
        }
        
        // تعيين المنطقة الزمنية
        date_default_timezone_set(TIMEZONE);
    }
    
    // ==========================================
    // تهيئة الخدمات
    // ==========================================
    
    /**
     * تهيئة إدارة الإعدادات
     */
    private function initializeConfig(): void {
        $this->log('Initializing Config Manager...', 'debug');
        $this->config = Config_Manager::getInstance();
        $this->registerService('config', $this->config);
    }
    
    /**
     * تهيئة نظام التسجيل
     */
    private function initializeLogger(): void {
        $this->log('Initializing Logger...', 'debug');
        $this->logger = new Logger('app');
        $this->registerService('logger', $this->logger);
    }
    
    /**
     * تهيئة قاعدة البيانات
     * @throws Exception
     */
    private function initializeDatabase(): void {
        $this->log('Initializing Database...', 'debug');
        
        $db_config = [
            'host' => $this->config->get('db.host', 'localhost'),
            'database' => $this->config->get('db.name', 'app_db'),
            'username' => $this->config->get('db.user', 'root'),
            'password' => $this->config->get('db.password', ''),
            'charset' => $this->config->get('db.charset', 'utf8mb4'),
            'port' => $this->config->get('db.port', 3306)
        ];
        
        $this->db = App_DB::getInstance($db_config);
        
        // اختبار الاتصال
        if (!$this->db->ping()) {
            throw new Exception('Database connection failed');
        }
        
        $this->registerService('db', $this->db);
    }
    
    /**
     * تهيئة نظام التخزين المؤقت
     */
    private function initializeCache(): void {
        $this->log('Initializing Cache Manager...', 'debug');
        
        $cache_config = [
            'driver' => $this->config->get('cache.driver', 'file'),
            'path' => $this->config->get('cache.path', ROOT_PATH . '/cache'),
            'prefix' => $this->config->get('cache.prefix', 'app_')
        ];
        
        $this->cache = new Cache_Manager($cache_config);
        $this->registerService('cache', $this->cache);
    }
    
    /**
     * تهيئة نظام الجلسات
     */
    private function initializeSession(): void {
        $this->log('Initializing Session...', 'debug');
        
        $this->session = App_Session::getInstance();
        $this->session->setLifetime(SESSION_LIFETIME);
        $this->session->start();
        
        $this->registerService('session', $this->session);
    }
    
    /**
     * تهيئة نظام الأحداث
     */
    private function initializeEvents(): void {
        $this->log('Initializing Event Manager...', 'debug');
        
        $this->events = new Event_Manager();
        $this->registerService('events', $this->events);
        
        // تسجيل أحداث افتراضية
        $this->registerDefaultEvents();
    }
    
    /**
     * تسجيل الأحداث الافتراضية
     */
    private function registerDefaultEvents(): void {
        $this->events->listen('user.login', function($data) {
            $this->logger->info("User logged in: " . $data['user_id']);
        });
        
        $this->events->listen('user.register', function($data) {
            $this->logger->info("New user registered: " . $data['user_id']);
        });
        
        $this->events->listen('notification.sent', function($data) {
            $this->logger->debug("Notification sent: " . $data['notification_id']);
        });
    }
    
    /**
     * تهيئة نظام الصلاحيات
     */
    private function initializePermissions(): void {
        $this->log('Initializing Permissions System...', 'debug');
        
        $this->permissions = new صلاحيات_App($this->db);
        $this->registerService('permissions', $this->permissions);
    }
    
    /**
     * تهيئة نظام المصادقة
     */
    private function initializeAuth(): void {
        $this->log('Initializing Authentication...', 'debug');
        
        $this->auth = new Auth_App(
            $this->session,
            $this->db,
            $this->permissions
        );
        
        $this->registerService('auth', $this->auth);
    }
    
    /**
     * تهيئة نظام البريد الإلكتروني
     */
    private function initializeEmail(): void {
        $this->log('Initializing Email Service...', 'debug');
        
        $email_config = [
            'driver' => $this->config->get('email.driver', 'smtp'),
            'host' => $this->config->get('email.host', 'smtp.gmail.com'),
            'port' => $this->config->get('email.port', 587),
            'username' => $this->config->get('email.username', ''),
            'password' => $this->config->get('email.password', ''),
            'encryption' => $this->config->get('email.encryption', 'tls'),
            'from_address' => $this->config->get('email.from', 'noreply@example.com'),
            'from_name' => $this->config->get('email.from_name', APP_NAME)
        ];
        
        $this->email = new Email_App($email_config);
        $this->registerService('email', $this->email);
    }
    
    /**
     * تهيئة نظام الرسائل النصية
     */
    private function initializeSMS(): void {
        $this->log('Initializing SMS Service...', 'debug');
        
        $sms_config = [
            'provider' => $this->config->get('sms.provider', 'twilio'),
            'api_key' => $this->config->get('sms.api_key', ''),
            'api_secret' => $this->config->get('sms.api_secret', ''),
            'from' => $this->config->get('sms.from', '')
        ];
        
        $this->sms = new SMS_App($sms_config);
        $this->registerService('sms', $this->sms);
    }
    
    /**
     * تهيئة نظام الإشعارات
     */
    private function initializeNotifications(): void {
        $this->log('Initializing Notifications System...', 'debug');
        
        $this->notifications = new الإشعارات_App(
            $this->db,
            $this->email,
            $this->sms
        );
        
        $this->registerService('notifications', $this->notifications);
        
        // تسجيل قنوات الإشعارات
        $this->notifications->registerChannel('database', new Database_Channel($this->db));
        $this->notifications->registerChannel('email', new Email_Channel($this->email));
        $this->notifications->registerChannel('sms', new SMS_Channel($this->sms));
    }
    
    /**
     * تهيئة نظام CRUD
     */
    private function initializeCRUD(): void {
        $this->log('Initializing CRUD System...', 'debug');
        
        $this->crud = new CRUD_App($this->db);
        $this->registerService('crud', $this->crud);
    }
    
    /**
     * تهيئة لوحة تحكم المستخدم
     */
    private function initializeDashboard(): void {
        $this->log('Initializing User Dashboard...', 'debug');
        
        $this->dashboard = new لوحة_تحكم_المستخدم_App(
            $this->auth,
            $this->crud,
            $this->notifications
        );
        
        $this->registerService('dashboard', $this->dashboard);
    }
    
    /**
     * تهيئة نظام التوجيه الداخلي
     */
    private function initializeRouter(): void {
        $this->log('Initializing Router...', 'debug');
        
        $this->router = new Router();
        $this->registerDefaultRoutes();
        $this->registerService('router', $this->router);
    }
    
    /**
     * تسجيل المسارات الافتراضية
     */
    private function registerDefaultRoutes(): void {
        // مسارات API الداخلية
        $this->router->get('/health', function() {
            return ['status' => 'ok', 'time' => microtime(true) - $this->start_time];
        });
        
        $this->router->get('/stats', function() {
            return $this->getStatistics();
        });
    }
    
    /**
     * تسجيل خدمة
     * @param string $name اسم الخدمة
     * @param object $service كائن الخدمة
     */
    private function registerService(string $name, object $service): void {
        $this->services[$name] = $service;
    }
    
    // ==========================================
    // الوصول إلى الخدمات (Magic Methods)
    // ==========================================
    
    /**
     * الوصول إلى الخدمات كخصائص
     * @param string $name اسم الخدمة
     * @return mixed
     */
    public function __get(string $name) {
        if (!$this->initialized && $name !== 'config' && $name !== 'logger') {
            $this->initialize();
        }
        
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }
        
        // محاولة إرجاع الخاصية مباشرة
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        
        return null;
    }
    
    /**
     * استدعاء دوال الخدمات
     * @param string $name اسم الدالة
     * @param array $arguments المعاملات
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        if (!$this->initialized) {
            $this->initialize();
        }
        
        // البحث في الخدمات المسجلة
        foreach ($this->services as $service) {
            if (method_exists($service, $name)) {
                return $service->$name(...$arguments);
            }
        }
        
        throw new Exception("Method $name not found in any service");
    }
    
    // ==========================================
    // دوال عامة
    // ==========================================
    
    /**
     * التحقق من حالة التهيئة
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }
    
    /**
     * الحصول على إعداد
     * @param string $key مفتاح الإعداد
     * @param mixed $default القيمة الافتراضية
     * @return mixed
     */
    public function config(string $key, $default = null) {
        return $this->config->get($key, $default);
    }
    
    /**
     * تسجيل حدث
     * @param string $level المستوى
     * @param string $message الرسالة
     * @param array $context السياق
     */
    public function log(string $message, string $level = 'info', array $context = []): void {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        } else {
            // تسجيل احتياطي
            error_log("[$level] $message");
        }
    }
    
    /**
     * إطلاق حدث
     * @param string $event اسم الحدث
     * @param mixed $payload البيانات
     * @return mixed
     */
    public function dispatchEvent(string $event, $payload = null) {
        if ($this->events) {
            return $this->events->dispatch($event, $payload);
        }
        return null;
    }
    
    /**
     * الاستماع لحدث
     * @param string $event اسم الحدث
     * @param callable $listener الدالة المستمعة
     */
    public function listen(string $event, callable $listener): void {
        if ($this->events) {
            $this->events->listen($event, $listener);
        }
    }
    
    /**
     * تشغيل التطبيق (للطلبات الداخلية)
     * @param string|null $uri المسار
     * @return mixed
     */
    public function run(?string $uri = null) {
        if (!$this->initialized) {
            $this->initialize();
        }
        
        try {
            $uri = $uri ?? $_SERVER['REQUEST_URI'] ?? '/';
            return $this->router->dispatch($uri);
        } catch (Exception $e) {
            $this->log('Error in run: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * معالجة طلب داخلي
     * @param string $method طريقة HTTP
     * @param string $uri المسار
     * @param array $parameters المعاملات
     * @return mixed
     */
    public function handleInternalRequest(string $method, string $uri, array $parameters = []) {
        $request = new Internal_Request($method, $uri, $parameters);
        
        if ($this->auth->check()) {
            $request->setUser($this->auth->user());
        }
        
        return $this->router->dispatchRequest($request);
    }
    
    /**
     * عرض قالب
     * @param string $view اسم القالب
     * @param array $data البيانات
     * @return string
     */
    public function renderView(string $view, array $data = []): string {
        // يمكن إضافة نظام قوالب لاحقاً
        return "View: $view - " . json_encode($data);
    }
    
    /**
     * الحصول على إحصائيات التطبيق
     * @return array
     */
    public function getStatistics(): array {
        return [
            'version' => APP_VERSION,
            'uptime' => microtime(true) - $this->start_time,
            'initialized' => $this->initialized,
            'services' => array_keys($this->services),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => time()
        ];
    }
    
    /**
     * تنظيف وإغلاق التطبيق
     */
    public function shutdown(): void {
        $this->log('Shutting down application...', 'info');
        
        // إطلاق حدث الإغلاق
        $this->dispatchEvent('app.shutdown');
        
        // تنظيف الجلسة إذا لزم الأمر
        if ($this->session) {
            $this->session->close();
        }
        
        // إغلاق اتصال قاعدة البيانات
        if ($this->db) {
            $this->db->disconnect();
        }
    }
}

/**
 * Config_Manager
 * @package Core
 * إدارة إعدادات التطبيق
 */
class Config_Manager {
    
    private static $instance = null;
    private $items = [];
    private $loaded = [];
    
    private function __construct() {
        $this->loadDefaultConfig();
    }
    
    public static function getInstance(): Config_Manager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * تحميل الإعدادات الافتراضية
     */
    private function loadDefaultConfig(): void {
        $this->items = [
            'app' => [
                'name' => APP_NAME,
                'version' => APP_VERSION,
                'env' => 'production',
                'debug' => false,
                'timezone' => TIMEZONE
            ],
            'db' => [
                'host' => 'localhost',
                'name' => 'app_db',
                'user' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'port' => 3306
            ],
            'cache' => [
                'driver' => 'file',
                'path' => ROOT_PATH . '/cache',
                'prefix' => 'app_',
                'ttl' => 3600
            ],
            'email' => [
                'driver' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'from' => 'noreply@example.com',
                'from_name' => APP_NAME
            ],
            'sms' => [
                'provider' => 'twilio',
                'from' => ''
            ],
            'session' => [
                'lifetime' => SESSION_LIFETIME,
                'secure' => false,
                'httponly' => true
            ]
        ];
    }
    
    /**
     * تحميل إعدادات من ملف
     * @param string $file
     */
    public function load(string $file): void {
        if (file_exists($file)) {
            $config = require $file;
            if (is_array($config)) {
                $this->items = array_merge($this->items, $config);
                $this->loaded[] = $file;
            }
        }
    }
    
    /**
     * الحصول على إعداد
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->items;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    /**
     * تعيين إعداد
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, $value): void {
        $keys = explode('.', $key);
        $config = &$this->items;
        
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (!isset($config[$key]) || !is_array($config[$key])) {
                $config[$key] = [];
            }
            $config = &$config[$key];
        }
        
        $config[array_shift($keys)] = $value;
    }
    
    /**
     * التحقق من وجود إعداد
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    /**
     * الحصول على جميع الإعدادات
     * @return array
     */
    public function all(): array {
        return $this->items;
    }
}

/**
 * Cache_Manager
 * @package Core
 * نظام التخزين المؤقت
 */
class Cache_Manager {
    
    private $driver;
    private $config;
    private $prefix;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'cache_';
        $this->initializeDriver();
    }
    
    /**
     * تهيئة driver التخزين
     */
    private function initializeDriver(): void {
        switch ($this->config['driver']) {
            case 'file':
                $this->driver = new FileCacheDriver($this->config['path']);
                break;
            case 'redis':
                // سيتم إضافته لاحقاً
                $this->driver = new FileCacheDriver($this->config['path']);
                break;
            default:
                $this->driver = new FileCacheDriver($this->config['path']);
        }
    }
    
    /**
     * الحصول من الذاكرة المؤقتة
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $value = $this->driver->get($this->prefix . $key);
        return $value !== null ? $value : $default;
    }
    
    /**
     * تخزين في الذاكرة المؤقتة
     * @param string $key
     * @param mixed $value
     * @param int $ttl وقت الحياة بالثواني
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 3600): bool {
        return $this->driver->set($this->prefix . $key, $value, $ttl);
    }
    
    /**
     * التحقق من وجود مفتاح
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return $this->driver->has($this->prefix . $key);
    }
    
    /**
     * حذف من الذاكرة المؤقتة
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool {
        return $this->driver->delete($this->prefix . $key);
    }
    
    /**
     * تذكر قيمة (get or set)
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $callback) {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * مسح جميع الذاكرة المؤقتة
     * @return bool
     */
    public function flush(): bool {
        return $this->driver->clear();
    }
}

/**
 * FileCacheDriver
 * @package Core
 * تخزين مؤقت باستخدام الملفات
 */
class FileCacheDriver {
    
    private $path;
    
    public function __construct(string $path) {
        $this->path = $path;
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }
    
    private function getFilename(string $key): string {
        return $this->path . '/' . md5($key) . '.cache';
    }
    
    public function get(string $key) {
        $file = $this->getFilename($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        $data = unserialize($content);
        
        // التحقق من الصلاحية
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set(string $key, $value, int $ttl): bool {
        $file = $this->getFilename($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    public function delete(string $key): bool {
        $file = $this->getFilename($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }
    
    public function clear(): bool {
        $files = glob($this->path . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
}

/**
 * Event_Manager
 * @package Core
 * نظام الأحداث
 */
class Event_Manager {
    
    private $listeners = [];
    
    /**
     * الاستماع لحدث
     * @param string $event
     * @param callable $listener
     * @param int $priority
     */
    public function listen(string $event, callable $listener, int $priority = 0): void {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        
        $this->listeners[$event][$priority][] = $listener;
        ksort($this->listeners[$event]);
    }
    
    /**
     * إطلاق حدث
     * @param string $event
     * @param mixed $payload
     * @return array
     */
    public function dispatch(string $event, $payload = null): array {
        $results = [];
        
        if (!isset($this->listeners[$event])) {
            return $results;
        }
        
        foreach ($this->listeners[$event] as $priority => $listeners) {
            foreach ($listeners as $listener) {
                $results[] = $listener($payload);
            }
        }
        
        return $results;
    }
    
    /**
     * التحقق من وجود مستمعين لحدث
     * @param string $event
     * @return bool
     */
    public function hasListeners(string $event): bool {
        return isset($this->listeners[$event]) && !empty($this->listeners[$event]);
    }
    
    /**
     * إزالة جميع المستمعين لحدث
     * @param string|null $event
     */
    public function removeAllListeners(?string $event = null): void {
        if ($event === null) {
            $this->listeners = [];
        } elseif (isset($this->listeners[$event])) {
            unset($this->listeners[$event]);
        }
    }
}

/**
 * Internal_Request
 * @package Core
 * طلب داخلي بين مكونات التطبيق
 */
class Internal_Request {
    
    private $method;
    private $uri;
    private $parameters;
    private $user;
    private $headers = [];
    
    public function __construct(string $method, string $uri, array $parameters = []) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->parameters = $parameters;
    }
    
    public function getMethod(): string {
        return $this->method;
    }
    
    public function getUri(): string {
        return $this->uri;
    }
    
    public function getParameters(): array {
        return $this->parameters;
    }
    
    public function getParameter(string $key, $default = null) {
        return $this->parameters[$key] ?? $default;
    }
    
    public function setUser($user): void {
        $this->user = $user;
    }
    
    public function getUser() {
        return $this->user;
    }
    
    public function setHeader(string $key, string $value): void {
        $this->headers[$key] = $value;
    }
    
    public function getHeader(string $key, $default = null) {
        return $this->headers[$key] ?? $default;
    }
}

/**
 * Router
 * @package Core
 * نظام التوجيه الداخلي
 */
class Router {
    
    private $routes = [];
    
    public function get(string $uri, $action): void {
        $this->addRoute('GET', $uri, $action);
    }
    
    public function post(string $uri, $action): void {
        $this->addRoute('POST', $uri, $action);
    }
    
    public function put(string $uri, $action): void {
        $this->addRoute('PUT', $uri, $action);
    }
    
    public function delete(string $uri, $action): void {
        $this->addRoute('DELETE', $uri, $action);
    }
    
    private function addRoute(string $method, string $uri, $action): void {
        $this->routes[$method][$uri] = $action;
    }
    
    public function dispatch(string $uri): mixed {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return $this->dispatchRequest($method, $uri);
    }
    
    public function dispatchRequest($method, $uri = null): mixed {
        if ($uri instanceof Internal_Request) {
            $request = $uri;
            $method = $request->getMethod();
            $uri = $request->getUri();
        } else {
            $request = null;
        }
        
        if (!isset($this->routes[$method])) {
            throw new Exception("Method $method not allowed");
        }
        
        foreach ($this->routes[$method] as $route => $action) {
            if ($this->matchRoute($route, $uri, $params)) {
                if (is_callable($action)) {
                    return $action($params, $request);
                }
                return $action;
            }
        }
        
        throw new Exception("Route $uri not found");
    }
    
    private function matchRoute(string $route, string $uri, &$params = []): bool {
        // تحويل مسار إلى regex
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $route);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $uri, $matches)) {
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return true;
        }
        
        return false;
    }
}

/**
 * Logger
 * @package Core
 * نظام التسجيل
 */
class Logger {
    
    private $channel;
    private $log_file;
    private $levels = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];
    
    public function __construct(string $channel) {
        $this->channel = $channel;
        $log_dir = ROOT_PATH . DS . 'logs';
        
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $this->log_file = $log_dir . DS . $channel . '_' . date('Y-m-d') . '.log';
    }
    
    public function log(string $level, string $message, array $context = []): void {
        if (!isset($this->levels[$level])) {
            $level = 'info';
        }
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'channel' => $this->channel,
            'level' => $level,
            'message' => $this->interpolate($message, $context),
            'context' => $context,
            'pid' => getmypid(),
            'memory' => memory_get_usage(true)
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($this->log_file, $line, FILE_APPEND | LOCK_EX);
        
        // في وضع التصحيح، اطبع على الشاشة
        $app = Main_App::getInstance();
        if ($app->config('app.debug', false)) {
            error_log("[$level] " . $entry['message']);
        }
    }
    
    /**
     * استبدال المتغيرات في الرسالة
     */
    private function interpolate(string $message, array $context = []): string {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val)) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        
        return strtr($message, $replace);
    }
    
    public function emergency(string $message, array $context = []): void {
        $this->log('emergency', $message, $context);
    }
    
    public function alert(string $message, array $context = []): void {
        $this->log('alert', $message, $context);
    }
    
    public function critical(string $message, array $context = []): void {
        $this->log('critical', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
    
    public function notice(string $message, array $context = []): void {
        $this->log('notice', $message, $context);
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }
}

/**
 * User (فئة مبسطة)
 * @package Core
 */
class User {
    
    private $id;
    private $data;
    
    public function __construct(array $data) {
        $this->id = $data['id'] ?? null;
        $this->data = $data;
    }
    
    public function getId(): ?int {
        return $this->id;
    }
    
    public function toArray(): array {
        return $this->data;
    }
    
    public function hasRole(string $role): bool {
        // تنفيذ لاحقاً
        return $role === 'admin';
    }
    
    public function can(string $permission): bool {
        // تنفيذ لاحقاً
        return true;
    }
}

/**
 * تسجيل دالة الإغلاق
 */
register_shutdown_function(function() {
    if (Main_App::getInstance()->isInitialized()) {
        Main_App::getInstance()->shutdown();
    }
});

/**
 * معالج الأخطاء
 */
set_error_handler(function($level, $message, $file, $line) {
    if (error_reporting() & $level) {
        $app = Main_App::getInstance();
        if ($app->isInitialized()) {
            $app->log("PHP Error: $message in $file:$line", 'error');
        }
    }
    return false;
});

/**
 * معالج الاستثناءات
 */
set_exception_handler(function($exception) {
    $app = Main_App::getInstance();
    if ($app->isInitialized()) {
        $app->log("Uncaught Exception: " . $exception->getMessage(), 'critical', [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
    
    // في وضع الإنتاج، عرض صفحة خطأ لطيفة
    if (!$app->config('app.debug', false)) {
        http_response_code(500);
        echo "Internal Server Error";
    } else {
        throw $exception;
    }
});

?>