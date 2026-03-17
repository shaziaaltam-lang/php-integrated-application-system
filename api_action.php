<?php
/**
 * api_action.php
 * @version 1.0.0
 * @package SystemAPI
 * 
 * نقطة الدخول الوحيدة للتطبيق - لا يمكن الوصول إلى Main_App إلا من هنا
 * جميع الطلبات الخارجية يجب أن تمر عبر هذا الملف
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__FILE__));
    define('DS', DIRECTORY_SEPARATOR);
}

// ==========================================
// تحميل الملفات الأساسية
// ==========================================
require_once ROOT_PATH . DS . 'main_app.php';
require_once ROOT_PATH . DS . 'app_db.php';
require_once ROOT_PATH . DS . 'Auth_app.php';

/**
 * API_Action
 * @subpackage API
 * 
 * مسؤول عن معالجة جميع طلبات API
 * يوفر طبقة أمان بين العالم الخارجي والتطبيق الداخلي
 */
class API_Action {
    
    /**
     * @var Main_App $main_app مرجع للتطبيق الرئيسي
     */
    private $main_app = null;
    
    /**
     * @var API_Request $request بيانات الطلب الحالي
     */
    private $request = null;
    
    /**
     * @var API_Response $response كائن الاستجابة
     */
    private $response = null;
    
    /**
     * @var API_Auth $auth نظام التوثيق
     */
    private $auth = null;
    
    /**
     * @var Rate_Limiter $rate_limiter نظام تحديد المعدل
     */
    private $rate_limiter = null;
    
    /**
     * @var Logger $logger نظام التسجيل
     */
    private $logger = null;
    
    /**
     * @var array $config إعدادات API
     */
    private $config = [
        'version' => '1.0.0',
        'max_requests_per_minute' => 60,
        'require_signature' => true,
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
        'default_format' => 'json',
        'debug_mode' => false
    ];
    
    /**
     * @var array $endpoints قائمة endpoints المسموحة
     */
    private $endpoints = [
        'users' => ['controller' => 'UserController', 'method' => 'handle'],
        'notifications' => ['controller' => 'NotificationController', 'method' => 'handle'],
        'auth' => ['controller' => 'AuthController', 'method' => 'handle'],
        'email' => ['controller' => 'EmailController', 'method' => 'handle'],
        'sms' => ['controller' => 'SMSController', 'method' => 'handle'],
        'permissions' => ['controller' => 'PermissionController', 'method' => 'handle'],
        'dashboard' => ['controller' => 'DashboardController', 'method' => 'handle'],
        'crud' => ['controller' => 'CRUDController', 'method' => 'handle']
    ];
    
    /**
     * المُنشئ
     * يهيئ API ويعالج الطلب فوراً
     */
    public function __construct() {
        try {
            $this->initialize();
            $this->process();
        } catch (Exception $e) {
            $this->handleFatalError($e);
        }
    }
    
    /**
     * تهيئة النظام
     * @throws Exception
     */
    private function initialize(): void {
        // تسجيل بدء التنفيذ
        $this->log("API Request Started", 'info');
        
        // تهيئة مكونات API
        $this->request = new API_Request();
        $this->response = new API_Response();
        $this->auth = new API_Auth();
        $this->rate_limiter = new Rate_Limiter();
        $this->logger = new Logger('api');
        
        // تحميل الإعدادات من قاعدة البيانات
        $this->loadConfig();
        
        // التحقق من صحة الطلب
        if (!$this->validateRequest()) {
            throw new API_Exception('Invalid request', 400, 'ERR_001');
        }
        
        // التحقق من معدل الطلبات
        if (!$this->rate_limiter->check($this->request->getClientIP())) {
            throw new API_Exception('Rate limit exceeded', 429, 'ERR_429');
        }
        
        // توثيق الطلب
        $user = $this->auth->authenticate($this->request);
        if (!$user) {
            throw new API_Exception('Unauthorized', 401, 'ERR_401');
        }
        
        // ✅ الخطوة الأهم: الحصول على Main_App (الوصف الوحيد المسموح)
        $this->main_app = Main_App::getInstance();
        
        // التحقق من نجاح التهيئة
        if (!$this->main_app->isInitialized()) {
            $this->main_app->initialize();
        }
        
        // إضافة معلومات المستخدم للطلب
        $this->request->setUser($user);
        
        // تسجيل نجاح التوثيق
        $this->log("User authenticated: " . $user->getId(), 'info');
    }
    
    /**
     * معالجة الطلب
     */
    private function process(): void {
        try {
            // الحصول على نوع الطلب
            $endpoint = $this->request->getEndpoint();
            $method = $this->request->getMethod();
            $parameters = $this->request->getParameters();
            
            // التحقق من وجود endpoint
            if (!isset($this->endpoints[$endpoint])) {
                throw new API_Exception('Endpoint not found', 404, 'ERR_404');
            }
            
            // التحقق من الصلاحية للوصول لهذا endpoint
            if (!$this->auth->canAccess($this->request->getUser(), $endpoint, $method)) {
                throw new API_Exception('Forbidden', 403, 'ERR_403');
            }
            
            // تسجيل الطلب
            $this->log("Processing: $endpoint/$method", 'debug');
            
            // معالجة الطلب حسب النوع
            $result = $this->routeToEndpoint($endpoint, $method, $parameters);
            
            // تنسيق الاستجابة
            $this->response->success($result, [
                'endpoint' => $endpoint,
                'method' => $method,
                'timestamp' => time()
            ]);
            
        } catch (API_Exception $e) {
            $this->response->error($e->getMessage(), $e->getCode(), $e->getErrorCode());
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $this->response->error('Internal server error', 500, 'ERR_500');
        }
        
        // إرسال الاستجابة
        $this->response->send();
        
        // تسجيل الاستجابة
        $this->log("Response sent with status: " . $this->response->getStatus(), 'info');
    }
    
    /**
     * توجيه الطلب إلى الـ endpoint المناسب
     */
    private function routeToEndpoint(string $endpoint, string $method, array $parameters): array {
        $config = $this->endpoints[$endpoint];
        
        switch ($endpoint) {
            case 'users':
                return $this->handleUsersEndpoint($method, $parameters);
                
            case 'notifications':
                return $this->handleNotificationsEndpoint($method, $parameters);
                
            case 'auth':
                return $this->handleAuthEndpoint($method, $parameters);
                
            case 'email':
                return $this->handleEmailEndpoint($method, $parameters);
                
            case 'sms':
                return $this->handleSMSEndpoint($method, $parameters);
                
            case 'permissions':
                return $this->handlePermissionsEndpoint($method, $parameters);
                
            case 'dashboard':
                return $this->handleDashboardEndpoint($method, $parameters);
                
            case 'crud':
                return $this->handleCRUDEndpoint($method, $parameters);
                
            default:
                throw new API_Exception('Invalid endpoint', 400, 'ERR_400');
        }
    }
    
    /**
     * معالجة endpoint المستخدمين
     */
    private function handleUsersEndpoint(string $method, array $params): array {
        $auth = $this->main_app->auth;
        $db = $this->main_app->db;
        
        switch ($method) {
            case 'GET':
                if (isset($params['id'])) {
                    $user = $auth->getUserById($params['id']);
                    return $user ? $user->toArray() : [];
                } else {
                    return $db->fetchAll("SELECT * FROM users LIMIT " . ($params['limit'] ?? 50));
                }
                
            case 'POST':
                // إنشاء مستخدم جديد
                $userId = $auth->register($params);
                return ['id' => $userId, 'message' => 'User created successfully'];
                
            case 'PUT':
                // تحديث مستخدم
                if (!isset($params['id'])) {
                    throw new API_Exception('User ID required', 400, 'ERR_400');
                }
                $result = $auth->updateProfile($params['id'], $params);
                return ['success' => $result];
                
            case 'DELETE':
                // حذف مستخدم
                if (!isset($params['id'])) {
                    throw new API_Exception('User ID required', 400, 'ERR_400');
                }
                // فقط المدير يمكنه الحذف
                if (!$this->auth->getUser()->hasRole('admin')) {
                    throw new API_Exception('Insufficient permissions', 403, 'ERR_403');
                }
                $result = $db->delete('users', ['id' => $params['id']]);
                return ['success' => $result];
                
            default:
                throw new API_Exception('Method not allowed', 405, 'ERR_405');
        }
    }
    
    /**
     * معالجة endpoint الإشعارات
     */
    private function handleNotificationsEndpoint(string $method, array $params): array {
        $notifications = $this->main_app->notifications;
        $userId = $this->request->getUser()->getId();
        
        switch ($method) {
            case 'GET':
                if (isset($params['unread_only']) && $params['unread_only']) {
                    return $notifications->getUserUnread($userId, $params['limit'] ?? 50);
                } else {
                    return $notifications->getUserNotifications($userId, $params);
                }
                
            case 'POST':
                if (isset($params['mark_read'])) {
                    return ['success' => $notifications->markAsRead($params['notification_id'])];
                }
                // إرسال إشعار جديد
                return $notifications->send(
                    $params['user_id'],
                    $params['type'],
                    $params['data'],
                    $params['channels'] ?? null
                );
                
            default:
                throw new API_Exception('Method not allowed', 405, 'ERR_405');
        }
    }
    
    /**
     * معالجة endpoint المصادقة
     */
    private function handleAuthEndpoint(string $method, array $params): array {
        $auth = $this->main_app->auth;
        
        switch ($method) {
            case 'POST':
                if (isset($params['login'])) {
                    // تسجيل دخول
                    $result = $auth->login($params['username'], $params['password']);
                    if ($result) {
                        return [
                            'success' => true,
                            'token' => $auth->generateApiToken(),
                            'user' => $auth->getUser()->toArray()
                        ];
                    }
                    throw new API_Exception('Invalid credentials', 401, 'ERR_401');
                    
                } elseif (isset($params['logout'])) {
                    // تسجيل خروج
                    $auth->logout();
                    return ['success' => true];
                    
                } elseif (isset($params['register'])) {
                    // تسجيل جديد
                    $userId = $auth->register($params);
                    return ['success' => true, 'user_id' => $userId];
                }
                break;
                
            case 'GET':
                if (isset($params['check'])) {
                    return ['authenticated' => $auth->check()];
                } elseif (isset($params['user'])) {
                    return $auth->user() ? $auth->user()->toArray() : null;
                }
                break;
        }
        
        throw new API_Exception('Invalid request', 400, 'ERR_400');
    }
    
    /**
     * معالجة endpoint البريد الإلكتروني
     */
    private function handleEmailEndpoint(string $method, array $params): array {
        $email = $this->main_app->email;
        
        if ($method === 'POST' && isset($params['send'])) {
            $result = $email->send(
                $params['to'],
                $params['subject'],
                $params['body'],
                $params['html'] ?? false
            );
            return ['success' => $result];
        }
        
        throw new API_Exception('Invalid request', 400, 'ERR_400');
    }
    
    /**
     * معالجة endpoint الرسائل النصية
     */
    private function handleSMSEndpoint(string $method, array $params): array {
        $sms = $this->main_app->sms;
        
        if ($method === 'POST' && isset($params['send'])) {
            $result = $sms->send($params['to'], $params['message']);
            return ['success' => $result];
        }
        
        throw new API_Exception('Invalid request', 400, 'ERR_400');
    }
    
    /**
     * معالجة endpoint الصلاحيات
     */
    private function handlePermissionsEndpoint(string $method, array $params): array {
        $permissions = $this->main_app->permissions;
        $userId = $this->request->getUser()->getId();
        
        switch ($method) {
            case 'GET':
                if (isset($params['check'])) {
                    return ['has_permission' => $permissions->checkPermission(
                        $userId,
                        $params['permission']
                    )];
                } elseif (isset($params['user'])) {
                    return $permissions->getUserPermissions($userId);
                }
                break;
                
            case 'POST':
                if (isset($params['assign_role'])) {
                    return ['success' => $permissions->assignRole(
                        $params['user_id'],
                        $params['role']
                    )];
                }
                break;
        }
        
        throw new API_Exception('Invalid request', 400, 'ERR_400');
    }
    
    /**
     * معالجة endpoint لوحة التحكم
     */
    private function handleDashboardEndpoint(string $method, array $params): array {
        $dashboard = $this->main_app->dashboard;
        $userId = $this->request->getUser()->getId();
        
        switch ($method) {
            case 'GET':
                if (isset($params['statistics'])) {
                    return $dashboard->getStatistics($userId);
                } elseif (isset($params['activities'])) {
                    return $dashboard->viewActivities($userId, $params['limit'] ?? 20);
                } elseif (isset($params['settings'])) {
                    return $dashboard->getSettings($userId);
                }
                break;
                
            case 'POST':
                if (isset($params['update_settings'])) {
                    return ['success' => $dashboard->updateSettings($userId, $params['settings'])];
                } elseif (isset($params['change_theme'])) {
                    return ['success' => $dashboard->changeTheme($userId, $params['theme'])];
                }
                break;
        }
        
        throw new API_Exception('Invalid request', 400, 'ERR_400');
    }
    
    /**
     * معالجة endpoint CRUD العام
     */
    private function handleCRUDEndpoint(string $method, array $params): array {
        if (!isset($params['table'])) {
            throw new API_Exception('Table name required', 400, 'ERR_400');
        }
        
        $crud = new CRUD_App($params['table']);
        
        switch ($method) {
            case 'GET':
                if (isset($params['id'])) {
                    return $crud->find($params['id']);
                } else {
                    return $crud->paginate(
                        $params['per_page'] ?? 15,
                        $params['page'] ?? 1
                    );
                }
                
            case 'POST':
                return ['id' => $crud->create($params['data'])];
                
            case 'PUT':
                return ['success' => $crud->update($params['id'], $params['data'])];
                
            case 'DELETE':
                return ['success' => $crud->delete($params['id'])];
                
            default:
                throw new API_Exception('Method not allowed', 405, 'ERR_405');
        }
    }
    
    /**
     * التحقق من صحة الطلب
     */
    private function validateRequest(): bool {
        // التحقق من وجود API Key
        if (!$this->request->has('api_key')) {
            return false;
        }
        
        // التحقق من التوقيع إذا مطلوب
        if ($this->config['require_signature']) {
            if (!$this->request->has('signature')) {
                return false;
            }
            if (!$this->auth->verifySignature($this->request)) {
                return false;
            }
        }
        
        // التحقق من صحة البيانات الأساسية
        if (!$this->request->has('endpoint')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * تحميل الإعدادات
     */
    private function loadConfig(): void {
        try {
            $db = App_DB::getInstance();
            $settings = $db->fetchOne("SELECT * FROM api_settings WHERE id = 1");
            if ($settings) {
                $this->config = array_merge($this->config, json_decode($settings['config'], true));
            }
        } catch (Exception $e) {
            // استخدام الإعدادات الافتراضية
        }
    }
    
    /**
     * تسجيل الأحداث
     */
    private function log(string $message, string $level = 'info'): void {
        if ($this->logger) {
            $this->logger->log($level, "[API] " . $message, [
                'ip' => $this->request ? $this->request->getClientIP() : 'unknown',
                'endpoint' => $this->request ? $this->request->getEndpoint() : 'unknown',
                'method' => $this->request ? $this->request->getMethod() : 'unknown'
            ]);
        }
    }
    
    /**
     * معالجة الأخطاء الفادحة
     */
    private function handleFatalError(Exception $e): void {
        $status = $e instanceof API_Exception ? $e->getCode() : 500;
        $message = $this->config['debug_mode'] ? $e->getMessage() : 'Internal server error';
        
        $response = [
            'status' => 'error',
            'code' => $status,
            'message' => $message,
            'timestamp' => time()
        ];
        
        if ($this->config['debug_mode']) {
            $response['trace'] = $e->getTraceAsString();
        }
        
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($response);
        
        // تسجيل الخطأ
        $this->log("Fatal error: " . $e->getMessage(), 'error');
    }
    
    /**
     * منع الاستنساخ
     */
    private function __clone() {}
    
    /**
     * منع إعادة الإنشاء
     */
    private function __wakeup() {}
}

/**
 * API_Exception
 * استثناء مخصص للـ API
 */
class API_Exception extends Exception {
    private $error_code;
    
    public function __construct(string $message, int $code = 400, string $error_code = 'ERR_000') {
        parent::__construct($message, $code);
        $this->error_code = $error_code;
    }
    
    public function getErrorCode(): string {
        return $this->error_code;
    }
}

/**
 * API_Request
 * يمثل طلب API
 */
class API_Request {
    
    private $data = [];
    private $method = '';
    private $endpoint = '';
    private $headers = [];
    private $files = [];
    private $client_ip = '';
    private $user = null;
    private $timestamp = 0;
    
    public function __construct() {
        $this->timestamp = time();
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->headers = getallheaders();
        $this->client_ip = $this->getClientIPFromServer();
        $this->parseInput();
        $this->parseEndpoint();
    }
    
    private function parseInput(): void {
        // قراءة المدخلات حسب نوع المحتوى
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($content_type, 'application/json') !== false) {
            // JSON input
            $input = file_get_contents('php://input');
            $this->data = json_decode($input, true) ?? [];
        } elseif (strpos($content_type, 'multipart/form-data') !== false) {
            // Form data with files
            $this->data = $_POST;
            $this->files = $_FILES;
        } else {
            // Regular POST/GET
            $this->data = $this->method === 'GET' ? $_GET : $_POST;
        }
        
        // إضافة input raw إذا وجد
        if (empty($this->data) && $this->method !== 'GET') {
            parse_str(file_get_contents('php://input'), $this->data);
        }
    }
    
    private function parseEndpoint(): void {
        // استخراج endpoint من URL
        $path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';
        $parts = explode('/', trim($path, '/'));
        $this->endpoint = $parts[0] ?? $this->data['endpoint'] ?? 'home';
    }
    
    private function getClientIPFromServer(): string {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
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
    
    public function has(string $key): bool {
        return isset($this->data[$key]);
    }
    
    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }
    
    public function getEndpoint(): string {
        return $this->endpoint;
    }
    
    public function getMethod(): string {
        return $this->method;
    }
    
    public function getParameters(): array {
        return $this->data;
    }
    
    public function getHeader(string $name, $default = null) {
        return $this->headers[$name] ?? $default;
    }
    
    public function getClientIP(): string {
        return $this->client_ip;
    }
    
    public function setUser($user): void {
        $this->user = $user;
    }
    
    public function getUser() {
        return $this->user;
    }
    
    public function getUserId(): ?int {
        return $this->user ? $this->user->getId() : null;
    }
    
    public function getFile(string $name) {
        return $this->files[$name] ?? null;
    }
    
    public function getTimestamp(): int {
        return $this->timestamp;
    }
    
    public function verifySignature(): bool {
        // تنفيذ التحقق من التوقيع
        $signature = $this->get('signature');
        $api_key = $this->get('api_key');
        $timestamp = $this->get('timestamp');
        
        if (!$signature || !$api_key || !$timestamp) {
            return false;
        }
        
        // التحقق من عدم انتهاء صلاحية الطلب (5 دقائق)
        if (abs(time() - $timestamp) > 300) {
            return false;
        }
        
        // حساب التوقيع المتوقع
        $expected = hash_hmac('sha256', $api_key . $timestamp, API_SECRET_KEY);
        
        return hash_equals($expected, $signature);
    }
}

/**
 * API_Response
 * يمثل استجابة API
 */
class API_Response {
    
    private $status = 200;
    private $data = [];
    private $message = '';
    private $meta = [];
    private $errors = [];
    private $format = 'json';
    
    public function __construct(string $format = 'json') {
        $this->format = $format;
    }
    
    public function success($data = null, array $meta = []): self {
        $this->status = 200;
        $this->data = $data ?? [];
        $this->meta = $meta;
        $this->message = 'Success';
        return $this;
    }
    
    public function error(string $message, int $status = 400, string $code = null): self {
        $this->status = $status;
        $this->message = $message;
        $this->errors[] = [
            'code' => $code,
            'message' => $message
        ];
        return $this;
    }
    
    public function withData($data): self {
        $this->data = $data;
        return $this;
    }
    
    public function withMeta(array $meta): self {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }
    
    public function setStatus(int $status): self {
        $this->status = $status;
        return $this;
    }
    
    public function getStatus(): int {
        return $this->status;
    }
    
    public function send(): void {
        http_response_code($this->status);
        
        switch ($this->format) {
            case 'json':
                $this->sendJson();
                break;
            case 'xml':
                $this->sendXml();
                break;
            default:
                $this->sendJson();
        }
        
        exit;
    }
    
    private function sendJson(): void {
        header('Content-Type: application/json');
        
        $response = [
            'status' => $this->status < 400 ? 'success' : 'error',
            'message' => $this->message,
            'data' => $this->data,
            'meta' => $this->meta,
            'timestamp' => time()
        ];
        
        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    private function sendXml(): void {
        header('Content-Type: application/xml');
        
        $xml = new SimpleXMLElement('<response/>');
        $xml->addChild('status', $this->status < 400 ? 'success' : 'error');
        $xml->addChild('message', $this->message);
        $xml->addChild('timestamp', time());
        
        // إضافة البيانات (مبسطة)
        $dataNode = $xml->addChild('data');
        $this->arrayToXml($this->data, $dataNode);
        
        echo $xml->asXML();
    }
    
    private function arrayToXml(array $data, SimpleXMLElement $xml): void {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild(is_numeric($key) ? 'item' : $key);
                $this->arrayToXml($value, $child);
            } else {
                $xml->addChild(is_numeric($key) ? 'item' : $key, htmlspecialchars($value));
            }
        }
    }
}

/**
 * API_Auth
 * نظام توثيق API
 */
class API_Auth {
    
    private $db = null;
    private $cache = [];
    
    public function __construct() {
        $this->db = App_DB::getInstance();
    }
    
    public function authenticate(API_Request $request): ?User {
        $api_key = $request->get('api_key');
        
        if (!$api_key) {
            return null;
        }
        
        // البحث عن المفتاح في قاعدة البيانات
        $key_data = $this->db->fetchOne(
            "SELECT * FROM api_keys WHERE api_key = ? AND expires_at > NOW() AND revoked = 0",
            [$api_key]
        );
        
        if (!$key_data) {
            return null;
        }
        
        // تحديث آخر استخدام
        $this->db->update('api_keys', 
            ['last_used' => date('Y-m-d H:i:s')],
            ['id' => $key_data['id']]
        );
        
        // جلب المستخدم
        $user_data = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = ?",
            [$key_data['user_id']]
        );
        
        if (!$user_data) {
            return null;
        }
        
        return new User($user_data);
    }
    
    public function generateToken(User $user): string {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $this->db->insert('api_keys', [
            'user_id' => $user->getId(),
            'api_key' => $token,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expires,
            'revoked' => 0
        ]);
        
        return $token;
    }
    
    public function revokeToken(string $token): bool {
        return (bool)$this->db->update('api_keys',
            ['revoked' => 1],
            ['api_key' => $token]
        );
    }
    
    public function verifySignature(API_Request $request): bool {
        return $request->verifySignature();
    }
    
    public function canAccess(?User $user, string $endpoint, string $method): bool {
        if (!$user) {
            return false;
        }
        
        // صلاحيات خاصة للمدير
        if ($user->hasRole('admin')) {
            return true;
        }
        
        // التحقق من صلاحية الوصول للـ endpoint
        $permission = "api.$endpoint.$method";
        return $user->can($permission);
    }
}

/**
 * Rate_Limiter
 * نظام تحديد معدل الطلبات
 */
class Rate_Limiter {
    
    private $limits = [];
    private $storage = [];
    
    public function __construct() {
        // استخدام Redis أو Memcached في الإنتاج
        // هنا نستخدم مصفوفة مؤقتة للتبسيط
    }
    
    public function check(string $identifier, int $max_requests = 60, int $minutes = 1): bool {
        $key = "rate_limit:$identifier:" . floor(time() / 60);
        $current = $this->get($key, 0);
        
        if ($current >= $max_requests) {
            return false;
        }
        
        $this->increment($key);
        return true;
    }
    
    public function getRemaining(string $identifier, int $max_requests = 60): int {
        $key = "rate_limit:$identifier:" . floor(time() / 60);
        $current = $this->get($key, 0);
        return max(0, $max_requests - $current);
    }
    
    public function getResetTime(string $identifier): int {
        $current_minute = floor(time() / 60);
        return ($current_minute + 1) * 60;
    }
    
    private function get(string $key, $default = null) {
        // في الإنتاج: استخدام Redis
        return $this->storage[$key] ?? $default;
    }
    
    private function increment(string $key): void {
        // في الإنتاج: استخدام Redis INCR
        if (!isset($this->storage[$key])) {
            $this->storage[$key] = 1;
        } else {
            $this->storage[$key]++;
        }
    }
}

/**
 * Logger
 * نظام تسجيل مبسط
 */
class Logger {
    
    private $channel;
    private $log_file;
    
    public function __construct(string $channel) {
        $this->channel = $channel;
        $this->log_file = ROOT_PATH . DS . 'logs' . DS . $channel . '_' . date('Y-m-d') . '.log';
        
        // التأكد من وجود مجلد logs
        if (!is_dir(ROOT_PATH . DS . 'logs')) {
            mkdir(ROOT_PATH . DS . 'logs', 0755, true);
        }
    }
    
    public function log(string $level, string $message, array $context = []): void {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'channel' => $this->channel,
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        $line = json_encode($entry) . PHP_EOL;
        file_put_contents($this->log_file, $line, FILE_APPEND | LOCK_EX);
        
        // في وضع التصحيح، اطبع على الشاشة
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("[$level] $message");
        }
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $this->log('debug', $message, $context);
        }
    }
}

// ==========================================
// تعريف الثوابت العامة
// ==========================================
if (!defined('API_SECRET_KEY')) {
    // يجب أن يكون هذا في ملف الإعدادات
    define('API_SECRET_KEY', 'your-secret-key-here-change-in-production');
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// ==========================================
// تشغيل API
// ==========================================
try {
    // تنظيف أي output سابق
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // تشغيل API
    new API_Action();
    
} catch (Throwable $e) {
    // خطأ فادح
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Fatal error: ' . (DEBUG_MODE ? $e->getMessage() : 'Internal server error'),
        'timestamp' => time()
    ]);
    
    // تسجيل الخطأ في السجل
    error_log("FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}
?>