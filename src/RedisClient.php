<?php

namespace maihuoche;

use Redis;
use RedisException;
use Exception;

class RedisClient
{
    private $redis;
    private $config;
    private $prefix = '';
    private $retryTimes = 3;        // 重试次数
    private $retryInterval = 100;    // 重试间隔(毫秒)
    private $serializer = redis::SERIALIZER_PHP;  // 序列化方式

    /**
     *  constructor.
     * @param array $config
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? '';
        $this->connect();
    }

    /**
     * 建立连接
     * @throws Exception
     */
    private function connect()
    {
        try {
            $this->redis = new redis();

            // 设置连接超时
            $timeout = $this->config['timeout'] ?? 2;
            $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                $timeout
            );

            // 认证
            if (!empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }

            // 选择数据库
            $this->redis->select($this->config['database'] ?? 0);

            // 设置序列化
            $this->redis->setOption(redis::OPT_SERIALIZER, $this->serializer);

            // 设置前缀
            if ($this->prefix) {
                $this->redis->setOption(redis::OPT_PREFIX, $this->prefix);
            }

            $this->lastConnectTime = time();
        } catch (RedisException $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }

    /**
     * 重连机制
     * @return bool
     * @throws Exception
     */
    private function retry()
    {
        $retries = 0;
        while ($retries < $this->retryTimes) {
            try {
                $this->connect();
                return true;
            } catch (Exception $e) {
                $retries++;
                if ($retries === $this->retryTimes) {
                    throw new Exception("Redis retry connection failed after {$this->retryTimes} attempts");
                }
                usleep($this->retryInterval * 1000);
            }
        }
        return false;
    }

    /**
     * 执行Redis命令的包装方法
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws Exception
     */
    public function __call($method, $arguments)
    {
        try {
            $result = $this->redis->$method(...$arguments);
            return $result;
        } catch (RedisException $e) {
            // 判断是否需要重连
            if ($this->isNeedReconnect($e)) {
                $this->retry();
                // 重试一次命令
                return $this->redis->$method(...$arguments);
            }
            throw new Exception("Redis command failed: " . $e->getMessage());
        }
    }

    /**
     * 判断是否需要重连
     * @param RedisException $e
     * @return bool
     */
    private function isNeedReconnect(RedisException $e)
    {
        $message = $e->getMessage();
        return strpos($message, 'Connection lost') !== false
            || strpos($message, 'went away') !== false
            || strpos($message, 'Socket closed') !== false;
    }

    /**
     * 设置缓存标签
     * @param string $key
     * @param mixed $value
     * @param array $tags
     * @param int $ttl
     * @return bool
     */
    public function setWithTags(string $key, $value, array $tags, int $ttl = 0): bool
    {
        try {
            // 开始事务
            $this->redis->multi();

            // 存储值
            $this->redis->set($key, $value);
            if ($ttl > 0) {
                $this->redis->expire($key, $ttl);
            }

            // 存储标签
            foreach ($tags as $tag) {
                $tagKey = "tag:{$tag}";
                $this->redis->sAdd($tagKey, $key);
                if ($ttl > 0) {
                    $this->redis->expire($tagKey, $ttl);
                }
            }

            // 提交事务
            return !in_array(false, $this->redis->exec());
        } catch (RedisException $e) {
            $this->redis->discard();
            throw new Exception("Failed to set cache with tags: " . $e->getMessage());
        }
    }

    /**
     * 通过标签清除缓存
     * @param string $tag
     * @return bool
     */
    public function clearByTag(string $tag): bool
    {
        try {
            $tagKey = "tag:{$tag}";
            $keys = $this->redis->sMembers($tagKey);

            if (!empty($keys)) {
                // 开始事务
                $this->redis->multi();

                // 删除所有相关的键
                $this->redis->del(...$keys);
                // 删除标签集合
                $this->redis->del($tagKey);

                // 提交事务
                return !in_array(false, $this->redis->exec());
            }
            return true;
        } catch (RedisException $e) {
            $this->redis->discard();
            throw new Exception("Failed to clear cache by tag: " . $e->getMessage());
        }
    }

    /**
     * 设置序列化方式
     * @param int $serializer
     * @return bool
     */
    public function setSerializer(int $serializer): bool
    {
        $this->serializer = $serializer;
        return $this->redis->setOption(redis::OPT_SERIALIZER, $serializer);
    }

    /**
     * 设置键前缀
     * @param string $prefix
     * @return bool
     */
    public function setPrefix(string $prefix): bool
    {
        $this->prefix = $prefix;
        return $this->redis->setOption(redis::OPT_PREFIX, $prefix);
    }

    /**
     * 获取Redis实例
     * @return redis
     */
    public function getRedis(): redis
    {
        return $this->redis;
    }
}