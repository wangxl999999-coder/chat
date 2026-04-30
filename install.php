<?php
/**
 * 阅后即焚 - 安装向导
 */

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? 'chat_app');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';
    $siteUrl = trim($_POST['site_url'] ?? '');
    
    // 验证输入
    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = '请填写完整的数据库信息';
    } else {
        // 测试数据库连接
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            
            // 保存配置
            $configContent = "<?php\n// 数据库配置 - 自动生成\n";
            $configContent .= "define('DB_HOST', '{$dbHost}');\n";
            $configContent .= "define('DB_NAME', '{$dbName}');\n";
            $configContent .= "define('DB_USER', '{$dbUser}');\n";
            $configContent .= "define('DB_PASS', '{$dbPass}');\n";
            $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
            
            // 读取原database.php的其他内容
            $originalFile = __DIR__ . '/config/database.php';
            $originalContent = file_get_contents($originalFile);
            
            // 替换配置部分
            $pattern = '/^<\?php.*?define\(\'DB_CHARSET\'[^;]+;\s*/s';
            $newContent = preg_replace($pattern, $configContent, $originalContent);
            
            file_put_contents($originalFile, $newContent);
            
            // 更新config.php中的SITE_URL
            if (!empty($siteUrl)) {
                $configFile = __DIR__ . '/config/config.php';
                $configContent = file_get_contents($configFile);
                $configContent = preg_replace(
                    "/define\('SITE_URL',\s*'[^']+'\);/",
                    "define('SITE_URL', '{$siteUrl}');",
                    $configContent
                );
                file_put_contents($configFile, $configContent);
            }
            
            // 初始化数据库
            require_once __DIR__ . '/config/database.php';
            
            if (initDB()) {
                $success = '安装成功！数据库已初始化完成。';
                $step = 3;
            } else {
                $error = '数据库初始化失败';
            }
            
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阅后即焚 - 安装向导</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .install-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }
        .install-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .install-header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .install-header p {
            font-size: 14px;
            opacity: 0.9;
        }
        .install-steps {
            display: flex;
            padding: 20px 30px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            right: -30%;
            width: 60%;
            height: 2px;
            background: #e5e7eb;
        }
        .step-item.completed:not(:last-child)::after {
            background: #6366f1;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: 600;
            font-size: 14px;
        }
        .step-item.active .step-number,
        .step-item.completed .step-number {
            background: #6366f1;
            color: white;
        }
        .step-label {
            font-size: 13px;
            color: #6b7280;
        }
        .step-item.active .step-label {
            color: #6366f1;
            font-weight: 500;
        }
        .install-body {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .form-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 24px;
            font-size: 15px;
            font-weight: 500;
            color: white;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn:active {
            transform: scale(0.98);
        }
        .btn-block {
            width: 100%;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .welcome-content {
            text-align: center;
            padding: 20px 0;
        }
        .welcome-icon {
            width: 80px;
            height: 80px;
            background: #ecfdf5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .welcome-icon svg {
            width: 40px;
            height: 40px;
            color: #10b981;
        }
        .welcome-content h3 {
            font-size: 20px;
            color: #1f2937;
            margin-bottom: 12px;
        }
        .welcome-content p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 8px;
        }
        .links-group {
            margin-top: 24px;
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .links-group a {
            color: #6366f1;
            text-decoration: none;
            font-size: 14px;
        }
        .links-group a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1>阅后即焚</h1>
            <p>即时通信应用安装向导</p>
        </div>
        
        <div class="install-steps">
            <div class="step-item <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">
                <div class="step-number"><?php echo $step > 1 ? '✓' : '1'; ?></div>
                <div class="step-label">欢迎</div>
            </div>
            <div class="step-item <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">
                <div class="step-number"><?php echo $step > 2 ? '✓' : '2'; ?></div>
                <div class="step-label">配置</div>
            </div>
            <div class="step-item <?php echo $step >= 3 ? 'active' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">完成</div>
            </div>
        </div>
        
        <div class="install-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($step === 1): ?>
                <div class="welcome-content">
                    <h3>欢迎使用阅后即焚</h3>
                    <p>感谢您选择阅后即焚即时通信应用！</p>
                    <p>本应用支持：用户注册登录、好友系统、<br>文字/图片/表情消息、阅后即焚、管理后台</p>
                    <p style="margin-top: 16px; font-size: 12px; color: #9ca3af;">
                        请确保您的环境已满足以下要求：<br>
                        PHP 7.0+、MySQL 5.7+、PDO扩展
                    </p>
                </div>
                <button class="btn btn-block" onclick="window.location.href='?step=2'">开始安装</button>
                
            <?php elseif ($step === 2): ?>
                <form method="POST" action="?step=2">
                    <div class="form-group">
                        <label class="form-label">数据库主机</label>
                        <input type="text" class="form-input" name="db_host" value="localhost" required>
                        <div class="form-hint">一般为 localhost 或 127.0.0.1</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">数据库名</label>
                        <input type="text" class="form-input" name="db_name" value="chat_app" required>
                        <div class="form-hint">请确保数据库已创建，或用户有创建数据库权限</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">数据库用户名</label>
                        <input type="text" class="form-input" name="db_user" value="root" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">数据库密码</label>
                        <input type="password" class="form-input" name="db_pass" placeholder="留空表示无密码">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">网站URL（可选）</label>
                        <input type="text" class="form-input" name="site_url" placeholder="例如: http://localhost/chat">
                        <div class="form-hint">用于图片链接等，安装后可在 config/config.php 中修改</div>
                    </div>
                    
                    <button type="submit" class="btn btn-block">下一步</button>
                </form>
                
            <?php elseif ($step === 3): ?>
                <div class="welcome-content">
                    <div class="welcome-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <h3>安装完成！</h3>
                    <p>恭喜，阅后即焚应用已成功安装！</p>
                    <p><strong>默认管理后台账号：</strong></p>
                    <p>用户名: admin | 密码: admin123</p>
                </div>
                
                <div class="links-group">
                    <a href="auth.html">进入前台</a>
                    <a href="admin.html">进入管理后台</a>
                    <a href="README.md">查看文档</a>
                </div>
                
                <div style="margin-top: 24px; padding: 12px; background: #fef3c7; border-radius: 8px; font-size: 12px; color: #92400e;">
                    <strong>安全提示：</strong> 安装完成后请立即删除 install.php 文件，并修改管理后台默认密码。
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
