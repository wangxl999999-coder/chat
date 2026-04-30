# 阅后即焚 - 即时通信H5应用

一款基于PHP开发的阅后即焚即时通信H5网页应用，支持Web端和移动端自适应显示。

## 功能特性

### 用户端功能
- **用户注册登录**：支持手机号+密码、手机号+验证码两种登录方式
- **唯一号码标识**：用户注册后自动生成6-9位随机唯一数字号码
- **好友系统**：
  - 搜索用户（按号码或昵称）
  - 发送/接受好友申请
  - 好友列表管理
- **即时通信**：
  - 发送文字消息
  - 发送图片消息
  - 发送表情消息
- **阅后即焚**：消息被阅读后30秒自动焚毁
- **个人资料**：头像、昵称、性别、简介等编辑
- **三大主菜单**：
  - 会话：查看历史会话、对话记录
  - 通讯录：查看所有好友、好友基本信息
  - 我的：展示用户信息、设置

### 管理后台功能
- **仪表盘**：数据统计（用户数、消息数等）
- **用户管理**：
  - 用户列表展示
  - 搜索/筛选用户
  - 启用/禁用用户
  - 重置用户密码
  - 查看用户详情
- **管理员管理**：
  - 添加/删除管理员
  - 角色权限区分（普通/超级管理员）

## 技术栈

- **后端**：PHP 7.0+、PDO、MySQL
- **前端**：HTML5、CSS3、原生JavaScript
- **特性**：响应式设计、自适应布局

## 项目结构

```
chat/
├── api/                    # API接口
│   ├── index.php          # 路由入口
│   ├── auth.php           # 认证相关接口
│   ├── user.php           # 用户相关接口
│   ├── friend.php         # 好友相关接口
│   ├── message.php        # 消息相关接口
│   ├── conversation.php   # 会话相关接口
│   └── admin.php          # 管理后台接口
├── assets/                 # 静态资源
│   ├── css/style.css      # 样式文件
│   └── js/app.js          # 前端应用脚本
├── config/                 # 配置文件
│   ├── config.php         # 基础配置
│   └── database.php       # 数据库配置
├── includes/               # 核心文件
│   └── functions.php      # 工具函数库
├── uploads/                # 上传文件目录
│   ├── avatars/           # 头像
│   └── images/            # 消息图片
├── logs/                   # 日志目录
├── auth.html              # 登录注册页
├── index.html             # 主应用页
├── chat.html              # 聊天页
├── friend-detail.html     # 好友详情页
├── edit-profile.html      # 编辑资料页
├── admin.html             # 管理后台页
└── README.md
```

## 安装部署

### 环境要求
- PHP 7.0+
- MySQL 5.7+
- Apache/Nginx Web服务器

### 安装步骤

1. **创建数据库**
```sql
CREATE DATABASE chat_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. **配置数据库连接**

编辑 `config/database.php`，修改数据库连接信息：
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'chat_app');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

3. **配置网站URL**

编辑 `config/config.php`，修改网站URL：
```php
define('SITE_URL', 'http://your-domain/chat');
```

4. **初始化数据库**

访问 `http://your-domain/chat/api/init` 自动创建数据表。

5. **设置目录权限**

确保以下目录有写入权限：
- `uploads/`
- `logs/`

### 默认账号

- **管理后台**：
  - 用户名：`admin`
  - 密码：`admin123`

## API接口说明

### 认证接口
- `POST /api/auth/send_code` - 发送验证码
- `POST /api/auth/register` - 用户注册
- `POST /api/auth/login` - 用户登录
- `POST /api/auth/logout` - 退出登录
- `GET /api/auth/check_login` - 检查登录状态

### 用户接口
- `GET /api/user/profile` - 获取个人资料
- `POST /api/user/update` - 更新个人资料
- `POST /api/user/update_password` - 修改密码
- `POST /api/user/upload_avatar` - 上传头像
- `GET /api/user/search` - 搜索用户
- `GET /api/user/info` - 获取指定用户信息

### 好友接口
- `GET /api/friend/list` - 获取好友列表
- `GET /api/friend/requests` - 获取好友申请列表
- `POST /api/friend/add` - 发送好友申请
- `POST /api/friend/accept` - 接受好友申请
- `POST /api/friend/reject` - 拒绝好友申请
- `POST /api/friend/delete` - 删除好友

### 消息接口
- `GET /api/message/list` - 获取消息列表
- `POST /api/message/send` - 发送消息
- `POST /api/message/upload_image` - 上传图片
- `POST /api/message/mark_read` - 标记已读
- `GET /api/message/check_burn` - 检查焚毁消息

### 会话接口
- `GET /api/conversation/list` - 获取会话列表
- `POST /api/conversation/delete` - 删除会话
- `POST /api/conversation/clear_unread` - 清空未读

### 管理后台接口
- `POST /api/admin/login` - 管理员登录
- `POST /api/admin/logout` - 退出登录
- `GET /api/admin/check_login` - 检查登录状态
- `GET /api/admin/dashboard` - 获取仪表盘数据
- `GET /api/admin/users` - 获取用户列表
- `GET /api/admin/user_detail` - 获取用户详情
- `POST /api/admin/update_user_status` - 更新用户状态
- `POST /api/admin/reset_user_password` - 重置用户密码
- `GET /api/admin/admins` - 获取管理员列表
- `POST /api/admin/add_admin` - 添加管理员
- `POST /api/admin/delete_admin` - 删除管理员

## 阅后即焚机制

1. 消息发送后，状态为未读
2. 接收者查看消息时，消息被标记为已读并设置焚毁时间（默认30秒后）
3. 系统定时轮询检查已到期的消息，自动标记为已焚毁
4. 已焚毁的消息内容被替换为"该消息已焚毁"

## 注意事项

1. **实时消息**：当前版本采用轮询方式实现消息推送，建议生产环境使用WebSocket
2. **验证码**：当前验证码仅存储在Session中，生产环境建议接入真实短信服务
3. **图片存储**：当前图片存储在本地，建议生产环境使用云存储服务
4. **安全**：建议生产环境开启HTTPS，使用更复杂的密码策略

## 开发说明

- 前端采用原生JavaScript，无第三方框架依赖
- 后端采用PHP原生开发，采用PDO数据库操作
- 响应式设计，适配PC和移动端

## 许可证

本项目仅供学习交流使用。
