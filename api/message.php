<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'list':
        // 获取消息列表
        $targetId = intval($_GET['target_id'] ?? 0);
        $lastMsgId = intval($_GET['last_msg_id'] ?? 0);
        $limit = intval($_GET['limit'] ?? 20);
        
        if ($targetId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
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
        
        // 获取或创建会话
        $conversation = getOrCreateConversation($currentUser['id'], $targetId);
        
        // 构建查询条件
        $where = "conversation_id = ? AND is_burned = 0";
        $params = [$conversation['id']];
        
        if ($lastMsgId > 0) {
            $where .= " AND id < ?";
            $params[] = $lastMsgId;
        }
        
        // 查询消息
        $stmt = $pdo->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.msg_type, m.content, 
                   m.is_read, m.is_burned, m.burn_time, m.created_at
            FROM messages m
            WHERE {$where}
            ORDER BY m.id DESC
            LIMIT ?
        ");
        $params[] = $limit;
        $stmt->execute($params);
        
        $messages = $stmt->fetchAll();
        
        // 倒序排列
        $messages = array_reverse($messages);
        
        // 标记对方发送的消息为已读
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversation['id'], $targetId]);
        
        // 更新会话的未读计数
        $stmt = $pdo->prepare("
            UPDATE conversations 
            SET unread_count = 0 
            WHERE user_id = ? AND target_id = ?
        ");
        $stmt->execute([$currentUser['id'], $targetId]);
        
        jsonResponse(true, '获取成功', [
            'conversation_id' => $conversation['id'],
            'messages' => $messages
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
        
        if ($targetId <= 0 || empty($content)) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查消息类型
        if (!in_array($msgType, [1, 2, 3])) {
            jsonResponse(false, '不支持的消息类型');
        }
        
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
        
        // 处理消息内容
        $processedContent = processMessageContent($msgType, $content);
        
        try {
            $pdo->beginTransaction();
            
            // 获取或创建发送者侧的会话
            $senderConv = getOrCreateConversation($currentUser['id'], $targetId);
            
            // 获取或创建接收者侧的会话
            $receiverConv = getOrCreateConversation($targetId, $currentUser['id']);
            
            // 插入消息到发送者会话
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
            
            jsonResponse(true, '发送成功', $message);
            
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
        $readDelay = intval($input['read_delay'] ?? BURN_DELAY_SECONDS);
        
        if ($msgId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 获取消息信息
            $stmt = $pdo->prepare("
                SELECT * FROM messages WHERE id = ? AND is_burned = 0
            ");
            $stmt->execute([$msgId]);
            $message = $stmt->fetch();
            
            if (!$message) {
                jsonResponse(false, '消息不存在或已焚毁');
            }
            
            // 只能标记别人发给我的消息为已读
            if ($message['receiver_id'] != $currentUser['id']) {
                jsonResponse(false, '无权操作此消息');
            }
            
            // 标记为已读并设置焚毁时间
            $burnTime = date('Y-m-d H:i:s', time() + $readDelay);
            $stmt = $pdo->prepare("
                UPDATE messages 
                SET is_read = 1, burn_time = ? 
                WHERE id = ?
            ");
            $stmt->execute([$burnTime, $msgId]);
            
            $pdo->commit();
            
            jsonResponse(true, '已标记为已读，消息将于 ' . $burnTime . ' 焚毁');
            
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
              AND is_burned = 0
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
