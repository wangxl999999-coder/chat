<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

// 检查用户的阅后即焚设置
function getBurnSetting($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT setting_value FROM user_settings 
        WHERE user_id = ? AND setting_key = 'burn_after_read'
    ");
    $stmt->execute([$userId]);
    $setting = $stmt->fetch();
    
    if ($setting) {
        return $setting['setting_value'] === '1';
    }
    return false;
}

// 获取用户设置的焚毁延迟时间
function getBurnDelay($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT setting_value FROM user_settings 
        WHERE user_id = ? AND setting_key = 'burn_delay_seconds'
    ");
    $stmt->execute([$userId]);
    $setting = $stmt->fetch();
    
    if ($setting) {
        return intval($setting['setting_value']);
    }
    return BURN_DELAY_SECONDS;
}

switch ($action) {
    case 'list':
        // 获取消息列表
        $targetId = intval($_GET['target_id'] ?? 0);
        $lastMsgId = intval($_GET['last_msg_id'] ?? 0);
        $limit = intval($_GET['limit'] ?? 20);
        $chatType = intval($_GET['chat_type'] ?? 1); // 1单聊 2群聊
        
        if ($targetId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $conversation = null;
        $messages = [];
        
        if ($chatType === 2) {
            // 群聊消息
            // 检查是否是群成员
            $stmt = $pdo->prepare("
                SELECT * FROM group_members 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$targetId, $currentUser['id']]);
            
            if ($stmt->rowCount() == 0) {
                jsonResponse(false, '您不是该群成员');
            }
            
            // 获取或创建会话
            $stmt = $pdo->prepare("
                SELECT * FROM conversations 
                WHERE user_id = ? AND target_id = ? AND type = 2
            ");
            $stmt->execute([$currentUser['id'], $targetId]);
            $conversation = $stmt->fetch();
            
            if (!$conversation) {
                $stmt = $pdo->prepare("
                    INSERT INTO conversations (user_id, target_id, type)
                    VALUES (?, ?, 2)
                ");
                $stmt->execute([$currentUser['id'], $targetId]);
                $convId = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("SELECT * FROM conversations WHERE id = ?");
                $stmt->execute([$convId]);
                $conversation = $stmt->fetch();
            }
            
            // 构建查询条件
            $where = "m.group_id = ? AND (m.is_burned = 0 OR m.is_burned IS NULL)";
            $params = [$targetId];
            
            if ($lastMsgId > 0) {
                $where .= " AND m.id < ?";
                $params[] = $lastMsgId;
            }
            
            // 查询群聊消息 - 关联发送者信息和收藏状态
            $stmt = $pdo->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, m.receiver_id, m.group_id, 
                       m.msg_type, m.content, m.is_read, m.is_burned, m.burn_time, m.created_at,
                       u.nickname as sender_nickname, u.avatar as sender_avatar,
                       gm.nickname as group_nickname,
                       f.id as favorite_id
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN group_members gm ON m.sender_id = gm.user_id AND gm.group_id = m.group_id
                LEFT JOIN favorites f ON f.msg_id = m.id AND f.user_id = ?
                WHERE {$where}
                ORDER BY m.id ASC
                LIMIT ?
            ");
            $newParams = [$currentUser['id']];
            foreach ($params as $p) {
                $newParams[] = $p;
            }
            $newParams[] = $limit;
            $stmt->execute($newParams);
            
            $messages = $stmt->fetchAll();
            
            // 添加 is_favorited 字段
            foreach ($messages as &$msg) {
                $msg['is_favorited'] = !empty($msg['favorite_id']) ? 1 : 0;
            }
            
            // 更新群成员的最后阅读消息ID
            if (!empty($messages)) {
                $maxMsgId = max(array_column($messages, 'id'));
                $stmt = $pdo->prepare("
                    UPDATE group_members 
                    SET last_read_msg_id = ? 
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$maxMsgId, $targetId, $currentUser['id']]);
            }
            
            // 清空会话未读数
            $stmt = $pdo->prepare("
                UPDATE conversations 
                SET unread_count = 0 
                WHERE user_id = ? AND target_id = ? AND type = 2
            ");
            $stmt->execute([$currentUser['id'], $targetId]);
            
        } else {
            // 单聊消息
            // 检查是否是好友（双向检查）
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE user_id = ? AND friend_id = ? AND status = 1
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $targetId]);
            
            if ($stmt->rowCount() == 0) {
                jsonResponse(false, '请先添加对方为好友');
            }
            
            // 获取或创建会话（用于后续更新）
            $conversation = getOrCreateConversation($currentUser['id'], $targetId);
            
            // 构建查询条件 - 使用 sender_id 和 receiver_id，这样可以获取双方的所有消息
            $where = "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND (m.is_burned = 0 OR m.is_burned IS NULL)";
            $params = [$currentUser['id'], $targetId, $targetId, $currentUser['id']];
            
            if ($lastMsgId > 0) {
                $where .= " AND m.id < ?";
                $params[] = $lastMsgId;
            }
            
            // 查询消息 - 按时间排序，关联收藏状态
            $stmt = $pdo->prepare("
                SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.msg_type, m.content, 
                       m.is_read, m.is_burned, m.burn_time, m.created_at,
                       u.nickname as sender_nickname, u.avatar as sender_avatar,
                       f.id as favorite_id
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN favorites f ON f.msg_id = m.id AND f.user_id = ?
                WHERE {$where}
                ORDER BY m.id ASC
                LIMIT ?
            ");
            $newParams = [$currentUser['id']];
            foreach ($params as $p) {
                $newParams[] = $p;
            }
            $newParams[] = $limit;
            $stmt->execute($newParams);
            
            $messages = $stmt->fetchAll();
            
            // 添加 is_favorited 字段
            foreach ($messages as &$msg) {
                $msg['is_favorited'] = !empty($msg['favorite_id']) ? 1 : 0;
            }
            
            // 标记对方发送的消息为已读
            $stmt = $pdo->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
            ");
            $stmt->execute([$targetId, $currentUser['id']]);
            
            // 更新会话的未读计数
            $stmt = $pdo->prepare("
                UPDATE conversations 
                SET unread_count = 0 
                WHERE user_id = ? AND target_id = ? AND type = 1
            ");
            $stmt->execute([$currentUser['id'], $targetId]);
        }
        
        jsonResponse(true, '获取成功', [
            'conversation_id' => $conversation ? $conversation['id'] : null,
            'messages' => $messages,
            'chat_type' => $chatType
        ]);
        break;
        
    case 'send':
        // 发送消息
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $targetId = intval($input['target_id'] ?? 0);
        $msgType = intval($input['msg_type'] ?? 1);
        $content = $input['content'] ?? '';
        $chatType = intval($input['chat_type'] ?? 1); // 1单聊 2群聊
        
        if ($targetId <= 0 || empty($content)) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查消息类型
        if (!in_array($msgType, [1, 2, 3])) {
            jsonResponse(false, '不支持的消息类型');
        }
        
        // 处理消息内容
        $processedContent = processMessageContent($msgType, $content);
        
        try {
            $pdo->beginTransaction();
            
            // 检查接收者的阅后即焚设置
            $burnEnabled = false;
            $burnDelay = BURN_DELAY_SECONDS;
            
            if ($chatType === 2) {
                // 群聊消息
                // 检查是否是群成员
                $stmt = $pdo->prepare("
                    SELECT * FROM group_members 
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$targetId, $currentUser['id']]);
                
                if ($stmt->rowCount() == 0) {
                    jsonResponse(false, '您不是该群成员');
                }
                
                // 获取群组信息
                $stmt = $pdo->prepare("
                    SELECT * FROM `groups` WHERE id = ?
                ");
                $stmt->execute([$targetId]);
                $group = $stmt->fetch();
                
                if (!$group) {
                    jsonResponse(false, '群组不存在');
                }
                
                // 获取所有群成员
                $stmt = $pdo->prepare("
                    SELECT gm.user_id, gm.role, gm.last_read_msg_id,
                           s.setting_value as burn_setting
                    FROM group_members gm
                    LEFT JOIN user_settings s ON gm.user_id = s.user_id AND s.setting_key = 'burn_after_read'
                    WHERE gm.group_id = ?
                ");
                $stmt->execute([$targetId]);
                $members = $stmt->fetchAll();
                
                // 生成消息预览
                $preview = '';
                switch ($msgType) {
                    case 1:
                        $preview = mb_substr($processedContent, 0, 50, 'UTF-8');
                        break;
                    case 2:
                        $preview = '[图片]';
                        break;
                    case 3:
                        $preview = '[表情]';
                        break;
                }
                
                // 插入消息
                $stmt = $pdo->prepare("
                    INSERT INTO messages (conversation_id, sender_id, receiver_id, group_id, msg_type, content)
                    VALUES (0, ?, 0, ?, ?, ?)
                ");
                $stmt->execute([$currentUser['id'], $targetId, $msgType, $processedContent]);
                $messageId = $pdo->lastInsertId();
                
                // 更新所有群成员的会话
                foreach ($members as $member) {
                    $memberId = $member['user_id'];
                    
                    // 获取或创建会话
                    $stmt = $pdo->prepare("
                        SELECT id FROM conversations 
                        WHERE user_id = ? AND target_id = ? AND type = 2
                    ");
                    $stmt->execute([$memberId, $targetId]);
                    $conv = $stmt->fetch();
                    
                    if ($conv) {
                        $convId = $conv['id'];
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO conversations (user_id, target_id, type)
                            VALUES (?, ?, 2)
                        ");
                        $stmt->execute([$memberId, $targetId]);
                        $convId = $pdo->lastInsertId();
                    }
                    
                    // 如果是发送者自己，不增加未读数
                    if ($memberId == $currentUser['id']) {
                        $stmt = $pdo->prepare("
                            UPDATE conversations 
                            SET last_message = ?, last_message_time = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$preview, $convId]);
                    } else {
                        // 检查成员是否已读这条消息（根据last_read_msg_id）
                        // 这里简化处理：都增加未读数，后续读取时清零
                        $stmt = $pdo->prepare("
                            UPDATE conversations 
                            SET last_message = ?, last_message_time = NOW(), 
                                unread_count = unread_count + 1
                            WHERE id = ?
                        ");
                        $stmt->execute([$preview, $convId]);
                    }
                }
                
                $pdo->commit();
                
                // 返回消息信息
                $stmt = $pdo->prepare("
                    SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.msg_type, 
                           m.content, m.is_read, m.created_at,
                           u.nickname as sender_nickname, u.avatar as sender_avatar,
                           gm.nickname as group_nickname
                    FROM messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    LEFT JOIN group_members gm ON m.sender_id = gm.user_id AND gm.group_id = m.group_id
                    WHERE m.id = ?
                ");
                $stmt->execute([$messageId]);
                $message = $stmt->fetch();
                
                jsonResponse(true, '发送成功', $message);
                
            } else {
                // 单聊消息
                // 检查是否是好友
                $stmt = $pdo->prepare("
                    SELECT status FROM friends 
                    WHERE user_id = ? AND friend_id = ? AND status = 1
                    LIMIT 1
                ");
                $stmt->execute([$currentUser['id'], $targetId]);
                
                if ($stmt->rowCount() == 0) {
                    jsonResponse(false, '请先添加对方为好友');
                }
                
                // 检查接收者的阅后即焚设置
                $burnEnabled = getBurnSetting($targetId);
                $burnDelay = getBurnDelay($targetId);
                
                // 获取或创建发送者侧的会话
                $senderConv = getOrCreateConversation($currentUser['id'], $targetId);
                
                // 获取或创建接收者侧的会话
                $receiverConv = getOrCreateConversation($targetId, $currentUser['id']);
                
                // 插入消息
                $stmt = $pdo->prepare("
                    INSERT INTO messages (conversation_id, sender_id, receiver_id, msg_type, content)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$senderConv['id'], $currentUser['id'], $targetId, $msgType, $processedContent]);
                $messageId = $pdo->lastInsertId();
                
                // 生成消息预览
                $preview = '';
                switch ($msgType) {
                    case 1:
                        $preview = mb_substr($processedContent, 0, 50, 'UTF-8');
                        break;
                    case 2:
                        $preview = '[图片]';
                        break;
                    case 3:
                        $preview = '[表情]';
                        break;
                }
                
                // 更新发送者的会话
                $stmt = $pdo->prepare("
                    UPDATE conversations 
                    SET last_message = ?, last_message_time = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$preview, $senderConv['id']]);
                
                // 更新接收者的会话（增加未读计数）
                $stmt = $pdo->prepare("
                    UPDATE conversations 
                    SET last_message = ?, last_message_time = NOW(), unread_count = unread_count + 1
                    WHERE id = ?
                ");
                $stmt->execute([$preview, $receiverConv['id']]);
                
                $pdo->commit();
                
                // 返回消息信息
                $stmt = $pdo->prepare("
                    SELECT id, sender_id, receiver_id, msg_type, content, is_read, created_at
                    FROM messages WHERE id = ?
                ");
                $stmt->execute([$messageId]);
                $message = $stmt->fetch();
                
                $message['burn_enabled'] = $burnEnabled;
                $message['burn_delay'] = $burnDelay;
                
                jsonResponse(true, '发送成功', $message);
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '发送失败: ' . $e->getMessage());
        }
        break;
        
    case 'upload_image':
        // 上传图片消息
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        if (!isset($_FILES['image'])) {
            jsonResponse(false, '请选择图片文件');
        }
        
        $result = uploadImage($_FILES['image'], 'images');
        
        if ($result['success']) {
            jsonResponse(true, '上传成功', [
                'url' => $result['data']['url'],
                'filename' => $result['data']['filename']
            ]);
        } else {
            jsonResponse(false, $result['message']);
        }
        break;
        
    case 'mark_read':
        // 标记消息为已读
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $msgId = intval($input['msg_id'] ?? 0);
        $readDelay = intval($input['read_delay'] ?? 0);
        
        if ($msgId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 获取消息信息
            $stmt = $pdo->prepare("
                SELECT * FROM messages WHERE id = ? AND (is_burned = 0 OR is_burned IS NULL)
            ");
            $stmt->execute([$msgId]);
            $message = $stmt->fetch();
            
            if (!$message) {
                jsonResponse(false, '消息不存在或已焚毁');
            }
            
            // 检查是否是群聊消息
            if (!empty($message['group_id'])) {
                // 群聊消息 - 检查是否是群成员
                $stmt = $pdo->prepare("
                    SELECT * FROM group_members 
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$message['group_id'], $currentUser['id']]);
                
                if ($stmt->rowCount() == 0) {
                    jsonResponse(false, '无权操作此消息');
                }
                
                // 群聊消息不标记单独的已读状态（已在读消息列表时统一处理）
                $pdo->commit();
                jsonResponse(true, '已标记');
                
            } else {
                // 单聊消息
                // 只能标记别人发给我的消息为已读
                if ($message['receiver_id'] != $currentUser['id']) {
                    jsonResponse(false, '无权操作此消息');
                }
                
                // 检查当前用户的阅后即焚设置
                $burnEnabled = getBurnSetting($currentUser['id']);
                $burnDelay = $readDelay > 0 ? $readDelay : getBurnDelay($currentUser['id']);
                
                if ($burnEnabled) {
                    // 标记为已读并设置焚毁时间
                    $burnTime = date('Y-m-d H:i:s', time() + $burnDelay);
                    $stmt = $pdo->prepare("
                        UPDATE messages 
                        SET is_read = 1, burn_time = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$burnTime, $msgId]);
                    
                    $pdo->commit();
                    
                    jsonResponse(true, '已标记为已读，消息将于 ' . $burnTime . ' 焚毁');
                } else {
                    // 只标记已读，不设置焚毁时间
                    $stmt = $pdo->prepare("
                        UPDATE messages 
                        SET is_read = 1 
                        WHERE id = ?
                    ");
                    $stmt->execute([$msgId]);
                    
                    $pdo->commit();
                    
                    jsonResponse(true, '已标记为已读');
                }
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    case 'check_burn':
        // 检查并执行消息焚毁（定时任务或轮询调用）
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_burned = 1, content = '该消息已焚毁'
            WHERE burn_time IS NOT NULL 
              AND burn_time <= NOW() 
              AND (is_burned = 0 OR is_burned IS NULL)
        ");
        $stmt->execute();
        
        $count = $stmt->rowCount();
        
        jsonResponse(true, '检查完成', [
            'burned_count' => $count
        ]);
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
