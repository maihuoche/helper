<?php

namespace Maihuoche;

class Response {
    /**
     * HTTP 状态码
     */
    private int $statusCode = 200;

    /**
     * 响应头
     */
    private array $headers = [];

    /**
     * 响应数据
     */
    private $data = null;

    /**
     * 视图变量
     */
    private array $viewVars = [];


    /**
     * 设置状态码
     */
    public function setStatus(int $code): self {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * 分配变量到视图
     * @param string|array $name 变量名或关联数组
     * @param mixed $value 变量值
     */
    public function assign($name, $value = null): self {
        if (is_array($name)) {
            $this->viewVars = array_merge($this->viewVars, $name);
        } else {
            $this->viewVars[$name] = $value;
        }
        return $this;
    }


    /**
     * 获取视图文件的完整路径
     */
    private function getViewPath(string $view): string {
        // 获取调用者的信息以确定应用目录
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2];
        $controllerClass = $caller['class'];
        $parts = explode('\\', $controllerClass);
        $app = $parts[0];  // 应用名

        // 构建视图文件的完整路径
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR .
            $app . DIRECTORY_SEPARATOR .
            'views' . DIRECTORY_SEPARATOR .
            ltrim($view, '/');
    }


    /**
     * 渲染视图
     * @param string|null $view 视图文件路径（相对于应用的views目录）
     * @param array $data 要传递给视图的数据
     * @throws \Exception
     */
    public function view(?string $view = null, array $data = []): void {
        // 合并变量
        $this->assign($data);

        // 如果没有指定视图文件，自动生成视图路径
        if ($view === null) {
            // 获取调用者的信息
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1];

            // 获取控制器名和方法名
            $controllerClass = $caller['class'];
            $method = $caller['function'];

            // 解析出应用名和控制器名
            $parts = explode('\\', $controllerClass);
            $app = $parts[0];  // 应用名
            $controller = strtolower(str_replace('Controller', '', end($parts)));  // 控制器名（不含Controller后缀）

            // 构建默认视图路径
            $view = $controller . '/' . $method . '.php';
        }

        // 构建完整的视图文件路径
        $viewFile = $this->getViewPath($view);

        if (!file_exists($viewFile)) {
            throw new \Exception("View file not found: {$viewFile}");
        }

        // 开启输出缓冲
        ob_start();

        // 将变量解压到当前符号表
        extract($this->viewVars);

        // 引入视图文件
        require $viewFile;

        // 获取缓冲区内容
        $content = ob_get_clean();

        // 输出HTML内容
        $this->html($content);
    }


    /**
     * 添加响应头
     */
    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 设置JSON响应
     */
    public function json($data, int $status = 200): void {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setStatus($status)
            ->data = $data;

        $this->send();
    }

    /**
     * 统一的API响应格式
     */
    public function apiResponse(int $code, string $message, $data = null, array $extra = []): void {
        $response = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        $this->json($response);
    }

    /**
     * 成功响应
     */
    public function success($data = null, string $message = 'Success', array $extra = []): void {
        $this->apiResponse(200, $message, $data, $extra);
    }

    /**
     * 错误响应
     */
    public function error(string $message = 'Error', int $code = 400, $data = null, array $extra = []): void {
        $this->apiResponse($code, $message, $data, $extra);
    }

    /**
     * 重定向
     */
    public function redirect(string $url, int $status = 302): void {
        $this->setHeader('Location', $url)
            ->setStatus($status)
            ->send();
    }

    /**
     * 文件下载
     */
    public function download(string $filePath, string $fileName = null): void {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $fileName = $fileName ?? basename($filePath);

        $this->setHeader('Content-Type', 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->setHeader('Content-Length', filesize($filePath));

        $this->data = function() use ($filePath) {
            readfile($filePath);
        };

        $this->send();
    }

    /**
     * 输出HTML
     */
    public function html(string $html, int $status = 200): void {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setStatus($status)
            ->data = $html;

        $this->send();
    }

    /**
     * 输出纯文本
     */
    public function text(string $text, int $status = 200): void {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setStatus($status)
            ->data = $text;

        $this->send();
    }

    /**
     * JSONP响应
     */
    public function jsonp(string $callback, $data, int $status = 200): void {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $this->setHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->setStatus($status)
            ->data = "{$callback}({$json});";

        $this->send();
    }

    /**
     * 发送响应
     */
    public function send(): void {
        if (!headers_sent()) {
            // 发送状态码
            http_response_code($this->statusCode);

            // 发送响应头
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        // 发送响应内容
        if (is_callable($this->data)) {
            call_user_func($this->data);
        } else {
            if (is_array($this->data) || is_object($this->data)) {
                echo json_encode($this->data, JSON_UNESCAPED_UNICODE);
            } else {
                echo $this->data;
            }
        }

        exit();
    }

    /**
     * 设置跨域响应头
     */
    public function cors(array $options = []): self {
        $defaultOptions = [
            'origin' => '*',
            'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-Requested-With',
            'credentials' => 'true',
            'max_age' => '86400'
        ];

        $options = array_merge($defaultOptions, $options);

        $this->setHeader('Access-Control-Allow-Origin', $options['origin'])
            ->setHeader('Access-Control-Allow-Methods', $options['methods'])
            ->setHeader('Access-Control-Allow-Headers', $options['headers'])
            ->setHeader('Access-Control-Allow-Credentials', $options['credentials'])
            ->setHeader('Access-Control-Max-Age', $options['max_age']);

        return $this;
    }
}