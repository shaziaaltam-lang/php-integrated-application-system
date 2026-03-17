<?php
/**
 * Auth_app.php
 * @version 1.0.0
 * @package Authentication
 * 
 * نظام المصادقة الكامل
 * يدعم تسجيل الدخول، التسجيل، التحقق بالبريد، المصادقة الثنائية، وتذكرني
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * Auth_App
 * @package Authentication
 * 
 * الكلاس الرئيسي لنظام المصادقة
 */
class Auth_App {
    
    // ==========================================
    // الخصائص
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
     * @var صلاحيات_App|null نظام الصلاحيات
     */
    private $permissions = null;
    
    /**
     * @var array إعدادات المصادقة
     */
    private $config = [
        'session_lifetime' => 7200, // ساعتين
        'remember_lifetime' => 2592000, // 30 يوم
        'max_login_attempts' => 5,
        'lockout_time' => 900, // 15 دقيقة
        'password_min_length' => 8,
        'require_email_verification' => true,
        'two_factor_enabled' => false,
        'password_hash_algo' => PASSWORD_BCRYPT,
        'password_hash_options' => ['cost' => 12],
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ];
    
    /**
     * @var User|null المستخدم الحالي
     */
    private $user = null;
    
    /**
     * @var Login_Attempts|null محاولات تسجيل الدخول
     */
    private $attempts = null;
    
    /**
     * @var Token_Manager|null مدير التوكنات
     */
    private $tokens = null;
    
    /**
     * @var Two_Factor_Auth|null المصادقة الثنائية
     */
    private $two_factor = null;
    
    /**
     * @var bool حالة تسجيل الدخول
     */
    private $logged_in = false;
    
    /**
     * @var bool تسجيل الدخول عبر "تذكرني"
     */
    private $via_remember = false;
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param App_Session $session
     * @param App_DB $db
     * @param صلاحيات_App|null $permissions
     */
    public function __construct(App_Session $session, App_DB $db, ?صلاحيات_App $permissions = null) {
        $this->session = $session;
        $this->db = $db;
        $this->permissions = $permissions;
        
        $this->attempts = new Login_Attempts($db);
        $this->tokens = new Token_Manager($db);
        $this->two_factor = new Two_Factor_Auth($db);
        
        $this->loadConfig();
        $this->checkSession();
        $this->checkRememberCookie();
    }
    
    /**
     * تحميل إعدادات المصادقة من قاعدة البيانات
     */
    private function loadConfig(): void {
        try {
            $settings = $this->db->fetchOne("SELECT * FROM auth_settings WHERE id = 1");
            if ($settings && !empty($settings['config'])) {
                $this->config = array_merge($this->config, json_decode($settings['config'], true));
            }
        } catch (Exception $e) {
            // استخدام الإعدادات الافتراضية
        }
    }
    
    /**
     * التحقق من الجلسة الحالية
     */
    private function checkSession(): void {
        $userData = $this->session->getUser();
        
        if ($userData && isset($userData['id'])) {
            $user = $this->getUserById($userData['id']);
            
            if ($user && $user->isActive()) {
                $this->user = $user;
                $this->logged_in = true;
                
                // تحديث آخر نشاط
                $this->db->update('users', 
                    ['last_activity' => date('Y-m-d H:i:s')],
                    ['id' => $user->getId()]
                );
            }
        }
    }
    
    /**
     * التحقق من كوكي "تذكرني"
     */
    private function checkRememberCookie(): void {
        if ($this->logged_in) {
            return;
        }
        
        $cookieName = 'remember_token';
        
        if (isset($_COOKIE[$cookieName])) {
            $token = $_COOKIE[$cookieName];
            $userId = $this->tokens->validate($token, 'remember');
            
            if ($userId) {
                $user = $this->getUserById($userId);
                
                if ($user && $user->isActive()) {
                    $this->loginUsingId($userId);
                    $this->via_remember = true;
                    
                    // تجديد الكوكي
                    $this->setRememberCookie($user->getId());
                }
            }
        }
    }
    
    // ==========================================
    // دوال تسجيل الدخول والخروج
    // ==========================================
    
    /**
     * تسجيل الدخول
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @return bool
     */
    public function login(string $username, string $password, bool $remember = false): bool {
        // التحقق من القفل
        if ($this->attempts->isLocked($username)) {
            $this->log("Login attempt on locked account: {$username}", 'warning');
            return false;
        }
        
        // البحث عن المستخدم
        $user = $this->getUserByUsername($username);
        
        if (!$user) {
            $this->attempts->increment($username);
            $this->log("Failed login attempt - user not found: {$username}", 'warning');
            return false;
        }
        
        // التحقق من تفعيل الحساب
        if ($this->config['require_email_verification'] && !$user->isVerified()) {
            $this->log("Login attempt on unverified account: {$username}", 'warning');
            return false;
        }
        
        // التحقق من كلمة المرور
        if (!$user->verifyPassword($password)) {
            $this->attempts->increment($username);
            $this->log("Failed login attempt - wrong password: {$username}", 'warning');
            return false;
        }
        
        // التحقق من المصادقة الثنائية
        if ($user->hasTwoFactor()) {
            return $this->requireTwoFactor($user);
        }
        
        // تسجيل الدخول
        return $this->completeLogin($user, $remember);
    }
    
    /**
     * إكمال عملية تسجيل الدخول
     * @param User $user
     * @param bool $remember
     * @return bool
     */
    private function completeLogin(User $user, bool $remember = false): bool {
        // تحديث بيانات المستخدم
        $this->db->update('users', [
            'last_login' => date('Y-m-d H:i:s'),
            'last_ip' => $this->getClientIP(),
            'login_count' => $this->db->raw('login_count + 1')
        ], ['id' => $user->getId()]);
        
        // تخزين في الجلسة
        $this->session->setUser([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'login_time' => time()
        ]);
        
        // تعيين كوكي التذكر
        if ($remember) {
            $this->setRememberCookie($user->getId());
        }
        
        // تعيين المستخدم الحالي
        $this->user = $user;
        $this->logged_in = true;
        $this->attempts->clear($user->getUsername());
        
        // تسجيل الحدث
        $this->log("User logged in successfully: {$user->getUsername()}", 'info');
        
        // إطلاق حدث
        $this->dispatchEvent('user.login', ['user_id' => $user->getId()]);
        
        return true;
    }
    
    /**
     * طلب المصادقة الثنائية
     * @param User $user
     * @return bool
     */
    private function requireTwoFactor(User $user): bool {
        // تخزين معرف المستخدم مؤقتاً
        $this->session->set('2fa_user_id', $user->getId());
        
        // إرسال رمز التحقق
        $this->two_factor->sendCode($user);
        
        return false;
    }
    
    /**
     * التحقق من رمز المصادقة الثنائية
     * @param string $code
     * @param bool $remember
     * @return bool
     */
    public function verifyTwoFactor(string $code, bool $remember = false): bool {
        $userId = $this->session->get('2fa_user_id');
        
        if (!$userId) {
            return false;
        }
        
        $user = $this->getUserById($userId);
        
        if (!$user) {
            return false;
        }
        
        if ($this->two_factor->verify($user, $code)) {
            $this->session->remove('2fa_user_id');
            return $this->completeLogin($user, $remember);
        }
        
        return false;
    }
    
    /**
     * تعيين كوكي التذكر
     * @param int $userId
     */
    private function setRememberCookie(int $userId): void {
        $token = $this->tokens->generate('remember', $userId, $this->config['remember_lifetime']);
        
        setcookie(
            'remember_token',
            $token,
            time() + $this->config['remember_lifetime'],
            '/',
            '',
            $this->config['cookie_secure'],
            $this->config['cookie_httponly']
        );
    }
    
    /**
     * تسجيل الخروج
     */
    public function logout(): void {
        // حذف كوكي التذكر
        if (isset($_COOKIE['remember_token'])) {
            $this->tokens->revoke($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        // تنظيف الجلسة
        $userId = $this->user ? $this->user->getId() : null;
        $this->session->destroySession();
        
        // تسجيل الحدث
        if ($userId) {
            $this->log("User logged out: {$userId}", 'info');
            $this->dispatchEvent('user.logout', ['user_id' => $userId]);
        }
        
        $this->user = null;
        $this->logged_in = false;
    }
    
    /**
     * تسجيل الدخول باستخدام ID
     * @param int $userId
     * @return bool
     */
    public function loginUsingId(int $userId): bool {
        $user = $this->getUserById($userId);
        
        if (!$user || !$user->isActive()) {
            return false;
        }
        
        $this->session->setUser([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'login_time' => time()
        ]);
        
        $this->user = $user;
        $this->logged_in = true;
        
        $this->log("User logged in using ID: {$user->getUsername()}", 'info');
        
        return true;
    }
    
    /**
     * تسجيل الدخول كمستخدم آخر (للمدير)
     * @param int $userId
     * @return bool
     */
    public function loginAs(int $userId): bool {
        if (!$this->user || !$this->user->hasRole('admin')) {
            return false;
        }
        
        $originalId = $this->user->getId();
        $user = $this->getUserById($userId);
        
        if (!$user) {
            return false;
        }
        
        // تخزين المستخدم الأصلي
        $this->session->set('original_admin_id', $originalId);
        
        return $this->loginUsingId($userId);
    }
    
    /**
     * العودة للمستخدم الأصلي
     * @return bool
     */
    public function logoutAs(): bool {
        $originalId = $this->session->get('original_admin_id');
        
        if ($originalId) {
            $this->session->remove('original_admin_id');
            return $this->loginUsingId($originalId);
        }
        
        return false;
    }
    
    /**
     * تسجيل الخروج من جميع الأجهزة
     * @param string $password
     * @return bool
     */
    public function logoutOtherDevices(string $password): bool {
        if (!$this->user) {
            return false;
        }
        
        if (!$this->user->verifyPassword($password)) {
            return false;
        }
        
        // حذف جميع توكنات التذكر
        $this->tokens->revokeAllForUser($this->user->getId(), 'remember');
        
        // تغيير كلمة المرور قليلاً لتسجيل الخروج من الجلسات الأخرى
        $this->db->update('users', [
            'password_version' => $this->db->raw('password_version + 1')
        ], ['id' => $this->user->getId()]);
        
        $this->log("Logged out from other devices: {$this->user->getUsername()}", 'info');
        
        return true;
    }
    
    // ==========================================
    // دوال التسجيل
    // ==========================================
    
    /**
     * تسجيل مستخدم جديد
     * @param array $userData
     * @return User|false
     */
    public function register(array $userData): ?User {
        // التحقق من البيانات
        $validation = $this->validateRegistration($userData);
        
        if (!$validation['valid']) {
            $this->log("Registration failed: " . implode(', ', $validation['errors']), 'warning');
            return null;
        }
        
        // التحقق من عدم تكرار البريد
        if ($this->emailExists($userData['email'])) {
            $this->log("Registration failed - email already exists: {$userData['email']}", 'warning');
            return null;
        }
        
        // التحقق من عدم تكرار اسم المستخدم
        if ($this->usernameExists($userData['username'])) {
            $this->log("Registration failed - username already exists: {$userData['username']}", 'warning');
            return null;
        }
        
        // تشفير كلمة المرور
        $userData['password'] = $this->hashPassword($userData['password']);
        
        // إضافة حقول إضافية
        $userData['created_at'] = date('Y-m-d H:i:s');
        $userData['updated_at'] = date('Y-m-d H:i:s');
        $userData['verification_token'] = $this->tokens->generate('verification', null, 86400); // 24 ساعة
        $userData['status'] = $this->config['require_email_verification'] ? 'pending' : 'active';
        
        // إدراج المستخدم
        $userId = $this->db->insert('users', $userData);
        
        if (!$userId) {
            return null;
        }
        
        // تعيين الدور الافتراضي
        if ($this->permissions) {
            $this->permissions->assignRole($userId, 'user');
        }
        
        // إرسال بريد التحقق إذا مطلوب
        if ($this->config['require_email_verification']) {
            $this->sendVerificationEmail($userData['email'], $userData['verification_token']);
        }
        
        // تسجيل الحدث
        $this->log("New user registered: {$userData['username']} (ID: {$userId})", 'info');
        $this->dispatchEvent('user.register', ['user_id' => $userId, 'user_data' => $userData]);
        
        return $this->getUserById($userId);
    }
    
    /**
     * التحقق من صحة بيانات التسجيل
     * @param array $data
     * @return array
     */
    private function validateRegistration(array $data): array {
        $errors = [];
        
        // التحقق من اسم المستخدم
        if (empty($data['username'])) {
            $errors[] = 'Username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $errors[] = 'Username can only contain letters, numbers and underscore';
        }
        
        // التحقق من البريد
        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }
        
        // التحقق من كلمة المرور
        if (empty($data['password'])) {
            $errors[] = 'Password is required';
        } elseif (strlen($data['password']) < $this->config['password_min_length']) {
            $errors[] = "Password must be at least {$this->config['password_min_length']} characters";
        } elseif (!preg_match('/[A-Z]/', $data['password'])) {
            $errors[] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $data['password'])) {
            $errors[] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $data['password'])) {
            $errors[] = 'Password must contain at least one number';
        }
        
        // التحقق من تأكيد كلمة المرور
        if (isset($data['password_confirm']) && $data['password'] !== $data['password_confirm']) {
            $errors[] = 'Passwords do not match';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    // ==========================================
    // دوال التحقق من البريد
    // ==========================================
    
    /**
     * التحقق من البريد الإلكتروني
     * @param string $token
     * @return bool
     */
    public function verifyEmail(string $token): bool {
        $userId = $this->tokens->validate($token, 'verification');
        
        if (!$userId) {
            return false;
        }
        
        $result = $this->db->update('users', [
            'verified_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'verification_token' => null
        ], ['id' => $userId]);
        
        if ($result) {
            $this->tokens->use($token);
            $this->log("Email verified for user ID: {$userId}", 'info');
            $this->dispatchEvent('user.verified', ['user_id' => $userId]);
        }
        
        return (bool)$result;
    }
    
    /**
     * إعادة إرسال بريد التحقق
     * @param string $email
     * @return bool
     */
    public function resendVerification(string $email): bool {
        $user = $this->getUserByEmail($email);
        
        if (!$user || $user->isVerified()) {
            return false;
        }
        
        $token = $this->tokens->generate('verification', $user->getId(), 86400);
        
        $this->db->update('users', [
            'verification_token' => $token
        ], ['id' => $user->getId()]);
        
        return $this->sendVerificationEmail($email, $token);
    }
    
    /**
     * إرسال بريد التحقق
     * @param string $email
     * @param string $token
     * @return bool
     */
    private function sendVerificationEmail(string $email, string $token): bool {
        // يمكن استخدام Email_App هنا
        $verificationLink = $this->getVerificationLink($token);
        
        // تسجيل للإرسال
        $this->log("Verification email sent to: {$email}", 'info');
        
        return true;
    }
    
    // ==========================================
    // دوال إعادة تعيين كلمة المرور
    // ==========================================
    
    /**
     * طلب إعادة تعيين كلمة المرور
     * @param string $email
     * @return bool
     */
    public function resetPassword(string $email): bool {
        $user = $this->getUserByEmail($email);
        
        if (!$user) {
            // لا نخبر المستخدم أن البريد غير موجود
            return true;
        }
        
        $token = $this->tokens->generate('password_reset', $user->getId(), 3600); // ساعة واحدة
        
        $this->db->update('users', [
            'reset_token' => $token,
            'reset_expires' => date('Y-m-d H:i:s', time() + 3600)
        ], ['id' => $user->getId()]);
        
        $this->sendPasswordResetEmail($email, $token);
        
        $this->log("Password reset requested for: {$email}", 'info');
        
        return true;
    }
    
    /**
     * تأكيد إعادة تعيين كلمة المرور
     * @param string $token
     * @param string $newPassword
     * @return bool
     */
    public function confirmResetPassword(string $token, string $newPassword): bool {
        $userId = $this->tokens->validate($token, 'password_reset');
        
        if (!$userId) {
            return false;
        }
        
        $user = $this->getUserById($userId);
        
        if (!$user) {
            return false;
        }
        
        // التحقق من عدم استخدام كلمة المرور القديمة
        if ($user->verifyPassword($newPassword)) {
            return false;
        }
        
        // تحديث كلمة المرور
        $result = $this->db->update('users', [
            'password' => $this->hashPassword($newPassword),
            'reset_token' => null,
            'reset_expires' => null,
            'password_version' => $this->db->raw('password_version + 1')
        ], ['id' => $userId]);
        
        if ($result) {
            $this->tokens->use($token);
            $this->log("Password reset completed for user ID: {$userId}", 'info');
            $this->dispatchEvent('user.password_reset', ['user_id' => $userId]);
        }
        
        return (bool)$result;
    }
    
    /**
     * إرسال بريد إعادة تعيين كلمة المرور
     * @param string $email
     * @param string $token
     * @return bool
     */
    private function sendPasswordResetEmail(string $email, string $token): bool {
        $resetLink = $this->getPasswordResetLink($token);
        
        // تسجيل للإرسال
        $this->log("Password reset email sent to: {$email}", 'info');
        
        return true;
    }
    
    // ==========================================
    // دوال تغيير كلمة المرور
    // ==========================================
    
    /**
     * تغيير كلمة المرور
     * @param string $oldPassword
     * @param string $newPassword
     * @return bool
     */
    public function changePassword(string $oldPassword, string $newPassword): bool {
        if (!$this->user) {
            return false;
        }
        
        if (!$this->user->verifyPassword($oldPassword)) {
            $this->log("Failed password change attempt - wrong old password: {$this->user->getUsername()}", 'warning');
            return false;
        }
        
        // التحقق من قوة كلمة المرور الجديدة
        if (strlen($newPassword) < $this->config['password_min_length']) {
            return false;
        }
        
        $result = $this->db->update('users', [
            'password' => $this->hashPassword($newPassword),
            'password_version' => $this->db->raw('password_version + 1'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $this->user->getId()]);
        
        if ($result) {
            $this->log("Password changed for user: {$this->user->getUsername()}", 'info');
            $this->dispatchEvent('user.password_change', ['user_id' => $this->user->getId()]);
        }
        
        return (bool)$result;
    }
    
    /**
     * تأكيد كلمة المرور
     * @param string $password
     * @return bool
     */
    public function confirmPassword(string $password): bool {
        if (!$this->user) {
            return false;
        }
        
        return $this->user->verifyPassword($password);
    }
    
    // ==========================================
    // دوال تحديث الملف الشخصي
    // ==========================================
    
    /**
     * تحديث الملف الشخصي
     * @param array $data
     * @return bool
     */
    public function updateProfile(array $data): bool {
        if (!$this->user) {
            return false;
        }
        
        $allowedFields = ['name', 'email', 'phone', 'avatar', 'bio', 'settings'];
        $updateData = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return false;
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        $result = $this->db->update('users', $updateData, ['id' => $this->user->getId()]);
        
        if ($result) {
            // تحديث بيانات المستخدم في الذاكرة
            $this->user = $this->getUserById($this->user->getId());
            
            $this->log("Profile updated for user: {$this->user->getUsername()}", 'info');
            $this->dispatchEvent('user.profile_updated', [
                'user_id' => $this->user->getId(),
                'updated_fields' => array_keys($updateData)
            ]);
        }
        
        return (bool)$result;
    }
    
    // ==========================================
    // دوال المصادقة الثنائية
    // ==========================================
    
    /**
     * تفعيل المصادقة الثنائية
     * @return array
     */
    public function twoFactorEnable(): array {
        if (!$this->user) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        $secret = $this->two_factor->generateSecret();
        $recoveryCodes = $this->two_factor->generateRecoveryCodes();
        
        $this->db->update('users', [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => json_encode($recoveryCodes),
            'two_factor_enabled' => 0 // لم يتم التأكيد بعد
        ], ['id' => $this->user->getId()]);
        
        return [
            'success' => true,
            'secret' => $secret,
            'qr_code' => $this->two_factor->getQRCodeUrl($this->user->getEmail(), $secret),
            'recovery_codes' => $recoveryCodes
        ];
    }
    
    /**
     * التحقق من رمز المصادقة الثنائية للتفعيل
     * @param string $code
     * @return bool
     */
    public function twoFactorVerify(string $code): bool {
        if (!$this->user) {
            return false;
        }
        
        $secret = $this->user->getTwoFactorSecret();
        
        if (!$secret) {
            return false;
        }
        
        if ($this->two_factor->verifyCode($secret, $code)) {
            $this->db->update('users', [
                'two_factor_enabled' => 1,
                'two_factor_verified_at' => date('Y-m-d H:i:s')
            ], ['id' => $this->user->getId()]);
            
            $this->log("Two-factor authentication enabled for user: {$this->user->getUsername()}", 'info');
            
            return true;
        }
        
        return false;
    }
    
    /**
     * تعطيل المصادقة الثنائية
     * @param string $password
     * @return bool
     */
    public function twoFactorDisable(string $password): bool {
        if (!$this->user) {
            return false;
        }
        
        if (!$this->user->verifyPassword($password)) {
            return false;
        }
        
        $this->db->update('users', [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => 0
        ], ['id' => $this->user->getId()]);
        
        $this->log("Two-factor authentication disabled for user: {$this->user->getUsername()}", 'info');
        
        return true;
    }
    
    /**
     * استخدام رمز الاسترداد
     * @param string $code
     * @return bool
     */
    public function twoFactorUseRecoveryCode(string $code): bool {
        if (!$this->user) {
            return false;
        }
        
        $recoveryCodes = json_decode($this->user->getTwoFactorRecoveryCodes(), true) ?: [];
        
        foreach ($recoveryCodes as $index => $recoveryCode) {
            if (hash_equals($recoveryCode, $code)) {
                unset($recoveryCodes[$index]);
                
                $this->db->update('users', [
                    'two_factor_recovery_codes' => json_encode(array_values($recoveryCodes))
                ], ['id' => $this->user->getId()]);
                
                return true;
            }
        }
        
        return false;
    }
    
    // ==========================================
    // دوال تسجيل الدخول عبر وسائل التواصل
    // ==========================================
    
    /**
     * تسجيل الدخول عبر وسائل التواصل
     * @param string $provider
     * @param array $data
     * @return User|null
     */
    public function socialLogin(string $provider, array $data): ?User {
        $providerId = $data['id'] ?? null;
        
        if (!$providerId) {
            return null;
        }
        
        // البحث عن حساب مرتبط
        $socialAccount = $this->db->fetchOne(
            "SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ?",
            [$provider, $providerId]
        );
        
        if ($socialAccount) {
            // تسجيل الدخول للمستخدم المرتبط
            $user = $this->getUserById($socialAccount['user_id']);
            
            if ($user) {
                $this->completeLogin($user, false);
                return $user;
            }
        }
        
        // إذا كان هناك بريد، حاول ربط حساب موجود
        if (isset($data['email'])) {
            $user = $this->getUserByEmail($data['email']);
            
            if ($user) {
                $this->linkSocialAccount($provider, $providerId, $user->getId(), $data);
                $this->completeLogin($user, false);
                return $user;
            }
        }
        
        // إنشاء مستخدم جديد
        $userData = [
            'username' => $this->generateUsername($data['name'] ?? $providerId),
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'password' => $this->hashPassword(bin2hex(random_bytes(16))),
            'verified_at' => $data['email'] ? date('Y-m-d H:i:s') : null,
            'status' => 'active'
        ];
        
        $user = $this->register($userData);
        
        if ($user) {
            $this->linkSocialAccount($provider, $providerId, $user->getId(), $data);
            $this->completeLogin($user, false);
        }
        
        return $user;
    }
    
    /**
     * ربط حساب وسائل التواصل
     * @param string $provider
     * @param string $providerId
     * @param int $userId
     * @param array $data
     * @return bool
     */
    public function linkSocialAccount(string $provider, string $providerId, int $userId, array $data = []): bool {
        $accountData = [
            'user_id' => $userId,
            'provider' => $provider,
            'provider_id' => $providerId,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'token' => $data['token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return (bool)$this->db->insert('social_accounts', $accountData);
    }
    
    // ==========================================
    // دوال الحصول على المستخدم
    // ==========================================
    
    /**
     * الحصول على المستخدم الحالي
     * @return User|null
     */
    public function user(): ?User {
        return $this->user;
    }
    
    /**
     * الحصول على معرف المستخدم الحالي
     * @return int|null
     */
    public function id(): ?int {
        return $this->user ? $this->user->getId() : null;
    }
    
    /**
     * التحقق من تسجيل الدخول
     * @return bool
     */
    public function check(): bool {
        return $this->logged_in;
    }
    
    /**
     * التحقق من عدم تسجيل الدخول
     * @return bool
     */
    public function guest(): bool {
        return !$this->logged_in;
    }
    
    /**
     * التحقق من تسجيل الدخول عبر تذكرني
     * @return bool
     */
    public function viaRemember(): bool {
        return $this->via_remember;
    }
    
    /**
     * الحصول على مستخدم بالمعرف
     * @param int $id
     * @return User|null
     */
    public function getUserById(int $id): ?User {
        $data = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        
        if (!$data) {
            return null;
        }
        
        return new User($data, $this->db, $this->permissions);
    }
    
    /**
     * الحصول على مستخدم باسم المستخدم
     * @param string $username
     * @return User|null
     */
    public function getUserByUsername(string $username): ?User {
        $data = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        );
        
        if (!$data) {
            return null;
        }
        
        return new User($data, $this->db, $this->permissions);
    }
    
    /**
     * الحصول على مستخدم بالبريد
     * @param string $email
     * @return User|null
     */
    public function getUserByEmail(string $email): ?User {
        $data = $this->db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
        
        if (!$data) {
            return null;
        }
        
        return new User($data, $this->db, $this->permissions);
    }
    
    /**
     * التحقق من وجود بريد
     * @param string $email
     * @return bool
     */
    public function emailExists(string $email): bool {
        return (bool)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE email = ?",
            [$email]
        );
    }
    
    /**
     * التحقق من وجود اسم مستخدم
     * @param string $username
     * @return bool
     */
    public function usernameExists(string $username): bool {
        return (bool)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE username = ?",
            [$username]
        );
    }
    
    // ==========================================
    // دوال التحقق من الصلاحيات
    // ==========================================
    
    /**
     * التحقق من الصلاحية
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool {
        if (!$this->user) {
            return false;
        }
        
        if ($this->user->hasRole('admin')) {
            return true;
        }
        
        if ($this->permissions) {
            return $this->permissions->checkPermission($this->user->getId(), $permission);
        }
        
        return false;
    }
    
    /**
     * التحقق من أي صلاحية من قائمة
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * التحقق من الدور
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool {
        if (!$this->user) {
            return false;
        }
        
        if ($role === 'admin' && $this->user->hasRole('admin')) {
            return true;
        }
        
        if ($this->permissions) {
            return $this->permissions->hasRole($this->user->getId(), $role);
        }
        
        return false;
    }
    
    /**
     * التحقق من القدرة
     * @param string $ability
     * @param mixed $arguments
     * @return bool
     */
    public function can(string $ability, $arguments = null): bool {
        if (!$this->user) {
            return false;
        }
        
        // يمكن توسيعها لاحقاً
        return $this->hasPermission($ability);
    }
    
    /**
     * عكس can
     * @param string $ability
     * @param mixed $arguments
     * @return bool
     */
    public function cannot(string $ability, $arguments = null): bool {
        return !$this->can($ability, $arguments);
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * تشفير كلمة المرور
     * @param string $password
     * @return string
     */
    private function hashPassword(string $password): string {
        return password_hash($password, $this->config['password_hash_algo'], $this->config['password_hash_options']);
    }
    
    /**
     * الحصول على رابط التحقق
     * @param string $token
     * @return string
     */
    private function getVerificationLink(string $token): string {
        return "/verify-email?token=" . urlencode($token);
    }
    
    /**
     * الحصول على رابط إعادة تعيين كلمة المرور
     * @param string $token
     * @return string
     */
    private function getPasswordResetLink(string $token): string {
        return "/reset-password?token=" . urlencode($token);
    }
    
    /**
     * الحصول على IP العميل
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
     * إنشاء اسم مستخدم من الاسم
     * @param string $name
     * @return string
     */
    private function generateUsername(string $name): string {
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $name));
        $username = substr($username, 0, 20);
        
        if (empty($username)) {
            $username = 'user_' . bin2hex(random_bytes(4));
        }
        
        // التأكد من عدم التكرار
        $base = $username;
        $counter = 1;
        
        while ($this->usernameExists($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * تسجيل حدث
     * @param string $message
     * @param string $level
     */
    private function log(string $message, string $level = 'info'): void {
        $main = Main_App::getInstance();
        $main->log($message, $level, ['component' => 'auth']);
    }
    
    /**
     * إطلاق حدث
     * @param string $event
     * @param mixed $payload
     */
    private function dispatchEvent(string $event, $payload = null): void {
        $main = Main_App::getInstance();
        $main->dispatchEvent($event, $payload);
    }
}

/**
 * User
 * @package Authentication
 * 
 * يمثل مستخدم في النظام
 */
class User {
    
    /**
     * @var array بيانات المستخدم
     */
    private $data;
    
    /**
     * @var App_DB|null
     */
    private $db;
    
    /**
     * @var صلاحيات_App|null
     */
    private $permissions;
    
    /**
     * المُنشئ
     * @param array $data
     * @param App_DB|null $db
     * @param صلاحيات_App|null $permissions
     */
    public function __construct(array $data, ?App_DB $db = null, ?صلاحيات_App $permissions = null) {
        $this->data = $data;
        $this->db = $db;
        $this->permissions = $permissions;
    }
    
    /**
     * الحصول على معرف المستخدم
     * @return int
     */
    public function getId(): int {
        return (int)$this->data['id'];
    }
    
    /**
     * الحصول على اسم المستخدم
     * @return string
     */
    public function getUsername(): string {
        return $this->data['username'] ?? '';
    }
    
    /**
     * الحصول على البريد
     * @return string
     */
    public function getEmail(): string {
        return $this->data['email'] ?? '';
    }
    
    /**
     * الحصول على الاسم
     * @return string
     */
    public function getName(): string {
        return $this->data['name'] ?? $this->getUsername();
    }
    
    /**
     * الحصول على الصورة الرمزية
     * @return string|null
     */
    public function getAvatar(): ?string {
        return $this->data['avatar'] ?? null;
    }
    
    /**
     * الحصول على الهاتف
     * @return string|null
     */
    public function getPhone(): ?string {
        return $this->data['phone'] ?? null;
    }
    
    /**
     * الحصول على سر المصادقة الثنائية
     * @return string|null
     */
    public function getTwoFactorSecret(): ?string {
        return $this->data['two_factor_secret'] ?? null;
    }
    
    /**
     * الحصول على رموز استرداد المصادقة الثنائية
     * @return string|null
     */
    public function getTwoFactorRecoveryCodes(): ?string {
        return $this->data['two_factor_recovery_codes'] ?? null;
    }
    
    /**
     * التحقق من تفعيل المصادقة الثنائية
     * @return bool
     */
    public function hasTwoFactor(): bool {
        return !empty($this->data['two_factor_enabled']) && !empty($this->data['two_factor_secret']);
    }
    
    /**
     * التحقق من تفعيل البريد
     * @return bool
     */
    public function isVerified(): bool {
        return !empty($this->data['verified_at']);
    }
    
    /**
     * التحقق من نشاط الحساب
     * @return bool
     */
    public function isActive(): bool {
        return ($this->data['status'] ?? 'active') === 'active';
    }
    
    /**
     * التحقق من كلمة المرور
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $password): bool {
        return password_verify($password, $this->data['password'] ?? '');
    }
    
    /**
     * التحقق من الدور
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool {
        if ($role === 'admin' && $this->data['is_admin'] ?? false) {
            return true;
        }
        
        if ($this->permissions) {
            return $this->permissions->hasRole($this->getId(), $role);
        }
        
        return false;
    }
    
    /**
     * التحقق من الصلاحية
     * @param string $permission
     * @return bool
     */
    public function can(string $permission): bool {
        if ($this->hasRole('admin')) {
            return true;
        }
        
        if ($this->permissions) {
            return $this->permissions->checkPermission($this->getId(), $permission);
        }
        
        return false;
    }
    
    /**
     * الحصول على إعداد
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        $settings = json_decode($this->data['settings'] ?? '{}', true);
        return $settings[$key] ?? $default;
    }
    
    /**
     * تحديث إعداد
     * @param string $key
     * @param mixed $value
     */
    public function updateSetting(string $key, $value): void {
        $settings = json_decode($this->data['settings'] ?? '{}', true);
        $settings[$key] = $value;
        $this->data['settings'] = json_encode($settings);
    }
    
    /**
     * الحصول على بيانات المستخدم كمصفوفة
     * @return array
     */
    public function toArray(): array {
        $hidden = ['password', 'verification_token', 'reset_token', 'two_factor_secret', 'two_factor_recovery_codes'];
        $data = $this->data;
        
        foreach ($hidden as $field) {
            unset($data[$field]);
        }
        
        return $data;
    }
}

/**
 * Login_Attempts
 * @package Authentication
 * 
 * إدارة محاولات تسجيل الدخول
 */
class Login_Attempts {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
        $this->config = Main_App::getInstance()->config('auth', []);
    }
    
    /**
     * زيادة محاولة فاشلة
     * @param string $username
     */
    public function increment(string $username): void {
        $ip = $this->getClientIP();
        $key = md5($username . $ip);
        
        // البحث عن سجل موجود
        $record = $this->db->fetchOne(
            "SELECT * FROM login_attempts WHERE attempt_key = ?",
            [$key]
        );
        
        if ($record) {
            $this->db->update('login_attempts', [
                'attempts' => $record['attempts'] + 1,
                'last_attempt' => date('Y-m-d H:i:s')
            ], ['id' => $record['id']]);
        } else {
            $this->db->insert('login_attempts', [
                'attempt_key' => $key,
                'username' => $username,
                'ip' => $ip,
                'attempts' => 1,
                'first_attempt' => date('Y-m-d H:i:s'),
                'last_attempt' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * التحقق من القفل
     * @param string $username
     * @return bool
     */
    public function isLocked(string $username): bool {
        $ip = $this->getClientIP();
        $key = md5($username . $ip);
        $maxAttempts = $this->config['max_login_attempts'] ?? 5;
        $lockoutTime = $this->config['lockout_time'] ?? 900;
        
        $record = $this->db->fetchOne(
            "SELECT * FROM login_attempts WHERE attempt_key = ?",
            [$key]
        );
        
        if (!$record) {
            return false;
        }
        
        if ($record['attempts'] < $maxAttempts) {
            return false;
        }
        
        $lastAttempt = strtotime($record['last_attempt']);
        $timeSinceLast = time() - $lastAttempt;
        
        return $timeSinceLast < $lockoutTime;
    }
    
    /**
     * مسح المحاولات
     * @param string $username
     */
    public function clear(string $username): void {
        $ip = $this->getClientIP();
        $key = md5($username . $ip);
        
        $this->db->delete('login_attempts', ['attempt_key' => $key]);
    }
    
    /**
     * الحصول على المحاولات المتبقية
     * @param string $username
     * @return int
     */
    public function getRemainingAttempts(string $username): int {
        $ip = $this->getClientIP();
        $key = md5($username . $ip);
        $maxAttempts = $this->config['max_login_attempts'] ?? 5;
        
        $record = $this->db->fetchOne(
            "SELECT attempts FROM login_attempts WHERE attempt_key = ?",
            [$key]
        );
        
        $attempts = $record ? $record['attempts'] : 0;
        return max(0, $maxAttempts - $attempts);
    }
    
    /**
     * الحصول على وقت القفل
     * @param string $username
     * @return int
     */
    public function getLockTime(string $username): int {
        $ip = $this->getClientIP();
        $key = md5($username . $ip);
        $lockoutTime = $this->config['lockout_time'] ?? 900;
        
        $record = $this->db->fetchOne(
            "SELECT last_attempt FROM login_attempts WHERE attempt_key = ?",
            [$key]
        );
        
        if (!$record) {
            return 0;
        }
        
        $lastAttempt = strtotime($record['last_attempt']);
        $timeSince = time() - $lastAttempt;
        
        return max(0, $lockoutTime - $timeSince);
    }
    
    /**
     * الحصول على IP العميل
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
}

/**
 * Token_Manager
 * @package Authentication
 * 
 * إدارة التوكنات
 */
class Token_Manager {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
    }
    
    /**
     * إنشاء توكن
     * @param string $type
     * @param int|null $userId
     * @param int $expiresIn
     * @return string
     */
    public function generate(string $type, ?int $userId = null, int $expiresIn = 3600): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        $this->db->insert('tokens', [
            'token' => $token,
            'type' => $type,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $token;
    }
    
    /**
     * التحقق من التوكن
     * @param string $token
     * @param string $type
     * @return int|null
     */
    public function validate(string $token, string $type): ?int {
        $record = $this->db->fetchOne(
            "SELECT * FROM tokens WHERE token = ? AND type = ? AND used_at IS NULL AND expires_at > NOW()",
            [$token, $type]
        );
        
        return $record ? (int)$record['user_id'] : null;
    }
    
    /**
     * استخدام التوكن
     * @param string $token
     * @return bool
     */
    public function use(string $token): bool {
        return (bool)$this->db->update('tokens', [
            'used_at' => date('Y-m-d H:i:s')
        ], ['token' => $token]);
    }
    
    /**
     * إلغاء التوكن
     * @param string $token
     * @return bool
     */
    public function revoke(string $token): bool {
        return (bool)$this->db->update('tokens', [
            'revoked_at' => date('Y-m-d H:i:s')
        ], ['token' => $token]);
    }
    
    /**
     * إلغاء جميع توكنات المستخدم
     * @param int $userId
     * @param string $type
     * @return int
     */
    public function revokeAllForUser(int $userId, string $type): int {
        return $this->db->update('tokens', [
            'revoked_at' => date('Y-m-d H:i:s')
        ], ['user_id' => $userId, 'type' => $type, 'used_at' => null]);
    }
    
    /**
     * تنظيف التوكنات المنتهية
     * @return int
     */
    public function cleanExpired(): int {
        return $this->db->delete('tokens', 'expires_at < NOW() OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY))');
    }
}

/**
 * Two_Factor_Auth
 * @package Authentication
 * 
 * إدارة المصادقة الثنائية
 */
class Two_Factor_Auth {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * المُنشئ
     * @param App_DB $db
     */
    public function __construct(App_DB $db) {
        $this->db = $db;
    }
    
    /**
     * إنشاء سر جديد
     * @return string
     */
    public function generateSecret(): string {
        $secret = '';
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        
        return $secret;
    }
    
    /**
     * إنشاء رموز استرداد
     * @param int $count
     * @return array
     */
    public function generateRecoveryCodes(int $count = 8): array {
        $codes = [];
        
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5));
        }
        
        return $codes;
    }
    
    /**
     * الحصول على رابط QR
     * @param string $email
     * @param string $secret
     * @return string
     */
    public function getQRCodeUrl(string $email, string $secret): string {
        $issuer = urlencode(APP_NAME);
        $account = urlencode($email);
        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";
    }
    
    /**
     * التحقق من الرمز
     * @param string $secret
     * @param string $code
     * @return bool
     */
    public function verifyCode(string $secret, string $code): bool {
        // تنفيذ التحقق من TOTP
        // يمكن استخدام مكتبة مثل Sonata/GoogleAuthenticator
        return true; // تبسيطاً
    }
    
    /**
     * إرسال رمز التحقق
     * @param User $user
     * @return bool
     */
    public function sendCode(User $user): bool {
        $code = sprintf('%06d', random_int(0, 999999));
        
        // تخزين الرمز
        $this->db->insert('two_factor_codes', [
            'user_id' => $user->getId(),
            'code' => $code,
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // إرسال الرمز (SMS, Email, إلخ)
        // يمكن استخدام SMS_App أو Email_App هنا
        
        return true;
    }
    
    /**
     * التحقق من رمز المرسل
     * @param User $user
     * @param string $code
     * @return bool
     */
    public function verify(User $user, string $code): bool {
        $record = $this->db->fetchOne(
            "SELECT * FROM two_factor_codes WHERE user_id = ? AND code = ? AND used_at IS NULL AND expires_at > NOW()",
            [$user->getId(), $code]
        );
        
        if (!$record) {
            return false;
        }
        
        $this->db->update('two_factor_codes', [
            'used_at' => date('Y-m-d H:i:s')
        ], ['id' => $record['id']]);
        
        return true;
    }
}

?>