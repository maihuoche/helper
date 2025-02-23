<?php

namespace Maihuoche;

class Router {
    private $defaultApp = 'app';
    private $allowedApps = ['app'];
    private $controllerBasePath;
    private $cacheRules = [];

    public function __construct() {
        // 设置控制器基础路径，假设在项目根目录下
        $this->controllerBasePath = dirname(__DIR__);

        // 设置默认缓存规则
        $this->cacheRules = [
            'app' => [
                'max-age' => 86400,  // 1天
                'public' => true
            ],
            'admin' => [
                'no-store' => true,  // 禁止缓存
                'private' => true
            ]
        ];
    }

    /**
     * 设置应用的缓存规则
     * @param string $appName 应用名称
     * @param array $rules 缓存规则
     */
    public function setCacheRules(string $appName, array $rules): void {
        $this->cacheRules[strtolower($appName)] = $rules;
    }

    
    /**
     * 添加允许的应用
     * @param string $appName 应用名称
     */
    public function addAllowedApp(string $appName): void {
        $appName = strtolower($appName);
        if (!in_array($appName, $this->allowedApps)) {
            $this->allowedApps[] = $appName;
        }
    }

    /**
     * 解析路由
     * @throws \Exception
     */
    public function dispatch(): void {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $uri)));

        // 解析应用、控制器和方法
        $app = $this->defaultApp;
        $start = 0;

        // 检查是否指定了应用
        if (isset($parts[0]) && in_array(strtolower($parts[0]), $this->allowedApps)) {
            $app = strtolower($parts[0]);
            $start = 1;
        }

        // 设置缓存控制头
        if (isset($this->cacheRules[$app])) {
            $cacheRules = $this->cacheRules[$app];
            $cacheControl = [];

            if (isset($cacheRules['max-age'])) {
                $cacheControl[] = "max-age=" . $cacheRules['max-age'];
            }
            if (!empty($cacheRules['public'])) {
                $cacheControl[] = "public";
            }
            if (!empty($cacheRules['private'])) {
                $cacheControl[] = "private";
            }
            if (!empty($cacheRules['no-store'])) {
                $cacheControl[] = "no-store";
            }

            if (!empty($cacheControl)) {
                header('Cache-Control: ' . implode(', ', $cacheControl));
            }
        }

        // 获取控制器名称
        $controllerName = isset($parts[$start]) ? ucfirst($parts[$start]) . 'Controller' : 'IndexController';

        // 获取方法名称
        $methodName = isset($parts[$start + 1]) ? strtolower($parts[$start + 1]) : 'index';

        // 构建控制器文件路径
        $controllerFile = $this->controllerBasePath . DIRECTORY_SEPARATOR .
            $app . DIRECTORY_SEPARATOR .
            'controller' . DIRECTORY_SEPARATOR .
            $controllerName . '.php';

        // 检查控制器文件是否存在
        if (!file_exists($controllerFile)) {
            throw new \Exception("Controller file not found: {$controllerFile}");
        }

        // 构建完整的控制器类名
        $controllerClass = '\\' . $app . '\\controller\\' . $controllerName;

        // 引入控制器文件
        require_once $controllerFile;

        // 检查控制器类是否存在
        if (!class_exists($controllerClass)) {
            throw new \Exception("Controller class not found: {$controllerClass}");
        }

        // 检查方法是否存在
        if (!method_exists($controllerClass, $methodName)) {
            throw new \Exception("Method not found: {$methodName} in {$controllerClass}");
        }

        // 获取方法参数
        $parameters = [];
        for ($i = $start + 2; $i < count($parts); $i += 2) {
            if (isset($parts[$i + 1])) {
                $parameters[$parts[$i]] = $parts[$i + 1];
            }
        }

        // 通过反射获取方法参数类型
        $reflection = new \ReflectionMethod($controllerClass, $methodName);
        $methodParams = $reflection->getParameters();

        // 准备方法调用参数
        $callParams = [];
        foreach ($methodParams as $param) {
            $paramName = $param->getName();
            if (!isset($parameters[$paramName])) {
                if (!$param->isOptional()) {
                    throw new \Exception("Missing required parameter: {$paramName}");
                }
                $callParams[] = $param->getDefaultValue();
                continue;
            }

            $value = $parameters[$paramName];
            $type = $param->getType();

            if ($type) {
                $typeName = $type->getName();
                // 类型转换和验证
                try {
                    switch ($typeName) {
                        case 'int':
                            if (!is_numeric($value)) {
                                throw new \Exception("Parameter {$paramName} must be numeric");
                            }
                            $value = (int)$value;
                            break;
                        case 'float':
                            if (!is_numeric($value)) {
                                throw new \Exception("Parameter {$paramName} must be numeric");
                            }
                            $value = (float)$value;
                            break;
                        case 'bool':
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                            if ($value === null) {
                                throw new \Exception("Parameter {$paramName} must be boolean");
                            }
                            break;
                        case 'string':
                            $value = (string)$value;
                            break;
                    }
                } catch (\Exception $e) {
                    throw new \Exception("Invalid parameter type for {$paramName}: " . $e->getMessage());
                }
            }

            $callParams[] = $value;
        }

        // 创建控制器实例并调用方法
        $controller = new $controllerClass();
        call_user_func_array([$controller, $methodName], $callParams);
    }
}