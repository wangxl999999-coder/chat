const API_BASE = '/chat/api';

// 工具函数
const App = {
    currentUser: null,
    currentPage: 'conversation',
    pollTimer: null,
    stickerPanelVisible: false,
    
    init() {
        this.checkLogin().then(loggedIn => {
            if (loggedIn) {
                this.initApp();
            } else {
                this.showAuthPage();
            }
        });
    },
    
    // 检查登录状态
    async checkLogin() {
        try {
            const res = await this.api('auth/check_login');
            if (res.success) {
                this.currentUser = res.data;
                return true;
            }
        } catch (e) {
            console.error('检查登录状态失败:', e);
        }
        return false;
    },
    
    // 显示登录注册页面
    showAuthPage() {
        window.location.href = 'auth.html';
    },
    
    // 初始化应用
    initApp() {
        this.bindEvents();
        this.switchPage('conversation');
        this.startPolling();
        this.updateUserInfo();
    },
    
    // 更新用户信息显示
    updateUserInfo() {
        if (!this.currentUser) return;
        
        // 我的页面信息
        const profileName = document.getElementById('profile-name');
        const profileNumber = document.getElementById('profile-number');
        const profileBio = document.getElementById('profile-bio');
        
        if (profileName) {
            profileName.textContent = this.currentUser.nickname || '用户';
        }
        if (profileNumber) {
            profileNumber.textContent = this.currentUser.user_number;
        }
        if (profileBio) {
            profileBio.textContent = this.currentUser.bio || '这个人很懒，什么都没写';
        }
        
        // 头像
        const avatarElements = document.querySelectorAll('.user-avatar');
        avatarElements.forEach(el => {
            if (this.currentUser.avatar) {
                el.innerHTML = `<img src="${this.currentUser.avatar}" class="avatar" alt="头像">`;
            } else {
                el.innerHTML = `<div class="avatar"><div class="avatar-placeholder">${(this.currentUser.nickname || 'U')[0].toUpperCase()}</div></div>`;
            }
        });
    },
    
    // 绑定事件
    bindEvents() {
        // 底部导航
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const page = item.dataset.page;
                this.switchPage(page);
            });
        });
        
        // 搜索用户
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            let searchTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    this.searchUsers(searchInput.value);
                }, 500);
            });
        }
        
        // 添加好友按钮
        const addFriendBtn = document.getElementById('add-friend-btn');
        if (addFriendBtn) {
            addFriendBtn.addEventListener('click', () => {
                this.showSearchPage();
            });
        }
        
        // 搜索页面返回
        const searchBackBtn = document.getElementById('search-back-btn');
        if (searchBackBtn) {
            searchBackBtn.addEventListener('click', () => {
                this.hideSearchPage();
            });
        }
        
        // 退出登录
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => {
                this.logout();
            });
        }
        
        // 编辑资料
        const editProfileBtn = document.getElementById('edit-profile-btn');
        if (editProfileBtn) {
            editProfileBtn.addEventListener('click', () => {
                this.showEditProfile();
            });
        }
        
        // 好友申请列表
        const friendRequestsBtn = document.getElementById('friend-requests-btn');
        if (friendRequestsBtn) {
            friendRequestsBtn.addEventListener('click', () => {
                this.showFriendRequests();
            });
        }
    },
    
    // 切换页面
    switchPage(page) {
        this.currentPage = page;
        
        // 更新导航状态
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.toggle('active', item.dataset.page === page);
        });
        
        // 隐藏所有页面
        document.querySelectorAll('.page-view').forEach(pv => {
            pv.classList.remove('active');
        });
        
        // 显示目标页面
        const targetPage = document.getElementById(`page-${page}`);
        if (targetPage) {
            targetPage.classList.add('active');
        }
        
        // 加载页面数据
        switch (page) {
            case 'conversation':
                this.loadConversations();
                break;
            case 'friend':
                this.loadFriends();
                break;
            case 'profile':
                this.loadProfile();
                break;
        }
    },
    
    // API请求
    async api(endpoint, method = 'GET', data = null) {
        const url = API_BASE + endpoint;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include'
        };
        
        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }
        
        try {
            const response = await fetch(url, options);
            const result = await response.json();
            return result;
        } catch (error) {
            console.error('API请求失败:', error);
            throw error;
        }
    },
    
    // 显示Toast
    toast(message, duration = 2000) {
        // 移除已存在的toast
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }
        
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, duration);
    },
    
    // 格式化时间
    formatTime(timeStr) {
        if (!timeStr) return '';
        
        const time = new Date(timeStr);
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDate = new Date(time.getFullYear(), time.getMonth(), time.getDate());
        
        const diffDays = Math.floor((today - msgDate) / (1000 * 60 * 60 * 24));
        
        if (diffDays === 0) {
            // 今天
            return time.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        } else if (diffDays === 1) {
            // 昨天
            return '昨天';
        } else if (diffDays < 7) {
            // 一周内
            const weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
            return weekdays[time.getDay()];
        } else {
            // 更早
            return `${time.getMonth() + 1}/${time.getDate()}`;
        }
    },
    
    // 加载会话列表
    async loadConversations() {
        try {
            const res = await this.api('/conversation/list');
            if (res.success) {
                this.renderConversations(res.data.conversations, res.data.total_unread);
            }
        } catch (e) {
            console.error('加载会话列表失败:', e);
        }
    },
    
    // 渲染会话列表
    renderConversations(conversations, totalUnread) {
        const container = document.getElementById('conversation-list');
        const emptyState = document.getElementById('conversation-empty');
        
        // 更新未读数角标
        const badge = document.querySelector('.nav-item[data-page="conversation"] .nav-badge');
        if (badge) {
            if (totalUnread > 0) {
                badge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
        
        if (!conversations || conversations.length === 0) {
            container.innerHTML = '';
            if (emptyState) emptyState.style.display = 'flex';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';
        
        container.innerHTML = conversations.map(conv => {
            const avatar = conv.avatar ? 
                `<img src="${conv.avatar}" class="avatar" alt="${conv.nickname}">` :
                `<div class="avatar"><div class="avatar-placeholder">${(conv.nickname || 'U')[0].toUpperCase()}</div></div>`;
            
            const lastMessage = conv.last_message || '暂无消息';
            const time = this.formatTime(conv.last_message_time);
            const unreadBadge = conv.unread_count > 0 ? 
                `<span class="list-item-badge">${conv.unread_count > 99 ? '99+' : conv.unread_count}</span>` : '';
            
            return `
                <div class="list-item" onclick="App.openChat(${conv.target_id}, '${conv.nickname}', '${conv.avatar || ''}')">
                    ${avatar}
                    <div class="list-item-content">
                        <div class="list-item-title">${conv.nickname || '用户'}</div>
                        <div class="list-item-subtitle">${lastMessage}</div>
                    </div>
                    <div class="list-item-meta">
                        <span class="list-item-time">${time}</span>
                        ${unreadBadge}
                    </div>
                </div>
            `;
        }).join('');
    },
    
    // 打开聊天
    openChat(targetId, nickname, avatar) {
        // 存储聊天目标信息
        sessionStorage.setItem('chat_target', JSON.stringify({
            id: targetId,
            nickname,
            avatar
        }));
        
        // 跳转到聊天页面
        window.location.href = 'chat.html';
    },
    
    // 加载好友列表
    async loadFriends() {
        try {
            const res = await this.api('/friend/list');
            if (res.success) {
                this.renderFriends(res.data);
            }
        } catch (e) {
            console.error('加载好友列表失败:', e);
        }
    },
    
    // 渲染好友列表
    renderFriends(friends) {
        const container = document.getElementById('friend-list');
        const emptyState = document.getElementById('friend-empty');
        
        if (!friends || friends.length === 0) {
            container.innerHTML = '';
            if (emptyState) emptyState.style.display = 'flex';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';
        
        // 按昵称拼音首字母分组（简化处理）
        container.innerHTML = friends.map(friend => {
            const avatar = friend.avatar ? 
                `<img src="${friend.avatar}" class="avatar" alt="${friend.nickname}">` :
                `<div class="avatar"><div class="avatar-placeholder">${(friend.nickname || 'U')[0].toUpperCase()}</div></div>`;
            
            const bio = friend.bio || '这个人很懒，什么都没写';
            
            return `
                <div class="list-item" onclick="App.openFriendDetail(${friend.friend_id})">
                    ${avatar}
                    <div class="list-item-content">
                        <div class="list-item-title">${friend.nickname || '用户'}</div>
                        <div class="list-item-subtitle">${bio}</div>
                    </div>
                </div>
            `;
        }).join('');
    },
    
    // 显示搜索页面
    showSearchPage() {
        const searchPage = document.getElementById('search-page');
        if (searchPage) {
            searchPage.style.display = 'block';
        }
    },
    
    // 隐藏搜索页面
    hideSearchPage() {
        const searchPage = document.getElementById('search-page');
        if (searchPage) {
            searchPage.style.display = 'none';
        }
        const results = document.getElementById('search-results');
        if (results) results.innerHTML = '';
        const searchInput = document.getElementById('search-input');
        if (searchInput) searchInput.value = '';
    },
    
    // 搜索用户
    async searchUsers(keyword) {
        const results = document.getElementById('search-results');
        if (!results) return;
        
        if (!keyword.trim()) {
            results.innerHTML = '';
            return;
        }
        
        try {
            const res = await this.api(`/user/search?keyword=${encodeURIComponent(keyword)}`);
            if (res.success) {
                this.renderSearchResults(res.data);
            } else {
                results.innerHTML = `<div class="empty-state"><p>${res.message}</p></div>`;
            }
        } catch (e) {
            results.innerHTML = `<div class="empty-state"><p>搜索失败</p></div>`;
        }
    },
    
    // 渲染搜索结果
    renderSearchResults(users) {
        const results = document.getElementById('search-results');
        if (!results) return;
        
        if (!users || users.length === 0) {
            results.innerHTML = `<div class="empty-state"><p>未找到用户</p></div>`;
            return;
        }
        
        // 确保是数组
        const userList = Array.isArray(users) ? users : [users];
        
        results.innerHTML = userList.map(user => {
            const avatar = user.avatar ? 
                `<img src="${user.avatar}" class="avatar" alt="${user.nickname}">` :
                `<div class="avatar"><div class="avatar-placeholder">${(user.nickname || 'U')[0].toUpperCase()}</div></div>`;
            
            // 好友状态
            let statusBadge = '';
            let actionBtn = '';
            
            if (user.id === this.currentUser.id) {
                statusBadge = '<span class="friend-status-badge is-friend">我自己</span>';
            } else if (user.friend_status === 1) {
                statusBadge = '<span class="friend-status-badge is-friend">已添加</span>';
                actionBtn = `<button class="btn btn-sm" onclick="event.stopPropagation(); App.openChat(${user.id}, '${user.nickname}', '${user.avatar || ''}')">发消息</button>`;
            } else if (user.friend_status === 0) {
                statusBadge = '<span class="friend-status-badge pending">待确认</span>';
            } else {
                actionBtn = `<button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); App.addFriend(${user.id})">添加好友</button>`;
            }
            
            return `
                <div class="search-result-item">
                    ${avatar}
                    <div class="search-result-info">
                        <div class="search-result-name">
                            ${user.nickname || '用户'}
                            ${statusBadge}
                        </div>
                        <div class="search-result-number">号码: ${user.user_number}</div>
                    </div>
                    ${actionBtn}
                </div>
            `;
        }).join('');
    },
    
    // 添加好友
    async addFriend(friendId) {
        try {
            const res = await this.api('/friend/add', 'POST', { friend_id: friendId });
            if (res.success) {
                this.toast('好友申请已发送');
                // 刷新搜索结果
                const searchInput = document.getElementById('search-input');
                if (searchInput && searchInput.value) {
                    this.searchUsers(searchInput.value);
                }
            } else {
                this.toast(res.message);
            }
        } catch (e) {
            this.toast('操作失败');
        }
    },
    
    // 显示好友申请列表
    async showFriendRequests() {
        try {
            const res = await this.api('/friend/requests?type=received');
            if (res.success) {
                this.renderFriendRequests(res.data);
                const requestsPage = document.getElementById('friend-requests-page');
                if (requestsPage) requestsPage.style.display = 'block';
            }
        } catch (e) {
            this.toast('加载失败');
        }
    },
    
    // 渲染好友申请列表
    renderFriendRequests(requests) {
        const container = document.getElementById('friend-requests-list');
        if (!container) return;
        
        if (!requests || requests.length === 0) {
            container.innerHTML = `<div class="empty-state"><p>暂无好友申请</p></div>`;
            return;
        }
        
        container.innerHTML = requests.map(req => {
            const avatar = req.avatar ? 
                `<img src="${req.avatar}" class="avatar" alt="${req.nickname}">` :
                `<div class="avatar"><div class="avatar-placeholder">${(req.nickname || 'U')[0].toUpperCase()}</div></div>`;
            
            return `
                <div class="request-item">
                    ${avatar}
                    <div class="request-info">
                        <div class="request-name">${req.nickname || '用户'}</div>
                        <div class="request-number">${req.user_number}</div>
                    </div>
                    <div class="request-actions">
                        <button class="btn btn-sm" onclick="App.acceptFriendRequest(${req.user_id})">同意</button>
                        <button class="btn btn-sm btn-secondary" onclick="App.rejectFriendRequest(${req.user_id})">拒绝</button>
                    </div>
                </div>
            `;
        }).join('');
    },
    
    // 接受好友申请
    async acceptFriendRequest(friendId) {
        try {
            const res = await this.api('/friend/accept', 'POST', { friend_id: friendId });
            if (res.success) {
                this.toast('已同意');
                this.showFriendRequests();
                this.loadFriends();
            } else {
                this.toast(res.message);
            }
        } catch (e) {
            this.toast('操作失败');
        }
    },
    
    // 拒绝好友申请
    async rejectFriendRequest(friendId) {
        try {
            const res = await this.api('/friend/reject', 'POST', { friend_id: friendId });
            if (res.success) {
                this.toast('已拒绝');
                this.showFriendRequests();
            } else {
                this.toast(res.message);
            }
        } catch (e) {
            this.toast('操作失败');
        }
    },
    
    // 打开好友详情
    openFriendDetail(friendId) {
        sessionStorage.setItem('friend_detail_id', friendId);
        window.location.href = 'friend-detail.html';
    },
    
    // 加载个人资料
    loadProfile() {
        // 从currentUser更新
        this.updateUserInfo();
    },
    
    // 显示编辑资料
    showEditProfile() {
        window.location.href = 'edit-profile.html';
    },
    
    // 退出登录
    async logout() {
        try {
            const res = await this.api('/auth/logout', 'POST');
            if (res.success) {
                this.stopPolling();
                window.location.href = 'auth.html';
            }
        } catch (e) {
            this.toast('退出失败');
        }
    },
    
    // 开始轮询更新
    startPolling() {
        this.pollTimer = setInterval(() => {
            if (this.currentPage === 'conversation') {
                this.loadConversations();
            }
            // 检查焚毁消息
            this.api('/message/check_burn');
        }, 10000); // 每10秒轮询
    },
    
    // 停止轮询
    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
};

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});
