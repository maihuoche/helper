# Redis 客户端使用文档

## 安装
```
composer require maihuoche/helper
```
## 目录
1. [配置说明](#配置说明)
2. [基本使用](#基本使用)
3. [高级特性](#高级特性)
4. [错误处理](#错误处理)
5. [性能优化](#性能优化)

## 配置说明

在 `config/config.php` 中配置：

```php
'redis_config' => [
    'host'     => '127.0.0.1',
    'password' => null,
    'port'     => 6379,
    'database' => 0,
    'timeout'  => 2,
    'prefix'   => 'app:',
    'pool' => [
        'max_connections' => 10,
        'min_connections' => 1,
        'wait_timeout' => 3,
        'idle_timeout' => 50,
        'heartbeat_interval' => 50,
    ],
]
```


## 基本使用

### 初始化

```php
use App\Library\RedisClient;

$redis = new RedisClient($config['redis_config']);
```


### 基本操作

```php
// 设置值
$redis->set('key', 'value');

// 获取值
$value = $redis->get('key');

// 删除键
$redis->del('key');

// 设置过期时间
$redis->set('key', 'value');
$redis->expire('key', 3600);
```


## 高级特性

### 缓存标签

```php
// 设置带标签的缓存
$redis->setWithTags(
    'user:1',
    ['name' => 'John'],
    ['users', 'active'],
    3600
);

// 通过标签清除缓存
$redis->clearByTag('users');
```


### 序列化配置

```php
// 设置序列化方式
$redis->setSerializer(Redis::SERIALIZER_PHP);    // PHP序列化
$redis->setSerializer(Redis::SERIALIZER_JSON);   // JSON序列化
$redis->setSerializer(Redis::SERIALIZER_NONE);   // 不序列化
```


### 前缀管理

```php
// 设置键前缀
$redis->setPrefix('newapp:');
```


## 错误处理

```php
try {
    $redis->set('key', 'value');
} catch (Exception $e) {
    echo $e->getMessage();
}
```


### 自动重连机制
- 连接断开时自动重试
- 默认重试3次
- 重试间隔100毫秒

## 性能优化

### 1. 序列化选择
- `SERIALIZER_PHP`: 复杂对象
- `SERIALIZER_JSON`: 简单数据结构
- `SERIALIZER_NONE`: 字符串

### 2. 前缀管理
使用前缀避免键名冲突：
```php
$redis->setPrefix('myapp:');
```


### 3. 标签功能
管理相关缓存：
```php
// 设置
$redis->setWithTags('key', 'value', ['tag1', 'tag2']);
// 清理
$redis->clearByTag('tag1');
```


## 注意事项

1. 确保Redis服务可用
2. 生产环境使用密码
3. 合理设置超时时间
4. 定期清理过期标签
5. 选择合适序列化方式

## 错误码

| 错误 | 原因 | 解决方案 |
|-----|------|---------|
| 连接失败 | 服务不可用 | 检查网络和服务 |
| 认证失败 | 密码错误 | 检查配置密码 |
| 连接断开 | 网络中断 | 自动重连处理 |

## 最佳实践

1. 使用标签管理缓存
2. 设置合理过期时间
3. 使用事务保证一致性
4. 配置适当序列化
5. 使用前缀分隔数据

## 版本信息

v1.0.0
- 基础Redis操作
- 缓存标签支持
- 自动重连机制
- 序列化配置
- 前缀管理