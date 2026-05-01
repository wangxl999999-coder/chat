<?php
require_once dirname(__DIR__) . '/includes/functions.php';

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 获取请求的接口（支持URL重写和查询参数两种方式）
$module = isset($_GET['module']) ? $_GET['module'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 如果没有查询参数，尝试从URL路径解析（URL重写方式）
if (empty($module)) {
    $request_uri = $_SERVER['REQUEST_URI'];
    $script_name = dirname($_SERVER['SCRIPT_NAME']);
    
    // 移除查询字符串
    if (($pos = strpos($request_uri, '?')) !== false) {
        $request_uri = substr($request_uri, 0, $pos);
    }
    
    // 移除基础路径
    $path = str_replace($script_name, '', $request_uri);
    $path = trim($path, '/');
    
    // 解析路径
    if (!empty($path)) {
        $parts = explode('/', $path);
        $module = $parts[0] ?? '';
        $action = $parts[1] ?? '';
    }
}

// 检查是否需要登录
$protectedModules = ['user', 'friend', 'message', 'conversation', 'moments', 'group', 'favorite', 'settings'];
if (in_array($module, $protectedModules)) {
    if (!isLoggedIn()) {
        jsonResponse(false, '请先登录');
    }
}

// 根据模块路由
switch ($module) {
    case '':
    case 'home':
        jsonResponse(true, '阅后即焚API服务运行中', ['version' => '1.0.0', 'time' => date('Y-m-d H:i:s')]);
        break;
        
    case 'init':
        if (initDB()) {
            jsonResponse(true, '数据库初始化成功');
        }
        break;
        
    case 'auth':
        require_once 'auth.php';
        break;
        
    case 'user':
        require_once 'user.php';
        break;
        
    case 'friend':
        require_once 'friend.php';
        break;
        
    case 'message':
        require_once 'message.php';
        break;
        
    case 'conversation':
        require_once 'conversation.php';
        break;
        
    case 'admin':
        require_once 'admin.php';
        break;
        
    case 'moments':
        require_once 'moments.php';
        break;
        
    case 'group':
        require_once 'group.php';
        break;
        
    case 'favorite':
        require_once 'favorite.php';
        break;
        
    case 'settings':
        require_once 'settings.php';
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
