<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

// 支持的设置键列表
$allowedSettings = [
    'burn_after_read' => ['type' => 'boolean', 'default' => '0'],
    'burn_delay_seconds' => ['type' => 'integer', 'default' => '10', 'min' => 5, 'max' => 86400],
    'notification_enabled' => ['type' => 'boolean', 'default' => '1'],
    'sound_enabled' => ['type' => 'boolean', 'default' => '1'],
];

switch ($action) {
    case 'get':
        // 获取设置
        $settingKey = trim($_GET['key'] ?? '');
        
        if (!empty($settingKey)) {
            // 获取单个设置
            if (!isset($allowedSettings[$settingKey])) {
                jsonResponse(false, '不支持的设置项');
            }
            
            $stmt = $pdo->prepare("
                SELECT setting_value FROM user_settings 
                WHERE user_id = ? AND setting_key = ?
            ");
            $stmt->execute([$currentUser['id'], $settingKey]);
            $setting = $stmt->fetch();
            
            if ($setting) {
                $value = $setting['setting_value'];
            } else {
                $value = $allowedSettings[$settingKey]['default'];
            }
            
            // 类型转换
            if ($allowedSettings[$settingKey]['type'] === 'boolean') {
                $value = ($value === '1' || $value === 1 || $value === true);
            } elseif ($allowedSettings[$settingKey]['type'] === 'integer') {
                $value = intval($value);
            }
            
            jsonResponse(true, '获取成功', [
                'key' => $settingKey,
                'value' => $value
            ]);
            
        } else {
            // 获取所有设置
            $stmt = $pdo->prepare("
                SELECT setting_key, setting_value FROM user_settings 
                WHERE user_id = ?
            ");
            $stmt->execute([$currentUser['id']]);
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 合并默认值
            $result = [];
            foreach ($allowedSettings as $key => $config) {
                if (isset($settings[$key])) {
                    $value = $settings[$key];
                } else {
                    $value = $config['default'];
                }
                
                // 类型转换
                if ($config['type'] === 'boolean') {
                    $value = ($value === '1' || $value === 1 || $value === true);
                } elseif ($config['type'] === 'integer') {
                    $value = intval($value);
                }
                
                $result[$key] = $value;
            }
            
            jsonResponse(true, '获取成功', $result);
        }
        break;
        
    case 'set':
        // 设置单个设置项
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settingKey = trim($input['key'] ?? '');
        $settingValue = $input['value'] ?? '';
        
        if (empty($settingKey)) {
            jsonResponse(false, '参数错误');
        }
        
        if (!isset($allowedSettings[$settingKey])) {
            jsonResponse(false, '不支持的设置项');
        }
        
        $config = $allowedSettings[$settingKey];
        
        // 验证和转换值
        if ($config['type'] === 'boolean') {
            $settingValue = ($settingValue === true || $settingValue === '1' || $settingValue === 1) ? '1' : '0';
        } elseif ($config['type'] === 'integer') {
            $settingValue = intval($settingValue);
            if (isset($config['min']) && $settingValue < $config['min']) {
                $settingValue = $config['min'];
            }
            if (isset($config['max']) && $settingValue > $config['max']) {
                $settingValue = $config['max'];
            }
            $settingValue = strval($settingValue);
        }
        
        try {
            // 使用 INSERT ON DUPLICATE KEY UPDATE
            $stmt = $pdo->prepare("
                INSERT INTO user_settings (user_id, setting_key, setting_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$currentUser['id'], $settingKey, $settingValue, $settingValue]);
            
            // 特殊处理阅后即焚设置
            if ($settingKey === 'burn_after_read') {
                $isEnabled = ($settingValue === '1');
                if ($isEnabled) {
                    jsonResponse(true, '阅后即焚已开启，消息将在您阅读后 ' . ($_SESSION['burn_delay_seconds'] ?? 10) . ' 秒自动焚毁');
                } else {
                    jsonResponse(true, '阅后即焚已关闭，消息将不会自动焚毁');
                }
            }
            
            jsonResponse(true, '设置成功');
            
        } catch (Exception $e) {
            jsonResponse(false, '设置失败: ' . $e->getMessage());
        }
        break;
        
    case 'batch_set':
        // 批量设置
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = $input['settings'] ?? [];
        
        if (!is_array($settings) || empty($settings)) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            $successCount = 0;
            $failedKeys = [];
            
            foreach ($settings as $key => $value) {
                if (!isset($allowedSettings[$key])) {
                    $failedKeys[] = $key;
                    continue;
                }
                
                $config = $allowedSettings[$key];
                
                // 验证和转换值
                if ($config['type'] === 'boolean') {
                    $processedValue = ($value === true || $value === '1' || $value === 1) ? '1' : '0';
                } elseif ($config['type'] === 'integer') {
                    $processedValue = intval($value);
                    if (isset($config['min']) && $processedValue < $config['min']) {
                        $processedValue = $config['min'];
                    }
                    if (isset($config['max']) && $processedValue > $config['max']) {
                        $processedValue = $config['max'];
                    }
                    $processedValue = strval($processedValue);
                } else {
                    $processedValue = strval($value);
                }
                
                // 插入或更新
                $stmt = $pdo->prepare("
                    INSERT INTO user_settings (user_id, setting_key, setting_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$currentUser['id'], $key, $processedValue, $processedValue]);
                $successCount++;
            }
            
            $pdo->commit();
            
            if (empty($failedKeys)) {
                jsonResponse(true, '所有设置已保存', [
                    'success_count' => $successCount
                ]);
            } else {
                jsonResponse(true, '部分设置已保存', [
                    'success_count' => $successCount,
                    'failed_keys' => $failedKeys
                ]);
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '设置失败: ' . $e->getMessage());
        }
        break;
        
    case 'reset':
        // 重置所有设置为默认值
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        try {
            $stmt = $pdo->prepare("
                DELETE FROM user_settings WHERE user_id = ?
            ");
            $stmt->execute([$currentUser['id']]);
            
            jsonResponse(true, '所有设置已重置为默认值');
            
        } catch (Exception $e) {
            jsonResponse(false, '重置失败: ' . $e->getMessage());
        }
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
