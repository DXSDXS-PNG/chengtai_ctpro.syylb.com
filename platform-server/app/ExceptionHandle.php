<?php
declare(strict_types=1);

namespace app;

use app\exception\ApiException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 全局异常处理
 * 约定：业务错误码即 HTTP 状态码；响应体统一 {code, msg, data}
 * 绝不向客户端暴露堆栈、SQL、路径、凭据
 */
class ExceptionHandle extends Handle
{
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        ApiException::class,
    ];

    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return parent::render($request, $e);
        }

        if ($e instanceof ApiException) {
            return json([
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
                'data' => $e->getData(),
            ], $e->getCode());
        }

        if ($e instanceof ValidateException) {
            return json(['code' => 400, 'msg' => $e->getError(), 'data' => null], 400);
        }

        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $msg    = $status === 404 ? '资源不存在' : ($status === 405 ? '请求方法不允许' : '请求异常');
            return json(['code' => $status, 'msg' => $msg, 'data' => null], $status);
        }

        // 开发环境保留框架调试输出，便于定位
        if ($this->app->isDebug()) {
            return parent::render($request, $e);
        }

        // 生产环境：记录真实异常，返回统一提示
        $this->report($e);
        return json(['code' => 500, 'msg' => '服务器繁忙，请稍后再试', 'data' => null], 500);
    }
}
