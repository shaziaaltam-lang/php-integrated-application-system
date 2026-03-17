<?php
/**
 * لوحة_تحكم_المستخدم_App.php
 * @version 1.0.0
 * @package User Dashboard
 * 
 * لوحة تحكم المستخدم المتكاملة
 * توفر واجهة موحدة لإدارة الملف الشخصي، الإعدادات، النشاطات، والإشعارات
 */

// ==========================================
// منع الوصول المباشر
// ==========================================
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * لوحة_تحكم_المستخدم_App
 * @package User Dashboard
 * 
 * الكلاس الرئيسي للوحة تحكم المستخدم
 */
class package_User_Dashboard_app {
    
    // ==========================================
    // الخصائص
    // ==========================================
    
    /**
     * @var Auth_App نظام المصادقة
     */
    private $auth;
    
    /**
     * @var CRUD_App نظام CRUD
     */
    private $crud;
    
    /**
     * @var الإشعارات_App نظام الإشعارات
     */
    private $notifications;
    
    /**
     * @var User_Settings إعدادات المستخدم
     */
    private $settings;
    
    /**
     * @var Activity_Log سجل النشاطات
     */
    private $activities;
    
    /**
     * @var array الأدوات (Widgets)
     */
    private $widgets = [];
    
    /**
     * @var array الإجراءات السريعة
     */
    private $quickActions = [];
    
    /**
     * @var array القوائم
     */
    private $menus = [];
    
    /**
     * @var array إعدادات العرض
     */
    private $displaySettings = [
        'theme' => 'light',
        'language' => 'ar',
        'timezone' => 'UTC',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'items_per_page' => 20,
        'sidebar_collapsed' => false,
        'notifications_sound' => true
    ];
    
    /**
     * @var array إحصائيات سريعة
     */
    private $quickStats = [];
    
    // ==========================================
    // البناء والتهيئة
    // ==========================================
    
    /**
     * المُنشئ
     * @param Auth_App $auth
     * @param CRUD_App $crud
     * @param الإشعارات_App $notifications
     */
    public function __construct(Auth_App $auth, CRUD_App $crud, الإشعارات_App $notifications) {
        $this->auth = $auth;
        $this->crud = $crud;
        $this->notifications = $notifications;
        
        $this->settings = new User_Settings($auth->db(), $auth);
        $this->activities = new Activity_Log($auth->db(), $auth);
        
        $this->initialize();
        $this->loadUserSettings();
        $this->registerDefaultWidgets();
        $this->registerDefaultQuickActions();
        $this->registerDefaultMenus();
    }
    
    /**
     * تهيئة اللوحة
     */
    private function initialize(): void {
        // التأكد من تسجيل الدخول
        if (!$this->auth->check()) {
            throw new Exception('User not authenticated');
        }
        
        $this->initializeTables();
    }
    
    /**
     * تهيئة الجداول المطلوبة
     */
    private function initializeTables(): void {
        $db = $this->auth->db();
        
        // جدول إعدادات المستخدمين
        if (!$db->tableExists('user_settings')) {
            $db->query("
                CREATE TABLE user_settings (
                    user_id INT PRIMARY KEY,
                    theme VARCHAR(20) DEFAULT 'light',
                    language VARCHAR(10) DEFAULT 'ar',
                    timezone VARCHAR(50) DEFAULT 'UTC',
                    date_format VARCHAR(20) DEFAULT 'Y-m-d',
                    time_format VARCHAR(20) DEFAULT 'H:i',
                    items_per_page INT DEFAULT 20,
                    sidebar_collapsed BOOLEAN DEFAULT FALSE,
                    notifications_sound BOOLEAN DEFAULT TRUE,
                    dashboard_widgets JSON,
                    quick_actions JSON,
                    preferences JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول تفضيلات المستخدمين
        if (!$db->tableExists('user_preferences')) {
            $db->query("
                CREATE TABLE user_preferences (
                    user_id INT,
                    `key` VARCHAR(100),
                    value TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, `key`),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول النشاطات
        if (!$db->tableExists('user_activities')) {
            $db->query("
                CREATE TABLE user_activities (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    action VARCHAR(100),
                    details TEXT,
                    ip VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_created (created_at),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول الأدوات المخصصة
        if (!$db->tableExists('user_widgets')) {
            $db->query("
                CREATE TABLE user_widgets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    widget_id VARCHAR(50),
                    title VARCHAR(255),
                    type VARCHAR(50),
                    settings JSON,
                    position INT,
                    size VARCHAR(20),
                    enabled BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        // جدول المفضلة
        if (!$db->tableExists('user_favorites')) {
            $db->query("
                CREATE TABLE user_favorites (
                    user_id INT,
                    item_type VARCHAR(50),
                    item_id INT,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, item_type, item_id),
                    INDEX idx_type (item_type),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
    
    /**
     * تحميل إعدادات المستخدم
     */
    private function loadUserSettings(): void {
        $userId = $this->auth->id();
        
        $settings = $this->auth->db()->fetchOne(
            "SELECT * FROM user_settings WHERE user_id = ?",
            [$userId]
        );
        
        if ($settings) {
            $this->displaySettings = array_merge($this->displaySettings, $settings);
        } else {
            // إنشاء إعدادات افتراضية للمستخدم الجديد
            $this->saveUserSettings($this->displaySettings);
        }
    }
    
    /**
     * تسجيل الأدوات الافتراضية
     */
    private function registerDefaultWidgets(): void {
        $this->registerWidget(new Dashboard_Widget(
            'stats',
            'الإحصائيات السريعة',
            'statistics',
            function() {
                return $this->getQuickStats();
            }
        ));
        
        $this->registerWidget(new Dashboard_Widget(
            'recent_activities',
            'آخر النشاطات',
            'list',
            function($limit = 10) {
                return $this->activities->getRecent($limit);
            }
        ));
        
        $this->registerWidget(new Dashboard_Widget(
            'notifications',
            'الإشعارات',
            'notifications',
            function($limit = 5) {
                return $this->notifications->getUserNotifications(
                    $this->auth->id(),
                    ['limit' => $limit, 'status' => 'sent']
                );
            }
        ));
        
        $this->registerWidget(new Dashboard_Widget(
            'calendar',
            'التقويم',
            'calendar',
            function() {
                return $this->getCalendarEvents();
            }
        ));
        
        $this->registerWidget(new Dashboard_Widget(
            'quick_actions',
            'إجراءات سريعة',
            'actions',
            function() {
                return $this->getQuickActions();
            }
        ));
    }
    
    /**
     * تسجيل الإجراءات السريعة الافتراضية
     */
    private function registerDefaultQuickActions(): void {
        $this->registerQuickAction(new Quick_Action(
            'profile',
            'تعديل الملف الشخصي',
            'user',
            '/profile/edit',
            'profile.edit',
            function() {
                return $this->showEditProfile();
            }
        ));
        
        $this->registerQuickAction(new Quick_Action(
            'settings',
            'الإعدادات',
            'cog',
            '/settings',
            'settings.view',
            function() {
                return $this->showSettings();
            }
        ));
        
        $this->registerQuickAction(new Quick_Action(
            'notifications',
            'عرض الإشعارات',
            'bell',
            '/notifications',
            'notifications.view',
            function() {
                return $this->showNotifications();
            }
        ));
        
        $this->registerQuickAction(new Quick_Action(
            'logout',
            'تسجيل الخروج',
            'sign-out',
            '/logout',
            null,
            function() {
                $this->auth->logout();
                return redirect('/login');
            }
        ));
    }
    
    /**
     * تسجيل القوائم الافتراضية
     */
    private function registerDefaultMenus(): void {
        $this->menus = [
            'main' => [
                [
                    'id' => 'dashboard',
                    'title' => 'الرئيسية',
                    'icon' => 'dashboard',
                    'url' => '/dashboard',
                    'permission' => null
                ],
                [
                    'id' => 'profile',
                    'title' => 'الملف الشخصي',
                    'icon' => 'user',
                    'url' => '/profile',
                    'permission' => null
                ],
                [
                    'id' => 'settings',
                    'title' => 'الإعدادات',
                    'icon' => 'cog',
                    'url' => '/settings',
                    'permission' => null
                ]
            ],
            'user' => [
                [
                    'id' => 'account',
                    'title' => 'الحساب',
                    'icon' => 'user-circle',
                    'url' => '/account',
                    'permission' => null
                ],
                [
                    'id' => 'security',
                    'title' => 'الأمان',
                    'icon' => 'lock',
                    'url' => '/security',
                    'permission' => null
                ],
                [
                    'id' => 'notifications',
                    'title' => 'الإشعارات',
                    'icon' => 'bell',
                    'url' => '/notifications',
                    'permission' => null,
                    'badge' => function() {
                        return $this->notifications->getUnreadCount($this->auth->id());
                    }
                ]
            ]
        ];
    }
    
    // ==========================================
    // عرض لوحة التحكم
    // ==========================================
    
    /**
     * عرض لوحة التحكم
     * @return View
     */
    public function showDashboard(): View {
        $this->logActivity('viewed_dashboard');
        
        $data = [
            'user' => $this->auth->user(),
            'settings' => $this->displaySettings,
            'widgets' => $this->getUserWidgets(),
            'quick_stats' => $this->getQuickStats(),
            'recent_activities' => $this->activities->getRecent(10),
            'unread_notifications' => $this->notifications->getUnreadCount($this->auth->id()),
            'menu' => $this->getMenu('main')
        ];
        
        return new View('dashboard/index', $data);
    }
    
    /**
     * الحصول على بيانات لوحة التحكم
     * @return array
     */
    public function getDashboardData(): array {
        return [
            'user' => $this->auth->user()->toArray(),
            'settings' => $this->displaySettings,
            'stats' => $this->getQuickStats(),
            'widgets' => $this->getUserWidgetsData(),
            'activities' => $this->activities->getRecent(5),
            'notifications' => [
                'unread' => $this->notifications->getUnreadCount($this->auth->id()),
                'latest' => $this->notifications->getUserNotifications($this->auth->id(), ['limit' => 5])
            ],
            'quick_actions' => $this->getQuickActionsData()
        ];
    }
    
    // ==========================================
    // إدارة الملف الشخصي
    // ==========================================
    
    /**
     * عرض صفحة الملف الشخصي
     * @return View
     */
    public function showProfile(): View {
        $this->logActivity('viewed_profile');
        
        $user = $this->auth->user();
        $profileManager = new Profile_Manager($user, $this->auth);
        
        $data = [
            'user' => $user,
            'profile' => $profileManager->getProfile(),
            'completion' => $profileManager->getCompletionPercentage(),
            'missing_fields' => $profileManager->getMissingFields()
        ];
        
        return new View('dashboard/profile', $data);
    }
    
    /**
     * تحديث الملف الشخصي
     * @param array $data
     * @return bool
     */
    public function updateProfile(array $data): bool {
        $profileManager = new Profile_Manager($this->auth->user(), $this->auth);
        $result = $profileManager->updateProfile($data);
        
        if ($result) {
            $this->logActivity('updated_profile', ['fields' => array_keys($data)]);
        }
        
        return $result;
    }
    
    /**
     * عرض صفحة تعديل الملف الشخصي
     * @return View
     */
    public function showEditProfile(): View {
        return new View('dashboard/profile_edit', [
            'user' => $this->auth->user()
        ]);
    }
    
    /**
     * رفع صورة شخصية
     * @param array $file
     * @return string|null
     */
    public function uploadAvatar(array $file): ?string {
        $profileManager = new Profile_Manager($this->auth->user(), $this->auth);
        $result = $profileManager->uploadAvatar($file);
        
        if ($result) {
            $this->logActivity('updated_avatar');
        }
        
        return $result;
    }
    
    /**
     * إزالة الصورة الشخصية
     * @return bool
     */
    public function removeAvatar(): bool {
        $profileManager = new Profile_Manager($this->auth->user(), $this->auth);
        $result = $profileManager->removeAvatar();
        
        if ($result) {
            $this->logActivity('removed_avatar');
        }
        
        return $result;
    }
    
    // ==========================================
    // إدارة الإعدادات
    // ==========================================
    
    /**
     * عرض صفحة الإعدادات
     * @return View
     */
    public function showSettings(): View {
        $this->logActivity('viewed_settings');
        
        return new View('dashboard/settings', [
            'settings' => $this->settings->getAll(),
            'display' => $this->displaySettings,
            'available_themes' => $this->getAvailableThemes(),
            'available_languages' => $this->getAvailableLanguages(),
            'timezones' => $this->getTimezones()
        ]);
    }
    
    /**
     * تحديث الإعدادات
     * @param array $settings
     * @return bool
     */
    public function updateSettings(array $settings): bool {
        $result = $this->settings->updateBatch($settings);
        
        if ($result) {
            $this->displaySettings = array_merge($this->displaySettings, $settings);
            $this->saveUserSettings($this->displaySettings);
            $this->logActivity('updated_settings', ['fields' => array_keys($settings)]);
        }
        
        return $result;
    }
    
    /**
     * الحصول على إعدادات المستخدم
     * @return array
     */
    public function getSettings(): array {
        return $this->settings->getAll();
    }
    
    /**
     * تغيير السمة
     * @param string $theme
     * @return bool
     */
    public function changeTheme(string $theme): bool {
        if (!in_array($theme, array_keys($this->getAvailableThemes()))) {
            return false;
        }
        
        $this->displaySettings['theme'] = $theme;
        $result = $this->saveUserSettings(['theme' => $theme]);
        
        if ($result) {
            $this->logActivity('changed_theme', ['theme' => $theme]);
        }
        
        return $result;
    }
    
    /**
     * تغيير اللغة
     * @param string $language
     * @return bool
     */
    public function changeLanguage(string $language): bool {
        if (!in_array($language, array_keys($this->getAvailableLanguages()))) {
            return false;
        }
        
        $this->displaySettings['language'] = $language;
        $result = $this->saveUserSettings(['language' => $language]);
        
        if ($result) {
            $this->logActivity('changed_language', ['language' => $language]);
        }
        
        return $result;
    }
    
    /**
     * حفظ إعدادات المستخدم
     * @param array $settings
     * @return bool
     */
    private function saveUserSettings(array $settings): bool {
        $userId = $this->auth->id();
        $db = $this->auth->db();
        
        $exists = $db->fetchOne("SELECT user_id FROM user_settings WHERE user_id = ?", [$userId]);
        
        if ($exists) {
            return (bool)$db->update('user_settings', $settings, ['user_id' => $userId]);
        } else {
            $settings['user_id'] = $userId;
            return (bool)$db->insert('user_settings', $settings);
        }
    }
    
    // ==========================================
    // إدارة النشاطات
    // ==========================================
    
    /**
     * عرض صفحة النشاطات
     * @param array $filters
     * @return View
     */
    public function showActivities(array $filters = []): View {
        $this->logActivity('viewed_activities');
        
        $activities = $this->activities->getUserActivities(
            $this->auth->id(),
            $filters
        );
        
        return new View('dashboard/activities', [
            'activities' => $activities,
            'filters' => $filters
        ]);
    }
    
    /**
     * الحصول على نشاطات المستخدم
     * @param array $filters
     * @return array
     */
    public function viewActivities(array $filters = []): array {
        return $this->activities->getUserActivities($this->auth->id(), $filters);
    }
    
    /**
     * تصدير النشاطات
     * @param string $format
     * @return string
     */
    public function exportActivities(string $format = 'csv'): string {
        $activities = $this->activities->getUserActivities($this->auth->id(), ['limit' => 1000]);
        
        $this->logActivity('exported_activities', ['format' => $format]);
        
        return $this->activities->export($activities, $format);
    }
    
    /**
     * تسجيل نشاط
     * @param string $action
     * @param array $details
     */
    private function logActivity(string $action, array $details = []): void {
        $this->activities->log($this->auth->id(), $action, $details);
    }
    
    // ==========================================
    // إدارة الإشعارات
    // ==========================================
    
    /**
     * عرض صفحة الإشعارات
     * @return View
     */
    public function showNotifications(): View {
        $this->logActivity('viewed_notifications');
        
        $notifications = $this->notifications->getUserNotifications($this->auth->id());
        
        return new View('dashboard/notifications', [
            'notifications' => $notifications,
            'unread_count' => $this->notifications->getUnreadCount($this->auth->id())
        ]);
    }
    
    /**
     * الحصول على إشعارات المستخدم
     * @param array $filters
     * @return array
     */
    public function getNotifications(array $filters = []): array {
        return $this->notifications->getUserNotifications($this->auth->id(), $filters);
    }
    
    /**
     * تعيين إشعار كمقروء
     * @param int $notificationId
     * @return bool
     */
    public function markNotificationRead(int $notificationId): bool {
        $result = $this->notifications->markAsRead($notificationId);
        
        if ($result) {
            $this->logActivity('marked_notification_read', ['notification_id' => $notificationId]);
        }
        
        return $result;
    }
    
    /**
     * تعيين جميع الإشعارات كمقروءة
     * @return bool
     */
    public function markAllNotificationsRead(): bool {
        $result = $this->notifications->markAllAsRead($this->auth->id());
        
        if ($result) {
            $this->logActivity('marked_all_notifications_read');
        }
        
        return $result;
    }
    
    /**
     * تحديث إعدادات الإشعارات
     * @param array $settings
     * @return bool
     */
    public function updateNotificationSettings(array $settings): bool {
        return $this->notifications->updateUserSettings($this->auth->id(), $settings);
    }
    
    // ==========================================
    // إدارة الأدوات (Widgets)
    // ==========================================
    
    /**
     * تسجيل أداة
     * @param Dashboard_Widget $widget
     */
    public function registerWidget(Dashboard_Widget $widget): void {
        $this->widgets[$widget->getId()] = $widget;
    }
    
    /**
     * الحصول على أدوات المستخدم
     * @return array
     */
    public function getUserWidgets(): array {
        $userId = $this->auth->id();
        $db = $this->auth->db();
        
        $userWidgets = $db->fetchAll(
            "SELECT * FROM user_widgets WHERE user_id = ? AND enabled = 1 ORDER BY position",
            [$userId]
        );
        
        $widgets = [];
        
        foreach ($userWidgets as $uw) {
            if (isset($this->widgets[$uw['widget_id']])) {
                $widget = clone $this->widgets[$uw['widget_id']];
                $widget->setSettings(json_decode($uw['settings'], true) ?: []);
                $widget->setPosition($uw['position']);
                $widget->setSize($uw['size']);
                $widget->setTitle($uw['title']);
                
                $widgets[] = $widget;
            }
        }
        
        return $widgets;
    }
    
    /**
     * الحصول على بيانات الأدوات
     * @return array
     */
    public function getUserWidgetsData(): array {
        $widgets = $this->getUserWidgets();
        $data = [];
        
        foreach ($widgets as $widget) {
            $data[] = [
                'id' => $widget->getId(),
                'title' => $widget->getTitle(),
                'type' => $widget->getType(),
                'data' => $widget->getData(),
                'size' => $widget->getSize(),
                'position' => $widget->getPosition()
            ];
        }
        
        return $data;
    }
    
    /**
     * إضافة أداة للمستخدم
     * @param string $widgetId
     * @param array $settings
     * @return bool
     */
    public function addWidget(string $widgetId, array $settings = []): bool {
        if (!isset($this->widgets[$widgetId])) {
            return false;
        }
        
        $userId = $this->auth->id();
        $db = $this->auth->db();
        
        // الحصول على آخر position
        $maxPosition = (int)$db->fetchColumn(
            "SELECT MAX(position) FROM user_widgets WHERE user_id = ?",
            [$userId]
        );
        
        $data = [
            'user_id' => $userId,
            'widget_id' => $widgetId,
            'title' => $settings['title'] ?? $this->widgets[$widgetId]->getTitle(),
            'type' => $this->widgets[$widgetId]->getType(),
            'settings' => json_encode($settings),
            'position' => $maxPosition + 1,
            'size' => $settings['size'] ?? 'medium',
            'enabled' => true
        ];
        
        $result = (bool)$db->insert('user_widgets', $data);
        
        if ($result) {
            $this->logActivity('added_widget', ['widget_id' => $widgetId]);
        }
        
        return $result;
    }
    
    /**
     * إزالة أداة من المستخدم
     * @param int $widgetId
     * @return bool
     */
    public function removeWidget(int $widgetId): bool {
        $result = (bool)$this->auth->db()->delete('user_widgets', [
            'id' => $widgetId,
            'user_id' => $this->auth->id()
        ]);
        
        if ($result) {
            $this->logActivity('removed_widget', ['widget_id' => $widgetId]);
        }
        
        return $result;
    }
    
    /**
     * إعادة ترتيب الأدوات
     * @param array $order
     * @return bool
     */
    public function rearrangeWidgets(array $order): bool {
        $userId = $this->auth->id();
        $db = $this->auth->db();
        
        foreach ($order as $position => $widgetId) {
            $db->update('user_widgets',
                ['position' => $position],
                ['id' => $widgetId, 'user_id' => $userId]
            );
        }
        
        $this->logActivity('rearranged_widgets');
        
        return true;
    }
    
    // ==========================================
    // إدارة الإجراءات السريعة
    // ==========================================
    
    /**
     * تسجيل إجراء سريع
     * @param Quick_Action $action
     */
    public function registerQuickAction(Quick_Action $action): void {
        $this->quickActions[$action->getId()] = $action;
    }
    
    /**
     * الحصول على الإجراءات السريعة
     * @return array
     */
    public function getQuickActions(): array {
        $actions = [];
        
        foreach ($this->quickActions as $action) {
            if ($action->canAccess($this->auth->user())) {
                $actions[] = $action;
            }
        }
        
        return $actions;
    }
    
    /**
     * الحصول على بيانات الإجراءات السريعة
     * @return array
     */
    public function getQuickActionsData(): array {
        $actions = $this->getQuickActions();
        $data = [];
        
        foreach ($actions as $action) {
            $data[] = [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'icon' => $action->getIcon(),
                'url' => $action->getUrl()
            ];
        }
        
        return $data;
    }
    
    /**
     * تنفيذ إجراء سريع
     * @param string $actionId
     * @return mixed
     */
    public function executeQuickAction(string $actionId) {
        if (!isset($this->quickActions[$actionId])) {
            return false;
        }
        
        $action = $this->quickActions[$actionId];
        
        if (!$action->canAccess($this->auth->user())) {
            return false;
        }
        
        $this->logActivity('executed_quick_action', ['action_id' => $actionId]);
        
        return $action->execute();
    }
    
    // ==========================================
    // إدارة القوائم
    // ==========================================
    
    /**
     * الحصول على قائمة
     * @param string $menuName
     * @return array
     */
    public function getMenu(string $menuName): array {
        if (!isset($this->menus[$menuName])) {
            return [];
        }
        
        $menu = [];
        
        foreach ($this->menus[$menuName] as $item) {
            // التحقق من الصلاحية
            if (isset($item['permission']) && $item['permission']) {
                if (!$this->auth->can($item['permission'])) {
                    continue;
                }
            }
            
            // إضافة badge إذا وجد
            if (isset($item['badge']) && is_callable($item['badge'])) {
                $item['badge'] = $item['badge']();
            }
            
            $menu[] = $item;
        }
        
        return $menu;
    }
    
    /**
     * إضافة عنصر قائمة
     * @param string $menuName
     * @param array $item
     */
    public function addMenuItem(string $menuName, array $item): void {
        if (!isset($this->menus[$menuName])) {
            $this->menus[$menuName] = [];
        }
        
        $this->menus[$menuName][] = $item;
    }
    
    // ==========================================
    // الإحصائيات
    // ==========================================
    
    /**
     * الحصول على إحصائيات سريعة
     * @return array
     */
    public function getQuickStats(): array {
        $userId = $this->auth->id();
        
        return [
            'notifications' => [
                'unread' => $this->notifications->getUnreadCount($userId),
                'total' => $this->notifications->getUserNotifications($userId, ['count' => true])
            ],
            'activities' => [
                'today' => $this->activities->countToday($userId),
                'week' => $this->activities->countThisWeek($userId)
            ],
            'account' => [
                'created' => $this->auth->user()->getCreatedAt(),
                'last_login' => $this->auth->user()->getLastLogin(),
                'login_count' => $this->auth->user()->getLoginCount()
            ]
        ];
    }
    
    /**
     * الحصول على إحصائيات المستخدم
     * @return array
     */
    public function getUserStats(): array {
        $userId = $this->auth->id();
        $db = $this->auth->db();
        
        return [
            'user' => $this->auth->user()->toArray(),
            'activities' => $this->activities->getStatistics($userId),
            'notifications' => $this->notifications->getStatistics(['user_id' => $userId]),
            'preferences' => $this->settings->getAll()
        ];
    }
    
    /**
     * الحصول على أحداث التقويم
     * @return array
     */
    private function getCalendarEvents(): array {
        // يمكن تخصيصها حسب احتياجات التطبيق
        return [];
    }
    
    // ==========================================
    // التصدير والاستيراد
    // ==========================================
    
    /**
     * تصدير بيانات المستخدم
     * @param string $format
     * @return string
     */
    public function exportData(string $format = 'json'): string {
        $userId = $this->auth->id();
        
        $data = [
            'user' => $this->auth->user()->toArray(),
            'settings' => $this->settings->getAll(),
            'activities' => $this->activities->getUserActivities($userId, ['limit' => 1000]),
            'notifications' => $this->notifications->getUserNotifications($userId, ['limit' => 1000]),
            'exported_at' => date('Y-m-d H:i:s')
        ];
        
        $this->logActivity('exported_data', ['format' => $format]);
        
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            case 'csv':
                return $this->arrayToCsv($data);
            default:
                return json_encode($data);
        }
    }
    
    /**
     * استيراد بيانات المستخدم
     * @param array $file
     * @return bool
     */
    public function importData(array $file): bool {
        // التحقق من الملف
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        $content = file_get_contents($file['tmp_name']);
        $data = json_decode($content, true);
        
        if (!$data || !isset($data['user'])) {
            return false;
        }
        
        // استيراد الإعدادات
        if (isset($data['settings'])) {
            $this->settings->import($data['settings']);
        }
        
        $this->logActivity('imported_data');
        
        return true;
    }
    
    /**
     * تحويل مصفوفة إلى CSV
     * @param array $data
     * @return string
     */
    private function arrayToCsv(array $data): string {
        $output = fopen('php://temp', 'r+');
        
        foreach ($data as $key => $row) {
            if (is_array($row)) {
                fputcsv($output, array_merge([$key], $row));
            }
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    // ==========================================
    // دوال مساعدة
    // ==========================================
    
    /**
     * الحصول على السمات المتاحة
     * @return array
     */
    private function getAvailableThemes(): array {
        return [
            'light' => 'فاتح',
            'dark' => 'داكن',
            'blue' => 'أزرق',
            'green' => 'أخضر',
            'purple' => 'بنفسجي'
        ];
    }
    
    /**
     * الحصول على اللغات المتاحة
     * @return array
     */
    private function getAvailableLanguages(): array {
        return [
            'ar' => 'العربية',
            'en' => 'English',
            'fr' => 'Français',
            'es' => 'Español'
        ];
    }
    
    /**
     * الحصول على المناطق الزمنية
     * @return array
     */
    private function getTimezones(): array {
        return [
            'UTC' => 'UTC',
            'Asia/Riyadh' => 'الرياض',
            'Asia/Dubai' => 'دبي',
            'Asia/Kuwait' => 'الكويت',
            'Africa/Cairo' => 'القاهرة'
        ];
    }
    
    /**
     * التحقق من الوصول لقسم
     * @param string $section
     * @return bool
     */
    public function checkAccess(string $section): bool {
        // التحقق من الصلاحية للقسم
        return $this->auth->can("dashboard.{$section}");
    }
    
    /**
     * الحصول على حالة النظام
     * @return array
     */
    public function getSystemStatus(): array {
        return [
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
            'disk_free' => disk_free_space(ROOT_PATH),
            'disk_total' => disk_total_space(ROOT_PATH)
        ];
    }
}

/**
 * User_Settings
 * @package User Dashboard
 * 
 * إدارة إعدادات المستخدم
 */
class User_Settings {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var Auth_App
     */
    private $auth;
    
    /**
     * @var int
     */
    private $userId;
    
    /**
     * @var array
     */
    private $settings = [];
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param Auth_App $auth
     */
    public function __construct(App_DB $db, Auth_App $auth) {
        $this->db = $db;
        $this->auth = $auth;
        $this->userId = $auth->id();
        $this->load();
    }
    
    /**
     * تحميل الإعدادات
     */
    private function load(): void {
        // إعدادات الجدول الرئيسي
        $mainSettings = $this->db->fetchOne(
            "SELECT * FROM user_settings WHERE user_id = ?",
            [$this->userId]
        );
        
        if ($mainSettings) {
            foreach ($mainSettings as $key => $value) {
                if (!in_array($key, ['user_id', 'created_at', 'updated_at'])) {
                    $this->settings[$key] = $value;
                }
            }
        }
        
        // إعدادات التفضيلات
        $preferences = $this->db->fetchAll(
            "SELECT `key`, value FROM user_preferences WHERE user_id = ?",
            [$this->userId]
        );
        
        foreach ($preferences as $pref) {
            $this->settings['pref_' . $pref['key']] = json_decode($pref['value'], true);
        }
    }
    
    /**
     * الحصول على إعداد
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * تعيين إعداد
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, $value): void {
        $this->settings[$key] = $value;
        $this->save($key, $value);
    }
    
    /**
     * الحصول على جميع الإعدادات
     * @return array
     */
    public function getAll(): array {
        return $this->settings;
    }
    
    /**
     * تحديث مجموعة إعدادات
     * @param array $settings
     * @return bool
     */
    public function updateBatch(array $settings): bool {
        foreach ($settings as $key => $value) {
            $this->settings[$key] = $value;
            $this->save($key, $value);
        }
        
        return true;
    }
    
    /**
     * حفظ إعداد
     * @param string $key
     * @param mixed $value
     */
    private function save(string $key, $value): void {
        // إعدادات عامة
        $generalFields = ['theme', 'language', 'timezone', 'date_format', 'time_format', 
                          'items_per_page', 'sidebar_collapsed', 'notifications_sound'];
        
        if (in_array($key, $generalFields)) {
            $this->db->update('user_settings',
                [$key => $value],
                ['user_id' => $this->userId]
            );
        } else {
            // إعدادات تفضيلات
            $prefKey = str_replace('pref_', '', $key);
            
            $exists = $this->db->fetchOne(
                "SELECT * FROM user_preferences WHERE user_id = ? AND `key` = ?",
                [$this->userId, $prefKey]
            );
            
            if ($exists) {
                $this->db->update('user_preferences',
                    ['value' => json_encode($value)],
                    ['user_id' => $this->userId, 'key' => $prefKey]
                );
            } else {
                $this->db->insert('user_preferences', [
                    'user_id' => $this->userId,
                    'key' => $prefKey,
                    'value' => json_encode($value)
                ]);
            }
        }
    }
    
    /**
     * إعادة التعيين للإعدادات الافتراضية
     */
    public function resetToDefault(): void {
        $default = [
            'theme' => 'light',
            'language' => 'ar',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'items_per_page' => 20,
            'sidebar_collapsed' => false,
            'notifications_sound' => true
        ];
        
        foreach ($default as $key => $value) {
            $this->set($key, $value);
        }
    }
    
    /**
     * تصدير الإعدادات
     * @return array
     */
    public function export(): array {
        return $this->settings;
    }
    
    /**
     * استيراد الإعدادات
     * @param array $data
     * @return bool
     */
    public function import(array $data): bool {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
        
        return true;
    }
    
    /**
     * التحقق من صحة إعداد
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function validate(string $key, $value): bool {
        switch ($key) {
            case 'theme':
                return in_array($value, ['light', 'dark', 'blue', 'green', 'purple']);
                
            case 'language':
                return in_array($value, ['ar', 'en', 'fr', 'es']);
                
            case 'timezone':
                return in_array($value, timezone_identifiers_list());
                
            case 'items_per_page':
                return is_numeric($value) && $value >= 5 && $value <= 100;
                
            case 'sidebar_collapsed':
            case 'notifications_sound':
                return is_bool($value);
                
            default:
                return true;
        }
    }
}

/**
 * Profile_Manager
 * @package User Dashboard
 * 
 * إدارة الملف الشخصي
 */
class Profile_Manager {
    
    /**
     * @var User
     */
    private $user;
    
    /**
     * @var Auth_App
     */
    private $auth;
    
    /**
     * @var Avatar_Manager
     */
    private $avatar;
    
    /**
     * @var array
     */
    private $fields = [
        'name' => ['label' => 'الاسم', 'type' => 'text', 'required' => true],
        'email' => ['label' => 'البريد الإلكتروني', 'type' => 'email', 'required' => true],
        'phone' => ['label' => 'رقم الهاتف', 'type' => 'tel', 'required' => false],
        'bio' => ['label' => 'نبذة عني', 'type' => 'textarea', 'required' => false],
        'birth_date' => ['label' => 'تاريخ الميلاد', 'type' => 'date', 'required' => false],
        'gender' => ['label' => 'الجنس', 'type' => 'select', 'options' => ['male' => 'ذكر', 'female' => 'أنثى'], 'required' => false],
        'address' => ['label' => 'العنوان', 'type' => 'text', 'required' => false],
        'website' => ['label' => 'الموقع الشخصي', 'type' => 'url', 'required' => false]
    ];
    
    /**
     * المُنشئ
     * @param User $user
     * @param Auth_App $auth
     */
    public function __construct(User $user, Auth_App $auth) {
        $this->user = $user;
        $this->auth = $auth;
        $this->avatar = new Avatar_Manager($user->getId());
    }
    
    /**
     * الحصول على الملف الشخصي
     * @return array
     */
    public function getProfile(): array {
        $profile = [];
        
        foreach (array_keys($this->fields) as $field) {
            $profile[$field] = $this->user->getAttribute($field);
        }
        
        $profile['avatar'] = $this->avatar->getUrl();
        $profile['avatar_initials'] = $this->avatar->generateInitials($this->user->getName());
        
        return $profile;
    }
    
    /**
     * تحديث الملف الشخصي
     * @param array $data
     * @return bool
     */
    public function updateProfile(array $data): bool {
        $updateData = [];
        
        foreach ($this->fields as $field => $config) {
            if (isset($data[$field])) {
                if ($this->validateField($field, $data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updateData)) {
            return false;
        }
        
        return $this->auth->updateProfile($updateData);
    }
    
    /**
     * التحقق من صحة حقل
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    private function validateField(string $field, $value): bool {
        $config = $this->fields[$field];
        
        if ($config['required'] && empty($value)) {
            return false;
        }
        
        switch ($config['type']) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
                
            case 'date':
                return strtotime($value) !== false;
                
            case 'select':
                return isset($config['options'][$value]);
                
            default:
                return true;
        }
    }
    
    /**
     * رفع صورة شخصية
     * @param array $file
     * @return string|null
     */
    public function uploadAvatar(array $file): ?string {
        return $this->avatar->upload($file);
    }
    
    /**
     * إزالة الصورة الشخصية
     * @return bool
     */
    public function removeAvatar(): bool {
        return $this->avatar->delete();
    }
    
    /**
     * تغيير كلمة المرور
     * @param string $oldPassword
     * @param string $newPassword
     * @return bool
     */
    public function changePassword(string $oldPassword, string $newPassword): bool {
        return $this->auth->changePassword($oldPassword, $newPassword);
    }
    
    /**
     * تحديث البريد الإلكتروني
     * @param string $email
     * @param string $password
     * @return bool
     */
    public function updateEmail(string $email, string $password): bool {
        if (!$this->auth->confirmPassword($password)) {
            return false;
        }
        
        return $this->auth->updateProfile(['email' => $email]);
    }
    
    /**
     * الحصول على نسبة اكتمال الملف الشخصي
     * @return int
     */
    public function getCompletionPercentage(): int {
        $total = count($this->fields);
        $filled = 0;
        
        foreach (array_keys($this->fields) as $field) {
            if (!empty($this->user->getAttribute($field))) {
                $filled++;
            }
        }
        
        if ($this->avatar->exists()) {
            $filled++;
            $total++;
        }
        
        return (int)($filled / $total * 100);
    }
    
    /**
     * الحصول على الحقول المفقودة
     * @return array
     */
    public function getMissingFields(): array {
        $missing = [];
        
        foreach ($this->fields as $field => $config) {
            if ($config['required'] && empty($this->user->getAttribute($field))) {
                $missing[] = $config['label'];
            }
        }
        
        return $missing;
    }
}

/**
 * Avatar_Manager
 * @package User Dashboard
 * 
 * إدارة الصور الشخصية
 */
class Avatar_Manager {
    
    /**
     * @var int
     */
    private $userId;
    
    /**
     * @var string
     */
    private $path;
    
    /**
     * @var string
     */
    private $url;
    
    /**
     * @var array
     */
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    
    /**
     * @var int
     */
    private $maxSize = 2097152; // 2MB
    
    /**
     * المُنشئ
     * @param int $userId
     */
    public function __construct(int $userId) {
        $this->userId = $userId;
        $this->path = ROOT_PATH . '/uploads/avatars/' . $userId;
        $this->url = '/uploads/avatars/' . $userId;
        
        $this->ensureDirectory();
    }
    
    /**
     * التأكد من وجود المجلد
     */
    private function ensureDirectory(): void {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }
    }
    
    /**
     * رفع صورة
     * @param array $file
     * @return string|null
     */
    public function upload(array $file): ?string {
        // التحقق من الخطأ
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        // التحقق من النوع
        if (!in_array($file['type'], $this->allowedTypes)) {
            return null;
        }
        
        // التحقق من الحجم
        if ($file['size'] > $this->maxSize) {
            return null;
        }
        
        // حذف الصورة القديمة
        $this->delete();
        
        // حفظ الصورة الجديدة
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . time() . '.' . $extension;
        $fullPath = $this->path . '/' . $filename;
        
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
        
        if (move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->resize($fullPath);
            return $this->url . '/' . $filename;
        }
        
        return null;
    }
    
    /**
     * تغيير حجم الصورة
     * @param string $path
     */
    private function resize(string $path): void {
        list($width, $height) = getimagesize($path);
        
        $maxSize = 200;
        
        if ($width <= $maxSize && $height <= $maxSize) {
            return;
        }
        
        $ratio = $width / $height;
        
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = $maxSize / $ratio;
        } else {
            $newHeight = $maxSize;
            $newWidth = $maxSize * $ratio;
        }
        
        // يمكن إضافة مكتبة لمعالجة الصور
        // مثل Intervention Image
    }
    
    /**
     * الحصول على رابط الصورة
     * @return string
     */
    public function getUrl(): string {
        if (!$this->exists()) {
            return $this->generateGravatar();
        }
        
        $files = glob($this->path . '/avatar_*');
        
        if (empty($files)) {
            return $this->generateGravatar();
        }
        
        $latest = array_pop($files);
        return $this->url . '/' . basename($latest);
    }
    
    /**
     * التحقق من وجود صورة
     * @return bool
     */
    public function exists(): bool {
        return is_dir($this->path) && count(glob($this->path . '/avatar_*')) > 0;
    }
    
    /**
     * حذف الصورة
     * @return bool
     */
    public function delete(): bool {
        if (!$this->exists()) {
            return true;
        }
        
        $files = glob($this->path . '/avatar_*');
        
        foreach ($files as $file) {
            unlink($file);
        }
        
        rmdir($this->path);
        
        return true;
    }
    
    /**
     * إنشاء صورة بالأحرف الأولى
     * @param string $name
     * @return string
     */
    public function generateInitials(string $name): string {
        $words = explode(' ', $name);
        $initials = '';
        
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= mb_substr($word, 0, 1);
            }
        }
        
        return strtoupper($initials);
    }
    
    /**
     * إنشاء Gravatar
     * @return string
     */
    private function generateGravatar(): string {
        $email = Main_App::getInstance()->auth->user()->getEmail();
        $hash = md5(strtolower(trim($email)));
        
        return "https://www.gravatar.com/avatar/{$hash}?s=200&d=identicon";
    }
}

/**
 * Activity_Log
 * @package User Dashboard
 * 
 * سجل النشاطات
 */
class Activity_Log {
    
    /**
     * @var App_DB
     */
    private $db;
    
    /**
     * @var Auth_App
     */
    private $auth;
    
    /**
     * المُنشئ
     * @param App_DB $db
     * @param Auth_App $auth
     */
    public function __construct(App_DB $db, Auth_App $auth) {
        $this->db = $db;
        $this->auth = $auth;
    }
    
    /**
     * تسجيل نشاط
     * @param int $userId
     * @param string $action
     * @param array $details
     * @return int
     */
    public function log(int $userId, string $action, array $details = []): int {
        return $this->db->insert('user_activities', [
            'user_id' => $userId,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * الحصول على نشاطات المستخدم
     * @param int $userId
     * @param array $filters
     * @return array
     */
    public function getUserActivities(int $userId, array $filters = []): array {
        $sql = "SELECT * FROM user_activities WHERE user_id = ?";
        $params = [$userId];
        
        if (isset($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }
        
        if (isset($filters['from_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }
        
        if (isset($filters['to_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }
        
        $activities = $this->db->fetchAll($sql, $params);
        
        foreach ($activities as &$activity) {
            $activity['details'] = json_decode($activity['details'], true) ?: [];
        }
        
        return $activities;
    }
    
    /**
     * الحصول على أحدث النشاطات
     * @param int $limit
     * @return array
     */
    public function getRecent(int $limit = 10): array {
        return $this->getUserActivities($this->auth->id(), ['limit' => $limit]);
    }
    
    /**
     * البحث في النشاطات
     * @param string $query
     * @return array
     */
    public function search(string $query): array {
        return $this->db->fetchAll(
            "SELECT * FROM user_activities 
             WHERE user_id = ? AND (action LIKE ? OR details LIKE ?)
             ORDER BY created_at DESC LIMIT 50",
            [$this->auth->id(), "%$query%", "%$query%"]
        );
    }
    
    /**
     * تصدير النشاطات
     * @param array $activities
     * @param string $format
     * @return string
     */
    public function export(array $activities, string $format = 'csv'): string {
        switch ($format) {
            case 'csv':
                $output = fopen('php://temp', 'r+');
                fputcsv($output, ['التاريخ', 'الإجراء', 'التفاصيل', 'IP']);
                
                foreach ($activities as $activity) {
                    fputcsv($output, [
                        $activity['created_at'],
                        $activity['action'],
                        json_encode($activity['details'], JSON_UNESCAPED_UNICODE),
                        $activity['ip']
                    ]);
                }
                
                rewind($output);
                $csv = stream_get_contents($output);
                fclose($output);
                
                return $csv;
                
            case 'json':
                return json_encode($activities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            default:
                return json_encode($activities);
        }
    }
    
    /**
     * تنظيف النشاطات القديمة
     * @param int $days
     * @return int
     */
    public function cleanOld(int $days = 30): int {
        return $this->db->delete('user_activities',
            "created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }
    
    /**
     * الحصول على إحصائيات النشاطات
     * @param int $userId
     * @return array
     */
    public function getStatistics(int $userId): array {
        return [
            'total' => (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM user_activities WHERE user_id = ?",
                [$userId]
            ),
            'today' => (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM user_activities WHERE user_id = ? AND DATE(created_at) = CURDATE()",
                [$userId]
            ),
            'this_week' => (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM user_activities WHERE user_id = ? AND YEARWEEK(created_at) = YEARWEEK(NOW())",
                [$userId]
            ),
            'by_action' => $this->db->fetchAll(
                "SELECT action, COUNT(*) as count FROM user_activities WHERE user_id = ? GROUP BY action ORDER BY count DESC LIMIT 5",
                [$userId]
            )
        ];
    }
    
    /**
     * عدد نشاطات اليوم
     * @param int $userId
     * @return int
     */
    public function countToday(int $userId): int {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_activities WHERE user_id = ? AND DATE(created_at) = CURDATE()",
            [$userId]
        );
    }
    
    /**
     * عدد نشاطات هذا الأسبوع
     * @param int $userId
     * @return int
     */
    public function countThisWeek(int $userId): int {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_activities WHERE user_id = ? AND YEARWEEK(created_at) = YEARWEEK(NOW())",
            [$userId]
        );
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
 * Dashboard_Widget
 * @package User Dashboard
 * 
 * أداة لوحة التحكم
 */
class Dashboard_Widget {
    
    /**
     * @var string
     */
    private $id;
    
    /**
     * @var string
     */
    private $title;
    
    /**
     * @var string
     */
    private $type;
    
    /**
     * @var callable
     */
    private $dataSource;
    
    /**
     * @var int
     */
    private $position;
    
    /**
     * @var string
     */
    private $size;
    
    /**
     * @var array
     */
    private $settings;
    
    /**
     * المُنشئ
     * @param string $id
     * @param string $title
     * @param string $type
     * @param callable $dataSource
     */
    public function __construct(string $id, string $title, string $type, callable $dataSource) {
        $this->id = $id;
        $this->title = $title;
        $this->type = $type;
        $this->dataSource = $dataSource;
        $this->position = 0;
        $this->size = 'medium';
        $this->settings = [];
    }
    
    /**
     * الحصول على معرف الأداة
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
    
    /**
     * الحصول على العنوان
     * @return string
     */
    public function getTitle(): string {
        return $this->title;
    }
    
    /**
     * تعيين العنوان
     * @param string $title
     */
    public function setTitle(string $title): void {
        $this->title = $title;
    }
    
    /**
     * الحصول على النوع
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }
    
    /**
     * الحصول على الموقع
     * @return int
     */
    public function getPosition(): int {
        return $this->position;
    }
    
    /**
     * تعيين الموقع
     * @param int $position
     */
    public function setPosition(int $position): void {
        $this->position = $position;
    }
    
    /**
     * الحصول على الحجم
     * @return string
     */
    public function getSize(): string {
        return $this->size;
    }
    
    /**
     * تعيين الحجم
     * @param string $size
     */
    public function setSize(string $size): void {
        $this->size = $size;
    }
    
    /**
     * الحصول على الإعدادات
     * @return array
     */
    public function getSettings(): array {
        return $this->settings;
    }
    
    /**
     * تعيين الإعدادات
     * @param array $settings
     */
    public function setSettings(array $settings): void {
        $this->settings = $settings;
    }
    
    /**
     * الحصول على البيانات
     * @return mixed
     */
    public function getData() {
        return call_user_func($this->dataSource, $this->settings);
    }
    
    /**
     * تحديث البيانات
     */
    public function refresh(): void {
        // يمكن إعادة تحميل البيانات
    }
    
    /**
     * عرض الأداة
     * @return string
     */
    public function render(): string {
        $data = $this->getData();
        return json_encode([
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'data' => $data,
            'size' => $this->size
        ]);
    }
    
    /**
     * التحقق من إمكانية العرض لمستخدم
     * @param User $user
     * @return bool
     */
    public function canView(User $user): bool {
        // يمكن إضافة صلاحيات مخصصة
        return true;
    }
}

/**
 * Quick_Action
 * @package User Dashboard
 * 
 * إجراء سريع
 */
class Quick_Action {
    
    /**
     * @var string
     */
    private $id;
    
    /**
     * @var string
     */
    private $name;
    
    /**
     * @var string
     */
    private $icon;
    
    /**
     * @var string
     */
    private $url;
    
    /**
     * @var string|null
     */
    private $permission;
    
    /**
     * @var callable
     */
    private $callback;
    
    /**
     * المُنشئ
     * @param string $id
     * @param string $name
     * @param string $icon
     * @param string $url
     * @param string|null $permission
     * @param callable $callback
     */
    public function __construct(string $id, string $name, string $icon, string $url, ?string $permission, callable $callback) {
        $this->id = $id;
        $this->name = $name;
        $this->icon = $icon;
        $this->url = $url;
        $this->permission = $permission;
        $this->callback = $callback;
    }
    
    /**
     * الحصول على معرف الإجراء
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
    
    /**
     * الحصول على الاسم
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * الحصول على الأيقونة
     * @return string
     */
    public function getIcon(): string {
        return $this->icon;
    }
    
    /**
     * الحصول على الرابط
     * @return string
     */
    public function getUrl(): string {
        return $this->url;
    }
    
    /**
     * تنفيذ الإجراء
     * @return mixed
     */
    public function execute() {
        return call_user_func($this->callback);
    }
    
    /**
     * التحقق من إمكانية الوصول
     * @param User $user
     * @return bool
     */
    public function canAccess(User $user): bool {
        if (!$this->permission) {
            return true;
        }
        
        return $user->can($this->permission);
    }
}

/**
 * Notification_Manager
 * @package User Dashboard
 * 
 * مدير الإشعارات للمستخدم
 */
class Notification_Manager {
    
    /**
     * @var الإشعارات_App
     */
    private $notifications;
    
    /**
     * @var int
     */
    private $userId;
    
    /**
     * المُنشئ
     * @param الإشعارات_App $notifications
     * @param int $userId
     */
    public function __construct(الإشعارات_App $notifications, int $userId) {
        $this->notifications = $notifications;
        $this->userId = $userId;
    }
    
    /**
     * الحصول على جميع الإشعارات
     * @return array
     */
    public function getAll(): array {
        return $this->notifications->getUserNotifications($this->userId);
    }
    
    /**
     * الحصول على الإشعارات غير المقروءة
     * @return array
     */
    public function getUnread(): array {
        return $this->notifications->getUserNotifications($this->userId, ['status' => 'sent']);
    }
    
    /**
     * تعيين إشعار كمقروء
     * @param int $notificationId
     * @return bool
     */
    public function markRead(int $notificationId): bool {
        return $this->notifications->markAsRead($notificationId);
    }
    
    /**
     * تعيين الكل كمقروء
     * @return bool
     */
    public function markAllRead(): bool {
        return $this->notifications->markAllAsRead($this->userId);
    }
    
    /**
     * حذف إشعار
     * @param int $notificationId
     * @return bool
     */
    public function delete(int $notificationId): bool {
        return $this->notifications->deleteNotification($notificationId);
    }
    
    /**
     * مسح الكل
     * @return bool
     */
    public function clearAll(): bool {
        return $this->notifications->deleteUserNotifications($this->userId) > 0;
    }
    
    /**
     * الحصول على الإعدادات
     * @return array
     */
    public function getSettings(): array {
        return $this->notifications->getUserSettings($this->userId);
    }
    
    /**
     * تحديث الإعدادات
     * @param array $settings
     * @return bool
     */
    public function updateSettings(array $settings): bool {
        return $this->notifications->updateUserSettings($this->userId, $settings);
    }
    
    /**
     * الاشتراك في نوع
     * @param string $type
     * @return bool
     */
    public function subscribe(string $type): bool {
        $settings = $this->getSettings();
        $types = json_decode($settings['notification_types'] ?? '[]', true);
        
        if (!in_array($type, $types)) {
            $types[] = $type;
            return $this->updateSettings(['notification_types' => $types]);
        }
        
        return true;
    }
    
    /**
     * إلغاء الاشتراك من نوع
     * @param string $type
     * @return bool
     */
    public function unsubscribe(string $type): bool {
        $settings = $this->getSettings();
        $types = json_decode($settings['notification_types'] ?? '[]', true);
        
        $key = array_search($type, $types);
        if ($key !== false) {
            unset($types[$key]);
            return $this->updateSettings(['notification_types' => array_values($types)]);
        }
        
        return true;
    }
}

// ==========================================
// View Class (مبسط)
// ==========================================

/**
 * View
 * @package User Dashboard
 * 
 * تمثيل بسيط للعرض
 */
class View {
    
    /**
     * @var string
     */
    private $template;
    
    /**
     * @var array
     */
    private $data;
    
    /**
     * المُنشئ
     * @param string $template
     * @param array $data
     */
    public function __construct(string $template, array $data = []) {
        $this->template = $template;
        $this->data = $data;
    }
    
    /**
     * عرض القالب
     * @return string
     */
    public function render(): string {
        extract($this->data);
        ob_start();
        
        $templateFile = ROOT_PATH . '/views/' . $this->template . '.php';
        
        if (file_exists($templateFile)) {
            include $templateFile;
        } else {
            echo "View not found: {$this->template}";
        }
        
        return ob_get_clean();
    }
    
    /**
     * تحويل إلى نص
     * @return string
     */
    public function __toString(): string {
        return $this->render();
    }
}

?>