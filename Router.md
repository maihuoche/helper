# 路由使用

## 1. 路由引入

```php
try {
    $router = new Maihuoche\Router();
    
    // 添加其他应用（可选）
    // $router->addAllowedApp('admin');

    // 执行路由分发
    $router->dispatch();
    
} catch (\Exception $e) {
    if (SHOW_ERROR) {
        echo "Error: " . $e->getMessage();
    } else {
        echo "Error: Internal Server Error";
    }

}
```

## 2. Nginx伪静态

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

## 3. 路由规则

```
1. 基本格式：`/{应用}/{控制器}/{方法}/{参数key}/{参数value}`
   
   - 例如：`/admin/user/list` 或`/app/product/detail`
2. 默认值：
   
   - 应用默认为 'app'
   - 控制器默认为 'index'
   - 方法默认为 'index'
3. 参数类型指定：
   
   - 整数类型：`/app/user/list/id:int/123`
   - 布尔类型：`/app/post/update/status:bool/true`
   - 字符串类型：`/app/article/search/keyword:string/hello`
4. 请求方法支持：
   
   - 支持GET和POST请求
   - 可以通过表单提交POST数据
例如：

- `/` → app/index/index
- `/admin` → admin/index/index
- `/admin/user` → admin/user/index
- `/admin/user/list/page:int/1/size:int/10` → admin应用的user控制器的list方法，带参数page=1和size=10
```

### 4. 错误处理

```
添加了错误处理：
- 404 Not Found：路由未找到
- 405 Method Not Allowed：请求方法不允许
- 500 Internal Server Error：服务器错误
```