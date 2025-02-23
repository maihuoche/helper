# 响应使用方法

### 命名规则

```
app/controller/IndexController.php
```

### 响应方法

```php
<?php

// 在控制器中使用
namespace app\controller;

use Maihuoche\Response;

class IndexController {
    private Response $response;

    public function __construct() {
        $this->response = new Response();
    }

    public function index(): void
    {
        $this->response->text('Hello, World!');

    }

    // 重定向
    public function redirect(): void {
        $this->response->redirect('/login');
    }

    // 返回text数据
    public function text(): void {
        $this->response->text('Hello, World!');
    }

    // 返回JSON数据
    public function json(): void {
        $data = ['name' => 'test', 'value' => 123];
        $this->response->success($data);
    }

    // 返回错误信息
    public function error(): void {
        $this->response->error('Invalid parameters', 400);
    }

    // 返回HTML
    public function page(): void {
        $html = '<h1>Welcome</h1>';
        $this->response->html($html);
    }

    // 文件下载
    public function download(): void {
        $this->response->download('/path/to/file.pdf', 'document.pdf');
    }


    // API with CORS
    public function api(): void {
        $this->response->cors()
            ->success(['data' => 'example']);
    }

    public function view(): void {

        // 准备数据
        $user = [
            'name' => 'John Doe',
            'age' => 30,
            'email' => 'john@example.com'
        ];

        // 方式1：通过assign方法分配变量
        $this->response->assign('user', $user);
        $this->response->assign('title', 'User Profile');

        // 或者一次分配多个变量
        $this->response->assign([
            'user' => $user,
            'title' => 'User Profile'
        ]);

        // 方式2：直接在view方法中传递数据
        $this->response->view(null, [
            'user' => $user,
            'title' => 'User Profile'
        ]);

        // 如果不指定视图文件，会自动使用 app/views/user/profile.php
        $this->response->view();

        // 也可以指定具体的视图文件
        $this->response->view('user/custom_profile.php');
    }

}
```